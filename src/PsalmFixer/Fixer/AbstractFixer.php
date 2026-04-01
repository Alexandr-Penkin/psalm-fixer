<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PsalmFixer\Ast\NodeFinder;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Base class with common helpers for fixers.
 */
abstract class AbstractFixer implements FixerInterface {
    protected NodeFinder $nodeFinder;

    public function __construct() {
        $this->nodeFinder = new NodeFinder();
    }

    #[\Override]
    public function canFix(PsalmIssue $issue, array $stmts): bool {
        $node = $this->nodeFinder->findNodeAtLine($stmts, $issue->getLineFrom());

        return $node !== null;
    }

    /**
     * Find the statement containing the target line and insert a statement before it.
     *
     * @param list<Node> $stmts
     */
    protected function insertStatementBefore(array &$stmts, int $targetLine, Stmt $newStmt): bool {
        return $this->insertStatementRelative($stmts, $targetLine, $newStmt, true);
    }

    /**
     * Find the statement containing the target line and insert a statement after it.
     *
     * @param list<Node> $stmts
     */
    protected function insertStatementAfter(array &$stmts, int $targetLine, Stmt $newStmt): bool {
        return $this->insertStatementRelative($stmts, $targetLine, $newStmt, false);
    }

    /**
     * @param list<Node> $stmts
     */
    private function insertStatementRelative(array &$stmts, int $targetLine, Stmt $newStmt, bool $before): bool {
        foreach ($stmts as $index => $stmt) {
            if (!($stmt instanceof Stmt)) {
                continue;
            }

            // Check nested stmts (namespaces, class methods, function bodies, etc.)
            if ($stmt instanceof Stmt\Namespace_ && $stmt->stmts !== null) {
                /** @var list<Node> $nsStmts */
                $nsStmts = $stmt->stmts;
                if ($this->insertStatementRelative($nsStmts, $targetLine, $newStmt, $before)) {
                    $stmt->stmts = $nsStmts;
                    return true;
                }
            }

            if ($stmt instanceof Stmt\ClassLike) {
                /** @var list<Node> $classStmts */
                $classStmts = $stmt->stmts;
                if ($this->insertStatementRelative($classStmts, $targetLine, $newStmt, $before)) {
                    $stmt->stmts = $classStmts;
                    return true;
                }
            }

            if ($stmt instanceof Stmt\ClassMethod || $stmt instanceof Stmt\Function_) {
                if ($stmt->stmts !== null) {
                    /** @var list<Node> $bodyStmts */
                    $bodyStmts = $stmt->stmts;
                    if ($this->insertStatementRelative($bodyStmts, $targetLine, $newStmt, $before)) {
                        $stmt->stmts = $bodyStmts;
                        return true;
                    }
                }
            }

            if ($stmt instanceof Stmt\If_ || $stmt instanceof Stmt\While_ || $stmt instanceof Stmt\For_ || $stmt instanceof Stmt\Foreach_) {
                /** @var list<Node> $innerStmts */
                $innerStmts = $stmt->stmts;
                if ($this->insertStatementRelative($innerStmts, $targetLine, $newStmt, $before)) {
                    $stmt->stmts = $innerStmts;
                    return true;
                }
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
     * Replace a node within the AST at the given line.
     *
     * @param list<Node> $stmts
     * @param callable(Node): ?Node $replacer Returns new node or null to skip
     */
    protected function replaceNodeAtLine(array &$stmts, int $line, callable $replacer): bool {
        return $this->nodeFinder->replaceNodeAtLine($stmts, $line, $replacer);
    }
}
