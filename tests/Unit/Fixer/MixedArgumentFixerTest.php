<?php

declare(strict_types=1);

namespace PsalmFixer\Tests\Unit\Fixer;

use PHPUnit\Framework\TestCase;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PsalmFixer\Fixer\Mixed\MixedArgumentFixer;
use PsalmFixer\Parser\PsalmIssue;

final class MixedArgumentFixerTest extends TestCase
{
    private MixedArgumentFixer $fixer;
    private Standard $printer;

    protected function setUp(): void
    {
        $this->fixer = new MixedArgumentFixer();
        $this->printer = new Standard();
    }

    public function testDegradesGenericArrayToIsArrayAssert(): void
    {
        // Expected type `array<string, string>` can't be checked at runtime;
        // fixer must degrade to `is_array($var)`.
        $code = "<?php\nfunction call(\$value): void {\n    fieldIdsToNames(\$value);\n}\n";
        $issue = new PsalmIssue(
            type: 'MixedArgument',
            message: 'Argument 2 of fieldIdsToNames cannot be array<string, string>|mixed, expecting array<string, string>',
            filePath: '/tmp/t.php',
            lineFrom: 3,
            lineTo: 3,
            columnFrom: 0,
            columnTo: 0,
            snippet: 'fieldIdsToNames($value)',
            severity: 'error',
        );

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('assert(is_array($value))', $output);
    }

    public function testDegradesListShapeToIsArrayAssert(): void
    {
        $code = "<?php\nfunction call(\$items): void {\n    process(\$items);\n}\n";
        $issue = new PsalmIssue(
            type: 'MixedArgument',
            message: 'Argument 1 of process cannot be mixed, expecting list<int>',
            filePath: '/tmp/t.php',
            lineFrom: 3,
            lineTo: 3,
            columnFrom: 0,
            columnTo: 0,
            snippet: 'process($items)',
            severity: 'error',
        );

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('assert(is_array($items))', $output);
    }

    public function testDegradesArrayShapeToIsArrayAssert(): void
    {
        $code = "<?php\nfunction call(\$row): void {\n    consume(\$row);\n}\n";
        $issue = new PsalmIssue(
            type: 'MixedArgument',
            message: 'Argument 1 of consume cannot be mixed, expecting array<array-key, mixed>',
            filePath: '/tmp/t.php',
            lineFrom: 3,
            lineTo: 3,
            columnFrom: 0,
            columnTo: 0,
            snippet: 'consume($row)',
            severity: 'error',
        );

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('assert(is_array($row))', $output);
    }

    private function runFixer(string $code, PsalmIssue $issue): string
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        $result = $this->fixer->fix($issue, $stmts);
        self::assertTrue($result->isFixed(), 'Expected fix to succeed: ' . ($result->getDescription() ?? ''));

        return $this->printer->prettyPrintFile($stmts);
    }
}
