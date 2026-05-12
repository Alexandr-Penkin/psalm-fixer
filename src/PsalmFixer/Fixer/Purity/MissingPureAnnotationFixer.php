<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\Purity;

use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\AppendsPsalmSuppress;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Adds Psalm purity annotations that the analyser asks for in its message —
 * `@psalm-pure`, `@psalm-mutation-free`, `@psalm-external-mutation-free` on
 * methods/functions, and `@psalm-immutable` on classes flagged as
 * `MissingImmutableAnnotation`.
 *
 * The required annotation is parsed directly from Psalm's message text
 * (`must be marked @psalm-…`). For classes the fixer maps the issue type to
 * `@psalm-immutable` even if Psalm's wording reads "psalm-pure".
 *
 * Baseline-input note: `MissingImmutableAnnotation` works without message text
 * (the issue type alone determines the tag). `MissingPureAnnotation` needs the
 * message to disambiguate between pure / mutation-free / external-mutation-free
 * and will report `not fixed` otherwise.
 */
final class MissingPureAnnotationFixer extends AbstractFixer
{
    use AppendsPsalmSuppress;

    #[\Override]
    public function getSupportedTypes(): array
    {
        return ['MissingPureAnnotation', 'MissingImmutableAnnotation'];
    }

    #[\Override]
    public function getName(): string
    {
        return 'MissingPureAnnotationFixer';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Adds @psalm-pure / @psalm-mutation-free / @psalm-immutable annotations requested by Psalm';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult
    {
        $tag = $this->resolveTag($issue);
        if ($tag === null) {
            return FixResult::notFixed('Could not determine which purity annotation to add from message');
        }

        return $this->attachDocTag($stmts, $issue->getLineFrom(), $tag);
    }

    /**
     * @return non-empty-string|null
     */
    private function resolveTag(PsalmIssue $issue): ?string
    {
        if ($issue->getType() === 'MissingImmutableAnnotation') {
            return '@psalm-immutable';
        }

        // Message format: "X must be marked @psalm-<kind> to aid security analysis".
        // The leading `@` is sometimes present, sometimes not — accept both.
        if (preg_match('/must be marked\s+@?(psalm-[a-z-]+)/i', $issue->getMessage(), $matches) === 1) {
            $kind = strtolower($matches[1]);
            if ($kind !== '') {
                return '@' . $kind;
            }
        }

        return null;
    }
}
