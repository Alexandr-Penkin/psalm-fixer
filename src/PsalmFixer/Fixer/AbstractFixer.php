<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\PrettyPrinter\Standard;
use PsalmFixer\Ast\NodeFinder;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Base class with common helpers for fixers.
 */
abstract class AbstractFixer implements FixerInterface
{
    protected NodeFinder $nodeFinder;
    private ?Standard $printer = null;

    public function __construct()
    {
        $this->nodeFinder = new NodeFinder();
    }

    #[\Override]
    public function canFix(PsalmIssue $issue, array $stmts): bool
    {
        $node = $this->nodeFinder->findNodeAtLine($stmts, $issue->getLineFrom());

        return $node !== null;
    }

    /**
     * Find the statement containing the target line and insert a statement before it.
     *
     * @param list<Node> $stmts
     */
    protected function insertStatementBefore(array &$stmts, int $targetLine, Stmt $newStmt): bool
    {
        return $this->insertStatementRelative($stmts, $targetLine, $newStmt, true);
    }

    /**
     * Find the statement containing the target line and insert a statement after it.
     *
     * @param list<Node> $stmts
     */
    protected function insertStatementAfter(array &$stmts, int $targetLine, Stmt $newStmt): bool
    {
        return $this->insertStatementRelative($stmts, $targetLine, $newStmt, false);
    }

    /**
     * @param list<Node> $stmts
     */
    private function insertStatementRelative(array &$stmts, int $targetLine, Stmt $newStmt, bool $before): bool
    {
        foreach ($stmts as $index => $stmt) {
            if (!$stmt instanceof Stmt) {
                continue;
            }

            // Descend into block-shaped statements first — prefer inserting at
            // the deepest matching scope (inside the method, not before the
            // class).
            if ($this->descendIntoNested($stmt, $targetLine, $newStmt, $before)) {
                return true;
            }

            $startLine = $stmt->getStartLine();
            $endLine = $stmt->getEndLine();
            if ($startLine <= $targetLine && $endLine >= $targetLine) {
                $insertIndex = $before ? $index : $index + 1;
                array_splice($stmts, $insertIndex, 0, [$newStmt]);
                return true;
            }
        }

        return false;
    }

