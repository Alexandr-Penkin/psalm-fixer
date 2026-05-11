<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\TypeSafety;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\If_;
use PsalmFixer\Fixer\AbstractIfWalkingFixer;
use PsalmFixer\Fixer\AppendsPsalmSuppress;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Removes redundant conditions: always-true → unwrap body, always-false → keep else.
 * 
 * Direction is taken from the Psalm message when available; otherwise from the
 * if-condition AST (literal true / false). The AST fallback lets the fixer work
 * with baseline input that carries no message text.
 * 
 * For compound `&&` chains, also strips an individual redundant operand without
 * unwrapping the whole if.
 * @psalm-suppress MixedReturnTypeCoercion
 */
final class RedundantConditionFixer extends AbstractIfWalkingFixer {
    use AppendsPsalmSuppress;

    private const DIR_TRUE = 'true';
    private const DIR_FALSE = 'false';

    private string $currentMessage = '';

    #[\Override]
    public function getSupportedTypes(): array {
        return ['RedundantCondition', 'RedundantConditionGivenDocblockType'];
    }

    #[\Override]
    public function getName(): string {
        return 'RedundantConditionFixer';
    }

    #[\Override]
    public function getDescription(): string {
        return 'Removes redundant always-true/false conditions';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult {
        $this->currentMessage = $issue->getMessage();
        /** @psalm-suppress ArgumentTypeCoercion */
        $result = $this->walkAndFix($stmts, $issue->getLineFrom());
        if ($result !== null) {
            return $result;
        }

        // No `if` at the target line. Fall back to a suppress annotation only
        // when the statement on that line is an `assert(...)` call (the typical
        // non-`if` shape Psalm flags as RedundantCondition). For any other
        // statement we refuse — a generic suppress would non-deterministically
        // attach to whatever code is at the line, including code an earlier
        // pass already cleaned up, which would break idempotence.
        if ($this->lineCarriesAssertCall($stmts, $issue->getLineFrom())) {
            return $this->attachPsalmSuppress($stmts, $issue->getLineFrom(), $issue->getType());
        }

        return FixResult::notFixed('No if or assert statement at target line');
    }

    /**
     * Return true if the statement at $line is an expression-statement that
     * directly calls `assert(...)`. Used to scope the suppress fallback.
     *
     * @param iterable<Node> $stmts
     */
    private function lineCarriesAssertCall(iterable $stmts, int $line): bool {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Expression
                && $stmt->getStartLine() === $line
                && $stmt->expr instanceof FuncCall
                && $stmt->expr->name instanceof Node\Name
                && strtolower($stmt->expr->name->toString()) === 'assert'
            ) {
                return true;
            }

            $nested = $this->nestedStmtsOf($stmt);
            if ($nested !== null && $this->lineCarriesAssertCall($nested, $line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the nested statement list of a block-shaped node, or null when
     * the node has no recursable body. Trusts Psalm's php-parser stubs (no
     * defensive `!== null` / `is_array` checks; those would be flagged as
     * redundant). Return type matches the stubs (`array<Node\Stmt>`) rather
     * than `list<Node\Stmt>` because ClassLike/ClassMethod $stmts are typed
     * as arrays by the stubs.
     *
     * @return array<int, Node\Stmt>|null
     */
    private function nestedStmtsOf(Node $stmt): ?array {
        if ($stmt instanceof Node\Stmt\Namespace_) {
            return $stmt->stmts;
        }
        if ($stmt instanceof Node\Stmt\ClassLike) {
            /** @psalm-suppress MixedReturnTypeCoercion */
            return $stmt->stmts;
        }
        if ($stmt instanceof Node\Stmt\ClassMethod || $stmt instanceof Node\Stmt\Function_) {
            /** @psalm-suppress MixedReturnTypeCoercion */
            return $stmt->stmts;
        }
        if ($stmt instanceof If_ || $stmt instanceof Node\Stmt\While_ || $stmt instanceof Node\Stmt\For_ || $stmt instanceof Node\Stmt\Foreach_) {
            return $stmt->stmts;
        }

        return null;
    }

    #[\Override]
    protected function tryFixIf(array &$stmts, int $index, If_ $if): ?FixResult {
        $direction = $this->inferDirection($this->currentMessage, $if->cond);
        if ($direction === self::DIR_TRUE) {
            array_splice($stmts, $index, 1, $if->stmts);

            return FixResult::fixed('Removed redundant always-true condition, kept body');
        }
        if ($direction === self::DIR_FALSE) {
            $this->spliceDeadBranch($stmts, $index, $if);

            return FixResult::fixed('Removed redundant always-false condition');
        }

        // Compound `A && B && ...` — try stripping the tautological operand.
        if ($if->cond instanceof BinaryOp\BooleanAnd) {
            $newCond = $this->stripRedundantAndOperand($this->currentMessage, $if->cond);
            if ($newCond !== null) {
                $if->cond = $newCond;

                return FixResult::fixed('Removed redundant operand from && chain');
            }
        }

        return FixResult::notFixed('Cannot determine if condition is always true or false');
    }

    /**
     * @return self::DIR_*|null
     */
    private function inferDirection(string $message, Node\Expr $cond): ?string {
        if (str_contains($message, 'is always true') || str_contains($message, 'always truthy')) {
            return self::DIR_TRUE;
        }
        if (str_contains($message, 'is always false') || str_contains($message, 'always falsy')) {
            return self::DIR_FALSE;
        }

        // Psalm phrases tautological / contradictory comparisons as "is never X" /
        // "can never contain X" / "can never be X". For a comparison expression
        // the direction follows from the operator:
        //   $x !== Y / $x != Y  →  always true
        //   $x === Y / $x == Y  →  always false
        $isNeverPhrasing = str_contains($message, 'is never ')
            || str_contains($message, 'can never contain ')
            || str_contains($message, 'can never be ');
        if ($isNeverPhrasing) {
            if ($cond instanceof BinaryOp\NotIdentical || $cond instanceof BinaryOp\NotEqual) {
                return self::DIR_TRUE;
            }
            if ($cond instanceof BinaryOp\Identical || $cond instanceof BinaryOp\Equal) {
                return self::DIR_FALSE;
            }
        }

        // AST-only fallback for literal conditions — works without any message
        // text, so this branch is what makes the fixer baseline-compatible.
        if ($cond instanceof ConstFetch) {
            $name = strtolower($cond->name->toString());
            if ($name === 'true') {
                return self::DIR_TRUE;
            }
            if ($name === 'false') {
                return self::DIR_FALSE;
            }
        }

        // `if ($var instanceof Foo)` where Psalm has already determined the
        // variable's type. Psalm flags this as `RedundantConditionGivenDocblockType`
        // with a message like "Docblock-defined type Foo for $var is always Foo".
        // The instanceof is tautologically true → unwrap.
        if ($cond instanceof Instanceof_ && str_contains($message, 'is always')) {
            return self::DIR_TRUE;
        }

        return null;
    }

    private function stripRedundantAndOperand(string $message, BinaryOp\BooleanAnd $cond): ?Node\Expr {
        $operands = $this->flattenAnd($cond);

        $kept = [];
        $removed = 0;
        foreach ($operands as $operand) {
            if ($this->isAlwaysTrueOperand($message, $operand)) {
                $removed++;
                continue;
            }
            $kept[] = $operand;
        }

        if ($removed === 0 || count($kept) === 0) {
            return null;
        }

        return $this->buildAndChain($kept);
    }

    /**
     * Detects whether the operand is a tautological comparison made redundant by
     * the message — `X !== null` / `X === null` when type is never-null,
     * `X !== ''` / `X === ''` when type can never contain empty string.
     *
     * Only safe leaf comparisons; calls and other side-effectful expressions
     * are left alone (== always considered "may have side effects").
     */
    private function isAlwaysTrueOperand(string $message, Node\Expr $operand): bool {
        $nullMessages = str_contains($message, 'is never null')
            || str_contains($message, 'can never contain null')
            || str_contains($message, 'can never be null');
        if ($nullMessages && $this->isComparisonWithNull($operand)) {
            return $operand instanceof BinaryOp\NotIdentical || $operand instanceof BinaryOp\NotEqual;
        }

        $emptyStringMessages = str_contains($message, "'' can never contain ")
            || str_contains($message, "can never be ''")
            || str_contains($message, "can never contain ''");
        if ($emptyStringMessages && $this->isComparisonWithEmptyString($operand)) {
            return $operand instanceof BinaryOp\NotIdentical || $operand instanceof BinaryOp\NotEqual;
        }

        return false;
    }

    private function isComparisonWithNull(Node\Expr $cond): bool {
        if (!($cond instanceof BinaryOp\Identical
            || $cond instanceof BinaryOp\NotIdentical
            || $cond instanceof BinaryOp\Equal
            || $cond instanceof BinaryOp\NotEqual)) {
            return false;
        }
        foreach ([$cond->left, $cond->right] as $side) {
            if ($side instanceof ConstFetch && strtolower($side->name->toString()) === 'null') {
                return true;
            }
        }

        return false;
    }

    private function isComparisonWithEmptyString(Node\Expr $cond): bool {
        if (!($cond instanceof BinaryOp\Identical
            || $cond instanceof BinaryOp\NotIdentical
            || $cond instanceof BinaryOp\Equal
            || $cond instanceof BinaryOp\NotEqual)) {
            return false;
        }
        foreach ([$cond->left, $cond->right] as $side) {
            if ($side instanceof Node\Scalar\String_ && $side->value === '') {
                return true;
            }
        }

        return false;
    }
}
