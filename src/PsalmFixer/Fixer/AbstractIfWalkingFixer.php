<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Stmt\If_;

/**
 * Base for fixers that locate an `if` statement at a target line, then rewrite
 * the AST in place. Centralises the recursive walk through namespaces/classes/
 * methods/control-flow blocks and the common compound-`&&` helpers, so each
 * concrete fixer only implements `tryFixIf()` with its issue-specific logic.
 */
abstract class AbstractIfWalkingFixer extends AbstractFixer {
    /**
     * Apply the fixer to the if statement at $index in $stmts. Return a
     * FixResult to signal success (fixed) or a known-but-skipped reason
     * (notFixed). Return null to mean "this if shouldn't be touched at all" —
     * the walk will not continue past the matched line, so null here is
     * conceptually identical to notFixed().
     *
     * @param list<Node\Stmt> $stmts
     */
    abstract protected function tryFixIf(array &$stmts, int $index, If_ $if): ?FixResult;

    /**
     * Recursive walk that finds the `if` at $line and delegates to tryFixIf().
     * Returns null when the target is not present in this subtree, so callers
     * can distinguish "keep looking" from "found but could not fix".
     *
     * @param list<Node\Stmt> $stmts
     */
    protected function walkAndFix(array &$stmts, int $line): ?FixResult {
        foreach ($stmts as $index => $stmt) {
            if ($stmt instanceof If_ && $stmt->getStartLine() === $line) {
                return $this->tryFixIf($stmts, $index, $stmt)
                    ?? FixResult::notFixed('Could not determine fix for if at target line');
            }

            // Defensive `!== null` / `is_array` checks match older php-parser
            // typings — current Psalm stubs treat them as redundant.
            if ($stmt instanceof Node\Stmt\Namespace_) {
                /** @psalm-suppress RedundantCondition */
                if ($stmt->stmts !== null) {
                    $result = $this->walkAndFix($stmt->stmts, $line);
                    if ($result !== null) {
                        return $result;
                    }
                }
            }
            if ($stmt instanceof Node\Stmt\ClassLike) {
                /** @psalm-suppress RedundantCondition */
                if (is_array($stmt->stmts)) {
                    /** @psalm-suppress ArgumentTypeCoercion */
                    $result = $this->walkAndFix($stmt->stmts, $line);
                    if ($result !== null) {
                        return $result;
                    }
                }
            }
            if ($stmt instanceof Node\Stmt\ClassMethod || $stmt instanceof Node\Stmt\Function_) {
                /** @psalm-suppress RedundantCondition */
                if ($stmt->stmts !== null) {
                    /** @psalm-suppress ArgumentTypeCoercion */
                    $result = $this->walkAndFix($stmt->stmts, $line);
                    if ($result !== null) {
                        return $result;
                    }
                }
            }
            if ($stmt instanceof If_ || $stmt instanceof Node\Stmt\While_ || $stmt instanceof Node\Stmt\For_ || $stmt instanceof Node\Stmt\Foreach_) {
                $result = $this->walkAndFix($stmt->stmts, $line);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * Splice the dead `if` branch in place — keep `else`, promote the first
     * `elseif` if no else exists, or remove the statement entirely if neither.
     *
     * @param list<Node\Stmt> $stmts
     */
    protected function spliceDeadBranch(array &$stmts, int $index, If_ $if): void {
        if ($if->else !== null) {
            array_splice($stmts, $index, 1, $if->else->stmts);
            return;
        }
        if (count($if->elseifs) > 0) {
            $elseif = $if->elseifs[0];
            $stmts[$index] = new If_($elseif->cond, [
                'stmts' => $elseif->stmts,
                'elseifs' => array_slice($if->elseifs, 1),
                'else' => $if->else,
            ]);
            return;
        }
        array_splice($stmts, $index, 1);
    }

    /**
     * Flatten a left-associative `&&` chain into its leaf operands.
     *
     * @return list<Node\Expr>
     */
    protected function flattenAnd(Node\Expr $cond): array {
        if ($cond instanceof BinaryOp\BooleanAnd) {
            return array_merge($this->flattenAnd($cond->left), $this->flattenAnd($cond->right));
        }

        return [$cond];
    }

    /**
     * Rebuild a left-associative `&&` chain from its operands.
     *
     * @param non-empty-list<Node\Expr> $operands
     */
    protected function buildAndChain(array $operands): Node\Expr {
        $result = $operands[0];
        $count = count($operands);
        for ($i = 1; $i < $count; $i++) {
            $result = new BinaryOp\BooleanAnd($result, $operands[$i]);
        }

        return $result;
    }
}
