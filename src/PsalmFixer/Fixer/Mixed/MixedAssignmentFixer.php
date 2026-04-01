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
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Adds assert() after mixed assignments.
 */
final class MixedAssignmentFixer extends AbstractFixer {
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
        $varName = $this->extractVarName($issue->getMessage());
        if ($varName === null) {
            // Try extracting from snippet
            $varName = $this->extractVarName($issue->getSnippet() ?? '');
        }
        if ($varName === null) {
            return FixResult::notFixed('Could not extract variable name');
        }

        // Try to detect expected type from message
        $expectedType = $this->typeParser->extractExpectedType($issue->getMessage());

        // Fallback: infer type from containing function's return type
        // (if the variable is used in a return statement)
        if ($expectedType === null) {
            $expectedType = $this->inferTypeFromContext($stmts, $issue->getLineFrom(), $varName);
        }

        if ($expectedType === null) {
            return FixResult::notFixed('Cannot determine expected type for mixed assignment');
        }

        $assertExpr = $this->buildAssertExpr($varName, $expectedType);

        if ($assertExpr === null) {
            return FixResult::notFixed("Cannot create assert for type: {$expectedType}");
        }

        $assertStmt = new Expression(
            new FuncCall(new Name('assert'), [new Arg($assertExpr)]),
        );

        $inserted = $this->insertStatementAfter($stmts, $issue->getLineFrom(), $assertStmt);

        if ($inserted) {
            return FixResult::fixed("Added assert after assignment of \${$varName}");
        }

        return FixResult::notFixed('Could not insert assert statement');
    }

    private function buildAssertExpr(string $varName, string $type): ?Node\Expr {
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
            if ($name !== '' && $name !== 'void' && $name !== 'never' && $name !== 'mixed') {
                return $name;
            }
        }
        if ($type instanceof Name) {
            $name = $type->toString();
            if ($name !== '') {
                return $name;
            }
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
