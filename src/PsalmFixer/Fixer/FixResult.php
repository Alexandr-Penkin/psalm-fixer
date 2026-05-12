<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer;

/**
 * Result of a fix attempt.
 *
 * @psalm-immutable
 */
final class FixResult
{
    /**
     * @param bool $isFixed
     * @param non-empty-string|null $description
     */
    private function __construct(
        private bool $isFixed,
        private ?string $description,
    ) {}

    /**
     * @param non-empty-string $description
     */
    public static function fixed(string $description): self
    {
        return new self(true, $description);
    }

    /**
     * @param non-empty-string|null $description
     */
    public static function notFixed(?string $description = null): self
    {
        return new self(false, $description);
    }

    public function isFixed(): bool
    {
        return $this->isFixed;
    }

    /** @return non-empty-string|null */
    public function getDescription(): ?string
    {
        return $this->description;
    }
}
