<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\Mixed;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Return_;
use PsalmFixer\Ast\TypeStringParser;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\AppendsPsalmSuppress;
use PsalmFixer\Fixer\BuildsAssertExpression;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Resolves `MixedAssignment` warnings in two modes:
 *
 *   1. When the message or surrounding code reveals a concrete expected type
 *      (scalar or class), insert `assert(is_*($var))` / `assert($var instanceof
 *      Type)` immediately after the assignment.
 *   2. Otherwise — including the common "Unable to determine the type that $X
 *      is being assigned to" cases — annotate the statement with
 *      `@psalm-suppress MixedAssignment`. Same conservative fallback used by
 *      `PropertyTypeCoercionFixer` / `ArgumentTypeCoercionFixer`.
 */
final class MixedAssignmentFixer extends AbstractFixer
{
    use AppendsPsalmSuppress;
    use BuildsAssertExpression;

    private TypeStringParser $typeParser;

    public function __construct()
    {
        parent::__construct();
        $this->typeParser = new TypeStringParser();
    }

    #[\Override]
    public function getSupportedTypes(): array
    {
        return ['MixedAssignment'];
    }

    #[\Override]
    public function getName(): string
    {
        return 'MixedAssignmentFixer';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Adds type assertion after mixed assignments';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult
    {
        $assertResult = $this->tryAddAssert($stmts, $issue);
        if ($assertResult !== null) {
            return $assertResult;
        }

        return $this->fallbackSuppress($stmts, $issue);
    }

    /**
     * @param list<Node> $stmts
     */
    private function tryAddAssert(array &$stmts, PsalmIssue $issue): ?FixResult
    {
        $varName = self::extractVarName($issue);
        if ($varName === null) {
            return null;
        }

        $expectedType = $this->typeParser->extractExpectedType($issue->getMessage());
        if ($expectedType === null) {
            $expectedType = $this->inferTypeFromContext($stmts, $issue->getLineFrom(), $varName);
        }
        if ($expectedType === null) {
            return null;
        }

        $assertExpr = $this->buildAssertExpr($varName, $expectedType, $this->typeParser);
        if ($assertExpr === null) {
            return null;
        }

        $assertStmt = new Expression($this->wrapInAssert($assertExpr));

        // foreach header introduces the iteration variable; the only place
        // where asserting it makes sense is as the FIRST statement of the
        // body, not after the foreach (out of scope).
        if ($this->lineIsForeachHeader($stmts, $issue->getLineFrom(), $varName)) {
            return $this->insertAtForeachBodyTop($stmts, $issue->getLineFrom(), $assertStmt, $varName);
        }

        // Regular assignment: insert assert after the assignment line so the
        // narrowed type is visible to subsequent usages.
        if ($this->alreadyHasGuardBefore($stmts, $issue->getLineFrom(), $assertStmt)) {
            return FixResult::notFixed("assert for \${$varName} already present");
        }

        $inserted = $this->insertStatementAfter($stmts, $issue->getLineFrom(), $assertStmt);
        if (!$inserted) {
            return null;
        }

        return FixResult::fixed("Added assert after assignment of \${$varName}");
    }

    /**
     * Returns true if the line is a foreach header whose value variable is
     * $varName — meaning the offending mixed assignment is the foreach
     * iteration itself, not a preceding `=` assignment.
     *
     * @param list<Node> $stmts
     */
    private function lineIsForeachHeader(array $stmts, int $line, string $varName): bool
    {
        $foreach = $this->nodeFinder->findNodeOfTypeAtLine($stmts, $line, Foreach_::class);
        if (!$foreach instanceof Foreach_) {
            return false;
        }
        return $foreach->valueVar instanceof Variable && $foreach->valueVar->name === $varName;
    }

    /**
     * Prepend $assertStmt as the first statement of the foreach body covering
     * $line. Idempotent: scope-walks the body and skips if any sibling matches.
     *
     * @param list<Node> $stmts
     */
    private function insertAtForeachBodyTop(
        array &$stmts,
        int $line,
        Expression $assertStmt,
        string $varName,
    ): FixResult {
        $foreach = $this->nodeFinder->findNodeOfTypeAtLine($stmts, $line, Foreach_::class);
        if (!$foreach instanceof Foreach_) {
            return FixResult::notFixed('Could not locate foreach body');
        }

        /** @var list<Node> $body */
        $body = $foreach->stmts;
        // Probe with a line guaranteed to be inside the body — any line > the
        // foreach header that's not past the end works.
        $probeLine = $line + 1;
        if ($this->alreadyHasGuardBefore($body, $probeLine, $assertStmt)) {
            return FixResult::notFixed("assert for \${$varName} already present");
        }

        array_unshift($foreach->stmts, $assertStmt);
        return FixResult::fixed("Added assert as first foreach body statement for \${$varName}");
    }

    /**
     * @param list<Node> $stmts
     */
    private function fallbackSuppress(array $stmts, PsalmIssue $issue): FixResult
    {
        return $this->attachPsalmSuppress($stmts, $issue->getLineFrom(), 'MixedAssignment');
    }

    /**
     * Try to infer expected type from how the variable is used in the containing function.
     *
     * @param list<Node> $stmts
     * @param non-empty-string $varName
     * @return non-empty-string|null
     */
    private function inferTypeFromContext(array $stmts, int $line, string $varName): ?string
    {
        $func = $this->nodeFinder->findContainingFunction($stmts, $line);
        if ($func === null || $func->stmts === null) {
            return null;
        }

        // Check if variable is directly returned → use function return type
        foreach ($func->stmts as $stmt) {
            if (
                $stmt instanceof Return_
                && $stmt->expr instanceof Variable
                && $stmt->expr->name === $varName
                && $func->returnType !== null
            ) {
                return $this->typeNodeToString($func->returnType);
            }
        }

        return null;
    }

    /**
     * @return non-empty-string|null
     */
    private function typeNodeToString(Node\ComplexType|Identifier|Name|null $type): ?string
    {
        if ($type instanceof Identifier) {
            $name = $type->name;
            if ($name !== 'void' && $name !== 'never' && $name !== 'mixed') {
                return $name;
            }
        }
        if ($type instanceof Name) {
            return $type->toString();
        }
        if ($type instanceof Node\NullableType) {
            return $this->typeNodeToString($type->type);
        }

        return null;
    }
}
