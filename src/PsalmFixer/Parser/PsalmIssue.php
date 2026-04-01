<?php

declare(strict_types=1);

namespace PsalmFixer\Parser;

/**
 * Value object representing a single Psalm issue.
 *
 * @psalm-immutable
 */
final class PsalmIssue {
    /**
     * @param non-empty-string $type
     * @param non-empty-string $message
     * @param non-empty-string $filePath
     * @param positive-int $lineFrom
     * @param positive-int $lineTo
     * @param 0|positive-int $columnFrom
     * @param 0|positive-int $columnTo
     * @param non-empty-string|null $snippet
     * @param non-empty-string $severity
     * @param non-empty-string|null $link
     */
    public function __construct(
        private string $type,
        private string $message,
        private string $filePath,
        private int $lineFrom,
        private int $lineTo,
        private int $columnFrom,
        private int $columnTo,
        private ?string $snippet,
        private string $severity,
        private ?string $link = null,
    ) {
    }

    /** @return non-empty-string */
    public function getType(): string {
        return $this->type;
    }

    /** @return non-empty-string */
    public function getMessage(): string {
        return $this->message;
    }

    /** @return non-empty-string */
    public function getFilePath(): string {
        return $this->filePath;
    }

    /** @return positive-int */
    public function getLineFrom(): int {
        return $this->lineFrom;
    }

    /** @return positive-int */
    public function getLineTo(): int {
        return $this->lineTo;
    }

    /** @return 0|positive-int */
    public function getColumnFrom(): int {
        return $this->columnFrom;
    }

    /** @return 0|positive-int */
    public function getColumnTo(): int {
        return $this->columnTo;
    }

    /** @return non-empty-string|null */
    public function getSnippet(): ?string {
        return $this->snippet;
    }

    /** @return non-empty-string */
    public function getSeverity(): string {
        return $this->severity;
    }

    /** @return non-empty-string|null */
    public function getLink(): ?string {
        return $this->link;
    }
}
