<?php

declare(strict_types=1);

namespace PsalmFixer\Report;

use PsalmFixer\Parser\PsalmIssue;

/**
 * Collects results of fix operations.
 */
final class FixReport
{
    /** @var list<array{file: non-empty-string, issue: PsalmIssue, fixer: non-empty-string, description: string}> */
    private array $fixed = [];

    /** @var list<array{file: non-empty-string, issue: PsalmIssue, reason: non-empty-string}> */
    private array $notFixed = [];

    /** @var list<array{file: non-empty-string, issue: PsalmIssue, reason: non-empty-string}> */
    private array $noFixer = [];

    /** @var list<array{file: non-empty-string, reason: non-empty-string}> */
    private array $skipped = [];

    /** @var array<non-empty-string, array{original: string, new: string}> */
    private array $diffs = [];

    /**
     * @param non-empty-string $file
     * @param non-empty-string $fixerName
     */
    public function addFixed(string $file, PsalmIssue $issue, string $fixerName, string $description): void
    {
        $this->fixed[] = [
            'file' => $file,
            'issue' => $issue,
            'fixer' => $fixerName,
            'description' => $description,
        ];
    }

    /**
     * @param non-empty-string $file
     * @param non-empty-string $reason
     */
    public function addNotFixed(string $file, PsalmIssue $issue, string $reason): void
    {
        $this->notFixed[] = ['file' => $file, 'issue' => $issue, 'reason' => $reason];
    }

    /**
     * @param non-empty-string $file
     * @param non-empty-string $reason
     */
    public function addNoFixer(string $file, PsalmIssue $issue, string $reason): void
    {
        $this->noFixer[] = ['file' => $file, 'issue' => $issue, 'reason' => $reason];
    }

    /**
     * @param non-empty-string $file
     * @param non-empty-string $reason
     */
    public function addSkipped(string $file, string $reason): void
    {
        $this->skipped[] = ['file' => $file, 'reason' => $reason];
    }

    /**
     * @param non-empty-string $file
     */
    public function addDiff(string $file, string $original, string $new): void
    {
        $this->diffs[$file] = ['original' => $original, 'new' => $new];
    }

    /** @return 0|positive-int */
    public function getFixedCount(): int
    {
        return count($this->fixed);
    }

    /** @return 0|positive-int */
    public function getNotFixedCount(): int
    {
        return count($this->notFixed);
    }

    /** @return 0|positive-int */
    public function getNoFixerCount(): int
    {
        return count($this->noFixer);
    }

    /** @return 0|positive-int */
    public function getSkippedCount(): int
    {
        return count($this->skipped);
    }

    /** @return list<array{file: non-empty-string, issue: PsalmIssue, fixer: non-empty-string, description: string}> */
    public function getFixed(): array
    {
        return $this->fixed;
    }

    /** @return list<array{file: non-empty-string, issue: PsalmIssue, reason: non-empty-string}> */
    public function getNotFixed(): array
    {
        return $this->notFixed;
    }

    /** @return list<array{file: non-empty-string, issue: PsalmIssue, reason: non-empty-string}> */
    public function getNoFixer(): array
    {
        return $this->noFixer;
    }

    /** @return list<array{file: non-empty-string, reason: non-empty-string}> */
    public function getSkipped(): array
    {
        return $this->skipped;
    }

    /** @return array<non-empty-string, array{original: string, new: string}> */
    public function getDiffs(): array
    {
        return $this->diffs;
    }
}
