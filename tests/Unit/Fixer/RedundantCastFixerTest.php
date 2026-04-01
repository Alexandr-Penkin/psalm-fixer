<?php

declare(strict_types=1);

namespace PsalmFixer\Tests\Unit\Fixer;

use PHPUnit\Framework\TestCase;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PsalmFixer\Fixer\CodeQuality\RedundantCastFixer;
use PsalmFixer\Parser\PsalmIssue;

final class RedundantCastFixerTest extends TestCase {
    private RedundantCastFixer $fixer;

    protected function setUp(): void {
        $this->fixer = new RedundantCastFixer();
    }

    public function testGetSupportedTypes(): void {
        $types = $this->fixer->getSupportedTypes();
        self::assertContains('RedundantCast', $types);
    }

    public function testFixRemovesIntCast(): void {
        $code = '<?php $x = (int)$alreadyInt;';
        $issue = new PsalmIssue(
            type: 'RedundantCast',
            message: 'Redundant (int) cast',
            filePath: '/tmp/test.php',
            lineFrom: 1,
            lineTo: 1,
            columnFrom: 6,
            columnTo: 25,
            snippet: '(int)$alreadyInt',
            severity: 'error',
        );

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        $result = $this->fixer->fix($issue, $stmts);
        self::assertTrue($result->isFixed());

        $printer = new Standard();
        $output = $printer->prettyPrintFile($stmts);
        self::assertStringContainsString('$x = $alreadyInt', $output);
        self::assertStringNotContainsString('(int)', $output);
    }

    public function testFixRemovesStringCast(): void {
        $code = '<?php $y = (string)$alreadyString;';
        $issue = new PsalmIssue(
            type: 'RedundantCast',
            message: 'Redundant (string) cast',
            filePath: '/tmp/test.php',
            lineFrom: 1,
            lineTo: 1,
            columnFrom: 6,
            columnTo: 30,
            snippet: '(string)$alreadyString',
            severity: 'error',
        );

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        $result = $this->fixer->fix($issue, $stmts);
        self::assertTrue($result->isFixed());

        $printer = new Standard();
        $output = $printer->prettyPrintFile($stmts);
        self::assertStringContainsString('$y = $alreadyString', $output);
        self::assertStringNotContainsString('(string)', $output);
    }
}
