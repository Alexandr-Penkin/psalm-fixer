<?php

declare(strict_types=1);

namespace PsalmFixer\Parser;

use RuntimeException;

/**
 * Parses Psalm JSON output into PsalmIssue objects.
 */
final class PsalmOutputParser {
    /**
     * Parse JSON from a file path or '-' for STDIN.
     *
     * @param non-empty-string $source File path or '-' for STDIN
     * @return list<PsalmIssue>
     */
    public function parse(string $source): array {
        $json = $this->readSource($source);

        return $this->parseJson($json);
    }

    /**
     * Parse JSON string directly.
     *
     * @param non-empty-string $json
     * @return list<PsalmIssue>
     */
    public function parseJson(string $json): array {
        /** @var mixed $data */
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new RuntimeException('Invalid Psalm JSON output: expected array');
        }

        $result = [];
        /** @var mixed $item */
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }

            $issue = $this->parseIssue($item);
            if ($issue !== null) {
                $result[] = $issue;
            }
        }

        return $result;
    }

    /**
     * @param non-empty-string $source
     * @return non-empty-string
     */
    private function readSource(string $source): string {
        if ($source === '-') {
            $content = stream_get_contents(STDIN);
            if ($content === false || $content === '') {
                throw new RuntimeException('Failed to read from STDIN or input is empty');
            }

            return $content;
        }

        if (!file_exists($source)) {
            throw new RuntimeException("File not found: {$source}");
        }

        $content = file_get_contents($source);
        if ($content === false || $content === '') {
            throw new RuntimeException("Failed to read file or file is empty: {$source}");
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parseIssue(array $data): ?PsalmIssue {
        $type = $data['type'] ?? null;
        $message = $data['message'] ?? null;
        $filePath = $data['file_path'] ?? ($data['file_name'] ?? null);
        $lineFrom = $data['line_from'] ?? null;
        $lineTo = $data['line_to'] ?? null;
        $columnFrom = $data['column_from'] ?? 0;
        $columnTo = $data['column_to'] ?? 0;
        $snippet = $data['snippet'] ?? null;
        $severity = $data['severity'] ?? 'error';
        $link = $data['link'] ?? null;

        if (!is_string($type) || $type === '') {
            return null;
        }
        if (!is_string($message) || $message === '') {
            return null;
        }
        if (!is_string($filePath) || $filePath === '') {
            return null;
        }
        if (!is_int($lineFrom) || $lineFrom < 1) {
            return null;
        }
        if (!is_int($lineTo) || $lineTo < 1) {
            return null;
        }

        return new PsalmIssue(
            type: $type,
            message: $message,
            filePath: $filePath,
            lineFrom: $lineFrom,
            lineTo: $lineTo,
            columnFrom: is_int($columnFrom) ? $columnFrom : 0,
            columnTo: is_int($columnTo) ? $columnTo : 0,
            snippet: is_string($snippet) && $snippet !== '' ? $snippet : null,
            severity: is_string($severity) && $severity !== '' ? $severity : 'error',
            link: is_string($link) && $link !== '' ? $link : null,
        );
    }
}
