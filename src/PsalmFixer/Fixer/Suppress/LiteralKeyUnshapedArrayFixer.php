<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\Suppress;

use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\AppendsPsalmSuppress;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Handles `LiteralKeyUnshapedArray` — Psalm flags `$arr['key']` when `$arr` has
 * no declared shape (`array<array-key, mixed>` or `mixed`).
 *
 * There is no safe AST rewrite: inferring the correct shape would require
 * tracking how `$arr` is built up, which lives outside this expression. So the
 * fixer attaches `@psalm-suppress LiteralKeyUnshapedArray` to the covering
 * statement, leaving runtime behavior untouched.
 */
final class LiteralKeyUnshapedArrayFixer extends AbstractFixer
{
    use AppendsPsalmSuppress;

    #[\Override]
    public function getSupportedTypes(): array
    {
        return ['LiteralKeyUnshapedArray'];
    }

    #[\Override]
    public function getName(): string
    {
        return 'LiteralKeyUnshapedArrayFixer';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Suppresses LiteralKeyUnshapedArray (no safe rewrite without shape info)';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult
    {
        return $this->attachPsalmSuppress($stmts, $issue->getLineFrom(), $issue->getType());
    }
}
