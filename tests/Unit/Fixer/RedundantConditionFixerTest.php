<?php

declare(strict_types=1);

namespace PsalmFixer\Tests\Unit\Fixer;

use PHPUnit\Framework\TestCase;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PsalmFixer\Fixer\TypeSafety\RedundantConditionFixer;
use PsalmFixer\Parser\PsalmIssue;

final class RedundantConditionFixerTest extends TestCase {
    private RedundantConditionFixer $fixer;
    private Standard $printer;

    protected function setUp(): void {
        $this->fixer = new RedundantConditionFixer();
        $this->printer = new Standard();
    }

    public function testFixesAlwaysTrueMessage(): void {
        $code = "<?php\nif (\$x === 1) {\n    return 1;\n}\n";
        $issue = $this->makeIssue('Type for $x is always true', 2);

        $output = $this->runFixer($code, $issue);

        self::assertStringNotContainsString('if (', $output);
        self::assertStringContainsString('return 1', $output);
    }

    public function testFixesAlwaysFalseMessage(): void {
        $code = "<?php\nif (\$x === 1) {\n    return 1;\n} else {\n    return 2;\n}\n";
        $issue = $this->makeIssue('Type for $x is always false', 2);

        $output = $this->runFixer($code, $issue);

        self::assertStringNotContainsString('if (', $output);
        self::assertStringContainsString('return 2', $output);
        self::assertStringNotContainsString('return 1', $output);
    }

    public function testFixesCanNeverContainWithNotIdentical(): void {
        // Psalm phrasing: "'' can never contain non-empty-string" with `$name !== ''`
        // → always true → unwrap.
        $code = "<?php\nif (\$name !== '') {\n    return \$name;\n}\n";
        $issue = $this->makeIssue("'' can never contain non-empty-string", 2);

        $output = $this->runFixer($code, $issue);

        self::assertStringNotContainsString('if (', $output);
        self::assertStringContainsString('return $name', $output);
    }

    public function testFixesCanNeverContainWithIdentical(): void {
        // Same phrasing but with === — the comparison can never be true → always false.
        $code = "<?php\nif (\$name === '') {\n    return 'empty';\n} else {\n    return \$name;\n}\n";
        $issue = $this->makeIssue("'' can never contain non-empty-string", 2);

        $output = $this->runFixer($code, $issue);

        self::assertStringNotContainsString('if (', $output);
        self::assertStringContainsString("return \$name", $output);
        self::assertStringNotContainsString("'empty'", $output);
    }

    public function testFixesIsNeverNullWithNotIdentical(): void {
        $code = "<?php\nif (\$x !== null) {\n    return \$x;\n}\n";
        $issue = $this->makeIssue('Type for $x is never null', 2);

        $output = $this->runFixer($code, $issue);

        self::assertStringNotContainsString('if (', $output);
        self::assertStringContainsString('return $x', $output);
    }

    public function testAstOnlyLiteralTrue(): void {
        // No message direction hints — only AST detects the literal.
        // This is the path that makes the fixer work with baseline input.
        $code = "<?php\nif (true) {\n    return 1;\n}\n";
        $issue = $this->makeIssue('From baseline: RedundantCondition', 2);

        $output = $this->runFixer($code, $issue);

        self::assertStringNotContainsString('if (', $output);
        self::assertStringContainsString('return 1', $output);
    }

    public function testAstOnlyLiteralFalse(): void {
        $code = "<?php\nif (false) {\n    return 1;\n} else {\n    return 2;\n}\n";
        $issue = $this->makeIssue('From baseline: RedundantCondition', 2);

        $output = $this->runFixer($code, $issue);

        self::assertStringNotContainsString('if (', $output);
        self::assertStringContainsString('return 2', $output);
        self::assertStringNotContainsString('return 1', $output);
    }

    public function testStripsRedundantEmptyStringOperandFromAndChain(): void {
        $code = "<?php\nif (\$a instanceof Foo && \$x !== '') {\n    return \$x;\n}\n";
        $issue = $this->makeIssue("'' can never contain non-empty-string", 2);

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('if ($a instanceof Foo) {', $output);
        self::assertStringNotContainsString("\$x !== ''", $output);
        self::assertStringContainsString('return $x', $output);
    }

