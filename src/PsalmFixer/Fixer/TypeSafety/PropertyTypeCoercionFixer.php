<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\TypeSafety;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Suppresses `PropertyTypeCoercion` warnings by attaching a
 * `@psalm-suppress PropertyTypeCoercion` line to the offending statement.
 *
 * Used for situations where the developer knows the assignment is safe but Psalm
 * cannot prove it (typically wider source type narrowing to a stricter property
 * type when the upstream call genuinely produces compatible values). The fixer
 * is conservative: it does not rewrite types or insert runtime assertions,
 * so the original behavior is preserved exactly.
 */
final class PropertyTypeCoercionFixer extends AbstractFixer {
    private const SUPPRESS_TAG = '@psalm-suppress PropertyTypeCoercion';

    #[\Override]
    public function getSupportedTypes(): array {
        return ['PropertyTypeCoercion'];
    }

    #[\Override]
    public function getName(): string {
        return 'PropertyTypeCoercionFixer';
    }

    #[\Override]
    public function getDescription(): string {
        return 'Adds @psalm-suppress PropertyTypeCoercion above the offending assignment';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult {
        $node = $this->findStatementAtLine($stmts, $issue->getLineFrom());
        if ($node === null) {
            return FixResult::notFixed('Could not find statement at target line');
        }

        $existingDoc = $node->getDocComment();
        if ($existingDoc !== null && str_contains($existingDoc->getText(), self::SUPPRESS_TAG)) {
            return FixResult::notFixed('Statement already has the suppress annotation');
        }

        $node->setDocComment($this->makeDocComment($existingDoc));

        return FixResult::fixed('Added @psalm-suppress PropertyTypeCoercion');
    }

    /**
     * Find the outermost statement-level node whose start line matches the
     * Psalm-reported line. PropertyTypeCoercion is always reported against an
     * assignment, which lives inside an Expression statement — that statement
     * is what carries any docblock.
     *
     * @param list<Node> $stmts
     */
    private function findStatementAtLine(array $stmts, int $line): ?Node\Stmt {
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
                if ($node->getStartLine() !== $this->targetLine) {
                    return null;
                }
                // Prefer the deepest (innermost) Stmt at the target line — the
                // outer Foreach_/If_ usually share their start line with their
                // first child statement in tight code, and we want the actual
                // assignment statement, not the block.
                if ($this->found === null || $node->getEndLine() <= $this->found->getEndLine()) {
                    $this->found = $node;
                }

                return null;
            }
        });
        $traverser->traverse($stmts);

        return $found;
    }

    private function makeDocComment(?Doc $existing): Doc {
        if ($existing === null) {
            return new Doc("/** " . self::SUPPRESS_TAG . " */");
        }

        $text = $existing->getText();
        if (preg_match('#^/\*\*\s*(.*?)\s*\*/$#s', $text, $matches) === 1) {
            $body = trim($matches[1]);
            if ($body === '') {
                $newText = "/** " . self::SUPPRESS_TAG . " */";
            } else {
                // Preserve existing tags; insert the new one on its own line.
                $lines = preg_split('/\R/', $body);
                if ($lines === false) {
                    $lines = [$body];
                }
                $normalised = [];
                foreach ($lines as $line) {
                    $normalised[] = ltrim($line, " \t*");
                }
                $normalised[] = self::SUPPRESS_TAG;
                $newText = "/**\n * " . implode("\n * ", $normalised) . "\n */";
            }
        } else {
            $newText = "/** " . self::SUPPRESS_TAG . " */";
        }

        return new Doc($newText);
    }
}
