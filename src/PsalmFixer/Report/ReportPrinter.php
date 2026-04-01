<?php

declare(strict_types=1);

namespace PsalmFixer\Report;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

/**
 * Prints fix reports to console.
 */
final class ReportPrinter {
    public function print(FixReport $report, OutputInterface $output, bool $showDiff = false): void {
        $this->printFixed($report, $output);
        $this->printNotFixed($report, $output);
        $this->printSkipped($report, $output);

        if ($showDiff) {
            $this->printDiffs($report, $output);
        }

        $this->printSummary($report, $output);
    }

    private function printFixed(FixReport $report, OutputInterface $output): void {
        $fixed = $report->getFixed();
        if (count($fixed) === 0) {
            return;
        }

        $output->writeln('<info>Fixed issues:</info>');
        foreach ($fixed as $entry) {
            $issue = $entry['issue'];
            $output->writeln(sprintf(
                '  <fg=green>✓</> %s:%d [%s] %s (by %s)',
                $entry['file'],
                $issue->getLineFrom(),
                $issue->getType(),
                $entry['description'],
                $entry['fixer'],
            ));
        }
        $output->writeln('');
    }

    private function printNotFixed(FixReport $report, OutputInterface $output): void {
        $notFixed = array_merge($report->getNotFixed(), $report->getNoFixer());
        if (count($notFixed) === 0) {
            return;
        }

        $output->writeln('<comment>Not fixed:</comment>');
        foreach ($notFixed as $entry) {
            $issue = $entry['issue'];
            $output->writeln(sprintf(
                '  <fg=yellow>⚠</> %s:%d [%s] %s',
                $entry['file'],
                $issue->getLineFrom(),
                $issue->getType(),
                $issue->getMessage(),
            ));
            $output->writeln(sprintf(
                '    <fg=gray>Reason: %s</>',
                $entry['reason'],
            ));
        }
        $output->writeln('');
    }

    private function printSkipped(FixReport $report, OutputInterface $output): void {
        $skipped = $report->getSkipped();
        if (count($skipped) === 0) {
            return;
        }

        $output->writeln('<comment>Skipped files:</comment>');
        foreach ($skipped as $entry) {
            $output->writeln(sprintf('  <fg=yellow>⚠</> %s: %s', $entry['file'], $entry['reason']));
        }
        $output->writeln('');
    }

    private function printDiffs(FixReport $report, OutputInterface $output): void {
        $diffs = $report->getDiffs();
        if (count($diffs) === 0) {
            return;
        }

        foreach ($diffs as $file => $diff) {
            $output->writeln("<info>--- {$file}</info>");
            $output->writeln("<info>+++ {$file} (fixed)</info>");

            $originalLines = explode("\n", $diff['original']);
            $newLines = explode("\n", $diff['new']);

            $this->printUnifiedDiff($originalLines, $newLines, $output);
            $output->writeln('');
        }
    }

    /**
     * @param list<string> $oldLines
     * @param list<string> $newLines
     */
    private function printUnifiedDiff(array $oldLines, array $newLines, OutputInterface $output): void {
        $maxLines = max(count($oldLines), count($newLines));
        $contextSize = 3;
        $inDiff = false;
        $buffer = [];

        for ($i = 0; $i < $maxLines; $i++) {
            $oldLine = $oldLines[$i] ?? null;
            $newLine = $newLines[$i] ?? null;

            if ($oldLine === $newLine) {
                if ($inDiff) {
                    $buffer[] = " {$oldLine}";
                    if (count($buffer) > $contextSize) {
                        foreach ($buffer as $line) {
                            $output->writeln($line);
                        }
                        $buffer = [];
                        $inDiff = false;
                    }
                }
                continue;
            }

            if (!$inDiff) {
                // Show context before
                $start = max(0, $i - $contextSize);
                for ($j = $start; $j < $i; $j++) {
                    if (array_key_exists($j, $oldLines)) {
                        $output->writeln(" {$oldLines[$j]}");
                    }
                }
                $inDiff = true;
            }

            foreach ($buffer as $line) {
                $output->writeln($line);
            }
            $buffer = [];

            if ($oldLine !== null && $newLine !== null) {
                $output->writeln("<fg=red>-{$oldLine}</>");
                $output->writeln("<fg=green>+{$newLine}</>");
            } elseif ($oldLine !== null) {
                $output->writeln("<fg=red>-{$oldLine}</>");
            } else {
                $output->writeln("<fg=green>+{$newLine}</>");
            }
        }
    }

    private function printSummary(FixReport $report, OutputInterface $output): void {
        $output->writeln(sprintf(
            '<info>Summary:</info> %d fixed, %d not fixed, %d no fixer available, %d files skipped',
            $report->getFixedCount(),
            $report->getNotFixedCount(),
            $report->getNoFixerCount(),
            $report->getSkippedCount(),
        ));
    }
}
