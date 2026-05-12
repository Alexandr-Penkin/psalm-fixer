<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\TypeSafety;

use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\AppendsPsalmSuppress;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Suppresses `PropertyTypeCoercion` warnings by attaching a
 * `@psalm-suppress PropertyTypeCoercion` line to the offending statement.
 *
 * Used for situations where the developer knows the assignment is safe but
 * Psalm cannot prove it (typically wider source type narrowing to a stricter
 * property type). The fixer is conservative: it does not rewrite types or
 * insert runtime assertions, so the original behavior is preserved exactly.
 */
final class PropertyTypeCoercionFixer extends AbstractFixer
{
    use AppendsPsalmSuppress;

    #[\Override]
    public function getSupportedTypes(): array
    {
        return ['PropertyTypeCoercion'];
    }

    #[\Override]
    public function getName(): string
    {
        return 'PropertyTypeCoercionFixer';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Adds @psalm-suppress PropertyTypeCoercion above the offending assignment';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult
    {
        return $this->attachPsalmSuppress($stmts, $issue->getLineFrom(), 'PropertyTypeCoercion');
    }
}
