<?php

declare(strict_types=1);

namespace PsalmFixer\Tests\Unit\Fixer;

use PHPUnit\Framework\TestCase;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PsalmFixer\Fixer\Suppress\LiteralKeyUnshapedArrayFixer;
use PsalmFixer\Parser\PsalmIssue;

final class LiteralKeyUnshapedArrayFixerTest extends TestCase
{
    private LiteralKeyUnshapedArrayFixer $fixer;
    private Standard $printer;

    protected function setUp(): void
    {
        $this->fixer = new LiteralKeyUnshapedArrayFixer();
        $this->printer = new Standard();
    }

    public function testAttachesSuppressToCoveringStatement(): void
    {
        $code = "<?php\n\$name = \$row['name'];\n";

        $output = $this->runFixer($code, $this->makeIssue(2));

        self::assertStringContainsString('@psalm-suppress LiteralKeyUnshapedArray', $output);
        self::assertStringContainsString("\$row['name']", $output);
    }

    public function testIsIdempotent(): void
    {
        $code = "<?php\n\$name = \$row['name'];\n";

        $first = $this->runFixer($code, $this->makeIssue(2));

        // Re-running the fixer over already-suppressed code must report notFixed
        // (suppress already present) and leave the docblock untouched.
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($first);
        self::assertNotNull($stmts);

        $result = $this->fixer->fix($this->makeIssue(3), $stmts);
        self::assertFalse($result->isFixed(), 'Second pass must not re-attach the same suppress');
    }

    public function testReportsSupportedType(): void
    {
        self::assertSame(['LiteralKeyUnshapedArray'], $this->fixer->getSupportedTypes());
    }

    private function makeIssue(int $line): PsalmIssue
    {
        return new PsalmIssue(
            type: 'LiteralKeyUnshapedArray',
            message: 'Literal offset string(name) was used on unshaped array array<array-key, mixed>',
            filePath: '/tmp/t.php',
            lineFrom: $line,
            lineTo: $line,
            columnFrom: 0,
            columnTo: 0,
            snippet: null,
            severity: 'error',
        );
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
