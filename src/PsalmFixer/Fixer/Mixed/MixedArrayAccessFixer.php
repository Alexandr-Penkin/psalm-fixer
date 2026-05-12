<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\Mixed;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\BuildsAssertExpression;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Adds assert(is_array($var)) before mixed array accesses.
 */
final class MixedArrayAccessFixer extends AbstractFixer
{
    use BuildsAssertExpression;

    #[\Override]
    public function getSupportedTypes(): array
    {
        return ['MixedArrayAccess'];
    }

    #[\Override]
    public function getName(): string
    {
        return 'MixedArrayAccessFixer';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Adds assert(is_array()) before mixed array accesses';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult
    {
        $varName = self::extractVarName($issue);
        if ($varName === null) {
            return FixResult::notFixed('Could not extract variable name');
        }

        $assertExpr = new FuncCall(new Name('is_array'), [new Arg(new Variable($varName))]);

        $assertStmt = new Expression($this->wrapInAssert($assertExpr));

        if ($this->alreadyHasGuardBefore($stmts, $issue->getLineFrom(), $assertStmt)) {
            return FixResult::notFixed("assert(is_array(\${$varName})) already present");
        }

        $inserted = $this->insertStatementBefore($stmts, $issue->getLineFrom(), $assertStmt);

        if ($inserted) {
            return FixResult::fixed("Added assert(is_array(\${$varName}))");
        }

        return FixResult::notFixed('Could not insert assert statement');
    }
}
