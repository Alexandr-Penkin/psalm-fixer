<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\TypeSafety;

use PhpParser\Node;
use PhpParser\Node\Stmt\If_;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Simplifies null checks where the type can never be null.
 * Removes dead `if ($x === null)` branches.
 */
final class TypeDoesNotContainNullFixer extends AbstractFixer {
    public function getSupportedTypes(): array {
        return ['TypeDoesNotContainNull', 'DocblockTypeContradiction'];
    }

    public function getName(): string {
        return 'TypeDoesNotContainNullFixer';
    }

    public function getDescription(): string {
        return 'Removes dead null-check branches where type cannot be null';
    }

    public function fix(PsalmIssue $issue, array &$stmts): FixResult {
        // The condition is always false (type never null), so we keep the else branch
        return $this->removeDeadBranch($stmts, $issue->getLineFrom());
    }

    /**
     * @param list<Node> $stmts
     */
    private function removeDeadBranch(array &$stmts, int $line): FixResult {
        foreach ($stmts as $index => $stmt) {
            if ($stmt instanceof If_ && $stmt->getStartLine() === $line) {
                if (count($stmt->elseifs) > 0 || $stmt->else !== null) {
                    // Replace with else body
                    if ($stmt->else !== null) {
                        array_splice($stmts, $index, 1, $stmt->else->stmts);
                    } else {
                        // First elseif becomes the new if
                        $elseif = $stmt->elseifs[0];
                        $newIf = new If_($elseif->cond, [
                            'stmts' => $elseif->stmts,
                            'elseifs' => array_slice($stmt->elseifs, 1),
                            'else' => $stmt->else,
                        ]);
                        $stmts[$index] = $newIf;
                    }
                } else {
                    // No else — just remove the if
                    array_splice($stmts, $index, 1);
                }

                return FixResult::fixed('Removed dead null-check branch');
            }

            // Recurse
            if ($stmt instanceof Node\Stmt\Namespace_ && $stmt->stmts !== null) {
                $result = $this->removeDeadBranch($stmt->stmts, $line);
                if ($result->isFixed()) {
                    return $result;
                }
            }
            if ($stmt instanceof Node\Stmt\ClassLike && is_array($stmt->stmts)) {
                $result = $this->removeDeadBranch($stmt->stmts, $line);
                if ($result->isFixed()) {
                    return $result;
                }
            }
            if (($stmt instanceof Node\Stmt\ClassMethod || $stmt instanceof Node\Stmt\Function_) && $stmt->stmts !== null) {
                $result = $this->removeDeadBranch($stmt->stmts, $line);
                if ($result->isFixed()) {
                    return $result;
                }
            }
            if ($stmt instanceof If_ || $stmt instanceof Node\Stmt\While_ || $stmt instanceof Node\Stmt\For_ || $stmt instanceof Node\Stmt\Foreach_) {
                $result = $this->removeDeadBranch($stmt->stmts, $line);
                if ($result->isFixed()) {
                    return $result;
                }
            }
        }

        return FixResult::notFixed('Could not find if statement at target line');
    }
}
