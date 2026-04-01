<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\Mixed;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;
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

        // For mixed assignments, we add assert(is_type()) after the line.
        // Try to detect expected type from usage context in the message.
        $expectedType = $this->typeParser->extractExpectedType($issue->getMessage());

        // If we can't determine the type, use a generic is_scalar or is_array check
        if ($expectedType !== null) {
            $assertExpr = $this->buildAssertExpr($varName, $expectedType);
        } else {
            // Default: just add a comment that type needs to be specified
            return FixResult::notFixed('Cannot determine expected type for mixed assignment');
        }

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

    /** @return non-empty-string|null */
    private function extractVarName(string $message): ?string {
        if (preg_match('/\$(\w+)/', $message, $matches) === 1 && $matches[1] !== '') {
            return $matches[1];
        }

        return null;
    }
}
