<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer;

use PhpParser\Node;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Contract for all Psalm issue fixers.
 */
interface FixerInterface
{
    /**
     * Returns Psalm issue types this fixer can handle.
     *
     * @return list<non-empty-string>
     */
    public function getSupportedTypes(): array;

    /**
     * Returns human-readable name of the fixer.
     *
     * @return non-empty-string
     */
    public function getName(): string;

    /**
     * Returns description of what this fixer does.
     *
     * @return non-empty-string
     */
    public function getDescription(): string;

    /**
     * Check if this fixer can handle the specific issue.
     *
     * @param list<Node> $stmts AST of the file
     */
    public function canFix(PsalmIssue $issue, array $stmts): bool;

    /**
     * Apply the fix to the AST.
     *
     * @param list<Node> $stmts AST of the file (mutable)
     */
    public function fix(PsalmIssue $issue, array &$stmts): FixResult;
}
