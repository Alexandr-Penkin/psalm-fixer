<?php

declare(strict_types=1);

namespace PsalmFixer\Tests\Unit\Parser;

use PHPUnit\Framework\TestCase;
use PsalmFixer\Parser\PsalmOutputParser;

final class PsalmOutputParserTest extends TestCase {
    private PsalmOutputParser $parser;

    protected function setUp(): void {
        $this->parser = new PsalmOutputParser();
    }

    public function testParseValidJson(): void {
        $json = json_encode([
            [
                'type' => 'PossiblyNullReference',
                'message' => 'Cannot call method on possibly null value',
                'file_path' => '/tmp/test.php',
                'line_from' => 10,
                'line_to' => 10,
                'column_from' => 5,
                'column_to' => 20,
                'snippet' => '$obj->method()',
                'severity' => 'error',
            ],
        ], JSON_THROW_ON_ERROR);

        $issues = $this->parser->parseJson($json);

        self::assertCount(1, $issues);
        self::assertSame('PossiblyNullReference', $issues[0]->getType());
        self::assertSame('/tmp/test.php', $issues[0]->getFilePath());
        self::assertSame(10, $issues[0]->getLineFrom());
        self::assertSame('error', $issues[0]->getSeverity());
    }

    public function testParseMultipleIssues(): void {
        $json = json_encode([
            [
                'type' => 'RedundantCast',
                'message' => 'Redundant cast',
                'file_path' => '/tmp/a.php',
                'line_from' => 5,
                'line_to' => 5,
                'severity' => 'error',
            ],
            [
                'type' => 'MixedArgument',
                'message' => 'Mixed argument',
                'file_path' => '/tmp/b.php',
                'line_from' => 15,
                'line_to' => 15,
                'severity' => 'error',
            ],
        ], JSON_THROW_ON_ERROR);

        $issues = $this->parser->parseJson($json);

        self::assertCount(2, $issues);
        self::assertSame('RedundantCast', $issues[0]->getType());
        self::assertSame('MixedArgument', $issues[1]->getType());
    }

    public function testParseSkipsInvalidEntries(): void {
        $json = json_encode([
            ['type' => '', 'message' => 'test', 'file_path' => '/tmp/a.php', 'line_from' => 1, 'line_to' => 1],
            ['message' => 'test', 'file_path' => '/tmp/a.php', 'line_from' => 1, 'line_to' => 1],
            'not an array',
        ], JSON_THROW_ON_ERROR);

        $issues = $this->parser->parseJson($json);

        self::assertCount(0, $issues);
    }

    public function testParseInvalidJsonThrows(): void {
        $this->expectException(\RuntimeException::class);
        $this->parser->parseJson('not json');
    }

    public function testParseFromFile(): void {
        $tmpFile = tempnam(sys_get_temp_dir(), 'psalm_test_');
        self::assertNotFalse($tmpFile);

        $json = json_encode([
            [
                'type' => 'RedundantCast',
                'message' => 'Redundant (int) cast',
                'file_path' => '/tmp/test.php',
                'line_from' => 3,
                'line_to' => 3,
                'severity' => 'error',
            ],
        ], JSON_THROW_ON_ERROR);

        file_put_contents($tmpFile, $json);

        $issues = $this->parser->parse($tmpFile);

        self::assertCount(1, $issues);
        self::assertSame('RedundantCast', $issues[0]->getType());

        unlink($tmpFile);
    }
}
