<?php

declare(strict_types=1);

namespace PsalmFixer\Tests\Unit\Parser;

use PHPUnit\Framework\TestCase;
use PsalmFixer\Parser\PsalmBaselineParser;
use RuntimeException;

final class PsalmBaselineParserTest extends TestCase {
    private PsalmBaselineParser $parser;
    private string $tmpDir;

    /** @var list<string> */
    private array $tmpPaths = [];

    protected function setUp(): void {
        $this->parser = new PsalmBaselineParser();
        $tmp = sys_get_temp_dir() . '/psalm_baseline_test_' . uniqid('', true);
        mkdir($tmp);
        $this->tmpDir = $tmp;
    }

    protected function tearDown(): void {
        foreach ($this->tmpPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->removeDir($this->tmpDir);
    }

    public function testParseSingleIssue(): void {
        $phpPath = $this->writeFile('Foo.php', "<?php\n\$obj->method();\n");
        $xml = $this->buildXml([
            'Foo.php' => [
                'PossiblyNullReference' => ['$obj->method()'],
            ],
        ]);

        $issues = $this->parser->parseXml($xml, $this->tmpDir);

        self::assertCount(1, $issues);
        $issue = $issues[0];
        self::assertSame('PossiblyNullReference', $issue->getType());
        self::assertSame('From baseline: PossiblyNullReference', $issue->getMessage());
        self::assertSame($phpPath, $issue->getFilePath());
        self::assertSame(2, $issue->getLineFrom());
        self::assertSame(2, $issue->getLineTo());
        self::assertSame(0, $issue->getColumnFrom());
        self::assertSame(0, $issue->getColumnTo());
        self::assertSame('error', $issue->getSeverity());
        self::assertNull($issue->getLink());
        self::assertSame('$obj->method()', $issue->getSnippet());
        self::assertSame([], $this->parser->getWarnings());
    }

    public function testParseMultipleDistinctSnippets(): void {
        $phpPath = $this->writeFile('Foo.php', "<?php\n\$obj->method();\n\$other->call();\n");
        $xml = $this->buildXml([
            'Foo.php' => [
                'PossiblyNullReference' => ['$obj->method()', '$other->call()'],
            ],
        ]);

        $issues = $this->parser->parseXml($xml, $this->tmpDir);

        self::assertCount(2, $issues);
        self::assertSame($phpPath, $issues[0]->getFilePath());
        self::assertSame(2, $issues[0]->getLineFrom());
        self::assertSame(3, $issues[1]->getLineFrom());
    }

    public function testParseMultipleIdenticalSnippetsConsumeDistinctLines(): void {
        $this->writeFile('Foo.php', "<?php\n(int)\$x;\n(int)\$x;\n(int)\$x;\n");
        $xml = $this->buildXml([
            'Foo.php' => [
                'RedundantCast' => ['(int)$x', '(int)$x'],
            ],
        ]);

        $issues = $this->parser->parseXml($xml, $this->tmpDir);

        self::assertCount(2, $issues);
        self::assertSame(2, $issues[0]->getLineFrom());
        self::assertSame(3, $issues[1]->getLineFrom());
    }

    public function testParseMultipleIssueTypesInSameFile(): void {
        $this->writeFile('Foo.php', "<?php\n\$obj->method();\n(int)\$x;\n");
        $xml = $this->buildXml([
            'Foo.php' => [
                'PossiblyNullReference' => ['$obj->method()'],
                'RedundantCast' => ['(int)$x'],
            ],
        ]);

        $issues = $this->parser->parseXml($xml, $this->tmpDir);

        self::assertCount(2, $issues);
        $types = array_map(static fn($i) => $i->getType(), $issues);
        self::assertContains('PossiblyNullReference', $types);
        self::assertContains('RedundantCast', $types);
    }

    public function testParseMultipleFiles(): void {
        $a = $this->writeFile('A.php', "<?php\n\$obj->method();\n");
        $b = $this->writeFile('B.php', "<?php\n(int)\$x;\n");
        $xml = $this->buildXml([
            'A.php' => ['PossiblyNullReference' => ['$obj->method()']],
            'B.php' => ['RedundantCast' => ['(int)$x']],
        ]);

        $issues = $this->parser->parseXml($xml, $this->tmpDir);

        self::assertCount(2, $issues);
        $byPath = [];
        foreach ($issues as $issue) {
            $byPath[$issue->getFilePath()] = $issue;
        }
        self::assertArrayHasKey($a, $byPath);
        self::assertArrayHasKey($b, $byPath);
    }

    public function testParseRelativePathResolvedAgainstBaselineDir(): void {
        $subDir = $this->tmpDir . '/sub';
        mkdir($subDir);
        $phpPath = $subDir . '/Foo.php';
        file_put_contents($phpPath, "<?php\n\$obj->method();\n");
        $this->tmpPaths[] = $phpPath;

        $xml = $this->buildXml([
            'sub/Foo.php' => ['PossiblyNullReference' => ['$obj->method()']],
        ]);

        $issues = $this->parser->parseXml($xml, $this->tmpDir);

        self::assertCount(1, $issues);
        self::assertSame(realpath($phpPath), $issues[0]->getFilePath());
    }

    public function testParseMissingFileEmitsWarningAndSkips(): void {
        $xml = $this->buildXml([
            'NoSuchFile.php' => ['PossiblyNullReference' => ['$obj->method()']],
        ]);

        $issues = $this->parser->parseXml($xml, $this->tmpDir);

        self::assertCount(0, $issues);
        $warnings = $this->parser->getWarnings();
        self::assertCount(1, $warnings);
        self::assertStringContainsString('missing or unreadable file', $warnings[0]);
        self::assertStringContainsString('NoSuchFile.php', $warnings[0]);
    }

    public function testParseSnippetNotFoundEmitsWarningAndSkips(): void {
        $this->writeFile('Foo.php', "<?php\necho 'hello';\n");
        $xml = $this->buildXml([
            'Foo.php' => ['PossiblyNullReference' => ['$obj->method()']],
        ]);

        $issues = $this->parser->parseXml($xml, $this->tmpDir);

        self::assertCount(0, $issues);
        $warnings = $this->parser->getWarnings();
        self::assertCount(1, $warnings);
        self::assertStringContainsString('Snippet not found', $warnings[0]);
        self::assertStringContainsString('$obj->method()', $warnings[0]);
    }

    public function testParseMalformedXmlThrows(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid Psalm baseline XML');
        $this->parser->parseXml('<not really xml', $this->tmpDir);
    }

    public function testParseWrongRootElementThrows(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expected root <files>');
        $this->parser->parseXml('<?xml version="1.0"?><root></root>', $this->tmpDir);
    }

    public function testParseFromFile(): void {
        $phpPath = $this->writeFile('Foo.php', "<?php\n\$obj->method();\n");
        $xml = $this->buildXml([
            'Foo.php' => ['PossiblyNullReference' => ['$obj->method()']],
        ]);
        $xmlPath = $this->writeFile('psalm-baseline.xml', $xml);

        $issues = $this->parser->parse($xmlPath);

        self::assertCount(1, $issues);
        self::assertSame($phpPath, $issues[0]->getFilePath());
        self::assertSame(2, $issues[0]->getLineFrom());
    }

    public function testExactMatchBeatsSubstringMatch(): void {
        // Line 2 contains the snippet as part of a larger expression;
        // line 4 contains it exactly. The resolver should prefer line 4.
        $this->writeFile('Foo.php', "<?php\n\$result = \$obj->method() + 1;\n\$other = 2;\n\$obj->method();\n");
        $xml = $this->buildXml([
            'Foo.php' => ['PossiblyNullReference' => ['$obj->method();']],
        ]);

        $issues = $this->parser->parseXml($xml, $this->tmpDir);

        self::assertCount(1, $issues);
        // Trimmed `$obj->method();` matches line 4 exactly. Line 2 is the
        // substring-fallback, which should not win when an exact match exists.
        self::assertSame(4, $issues[0]->getLineFrom());
    }

    public function testParseSnippetWithLeadingIndentation(): void {
        $this->writeFile('Foo.php', "<?php\nclass Foo {\n    public function run(): void {\n        return \$obj->method();\n    }\n}\n");
        $xml = $this->buildXml([
            'Foo.php' => ['PossiblyNullReference' => ['$obj->method()']],
        ]);

        $issues = $this->parser->parseXml($xml, $this->tmpDir);

        self::assertCount(1, $issues);
        self::assertSame(4, $issues[0]->getLineFrom());
    }

    public function testParseDecodesXmlEntitiesInSnippet(): void {
        $this->writeFile('Foo.php', "<?php\n\$obj->method();\n");
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<files><file src="Foo.php">'
            . '<PossiblyNullReference occurrences="1">'
            . '<code>$obj-&gt;method()</code>'
            . '</PossiblyNullReference>'
            . '</file></files>';

        $issues = $this->parser->parseXml($xml, $this->tmpDir);

        self::assertCount(1, $issues);
        self::assertSame(2, $issues[0]->getLineFrom());
    }

    public function testParseHandlesCdataSnippets(): void {
        // Psalm's real baseline output wraps <code> in CDATA.
        $this->writeFile('Foo.php', "<?php\npublic function enterNode(Node \$node): ?int {\n");
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<files psalm-version="6.15.1">'
            . '<file src="Foo.php">'
            . '<MissingOverrideAttribute>'
            . '<code><![CDATA[public function enterNode(Node $node): ?int {]]></code>'
            . '</MissingOverrideAttribute>'
            . '</file></files>';

        $issues = $this->parser->parseXml($xml, $this->tmpDir);

        self::assertCount(1, $issues);
        self::assertSame('MissingOverrideAttribute', $issues[0]->getType());
        self::assertSame(2, $issues[0]->getLineFrom());
    }

    public function testInjectedFileReaderIsUsed(): void {
        $reader = static function (string $path): ?string {
            if (str_ends_with($path, 'Virtual.php')) {
                return "<?php\n\$obj->method();\n";
            }
            return null;
        };
        $parser = new PsalmBaselineParser($reader);

        $xml = $this->buildXml([
            'Virtual.php' => ['PossiblyNullReference' => ['$obj->method()']],
        ]);

        $issues = $parser->parseXml($xml, $this->tmpDir);

        self::assertCount(1, $issues);
        self::assertSame(2, $issues[0]->getLineFrom());
    }

    /**
     * @param array<string, array<string, list<string>>> $files
     */
    private function buildXml(array $files): string {
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<files>' . "\n";
        foreach ($files as $src => $issues) {
            $out .= '  <file src="' . htmlspecialchars($src, ENT_XML1) . '">' . "\n";
            foreach ($issues as $type => $snippets) {
                $out .= '    <' . $type . ' occurrences="' . count($snippets) . '">' . "\n";
                foreach ($snippets as $snippet) {
                    $out .= '      <code>' . htmlspecialchars($snippet, ENT_XML1) . '</code>' . "\n";
                }
                $out .= '    </' . $type . '>' . "\n";
            }
            $out .= '  </file>' . "\n";
        }
        $out .= '</files>' . "\n";

        return $out;
    }

    private function writeFile(string $name, string $content): string {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $content);
        $this->tmpPaths[] = $path;

        return realpath($path) ?: $path;
    }

    private function removeDir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
