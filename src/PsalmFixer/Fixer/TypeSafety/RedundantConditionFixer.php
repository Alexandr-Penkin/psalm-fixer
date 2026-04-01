<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\TypeSafety;

use PhpParser\Node;
use PhpParser\Node\Stmt\If_;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Removes redundant conditions (always true → keep body, always false → remove).
 */
final class RedundantConditionFixer extends AbstractFixer {
    public function getSupportedTypes(): array {
        return ['RedundantCondition'];
    }

    public function getName(): string {
        return 'RedundantConditionFixer';
    }

    public function getDescription(): string {
        return 'Removes redundant always-true conditions, keeping the body';
    }

    public function fix(PsalmIssue $issue, array &$stmts): FixResult {
        $message = $issue->getMessage();

        // "Type X for $var is always true" — unwrap the if body
        if (str_contains($message, 'is always true') || str_contains($message, 'always truthy')) {
            return $this->unwrapIfBody($stmts, $issue->getLineFrom());
        }

        return FixResult::notFixed('Cannot determine if condition is always true or false');
    }

    /**
     * Replace if ($alwaysTrue) { body } with just body.
     *
     * @param list<Node> $stmts
     */
    private function unwrapIfBody(array &$stmts, int $line): FixResult {
        foreach ($stmts as $index => $stmt) {
            if ($stmt instanceof If_ && $stmt->getStartLine() === $line) {
                // Replace the if with its body statements
                $body = $stmt->stmts;
                array_splice($stmts, $index, 1, $body);

                return FixResult::fixed('Removed redundant always-true condition, kept body');
            }

            // Recurse into nested blocks
            if ($stmt instanceof Node\Stmt\ClassLike && is_array($stmt->stmts)) {
                $result = $this->unwrapIfBody($stmt->stmts, $line);
                if ($result->isFixed()) {
                    return $result;
                }
            }
            if (($stmt instanceof Node\Stmt\ClassMethod || $stmt instanceof Node\Stmt\Function_) && $stmt->stmts !== null) {
                $result = $this->unwrapIfBody($stmt->stmts, $line);
                if ($result->isFixed()) {
                    return $result;
                }
            }
            if ($stmt instanceof If_ || $stmt instanceof Node\Stmt\While_ || $stmt instanceof Node\Stmt\For_ || $stmt instanceof Node\Stmt\Foreach_) {
                $result = $this->unwrapIfBody($stmt->stmts, $line);
                if ($result->isFixed()) {
                    return $result;
                }
            }
        }

        return FixResult::notFixed('Could not find if statement at target line');
    }
}
