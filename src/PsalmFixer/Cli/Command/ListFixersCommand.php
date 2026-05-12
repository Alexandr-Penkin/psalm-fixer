<?php

declare(strict_types=1);

namespace PsalmFixer\Cli\Command;

use PsalmFixer\Fixer\FixerInterface;
use PsalmFixer\Fixer\FixerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ListFixersCommand extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this->setName('list-fixers')->setDescription('List all available fixers and their supported issue types');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = FixerRegistry::createDefault();
        $fixers = $registry->getAllFixers();

        // Alphabetical by name — registration order is meaningless to the
        // reader, and ~25 fixers are unscannable without a sort.
        usort($fixers, static fn(FixerInterface $a, FixerInterface $b): int => strcmp($a->getName(), $b->getName()));

        $table = new Table($output);
        $table->setHeaders(['Fixer', 'Issue Types', 'Description']);

        foreach ($fixers as $fixer) {
            $table->addRow([
                $fixer->getName(),
                implode(', ', $fixer->getSupportedTypes()),
                $fixer->getDescription(),
            ]);
        }

        $table->render();

        $output->writeln(sprintf(
            "\n<info>%d fixers</info> covering <info>%d issue types</info>",
            count($fixers),
            count($registry->getSupportedTypes()),
        ));

        return Command::SUCCESS;
    }
}
