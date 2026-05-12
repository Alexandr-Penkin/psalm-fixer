<?php

declare(strict_types=1);

namespace PsalmFixer\Ast;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PsalmFixer\Fixer\FixerRegistry;
use PsalmFixer\Parser\PsalmIssue;
use PsalmFixer\Report\FixReport;
use Throwable;

/**
 * Central engine: parses files, applies fixers, saves with format-preserving printing.
 */
final class FileProcessor
{
    private Parser $parser;
    private Standard $printer;

    public function __construct(
        private FixerRegistry $registry,
    ) {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->printer = new Standard();
    }

    /**
     * Process a list of issues grouped by file.
     *
     * @param list<PsalmIssue> $issues
     * @param list<non-empty-string>|null $issueTypeFilter
     * @param list<non-empty-string>|null $fileFilter
     */
    public function processIssues(
        array $issues,
        bool $dryRun = false,
        ?array $issueTypeFilter = null,
        ?array $fileFilter = null,
        bool $backup = false,
    ): FixReport {
        $report = new FixReport();

        // Filter issues
        $filteredIssues = $this->filterIssues($issues, $issueTypeFilter, $fileFilter);

        // Group by file
        /** @var array<non-empty-string, list<PsalmIssue>> $grouped */
        $grouped = [];
        foreach ($filteredIssues as $issue) {
            $filePath = $issue->getFilePath();
            if (!array_key_exists($filePath, $grouped)) {
                $grouped[$filePath] = [];
            }
            $grouped[$filePath][] = $issue;
        }

        foreach ($grouped as $filePath => $fileIssues) {
            $this->processFile($filePath, $fileIssues, $dryRun, $backup, $report);
        }

        return $report;
    }

    /**
     * @param non-empty-string $filePath
     * @param list<PsalmIssue> $issues
     */
    private function processFile(string $filePath, array $issues, bool $dryRun, bool $backup, FixReport $report): void
    {
        if (!file_exists($filePath)) {
            $report->addSkipped($filePath, 'File not found');
            return;
        }

        $originalCode = file_get_contents($filePath);
        if ($originalCode === false) {
            $report->addSkipped($filePath, 'Could not read file');
            return;
        }

        $oldStmts = $this->parser->parse($originalCode);
        if ($oldStmts === null) {
            $report->addSkipped($filePath, 'Parse error');
            return;
        }

        $oldTokens = $this->parser->getTokens();

        // Clone AST for format-preserving printing
        $traverser = new NodeTraverser();
        $cloningVisitor = new CloningVisitor();
        $traverser->addVisitor($cloningVisitor);
        /** @var list<Node> $stmts */
        $stmts = $traverser->traverse($oldStmts);

        // Sort by line descending to avoid position shifts
        usort($issues, static function (PsalmIssue $a, PsalmIssue $b): int {
            return $b->getLineFrom() - $a->getLineFrom();
        });

        $anyFixed = false;
        foreach ($issues as $issue) {
            $fixers = $this->registry->getFixersForType($issue->getType());
            if (count($fixers) === 0) {
                $report->addNoFixer($filePath, $issue, 'No fixer registered for ' . $issue->getType());
                continue;
            }

            $fixed = false;
            $lastReason = 'All fixers failed';
            foreach ($fixers as $fixer) {
                if (!$fixer->canFix($issue, $stmts)) {
                    $lastReason = $fixer->getName() . ': canFix returned false';
                    continue;
                }

                $result = $fixer->fix($issue, $stmts);
                if ($result->isFixed()) {
                    $report->addFixed($filePath, $issue, $fixer->getName(), $result->getDescription() ?? '');
                    $fixed = true;
                    $anyFixed = true;
                    break;
                }
                $lastReason = $fixer->getName() . ': ' . ($result->getDescription() ?? 'unknown reason');
            }

            if (!$fixed) {
                $report->addNotFixed($filePath, $issue, $lastReason);
            }
        }

        if (!$anyFixed) {
            return;
        }

        try {
            $newCode = $this->printer->printFormatPreserving($stmts, $oldStmts, $oldTokens);
        } catch (Throwable) {
            // Format-preserving printing fails when node types change (e.g. MethodCall -> NullsafeMethodCall)
            // or when assertions are disabled and an invariant trips a different error type.
            $newCode = $this->printer->prettyPrintFile($stmts);
        }
        // Ensure trailing newline
        if (!str_ends_with($newCode, "\n")) {
            $newCode .= "\n";
        }

        // Sanity check: rewritten source must parse, otherwise we'd write broken PHP.
        if ($this->parser->parse($newCode) === null) {
            $report->addSkipped($filePath, 'Rewritten source failed to parse — file left untouched');
            return;
        }

        if ($dryRun) {
            $report->addDiff($filePath, $originalCode, $newCode);
            return;
        }

        if ($backup) {
            $backupPath = $filePath . '.bak';
            if (file_put_contents($backupPath, $originalCode) === false) {
                $report->addSkipped($filePath, "Could not write backup file: {$backupPath}");
                return;
            }
        }

        if (file_put_contents($filePath, $newCode) === false) {
            $report->addSkipped($filePath, 'Could not write file');
        }
    }

    /**
     * @param list<PsalmIssue> $issues
     * @param list<non-empty-string>|null $issueTypeFilter
     * @param list<non-empty-string>|null $fileFilter
     * @return list<PsalmIssue>
     */
    private function filterIssues(array $issues, ?array $issueTypeFilter, ?array $fileFilter): array
    {
        $result = [];
        foreach ($issues as $issue) {
            if ($issueTypeFilter !== null && !in_array($issue->getType(), $issueTypeFilter, true)) {
                continue;
            }
            if ($fileFilter !== null) {
                $matchesFile = false;
                foreach ($fileFilter as $pattern) {
                    if (str_contains($issue->getFilePath(), $pattern)) {
                        $matchesFile = true;
                        break;
                    }
                }
                if (!$matchesFile) {
                    continue;
                }
            }
            $result[] = $issue;
        }

        return $result;
    }
}
