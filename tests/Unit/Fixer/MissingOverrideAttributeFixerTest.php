<?php

declare(strict_types=1);

namespace PsalmFixer\Tests\Unit\Fixer;

use PHPUnit\Framework\TestCase;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PsalmFixer\Fixer\ClassDesign\MissingOverrideAttributeFixer;
use PsalmFixer\Parser\PsalmIssue;

final class MissingOverrideAttributeFixerTest extends TestCase {
    private MissingOverrideAttributeFixer $fixer;

    protected function setUp(): void {
        $this->fixer = new MissingOverrideAttributeFixer();
    }

    public function testFixAddsOverrideAttribute(): void {
        $code = <<<'PHP'
<?php
class Child extends Parent_ {
    public function doSomething(): void {
    }
}
PHP;

        $issue = new PsalmIssue(
            type: 'MissingOverrideAttribute',
            message: 'Method doSomething should have the #[\\Override] attribute',
            filePath: '/tmp/test.php',
            lineFrom: 3,
            lineTo: 4,
            columnFrom: 5,
            columnTo: 5,
            snippet: 'public function doSomething(): void {',
            severity: 'error',
        );

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        $result = $this->fixer->fix($issue, $stmts);
        self::assertTrue($result->isFixed());

        $printer = new Standard();
        $output = $printer->prettyPrintFile($stmts);
        self::assertStringContainsString('#[\\Override]', $output);
    }
}
