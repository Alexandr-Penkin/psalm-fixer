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
use PsalmFixer\Fixer\BuildsAssertExpression;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Adds assert($var instanceof Class) before mixed method calls.
 */
final class MixedMethodCallFixer extends AbstractFixer
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
        return ['MixedMethodCall'];
    }

    #[\Override]
    public function getName(): string
    {
        return 'MixedMethodCallFixer';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Adds assert($var instanceof Class) before mixed method calls';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult
    {
        $varName = self::extractVarName($issue);
        if ($varName === null) {
            return FixResult::notFixed('Could not extract variable name');
        }

        $expectedType = $this->extractClassFromMessage($issue->getMessage());
        $assertExpr = $expectedType !== null && $this->typeParser->isClassType($expectedType)
            ? new Instanceof_(new Variable($varName), new Name\FullyQualified(ltrim($expectedType, '\\')))
            : new FuncCall(new Name('is_object'), [new Arg(new Variable($varName))]);

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

    /** @return non-empty-string|null */
    private function extractClassFromMessage(string $message): ?string
    {
        // Pattern: "Cannot call method on TYPE" or similar
        if (preg_match('/on\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)/', $message, $matches) === 1 && $matches[1] !== '') {
            return $matches[1];
        }

        return null;
    }
}
