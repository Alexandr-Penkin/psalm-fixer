<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Shared `@psalm-suppress` annotation helpers. Used by fixers that fall back to
 * suppressing a Psalm issue on the offending statement when no safe runtime
 * rewrite exists (e.g. generic / template types, genuinely-mixed values).
 *
 * The trait centralises three concerns:
 *  - locating the innermost statement whose line range covers the issue line
 *    (handles multiline expressions like `return new Foo(\n  arg: $x);` where
 *    the offending sub-expression sits on an inner line);
 *  - building a `Doc` block that adds a `@psalm-suppress <Type>` tag while
 *    preserving any existing tags;
 *  - idempotent attachment — re-running the fixer on already-suppressed code
 *    leaves the docblock untouched.
 */
trait AppendsPsalmSuppress {
    /**
     * Attach `@psalm-suppress <$issueType>` to the statement covering $line.
     * Returns a FixResult ready to surface to the caller: fixed on success,
     * notFixed with a precise reason otherwise (no statement at line, or the
     * suppress was already present).
     *
     * @param list<Node> $stmts
     * @param non-empty-string $issueType
     */
    private function attachPsalmSuppress(array $stmts, int $line, string $issueType): FixResult {
        return $this->attachDocTag($stmts, $line, '@psalm-suppress ' . $issueType);
    }

    /**
     * Attach an arbitrary `@psalm-*`-style tag to the statement covering $line.
     * Generic entry point shared by suppress-style fixers and purity-annotation
     * fixers (`@psalm-pure`, `@psalm-mutation-free`, `@psalm-immutable`, etc.).
     *
     * @param list<Node> $stmts
     * @param non-empty-string $tag Full docblock tag to insert (including the leading `@`).
     */
    private function attachDocTag(array $stmts, int $line, string $tag): FixResult {
        $node = $this->findStatementCoveringLine($stmts, $line);
        if ($node === null) {
            return FixResult::notFixed('Could not find statement at target line');
        }

        $existing = $node->getDocComment();
        if ($existing !== null && $this->docContainsTag($existing->getText(), $tag)) {
            return FixResult::notFixed("Statement already carries {$tag}");
        }

        $node->setDocComment($this->buildSuppressDoc($existing, $tag));

        return FixResult::fixed("Added {$tag}");
    }

    /**
     * Strict token-boundary check: $tag must be followed by whitespace, the
     * end-of-comment marker, or end-of-string. Plain substring matching is
     * too loose — `@psalm-pure` would falsely match inside `@psalm-pure-...`
     * and `@psalm-suppress RedundantCondition` would falsely match inside
     * `@psalm-suppress RedundantConditionGivenDocblockType`.
     */
    private function docContainsTag(string $text, string $tag): bool {
        $pattern = '/' . preg_quote($tag, '/') . '(?=\s|\*\/|$)/';

        return preg_match($pattern, $text) === 1;
    }

    /**
     * Locate the innermost `Stmt` whose line range covers $line.
     * Prefer the deepest match — outer Stmts (ClassLike, ClassMethod) span many
     * lines but we want the statement that actually contains the offending
     * expression.
     *
     * @param list<Node> $stmts
     */
    private function findStatementCoveringLine(array $stmts, int $line): ?Node\Stmt {
        $found = null;
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($line, $found) extends NodeVisitorAbstract {
            public function __construct(
                private int $targetLine,
                private ?Node\Stmt &$found,
            ) {
            }

            #[\Override]
            public function enterNode(Node $node): ?int {
                if (!$node instanceof Node\Stmt) {
                    return null;
                }
                $start = $node->getStartLine();
                $end = $node->getEndLine();
                if ($start > $this->targetLine || $end < $this->targetLine) {
                    return null;
                }
                if ($this->found === null) {
                    $this->found = $node;

                    return null;
                }
                $currentSpan = $end - $start;
                $bestSpan = $this->found->getEndLine() - $this->found->getStartLine();
                if ($currentSpan <= $bestSpan) {
                    $this->found = $node;
                }

                return null;
            }
        });
        $traverser->traverse($stmts);

        return $found;
    }

    /**
     * Build a Doc block that includes $tag, preserving existing content of $existing.
     *
     * @param non-empty-string $tag
     */
    private function buildSuppressDoc(?Doc $existing, string $tag): Doc {
        if ($existing === null) {
            return new Doc('/** ' . $tag . ' */');
        }

        $text = $existing->getText();
        if (preg_match('#^/\*\*\s*(.*?)\s*\*/$#s', $text, $matches) !== 1) {
            return new Doc('/** ' . $tag . ' */');
        }

        $body = trim($matches[1]);
        if ($body === '') {
            return new Doc('/** ' . $tag . ' */');
        }

        $lines = preg_split('/\R/', $body);
        if ($lines === false) {
            $lines = [$body];
        }
        $normalised = [];
        foreach ($lines as $line) {
            $normalised[] = ltrim($line, " \t*");
        }
        $normalised[] = $tag;

        return new Doc("/**\n * " . implode("\n * ", $normalised) . "\n */");
    }
}
