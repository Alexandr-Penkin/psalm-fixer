<?php

declare(strict_types=1);

namespace PsalmFixer\Tests\Unit\Fixer;

use PHPUnit\Framework\TestCase;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PsalmFixer\Fixer\NullSafety\PossiblyNullReferenceFixer;
use PsalmFixer\Fixer\NullSafety\PossiblyNullPropertyFetchFixer;
use PsalmFixer\Parser\PsalmIssue;

final class NullSafetyFixerTest extends TestCase {
    public function testPossiblyNullReferenceConvertsToNullSafe(): void {
        $code = <<<'PHP'
<?php
function test(?object $obj) {
    $obj->doSomething();
}
PHP;

        $issue = new PsalmIssue(
            type: 'PossiblyNullReference',
            message: 'Cannot call method doSomething on possibly null value',
            filePath: '/tmp/test.php',
            lineFrom: 3,
            lineTo: 3,
            columnFrom: 5,
            columnTo: 25,
            snippet: '$obj->doSomething()',
            severity: 'error',
        );

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        $fixer = new PossiblyNullReferenceFixer();
        $result = $fixer->fix($issue, $stmts);
        self::assertTrue($result->isFixed());

        $printer = new Standard();
        $output = $printer->prettyPrintFile($stmts);
        self::assertStringContainsString('$obj?->doSomething()', $output);
    }

    public function testPossiblyNullPropertyFetchConvertsToNullSafe(): void {
        $code = <<<'PHP'
<?php
function test(?object $obj) {
    $x = $obj->name;
}
PHP;

        $issue = new PsalmIssue(
            type: 'PossiblyNullPropertyFetch',
            message: 'Cannot access property on possibly null value',
            filePath: '/tmp/test.php',
            lineFrom: 3,
            lineTo: 3,
            columnFrom: 10,
            columnTo: 20,
            snippet: '$obj->name',
            severity: 'error',
        );

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        $fixer = new PossiblyNullPropertyFetchFixer();
        $result = $fixer->fix($issue, $stmts);
        self::assertTrue($result->isFixed());

        $printer = new Standard();
        $output = $printer->prettyPrintFile($stmts);
        self::assertStringContainsString('$obj?->name', $output);
    }
}
