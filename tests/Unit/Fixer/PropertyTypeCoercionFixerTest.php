<?php

declare(strict_types=1);

namespace PsalmFixer\Tests\Unit\Fixer;

use PHPUnit\Framework\TestCase;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PsalmFixer\Fixer\TypeSafety\PropertyTypeCoercionFixer;
use PsalmFixer\Parser\PsalmIssue;

final class PropertyTypeCoercionFixerTest extends TestCase {
    private PropertyTypeCoercionFixer $fixer;
    private Standard $printer;

    protected function setUp(): void {
        $this->fixer = new PropertyTypeCoercionFixer();
        $this->printer = new Standard();
    }

    public function testAddsSuppressTagOnUndocumentedAssignment(): void {
        $code = "<?php\n\$obj->prop = \$value;\n";
        $issue = $this->makeIssue(2);

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('@psalm-suppress PropertyTypeCoercion', $output);
        self::assertStringContainsString('$obj->prop = $value', $output);
    }

    public function testMergesIntoExistingDocblock(): void {
        $code = "<?php\n/** @var string \$value */\n\$obj->prop = \$value;\n";
        $issue = $this->makeIssue(3);

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('@psalm-suppress PropertyTypeCoercion', $output);
        self::assertStringContainsString('@var string $value', $output);
    }

    public function testDoesNotDuplicateExistingSuppress(): void {
        $code = "<?php\n/** @psalm-suppress PropertyTypeCoercion */\n\$obj->prop = \$value;\n";
        $issue = $this->makeIssue(3);

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        $result = $this->fixer->fix($issue, $stmts);

        self::assertFalse($result->isFixed());
        self::assertSame('Statement already has the suppress annotation', $result->getDescription());
    }

    public function testReturnsNotFixedWhenNoStatementAtLine(): void {
        $code = "<?php\n\$x = 1;\n\$y = 2;\n";
        $issue = $this->makeIssue(99);

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        $result = $this->fixer->fix($issue, $stmts);

        self::assertFalse($result->isFixed());
    }

    public function testGetSupportedTypes(): void {
        self::assertSame(['PropertyTypeCoercion'], $this->fixer->getSupportedTypes());
    }

    public function testWorksOnNestedAssignmentInMethod(): void {
        $code = <<<'PHP'
<?php
class Foo {
    public function bar(): void {
        $this->prop = $value;
    }
}
PHP;
        $issue = $this->makeIssue(4);

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('@psalm-suppress PropertyTypeCoercion', $output);
    }

    private function makeIssue(int $line): PsalmIssue {
        return new PsalmIssue(
            type: 'PropertyTypeCoercion',
            message: '$obj->prop expects X, parent type Y provided',
            filePath: '/tmp/test.php',
            lineFrom: $line,
            lineTo: $line,
            columnFrom: 0,
            columnTo: 0,
            snippet: '$obj->prop = $value;',
            severity: 'error',
        );
    }

    private function runFixer(string $code, PsalmIssue $issue): string {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        $result = $this->fixer->fix($issue, $stmts);
        self::assertTrue($result->isFixed(), 'Expected fix to succeed: ' . ($result->getDescription() ?? ''));

        return $this->printer->prettyPrintFile($stmts);
    }
}
