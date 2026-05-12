<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\Mixed;

use PhpParser\Node\Stmt\Expression;
use PsalmFixer\Ast\TypeStringParser;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\BuildsAssertExpression;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Adds assert(is_type()) or assert($var instanceof Type) for mixed arguments.
 */
final class MixedArgumentFixer extends AbstractFixer
{
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
        return ['MixedArgument'];
    }

    #[\Override]
    public function getName(): string
    {
        return 'MixedArgumentFixer';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Adds type assertion for mixed arguments';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult
    {
        $expectedType = $this->typeParser->extractExpectedType($issue->getMessage());
        if ($expectedType === null) {
            return FixResult::notFixed('Could not extract expected type from message');
        }

        $varName = self::extractVarName($issue);
        if ($varName === null) {
            return FixResult::notFixed('Could not extract variable name');
        }

        $assertExpr = $this->buildAssertExpr($varName, $expectedType, $this->typeParser);
        if ($assertExpr === null) {
            return FixResult::notFixed("Cannot create assert for type: {$expectedType}");
        }

        $assertStmt = new Expression($this->wrapInAssert($assertExpr));

        if ($this->alreadyHasGuardBefore($stmts, $issue->getLineFrom(), $assertStmt)) {
            return FixResult::notFixed("assert for \${$varName} already present");
        }

        $inserted = $this->insertStatementBefore($stmts, $issue->getLineFrom(), $assertStmt);

        if ($inserted) {
            return FixResult::fixed("Added assert for \${$varName}");
        }

        return FixResult::notFixed('Could not insert assert statement');
    }
}
