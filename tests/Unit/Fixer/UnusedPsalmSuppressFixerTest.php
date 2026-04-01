<?php

declare(strict_types=1);

namespace PsalmFixer\Tests\Unit\Fixer;

use PHPUnit\Framework\TestCase;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PsalmFixer\Fixer\Docblock\UnusedPsalmSuppressFixer;
use PsalmFixer\Parser\PsalmIssue;

final class UnusedPsalmSuppressFixerTest extends TestCase {
    private UnusedPsalmSuppressFixer $fixer;

    protected function setUp(): void {
        $this->fixer = new UnusedPsalmSuppressFixer();
    }

    public function testGetSupportedTypes(): void {
        $types = $this->fixer->getSupportedTypes();
        self::assertSame(['UnusedPsalmSuppress'], $types);
    }

    public function testFixRemovesSuppressFromDocblock(): void {
        $code = <<<'PHP'
<?php
class Foo {
    /**
     * @psalm-suppress RedundantCast
     */
    public function bar(): int {
        return 1;
    }
}
PHP;

        $issue = new PsalmIssue(
            type: 'UnusedPsalmSuppress',
            message: 'The @psalm-suppress RedundantCast annotation is unnecessary',
            filePath: '/tmp/test.php',
            lineFrom: 6,
            lineTo: 6,
            columnFrom: 0,
            columnTo: 0,
            snippet: null,
            severity: 'info',
        );

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        $result = $this->fixer->fix($issue, $stmts);
        self::assertTrue($result->isFixed());

        $printer = new Standard();
        $output = $printer->prettyPrintFile($stmts);
        self::assertStringNotContainsString('@psalm-suppress', $output);
    }

    public function testFixKeepsOtherTags(): void {
        $code = <<<'PHP'
<?php
class Foo {
    /**
     * @param int $x
     * @psalm-suppress RedundantCast
     * @return int
     */
    public function bar(int $x): int {
        return $x;
    }
}
PHP;

        $issue = new PsalmIssue(
            type: 'UnusedPsalmSuppress',
            message: 'The @psalm-suppress RedundantCast annotation is unnecessary',
            filePath: '/tmp/test.php',
            lineFrom: 8,
            lineTo: 8,
            columnFrom: 0,
            columnTo: 0,
            snippet: null,
            severity: 'info',
        );

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        $result = $this->fixer->fix($issue, $stmts);
        self::assertTrue($result->isFixed());

        $printer = new Standard();
        $output = $printer->prettyPrintFile($stmts);
        self::assertStringNotContainsString('@psalm-suppress', $output);
        self::assertStringContainsString('@param int $x', $output);
        self::assertStringContainsString('@return int', $output);
    }

    public function testFixRemovesOnlyTargetedSuppress(): void {
        $code = <<<'PHP'
<?php
class Foo {
    /**
     * @psalm-suppress RedundantCast
     * @psalm-suppress MixedAssignment
     */
    public function bar(): int {
        return 1;
    }
}
PHP;

        $issue = new PsalmIssue(
            type: 'UnusedPsalmSuppress',
            message: 'The @psalm-suppress RedundantCast annotation is unnecessary',
            filePath: '/tmp/test.php',
            lineFrom: 7,
            lineTo: 7,
            columnFrom: 0,
            columnTo: 0,
            snippet: null,
            severity: 'info',
        );

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        $result = $this->fixer->fix($issue, $stmts);
        self::assertTrue($result->isFixed());

        $printer = new Standard();
        $output = $printer->prettyPrintFile($stmts);
        self::assertStringNotContainsString('RedundantCast', $output);
        self::assertStringContainsString('@psalm-suppress MixedAssignment', $output);
    }
}
