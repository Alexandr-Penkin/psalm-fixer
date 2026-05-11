<?php

declare(strict_types=1);

namespace PsalmFixer\Tests\Fixtures\Convergence;

abstract class SampleParent {
    public function foo(): void {
    }

    abstract public function bar(): string;
}

final class SampleChild extends SampleParent {
    public function foo(): void {
        if (true) {
            echo "always";
        }
    }

    public function bar(): string {
        $name = $this->getName();
        if ($name !== '') {
            return $name;
        }

        return 'fallback';
    }

    private function getName(): string {
        return 'x';
    }
}
