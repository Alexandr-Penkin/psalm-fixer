<?php

declare(strict_types=1);

namespace PsalmFixer\Ast;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Finds AST nodes by position (line/column).
 */
final class NodeFinder {
    /**
     * Find the deepest node at the given line.
     *
     * @param list<Node> $stmts
     */
    public function findNodeAtLine(array $stmts, int $line): ?Node {
        $found = null;
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($line, $found) extends NodeVisitorAbstract {
            /** @var Node|null */
            private ?Node $result;

            public function __construct(
                private int $targetLine,
                private ?Node &$found,
            ) {
                $this->result = null;
            }

            #[\Override]
            public function enterNode(Node $node): ?int {
                if ($node->getStartLine() <= $this->targetLine && $node->getEndLine() >= $this->targetLine) {
                    $this->result = $node;
                    $this->found = $node;
                }

                return null;
            }
        });
        $traverser->traverse($stmts);

        return $found;
    }

    /**
     * Find all nodes at the given line.
     *
     * @param list<Node> $stmts
     * @return list<Node>
     */
    public function findAllNodesAtLine(array $stmts, int $line): array {
        /** @var list<Node> $nodes */
        $nodes = [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($line, $nodes) extends NodeVisitorAbstract {
            /**
             * @param list<Node> $collected
             */
            public function __construct(
                private int $targetLine,
                private array &$collected,
            ) {
            }

            #[\Override]
            public function enterNode(Node $node): ?int {
                if ($node->getStartLine() === $this->targetLine) {
                    $this->collected[] = $node;
                }

                return null;
            }
        });
        $traverser->traverse($stmts);

        return $nodes;
    }

    /**
     * Find a node of specific type at the given line.
     *
     * @template T of Node
     * @param list<Node> $stmts
     * @param class-string<T> $nodeClass
     * @return T|null
     */
    public function findNodeOfTypeAtLine(array $stmts, int $line, string $nodeClass): ?Node {
        $nodes = $this->findAllNodesAtLine($stmts, $line);
        foreach ($nodes as $node) {
            if ($node instanceof $nodeClass) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Replace a node at the given line using a callback.
     *
     * @param list<Node> $stmts
     * @param callable(Node): ?Node $replacer
     */
    public function replaceNodeAtLine(array &$stmts, int $line, callable $replacer): bool {
        $replaced = false;
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($line, $replacer, $replaced) extends NodeVisitorAbstract {
            /**
             * @param callable(Node): ?Node $replacer
             */
            public function __construct(
                private int $targetLine,
                private mixed $replacer,
                private bool &$replaced,
            ) {
            }

            #[\Override]
            public function leaveNode(Node $node): ?Node {
                if ($this->replaced) {
                    return null;
                }

                if ($node->getStartLine() === $this->targetLine) {
                    $result = ($this->replacer)($node);
                    if ($result !== null) {
                        $this->replaced = true;
                        return $result;
                    }
                }

                return null;
            }
        });
        $stmts = $traverser->traverse($stmts);

        return $replaced;
    }

    /**
     * Find the containing method/function for a given line.
     *
     * @param list<Node> $stmts
     * @return Stmt\ClassMethod|Stmt\Function_|null
     */
    public function findContainingFunction(array $stmts, int $line): Stmt\ClassMethod|Stmt\Function_|null {
        $found = null;
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($line, $found) extends NodeVisitorAbstract {
            /** @var Stmt\ClassMethod|Stmt\Function_|null */
            private Stmt\ClassMethod|Stmt\Function_|null $result;

            /**
             * @param Stmt\ClassMethod|Stmt\Function_|null $found
             */
            public function __construct(
                private int $targetLine,
                private Stmt\ClassMethod|Stmt\Function_|null &$found,
            ) {
                $this->result = null;
            }

            #[\Override]
            public function enterNode(Node $node): ?int {
                if (($node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_)
                    && $node->getStartLine() <= $this->targetLine
                    && $node->getEndLine() >= $this->targetLine
                ) {
                    $this->result = $node;
                    $this->found = $node;
                }

                return null;
            }
        });
        $traverser->traverse($stmts);

        return $found;
    }

    /**
     * Find the containing class for a given line.
     *
     * @param list<Node> $stmts
     */
    public function findContainingClass(array $stmts, int $line): ?Stmt\Class_ {
        $found = null;
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($line, $found) extends NodeVisitorAbstract {
            public function __construct(
                private int $targetLine,
                private ?Stmt\Class_ &$found,
            ) {
            }

            #[\Override]
            public function enterNode(Node $node): ?int {
                if ($node instanceof Stmt\Class_
                    && $node->getStartLine() <= $this->targetLine
                    && $node->getEndLine() >= $this->targetLine
                ) {
                    $this->found = $node;
                }

                return null;
            }
        });
        $traverser->traverse($stmts);

        return $found;
    }
}
