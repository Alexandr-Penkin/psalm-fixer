<?php

declare(strict_types=1);

namespace PsalmFixer\Tests\Unit\Fixer;

use PHPUnit\Framework\TestCase;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PsalmFixer\Fixer\Mixed\MixedArgumentFixer;
use PsalmFixer\Parser\PsalmIssue;

final class NamespaceInsertionTest extends TestCase {
    public function testAssertInsertedInsideMethodNotBeforeNamespace(): void {
        $code = <<<'PHP'
<?php

namespace App\Service;

final class Calculator {
    public function compute(mixed $data): int {
        return strlen($data);
    }
}
PHP;

        $issue = new PsalmIssue(
            type: 'MixedArgument',
            message: 'Argument 1 of strlen expects string, but $data provided',
            filePath: '/tmp/test.php',
            lineFrom: 7,
            lineTo: 7,
            columnFrom: 0,
            columnTo: 0,
            snippet: 'return strlen($data);',
            severity: 'error',
        );

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        $fixer = new MixedArgumentFixer();
        $result = $fixer->fix($issue, $stmts);
        self::assertTrue($result->isFixed());

        $printer = new Standard();
        $output = $printer->prettyPrintFile($stmts);

        // assert must be inside the method, after namespace declaration
        $namespacePos = strpos($output, 'namespace App\\Service;');
        $assertPos = strpos($output, 'assert(');
        self::assertNotFalse($namespacePos);
        self::assertNotFalse($assertPos);
        self::assertGreaterThan($namespacePos, $assertPos, 'assert() must appear after namespace declaration');

        // assert must be inside the function body
        $functionPos = strpos($output, 'public function compute');
        self::assertNotFalse($functionPos);
        self::assertGreaterThan($functionPos, $assertPos, 'assert() must appear inside the method body');
    }
}
