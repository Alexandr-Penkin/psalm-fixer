<?php

declare(strict_types=1);

namespace PsalmFixer\Parser;

use Closure;
use RuntimeException;
use SimpleXMLElement;

/**
 * Parses a Psalm baseline XML file (psalm-baseline.xml) into PsalmIssue objects.
 *
 * The baseline lists suppressed issues per file but lacks line numbers; this parser
 * resolves each <code> snippet to a source line by reading the referenced file.
 */
final class PsalmBaselineParser
{
    /** @var Closure(string): (string|null) */
    private Closure $fileReader;

    /** @var array<string, list<string>> */
    private array $fileLineCache = [];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param Closure(string): (string|null) | null $fileReader  Injectable file reader for testability.
     *        Receives an absolute path and returns the file contents, or null if unreadable.
     */
    public function __construct(?Closure $fileReader = null)
    {
        $this->fileReader = $fileReader ?? static function (string $path): ?string {
            if (!is_file($path)) {
                return null;
            }
            $content = file_get_contents($path);

            return $content === false ? null : $content;
        };
    }

    /**
     * Parse a baseline XML file. Relative <file src="..."> paths are resolved against the
     * directory containing the baseline file.
     *
     * @param non-empty-string $source Absolute or relative path to the baseline XML file.
     * @return list<PsalmIssue>
     */
    public function parse(string $source): array
    {
        if (!file_exists($source)) {
            throw new RuntimeException("File not found: {$source}");
        }

        $content = file_get_contents($source);
        if ($content === false || $content === '') {
            throw new RuntimeException("Failed to read file or file is empty: {$source}");
        }

        $basePath = dirname($source);

        return $this->parseXml($content, $basePath);
    }

    /**
     * Parse baseline XML directly from a string.
     *
     * @param non-empty-string $xml
     * @param string|null $basePath Directory used to resolve relative file paths in <file src="...">.
     *        Defaults to the current working directory.
     * @return list<PsalmIssue>
     */
    public function parseXml(string $xml, ?string $basePath = null): array
    {
        $this->fileLineCache = [];
        $this->warnings = [];

        if ($basePath === null) {
            $cwd = getcwd();
            $basePath = $cwd !== false ? $cwd : '.';
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $root = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_use_internal_errors($previous);
        }

        if ($root === false) {
            throw new RuntimeException('Invalid Psalm baseline XML: failed to parse');
        }

        if ($root->getName() !== 'files') {
            throw new RuntimeException("Invalid Psalm baseline XML: expected root <files>, got <{$root->getName()}>");
        }

        $result = [];

        foreach ($this->iterChildren($root) as $fileNode) {
            if ($fileNode->getName() !== 'file') {
                continue;
            }

            $src = $this->attr($fileNode, 'src');
            if ($src === '') {
                $this->warnings[] = 'Baseline <file> element missing "src" attribute; skipping.';
                continue;
            }

            $absolutePath = $this->resolvePath($src, $basePath);

            foreach ($this->iterChildren($fileNode) as $issueNode) {
                $issueType = $issueNode->getName();
                if ($issueType === '' || $issueType === 'file') {
                    continue;
                }

                $consumedLines = [];

                foreach ($this->iterChildren($issueNode) as $codeNode) {
                    if ($codeNode->getName() !== 'code') {
                        continue;
                    }

                    $rawSnippet = (string) $codeNode;
                    $trimmedSnippet = trim($rawSnippet);
                    if ($trimmedSnippet === '') {
                        $this->warnings[] = "Empty <code> snippet for {$issueType} in {$absolutePath}; skipping.";
                        continue;
                    }

                    $line = $this->resolveLine($absolutePath, $trimmedSnippet, $consumedLines);
                    if ($line === null) {
                        continue;
                    }

                    $result[] = new PsalmIssue(
                        type: $issueType,
                        message: "From baseline: {$issueType}",
                        filePath: $absolutePath,
                        lineFrom: $line,
                        lineTo: $line,
                        columnFrom: 0,
                        columnTo: 0,
                        snippet: $rawSnippet !== '' ? $rawSnippet : null,
                        severity: 'error',
                        link: null,
                    );
                }
            }
        }

        return $result;
    }

    /**
     * @return iterable<SimpleXMLElement>
     */
    private function iterChildren(SimpleXMLElement $node): iterable
    {
        $children = $node->children();
        if ($children === null) {
            return;
        }

        foreach ($children as $child) {
            /** @psalm-suppress TypeDoesNotContainNull,RedundantCondition */
            if ($child !== null) {
                yield $child;
            }
        }
    }

    private function attr(SimpleXMLElement $node, string $name): string
    {
        $attributes = $node->attributes();
        if ($attributes === null) {
            return '';
        }

        $value = $attributes[$name];
        if ($value === null) {
            return '';
        }

        return (string) $value;
    }

    /**
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return non-empty-string
     */
    private function resolvePath(string $src, string $basePath): string
    {
        $isAbsolute = $src !== '' && ($src[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $src) === 1);
        $candidate = $isAbsolute ? $src : rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . $src;

        $resolved = realpath($candidate);
        $finalPath = $resolved !== false ? $resolved : $candidate;

        if ($finalPath === '') {
            return $src !== '' ? $src : '.';
        }

        return $finalPath;
    }

    /**
     * Map a baseline `<code>` snippet to the source line. Two passes:
     *   1. Prefer lines whose trimmed content equals the snippet exactly —
     *      avoids matching the snippet inside a larger expression.
     *   2. Fall back to substring containment for the (Psalm-typical) case of
     *      a multi-token snippet that's part of a larger line.
     * Consumed lines are tracked per-file so two identical baseline snippets
     * resolve to distinct lines.
     *
     * @param list<int> $consumedLines
     * @param-out list<int> $consumedLines
     * @return positive-int|null
     */
    private function resolveLine(string $filePath, string $trimmedSnippet, array &$consumedLines): ?int
    {
        $lines = $this->loadFileLines($filePath);
        if ($lines === null) {
            return null;
        }

        // Pass 1: exact-match on trimmed line.
        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            if (in_array($lineNumber, $consumedLines, true)) {
                continue;
            }
            if (trim($line) === $trimmedSnippet) {
                $consumedLines[] = $lineNumber;
                /** @var positive-int $lineNumber */
                return $lineNumber;
            }
        }

        // Pass 2: substring containment (legacy behaviour, matches Psalm's
        // truncated snippets like `$obj->method()` within a longer expression).
        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            if (in_array($lineNumber, $consumedLines, true)) {
                continue;
            }
            if (str_contains(trim($line), $trimmedSnippet)) {
                $consumedLines[] = $lineNumber;
                /** @var positive-int $lineNumber */
                return $lineNumber;
            }
        }

        $this->warnings[] = "Snippet not found in {$filePath}: {$trimmedSnippet}";

        return null;
    }

    /**
     * @return list<string>|null  Returns null when the file cannot be read (warning emitted once).
     */
    private function loadFileLines(string $filePath): ?array
    {
        if (array_key_exists($filePath, $this->fileLineCache)) {
            $cached = $this->fileLineCache[$filePath];

            return $cached === [] ? null : $cached;
        }

        $content = ($this->fileReader)($filePath);
        if ($content === null) {
            $this->warnings[] = "Baseline references missing or unreadable file: {$filePath}";
            $this->fileLineCache[$filePath] = [];

            return null;
        }

        $split = preg_split('/\R/', $content);
        $lines = $split === false ? [] : $split;

        $this->fileLineCache[$filePath] = $lines;

        return $lines === [] ? null : $lines;
    }
}