    public function testStripsRedundantNullOperandFromAndChain(): void {
        $code = "<?php\nif (\$stmt instanceof Foo && \$stmt->prop !== null) {\n    return \$stmt;\n}\n";
        $issue = $this->makeIssue('Type X for $stmt->prop is never null', 2);

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('if ($stmt instanceof Foo) {', $output);
        self::assertStringNotContainsString('!== null', $output);
    }

    public function testStripsRedundantOperandFromMiddleOfAndChain(): void {
        $code = "<?php\nif (\$name !== '' && \$name !== 'void' && \$name !== 'never') {\n    return \$name;\n}\n";
        $issue = $this->makeIssue("'' can never contain non-empty-string", 2);

        $output = $this->runFixer($code, $issue);

        self::assertStringNotContainsString("\$name !== ''", $output);
        self::assertStringContainsString("\$name !== 'void'", $output);
        self::assertStringContainsString("\$name !== 'never'", $output);
    }

    public function testGracefullySkipsCompoundWithoutRecognizableOperand(): void {
        // Compound condition where neither operand matches a known redundancy pattern —
        // fixer must report notFixed and leave the code alone.
        $code = "<?php\nif (\$a instanceof Foo && \$b->method()) {\n    return 1;\n}\n";
        $issue = $this->makeIssue('Some unrelated redundancy message', 2);

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        $result = $this->fixer->fix($issue, $stmts);

        self::assertFalse($result->isFixed());
    }

    public function testGracefullySkipsAmbiguousMessageWithComplexCondition(): void {
        $code = "<?php\nif (\$obj->method()) {\n    return 1;\n}\n";
        $issue = $this->makeIssue('From baseline: RedundantCondition', 2);

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        $result = $this->fixer->fix($issue, $stmts);

        self::assertFalse($result->isFixed());
    }

    public function testFallsBackToSuppressForDocblockGivenWithoutIf(): void {
        // `RedundantConditionGivenDocblockType` raised on a non-if expression —
        // e.g. an array access where the docblock claims a tighter type. No
        // condition to rewrite, fall back to @psalm-suppress.
        $code = "<?php\n\$name = \$fields['Status']['name'];\n";
        $issue = new PsalmIssue(
            type: 'RedundantConditionGivenDocblockType',
            message: "Docblock-defined type array{name: string} for \$fields['Status'] is always array<array-key, mixed>",
            filePath: '/tmp/t.php',
            lineFrom: 2,
            lineTo: 2,
            columnFrom: 0,
            columnTo: 0,
            snippet: null,
            severity: 'error',
        );

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        $result = $this->fixer->fix($issue, $stmts);
        self::assertTrue($result->isFixed(), 'Expected suppress fallback to succeed: ' . ($result->getDescription() ?? ''));

        $output = $this->printer->prettyPrintFile($stmts);
        self::assertStringContainsString('@psalm-suppress RedundantConditionGivenDocblockType', $output);
        self::assertStringContainsString("\$fields['Status']['name']", $output);
    }

    public function testPlainRedundantConditionStillRefusesSuppressOnArbitraryStatement(): void {
        // Plain RedundantCondition (not docblock-given) on a non-if, non-assert
        // statement — fixer must still refuse to avoid attaching a suppress to
        // whatever happens to be at the line.
        $code = "<?php\n\$x = 1;\n";
        $issue = new PsalmIssue(
            type: 'RedundantCondition',
            message: 'Some redundancy on a non-if',
            filePath: '/tmp/t.php',
            lineFrom: 2,
            lineTo: 2,
            columnFrom: 0,
            columnTo: 0,
            snippet: null,
            severity: 'error',
        );

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        $result = $this->fixer->fix($issue, $stmts);
        self::assertFalse($result->isFixed());
    }

    private function makeIssue(string $message, int $line): PsalmIssue {
        return new PsalmIssue(
            type: 'RedundantCondition',
            message: $message,
            filePath: '/tmp/test.php',
            lineFrom: $line,
            lineTo: $line,
            columnFrom: 0,
            columnTo: 0,
            snippet: 'if (...)',
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
