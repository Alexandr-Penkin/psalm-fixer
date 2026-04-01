<?php

declare(strict_types=1);

namespace PsalmFixer\Cli\Command;

use PsalmFixer\Fixer\FixerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ListFixersCommand extends Command {
    protected function configure(): void {
        $this
            ->setName('list-fixers')
            ->setDescription('List all available fixers and their supported issue types')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $registry = FixerRegistry::createDefault();
        $fixers = $registry->getAllFixers();

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

        $output->writeln(sprintf("\n<info>%d fixers</info> covering <info>%d issue types</info>",
            count($fixers),
            count($registry->getSupportedTypes()),
        ));

        return Command::SUCCESS;
    }
}
