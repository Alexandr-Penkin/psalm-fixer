<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\Docblock;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Removes unused @psalm-suppress annotations.
 * @psalm-suppress MixedReturnTypeCoercion
 */
final class UnusedPsalmSuppressFixer extends AbstractFixer {
    #[\Override]
    public function getSupportedTypes(): array {
        return ['UnusedPsalmSuppress'];
    }

    #[\Override]
    public function getName(): string {
        return 'UnusedPsalmSuppressFixer';
    }

    #[\Override]
    public function getDescription(): string {
        return 'Removes unused @psalm-suppress annotations';
    }

    #[\Override]
    public function canFix(PsalmIssue $issue, array $stmts): bool {
        $suppressedType = $this->extractSuppressedType($issue->getMessage());
        if ($suppressedType === null) {
            return false;
        }

        return $this->findNodeWithSuppress($stmts, $issue->getLineFrom(), $suppressedType) !== null;
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult {
        $suppressedType = $this->extractSuppressedType($issue->getMessage());
        if ($suppressedType === null) {
            return FixResult::notFixed('Could not extract suppressed issue type from message');
        }

        $node = $this->findNodeWithSuppress($stmts, $issue->getLineFrom(), $suppressedType);
        if ($node === null) {
            return FixResult::notFixed('Could not find node with @psalm-suppress ' . $suppressedType);
        }

        $doc = $node->getDocComment();
        if ($doc === null) {
            return FixResult::notFixed('Node has no docblock');
        }

        /** @psalm-suppress ArgumentTypeCoercion */
        $newDocText = $this->removeSuppressTag($doc->getText(), $suppressedType);
        if ($newDocText === null) {
            return FixResult::notFixed('Could not find @psalm-suppress ' . $suppressedType . ' in docblock');
        }

        if ($this->isEmptyDocblock($newDocText)) {
            $node->setAttribute('comments', $this->getCommentsWithoutDoc($node));
        } else {
            $node->setDocComment(new Doc($newDocText));
        }

        return FixResult::fixed('Removed @psalm-suppress ' . $suppressedType);
    }

    /**
     * Find a node whose docblock contains @psalm-suppress for the given type,
     * near the reported line.
     *
     * @param list<Node> $stmts
     * @param non-empty-string $suppressedType
     */
    private function findNodeWithSuppress(array $stmts, int $line, string $suppressedType): ?Node {
        $found = null;
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($line, $suppressedType, $found) extends NodeVisitorAbstract {
            public function __construct(
                private int $targetLine,
                private string $suppressedType,
                private ?Node &$found,
            ) {
            }

            #[\Override]
            public function enterNode(Node $node): ?int {
                $doc = $node->getDocComment();
                if ($doc === null) {
                    return null;
                }

                $docStartLine = $doc->getStartLine();
                $nodeStartLine = $node->getStartLine();

                // Psalm may report the annotation line (inside docblock) or the statement line
                $lineInRange = ($docStartLine <= $this->targetLine && $this->targetLine <= $nodeStartLine);
                if (!$lineInRange) {
                    return null;
                }

                $pattern = '/@psalm-suppress\s+' . preg_quote($this->suppressedType, '/') . '(\s|$)/';
                if (preg_match($pattern, $doc->getText()) === 1) {
                    $this->found = $node;
                }

                return null;
            }
        });
        $traverser->traverse($stmts);

        return $found;
    }

    /**
     * Extract the suppressed issue type from the Psalm message.
     *
     * @return non-empty-string|null
     */
    private function extractSuppressedType(string $message): ?string {
        if (preg_match('/psalm-suppress\s+(\S+)/', $message, $matches) === 1
            && $matches[1] !== ''
        ) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Remove specific @psalm-suppress line from docblock text.
     *
     * @param non-empty-string $docText
     * @param non-empty-string $suppressedType
     * @return non-empty-string|null New docblock text, or null if tag not found
     */
    private function removeSuppressTag(string $docText, string $suppressedType): ?string {
        $lines = explode("\n", $docText);
        $filteredLines = [];
        $removed = false;

        foreach ($lines as $line) {
            $pattern = '/^\s*\*\s*@psalm-suppress\s+' . preg_quote($suppressedType, '/') . '(\s|$)/';
            if (!$removed && preg_match($pattern, $line) === 1) {
                $removed = true;
                continue;
            }
            $filteredLines[] = $line;
        }

        if (!$removed) {
            return null;
        }

        $result = implode("\n", $filteredLines);
        if ($result === '') {
            return null;
        }

        return $result;
    }

    /**
     * Check if a docblock contains only opening/closing markers and whitespace.
     */
    private function isEmptyDocblock(string $docText): bool {
        $stripped = preg_replace('/\/\*\*|\*\/|\*|\s/', '', $docText);

        return $stripped === '';
    }

    /**
     * Get all comments except the Doc comment.
     *
     * @return list<\PhpParser\Comment>
     */
    private function getCommentsWithoutDoc(Node $node): array {
        $result = [];
        $comments = $node->getAttribute('comments', []);
        if (!is_array($comments)) {
            return [];
        }
        /** @psalm-suppress MixedAssignment */
        foreach ($comments as $comment) {
            if (!($comment instanceof Doc)) {
                $result[] = $comment;
                /** @psalm-suppress RedundantCondition */
                assert(is_array($result));
            }
        }

        /** @psalm-suppress MixedReturnTypeCoercion */
        return $result;
    }
}
