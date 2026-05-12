<?php

declare(strict_types=1);

namespace PsalmFixer\Cli;

use PsalmFixer\Cli\Command\FixCommand;
use PsalmFixer\Cli\Command\ListFixersCommand;
use Symfony\Component\Console\Application as BaseApplication;

/**
 * @psalm-api Entry point invoked from bin/psalm-fixer.
 */
final class Application extends BaseApplication
{
    public function __construct()
    {
        parent::__construct('psalm-fixer', '0.2.0');

        $this->addCommand(new FixCommand());
        $this->addCommand(new ListFixersCommand());
        $this->setDefaultCommand('fix');
    }
}
