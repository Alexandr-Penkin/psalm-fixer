<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\TypeSafety;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Stmt\If_;
use PsalmFixer\Fixer\AbstractIfWalkingFixer;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Simplifies null checks where Psalm has determined the value can never be null.
 *
 * `if ($x === null)` → dead branch, keep else.
 * `if ($x !== null)` → always-true, unwrap and keep body.
 * For compound `A && B`: strips the redundant null comparison operand, or treats
 * the whole if as dead when an operand short-circuits the result to false.
 *
 * Works with baseline input as well as JSON: direction is inferred from the
 * comparison operator in the AST, so no message text is required.
 */
final class TypeDoesNotContainNullFixer extends AbstractIfWalkingFixer {
    private const DIR_TRUE = 'true';
    private const DIR_FALSE = 'false';

    #[\Override]
    public function getSupportedTypes(): array {
        return ['TypeDoesNotContainNull', 'DocblockTypeContradiction'];
    }

    #[\Override]
    public function getName(): string {
        return 'TypeDoesNotContainNullFixer';
    }

    #[\Override]
    public function getDescription(): string {
        return 'Removes dead null-check branches where type cannot be null';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult {
        /** @psalm-suppress ArgumentTypeCoercion */
        $result = $this->walkAndFix($stmts, $issue->getLineFrom());

        return $result ?? FixResult::notFixed('Could not find if statement at target line');
    }

    #[\Override]
    protected function tryFixIf(array &$stmts, int $index, If_ $if): ?FixResult {
        $direction = $this->inferDirection($if->cond);
        if ($direction === self::DIR_TRUE) {
            array_splice($stmts, $index, 1, $if->stmts);

            return FixResult::fixed('Unwrapped always-true null check, kept body');
        }
        if ($direction === self::DIR_FALSE) {
            $this->spliceDeadBranch($stmts, $index, $if);

            return FixResult::fixed('Removed dead null-check branch');
        }

        if ($if->cond instanceof BinaryOp\BooleanAnd) {
            return $this->handleCompoundAnd($stmts, $index, $if);
        }

        return FixResult::notFixed('Could not determine null-check direction');
    }

    /**
     * Direction inference from a leaf condition alone — no message needed.
     *
     * @return self::DIR_*|null
     */
    private function inferDirection(Node\Expr $cond): ?string {
        if (($cond instanceof BinaryOp\NotIdentical || $cond instanceof BinaryOp\NotEqual)
            && $this->hasNullSide($cond)
        ) {
            return self::DIR_TRUE;
        }

        if (($cond instanceof BinaryOp\Identical || $cond instanceof BinaryOp\Equal)
            && $this->hasNullSide($cond)
        ) {
            return self::DIR_FALSE;
        }

        return null;
    }

    /**
     * @param list<Node\Stmt> $stmts
     */
    private function handleCompoundAnd(array &$stmts, int $index, If_ $if): ?FixResult {
        $cond = $if->cond;
        if (!$cond instanceof BinaryOp\BooleanAnd) {
            return null;
        }

        $operands = $this->flattenAnd($cond);

        $kept = [];
        $stripped = 0;
        foreach ($operands as $operand) {
            $opDirection = $this->inferDirection($operand);
            if ($opDirection === self::DIR_TRUE) {
                $stripped++;
                continue;
            }
            if ($opDirection === self::DIR_FALSE) {
                // One operand always-false in an && chain → whole condition is
                // always false → dead branch.
                $this->spliceDeadBranch($stmts, $index, $if);

                return FixResult::fixed('Removed dead null-check branch (always-false operand in && chain)');
            }
            $kept[] = $operand;
        }

        if ($stripped === 0 || count($kept) === 0) {
            return null;
        }

        $if->cond = $this->buildAndChain($kept);

        return FixResult::fixed('Stripped redundant null-check operand from && chain');
    }

    private function hasNullSide(BinaryOp $cond): bool {
        foreach ([$cond->left, $cond->right] as $side) {
            if ($side instanceof ConstFetch && strtolower($side->name->toString()) === 'null') {
                return true;
            }
        }

        return false;
    }
}
