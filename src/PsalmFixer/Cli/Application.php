<?php

declare(strict_types=1);

namespace PsalmFixer\Cli;

use PsalmFixer\Cli\Command\FixCommand;
use PsalmFixer\Cli\Command\ListFixersCommand;
use Symfony\Component\Console\Application as BaseApplication;

final class Application extends BaseApplication {
    public function __construct() {
        parent::__construct('psalm-fixer', '0.1.0');

        $this->addCommand(new FixCommand());
        $this->addCommand(new ListFixersCommand());
        $this->setDefaultCommand('fix');
    }
}
