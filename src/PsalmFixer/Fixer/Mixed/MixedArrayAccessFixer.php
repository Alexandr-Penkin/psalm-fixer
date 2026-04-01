<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\Mixed;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Adds assert(is_array($var)) before mixed array accesses.
 */
final class MixedArrayAccessFixer extends AbstractFixer {
    #[\Override]
    public function getSupportedTypes(): array {
        return ['MixedArrayAccess'];
    }

    #[\Override]
    public function getName(): string {
        return 'MixedArrayAccessFixer';
    }

    #[\Override]
    public function getDescription(): string {
        return 'Adds assert(is_array()) before mixed array accesses';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult {
        $varName = $this->extractVarName($issue->getMessage());
        if ($varName === null) {
            $varName = $this->extractVarName($issue->getSnippet() ?? '');
        }
        if ($varName === null) {
            return FixResult::notFixed('Could not extract variable name');
        }

        $assertExpr = new FuncCall(
            new Name('is_array'),
            [new Arg(new Variable($varName))],
        );

        $assertStmt = new Expression(
            new FuncCall(new Name('assert'), [new Arg($assertExpr)]),
        );

        $inserted = $this->insertStatementBefore($stmts, $issue->getLineFrom(), $assertStmt);

        if ($inserted) {
            return FixResult::fixed("Added assert(is_array(\${$varName}))");
        }

        return FixResult::notFixed('Could not insert assert statement');
    }

    /** @return non-empty-string|null */
    private function extractVarName(string $message): ?string {
        if (preg_match('/\$(\w+)/', $message, $matches) === 1 && $matches[1] !== '') {
            return $matches[1];
        }

        return null;
    }
}
