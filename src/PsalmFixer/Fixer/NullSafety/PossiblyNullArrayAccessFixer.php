<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\NullSafety;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\If_;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Adds null guard before array access on possibly null variables.
 */
final class PossiblyNullArrayAccessFixer extends AbstractFixer
{
    #[\Override]
    public function getSupportedTypes(): array
    {
        return ['PossiblyNullArrayAccess'];
    }

    #[\Override]
    public function getName(): string
    {
        return 'PossiblyNullArrayAccessFixer';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Adds null guard before array access on possibly null values';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult
    {
        // Find the variable used in array access at the issue line
        $varName = $this->findArrayAccessVar($stmts, $issue->getLineFrom());
        if ($varName === null) {
            return FixResult::notFixed('Could not find array access variable');
        }

        $guard = $this->createNullGuard($varName);

        if ($this->alreadyHasGuardBefore($stmts, $issue->getLineFrom(), $guard)) {
            return FixResult::notFixed("null guard for \${$varName} already present");
        }

        $inserted = $this->insertStatementBefore($stmts, $issue->getLineFrom(), $guard);

        if ($inserted) {
            return FixResult::fixed("Added null guard for \${$varName}");
        }

        return FixResult::notFixed('Could not insert null guard');
    }

    /** @param list<Node> $stmts */
    private function findArrayAccessVar(array $stmts, int $line): ?string
    {
        $node = $this->nodeFinder->findNodeOfTypeAtLine($stmts, $line, ArrayDimFetch::class);
        if ($node instanceof ArrayDimFetch && $node->var instanceof Variable && is_string($node->var->name)) {
            return $node->var->name;
        }

        return null;
    }

    private function createNullGuard(string $varName): If_
    {
        $condition = new Identical(new Variable($varName), new ConstFetch(new Name('null')));

        $throw = new Throw_(new Node\Expr\New_(new Name\FullyQualified('RuntimeException'), [
            new Arg(new Node\Scalar\String_("Cannot access array on null \${$varName}")),
        ]));

        return new If_($condition, [
            'stmts' => [new Expression($throw)],
        ]);
    }
}
