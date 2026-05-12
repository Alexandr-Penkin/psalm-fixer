<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\Suppress;

use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\AppendsPsalmSuppress;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Generic last-resort fixer for issue types that cannot be safely rewritten at
 * the AST level — annotates the offending statement with
 * `@psalm-suppress <IssueType>`.
 *
 * Used for genuinely-unfixable patterns: by-reference parameter type narrowing
 * (`ReferenceConstraintViolation`), return-type coercion (`MixedReturnTypeCoercion`),
 * dynamic instantiation (`UnsafeInstantiation`), wider-array coercion at call
 * sites (`MixedArgumentTypeCoercion`).
 *
 * The fixer is conservative: it never rewrites code, only adds a docblock
 * annotation, so behavior is preserved exactly.
 */
final class SuppressFallbackFixer extends AbstractFixer
{
    use AppendsPsalmSuppress;

    /**
     * @var list<non-empty-string>
     */
    private const HANDLED_TYPES = [
        'ReferenceConstraintViolation',
        'MixedReturnTypeCoercion',
        'UnsafeInstantiation',
        'MixedArgumentTypeCoercion',
    ];

    #[\Override]
    public function getSupportedTypes(): array
    {
        return self::HANDLED_TYPES;
    }

    #[\Override]
    public function getName(): string
    {
        return 'SuppressFallbackFixer';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Adds @psalm-suppress for issue types that cannot be safely auto-rewritten';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult
    {
        $type = $issue->getType();
        if (!in_array($type, self::HANDLED_TYPES, true)) {
            return FixResult::notFixed("SuppressFallbackFixer does not handle {$type}");
        }

        return $this->attachPsalmSuppress($stmts, $issue->getLineFrom(), $type);
    }
}