    /**
     * Try to recurse into every nested statement list $stmt exposes. Returns
     * true once any nested insertion succeeds; writes the modified list back to
     * the owning node. Centralises the block-shaped-statement bag (namespaces,
     * class bodies, function bodies, control-flow, try/catch/finally, switch
     * cases) so we don't repeat the type checks.
     */
    private function descendIntoNested(Stmt $stmt, int $targetLine, Stmt $newStmt, bool $before): bool
    {
        if (
            $stmt instanceof Stmt\Namespace_
            || $stmt instanceof Stmt\ClassLike
            || $stmt instanceof Stmt\If_
            || $stmt instanceof Stmt\While_
            || $stmt instanceof Stmt\Do_
            || $stmt instanceof Stmt\For_
            || $stmt instanceof Stmt\Foreach_
        ) {
            /** @var list<Node> $children */
            $children = $stmt->stmts;
            if ($this->insertStatementRelative($children, $targetLine, $newStmt, $before)) {
                /** @psalm-suppress PropertyTypeCoercion */
                $stmt->stmts = $children;
                return true;
            }
            return false;
        }
        if ($stmt instanceof Stmt\ClassMethod || $stmt instanceof Stmt\Function_) {
            if ($stmt->stmts === null) {
                return false;
            }
            /** @var list<Node> $children */
            $children = $stmt->stmts;
            if ($this->insertStatementRelative($children, $targetLine, $newStmt, $before)) {
                /** @psalm-suppress PropertyTypeCoercion */
                $stmt->stmts = $children;
                return true;
            }
            return false;
        }
        if ($stmt instanceof Stmt\TryCatch) {
            /** @var list<Node> $tryStmts */
            $tryStmts = $stmt->stmts;
            if ($this->insertStatementRelative($tryStmts, $targetLine, $newStmt, $before)) {
                /** @psalm-suppress PropertyTypeCoercion */
                $stmt->stmts = $tryStmts;
                return true;
            }
            foreach ($stmt->catches as $catch) {
                /** @var list<Node> $catchStmts */
                $catchStmts = $catch->stmts;
                if ($this->insertStatementRelative($catchStmts, $targetLine, $newStmt, $before)) {
                    /** @psalm-suppress PropertyTypeCoercion */
                    $catch->stmts = $catchStmts;
                    return true;
                }
            }
            $finally = $stmt->finally;
            if ($finally !== null) {
                /** @var list<Node> $finallyStmts */
                $finallyStmts = $finally->stmts;
                if ($this->insertStatementRelative($finallyStmts, $targetLine, $newStmt, $before)) {
                    /** @psalm-suppress PropertyTypeCoercion */
                    $finally->stmts = $finallyStmts;
                    return true;
                }
            }
            return false;
        }
        if ($stmt instanceof Stmt\Switch_) {
            foreach ($stmt->cases as $case) {
                /** @var list<Node> $caseStmts */
                $caseStmts = $case->stmts;
                if ($this->insertStatementRelative($caseStmts, $targetLine, $newStmt, $before)) {
                    /** @psalm-suppress PropertyTypeCoercion */
                    $case->stmts = $caseStmts;
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Replace a node within the AST at the given line.
     *
     * @param list<Node> $stmts
     * @param callable(Node): ?Node $replacer Returns new node or null to skip
     */
    protected function replaceNodeAtLine(array &$stmts, int $line, callable $replacer): bool
    {
        return $this->nodeFinder->replaceNodeAtLine($stmts, $line, $replacer);
    }

    /**
     * Idempotence guard for assert-style inserters. Returns true if a
     * statement structurally identical to $candidate is already present in the
     * deepest scope (sibling list) that contains $targetLine. We compare
     * pretty-printed text rather than walking AST equality — the printed form
     * is robust to whitespace differences and works for the guard patterns we
     * inject (assert(...) and throw-if-null).
     *
     * Operates on the scope rather than the immediate predecessor on purpose:
     * inserted nodes carry no line numbers, so a line-based "is previous
     * statement identical?" check misses them entirely. Walking the whole
     * scope handles repeated runs within the same AST as well as repeated
     * runs against the same on-disk file.
     *
     * @param list<Node> $stmts
     */
    protected function alreadyHasGuardBefore(array $stmts, int $targetLine, Stmt $candidate): bool
    {
        $this->printer ??= new Standard();
        $printer = $this->printer;
        $candidateText = trim($printer->prettyPrint([$candidate]));
        if ($candidateText === '') {
            return false;
        }

        $scope = $this->findDeepestScope($stmts, $targetLine);
        foreach ($scope as $stmt) {
            if (!$stmt instanceof Stmt) {
                continue;
            }
            if (trim($printer->prettyPrint([$stmt])) === $candidateText) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the deepest list of sibling statements containing $targetLine.
     * Recurses into block-shaped stmts (namespace / class / function / control
     * flow / try-catch / switch) whose bodies still cover the target. Returns
     * the outermost list when no deeper scope can be located — including the
     * case when the inserted-but-line-less node lives at the outer level.
     *
     * @param list<Node> $stmts
     * @return list<Node>
     */
    private function findDeepestScope(array $stmts, int $targetLine): array
    {
        foreach ($stmts as $stmt) {
            if (!$stmt instanceof Stmt) {
                continue;
            }
            $start = $stmt->getStartLine();
            $end = $stmt->getEndLine();
            if ($start > $targetLine || $end < $targetLine) {
                continue;
            }

            foreach ($this->collectChildStmtLists($stmt) as $children) {
                if ($this->listContains($children, $targetLine)) {
                    return $this->findDeepestScope($children, $targetLine);
                }
            }

            return $stmts;
        }

        return $stmts;
    }

    /**
     * @param list<Node> $children
     */
    private function listContains(array $children, int $targetLine): bool
    {
        foreach ($children as $child) {
            if (
                $child instanceof Stmt
                && $child->getStartLine() <= $targetLine
                && $child->getEndLine() >= $targetLine
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Mirror of descendIntoNested(), but read-only: returns the list of nested
     * statement lists $stmt exposes.
     *
     * @return list<list<Node>>
     */
    private function collectChildStmtLists(Stmt $stmt): array
    {
        $lists = [];
        if (
            $stmt instanceof Stmt\Namespace_
            || $stmt instanceof Stmt\ClassLike
            || $stmt instanceof Stmt\If_
            || $stmt instanceof Stmt\While_
            || $stmt instanceof Stmt\Do_
            || $stmt instanceof Stmt\For_
            || $stmt instanceof Stmt\Foreach_
        ) {
            /** @var list<Node> $children */
            $children = $stmt->stmts;
            $lists[] = $children;
        }
        if ($stmt instanceof Stmt\ClassMethod || $stmt instanceof Stmt\Function_) {
            if ($stmt->stmts !== null) {
                /** @var list<Node> $children */
                $children = $stmt->stmts;
                $lists[] = $children;
            }
        }
        if ($stmt instanceof Stmt\TryCatch) {
            /** @var list<Node> $tryStmts */
            $tryStmts = $stmt->stmts;
            $lists[] = $tryStmts;
            foreach ($stmt->catches as $catch) {
                /** @var list<Node> $catchStmts */
                $catchStmts = $catch->stmts;
                $lists[] = $catchStmts;
            }
            if ($stmt->finally !== null) {
                /** @var list<Node> $finallyStmts */
                $finallyStmts = $stmt->finally->stmts;
                $lists[] = $finallyStmts;
            }
        }
        if ($stmt instanceof Stmt\Switch_) {
            foreach ($stmt->cases as $case) {
                /** @var list<Node> $caseStmts */
                $caseStmts = $case->stmts;
                $lists[] = $caseStmts;
            }
        }

        return $lists;
    }
}
