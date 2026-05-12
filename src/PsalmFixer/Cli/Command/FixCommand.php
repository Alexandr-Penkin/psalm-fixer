<?php

declare(strict_types=1);

namespace PsalmFixer\Cli\Command;

use PsalmFixer\Ast\FileProcessor;
use PsalmFixer\Fixer\FixerRegistry;
use PsalmFixer\Parser\PsalmBaselineParser;
use PsalmFixer\Parser\PsalmOutputParser;
use PsalmFixer\Report\ReportPrinter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class FixCommand extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this
            ->setName('fix')
            ->setDescription('Fix Psalm issues from JSON output or a Psalm baseline XML file')
            ->addArgument('source', InputArgument::OPTIONAL, 'Path to Psalm JSON output file, or "-" for STDIN')
            ->addOption(
                'baseline',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to Psalm baseline XML file (mutually exclusive with the source argument)',
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be fixed without modifying files')
            ->addOption('diff', null, InputOption::VALUE_NONE, 'Show diff of changes (implies --dry-run)')
            ->addOption('backup', null, InputOption::VALUE_NONE, 'Create .bak files before modifying')
            ->addOption('issue-type', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of issue types to fix')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of file patterns to filter');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string|null $source */
        $source = $input->getArgument('source');
        /** @var string|null $baseline */
        $baseline = $input->getOption('baseline');

        $hasSource = is_string($source) && $source !== '';
        $hasBaseline = is_string($baseline) && $baseline !== '';

        if (!$hasSource && !$hasBaseline) {
            $output->writeln('<error>Must provide either a JSON source argument or --baseline option.</error>');
            return Command::FAILURE;
        }
        if ($hasSource && $hasBaseline) {
            $output->writeln('<error>--baseline and the source argument are mutually exclusive.</error>');
            return Command::FAILURE;
        }

        $dryRun = $input->getOption('dry-run') === true || $input->getOption('diff') === true;
        $showDiff = $input->getOption('diff') === true;
        $backup = $input->getOption('backup') === true;

        /** @var string|null $issueTypeRaw */
        $issueTypeRaw = $input->getOption('issue-type');
        /** @var string|null $fileRaw */
        $fileRaw = $input->getOption('file');

        $issueTypeFilter = null;
        if (is_string($issueTypeRaw) && $issueTypeRaw !== '') {
            $issueTypeFilter = $this->splitNonEmpty($issueTypeRaw);
        }

        $fileFilter = null;
        if (is_string($fileRaw) && $fileRaw !== '') {
            $fileFilter = $this->splitNonEmpty($fileRaw);
        }

        $registry = FixerRegistry::createDefault();
        $processor = new FileProcessor($registry);
        $printer = new ReportPrinter();

        if ($hasBaseline) {
            $baselineParser = new PsalmBaselineParser();
            try {
                $issues = $baselineParser->parse($baseline);
            } catch (\RuntimeException $e) {
                $output->writeln("<error>{$e->getMessage()}</error>");
                return Command::FAILURE;
            }

            $warnings = $baselineParser->getWarnings();
            foreach ($warnings as $warning) {
                $output->writeln("<comment>Baseline warning: {$warning}</comment>");
            }
            if (count($warnings) > 0) {
                $output->writeln(sprintf('<comment>%d baseline warning(s) emitted.</comment>', count($warnings)));
            }

            $output->writeln(sprintf('Parsed <info>%d</info> issues from Psalm baseline.', count($issues)));
        } else {
            $jsonParser = new PsalmOutputParser();
            try {
                $issues = $jsonParser->parse($source);
            } catch (\RuntimeException $e) {
                $output->writeln("<error>{$e->getMessage()}</error>");
                return Command::FAILURE;
            }

            $output->writeln(sprintf('Parsed <info>%d</info> issues from Psalm output.', count($issues)));
        }

        if (count($issues) === 0) {
            $output->writeln('No issues to fix.');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $output->writeln('<comment>Dry run mode — no files will be modified.</comment>');
        }

        $report = $processor->processIssues($issues, $dryRun, $issueTypeFilter, $fileFilter, $backup);
        $printer->print($report, $output, $showDiff);

        return $report->getFixedCount() > 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return list<non-empty-string>
     */
    private function splitNonEmpty(string $raw): array
    {
        $result = [];
        foreach (explode(',', $raw) as $part) {
            if ($part !== '') {
                $result[] = $part;
            }
        }

        return $result;
    }
}
