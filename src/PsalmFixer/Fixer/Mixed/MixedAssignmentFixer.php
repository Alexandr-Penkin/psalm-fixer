<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\Mixed;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use PsalmFixer\Ast\TypeStringParser;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\AppendsPsalmSuppress;
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
final class MixedAssignmentFixer extends AbstractFixer {
    use AppendsPsalmSuppress;

    private TypeStringParser $typeParser;

    public function __construct() {
        parent::__construct();
        $this->typeParser = new TypeStringParser();
    }

    #[\Override]
    public function getSupportedTypes(): array {
        return ['MixedAssignment'];
    }

    #[\Override]
    public function getName(): string {
        return 'MixedAssignmentFixer';
    }

    #[\Override]
    public function getDescription(): string {
        return 'Adds type assertion after mixed assignments';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult {
        $assertResult = $this->tryAddAssert($stmts, $issue);
        if ($assertResult !== null) {
            return $assertResult;
        }

        return $this->fallbackSuppress($stmts, $issue);
    }

    /**
     * @param list<Node> $stmts
     */
    private function tryAddAssert(array &$stmts, PsalmIssue $issue): ?FixResult {
        $varName = $this->extractVarName($issue->getMessage());
        if ($varName === null) {
            $varName = $this->extractVarName($issue->getSnippet() ?? '');
        }
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

        $assertExpr = $this->buildAssertExpr($varName, $expectedType);
        if ($assertExpr === null) {
            return null;
        }

        $assertStmt = new Expression(
            new FuncCall(new Name('assert'), [new Arg($assertExpr)]),
        );

        $inserted = $this->insertStatementAfter($stmts, $issue->getLineFrom(), $assertStmt);
        if (!$inserted) {
            return null;
        }

        return FixResult::fixed("Added assert after assignment of \${$varName}");
    }

    /**
     * @param list<Node> $stmts
     */
    private function fallbackSuppress(array $stmts, PsalmIssue $issue): FixResult {
        return $this->attachPsalmSuppress($stmts, $issue->getLineFrom(), 'MixedAssignment');
    }

    private function buildAssertExpr(string $varName, string $type): ?Node\Expr {
        assert($type !== '');
        $isFunc = $this->typeParser->getIsTypeFunction($type);
        if ($isFunc !== null) {
            return new FuncCall(
                new Name($isFunc),
                [new Arg(new Variable($varName))],
            );
        }

        if ($this->typeParser->isClassType($type)) {
            return new Instanceof_(
                new Variable($varName),
                new Name\FullyQualified(ltrim($type, '\\')),
            );
        }

        return null;
    }

    /**
     * Try to infer expected type from how the variable is used in the containing function.
     *
     * @param list<Node> $stmts
     * @param non-empty-string $varName
     * @return non-empty-string|null
     */
    private function inferTypeFromContext(array $stmts, int $line, string $varName): ?string {
        $func = $this->nodeFinder->findContainingFunction($stmts, $line);
        if ($func === null || $func->stmts === null) {
            return null;
        }

        // Check if variable is directly returned → use function return type
        foreach ($func->stmts as $stmt) {
            if ($stmt instanceof Return_
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
    private function typeNodeToString(Node\ComplexType|Identifier|Name|null $type): ?string {
        if ($type instanceof Identifier) {
            $name = $type->name;
            if ($name !== 'void' && $name !== 'never' && $name !== 'mixed') {
                return $name;
            }
        }
        if ($type instanceof Name) {
            $name = $type->toString();
            return $name;
        }
        if ($type instanceof Node\NullableType) {
            return $this->typeNodeToString($type->type);
        }

        return null;
    }

    /** @return non-empty-string|null */
    private function extractVarName(string $message): ?string {
        if (preg_match('/\$(\w+)/', $message, $matches) === 1 && $matches[1] !== '') {
            return $matches[1];
        }

        return null;
    }
}
