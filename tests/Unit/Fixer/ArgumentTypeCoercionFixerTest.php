<?php

declare(strict_types=1);

namespace PsalmFixer\Tests\Unit\Fixer;

use PHPUnit\Framework\TestCase;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PsalmFixer\Fixer\TypeSafety\ArgumentTypeCoercionFixer;
use PsalmFixer\Parser\PsalmIssue;

final class ArgumentTypeCoercionFixerTest extends TestCase {
    private ArgumentTypeCoercionFixer $fixer;
    private Standard $printer;

    protected function setUp(): void {
        $this->fixer = new ArgumentTypeCoercionFixer();
        $this->printer = new Standard();
    }

    public function testInsertsInstanceofAssertWhenMessageNamesVariable(): void {
        $code = "<?php\n\$obj->method(\$value);\n";
        $issue = $this->makeIssue(2, 'Argument 1 of Foo::method expects Bar, $value provided');

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('assert($value instanceof', $output);
    }

    public function testInsertsScalarAssertForBuiltinType(): void {
        $code = "<?php\n\$obj->method(\$value);\n";
        $issue = $this->makeIssue(2, 'Argument 1 of Foo::method expects int, $value provided');

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('assert(is_int($value))', $output);
    }

    public function testInsertsNonEmptyStringAssertViaArgIndex(): void {
        // Message lacks `$var` — fixer must resolve the variable by walking to
        // the call at the issue line and reading argument N.
        $code = "<?php\n\$this->typeParser->getIsTypeFunction(\$type);\n";
        $issue = $this->makeIssue(2, 'Argument 1 of TypeStringParser::getIsTypeFunction expects non-empty-string, but parent type string provided');

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString("assert(\$type !== '')", $output);
    }

    public function testNonEmptyStringAssertExplicitVarInMessage(): void {
        $code = "<?php\nfoo(\$type);\n";
        $issue = $this->makeIssue(2, 'Argument 1 of foo expects non-empty-string, $type provided');

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString("assert(\$type !== '')", $output);
    }

    public function testFallsBackToSuppressForGenericType(): void {
        // `list<X>` cannot be safely asserted at runtime → suppress on the call.
        $code = "<?php\n\$processor->processIssues(\$issues, \$dryRun, \$filter);\n";
        $issue = $this->makeIssue(2, 'Argument 3 of Foo::processIssues expects list<non-empty-string>|null, but parent type array<int<0, max>, non-empty-string>|null provided');

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('@psalm-suppress ArgumentTypeCoercion', $output);
        // Original call preserved.
        self::assertStringContainsString('processIssues($issues, $dryRun, $filter)', $output);
    }

    public function testFallbackSuppressMergesIntoExistingDocblock(): void {
        $code = "<?php\n/** @var int \$x */\n\$obj->call(\$x);\n";
        $issue = $this->makeIssue(3, 'Argument 1 of Foo::call expects list<int>, list<mixed> provided');

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('@psalm-suppress ArgumentTypeCoercion', $output);
        self::assertStringContainsString('@var int $x', $output);
    }

    public function testFallbackSuppressIsIdempotent(): void {
        $code = "<?php\n/** @psalm-suppress ArgumentTypeCoercion */\n\$obj->call(\$x);\n";
        $issue = $this->makeIssue(3, 'Argument 1 of Foo::call expects list<int>, list<mixed> provided');

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        $result = $this->fixer->fix($issue, $stmts);

        self::assertFalse($result->isFixed());
        self::assertSame('Statement already has the suppress annotation', $result->getDescription());
    }

    public function testArgIndexResolutionPicksCorrectArgument(): void {
        // Argument 3 is `$third` — fixer must pick the right slot, not the first.
        $code = "<?php\nfoo(\$first, \$second, \$third);\n";
        $issue = $this->makeIssue(2, 'Argument 3 of foo expects non-empty-string, but parent type string provided');

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString("assert(\$third !== '')", $output);
        self::assertStringNotContainsString("assert(\$first", $output);
    }

    public function testFallsBackWhenArgumentIsComplexExpression(): void {
        // Argument is a method call, not a Variable — can't safely assert against
        // a sub-expression, so fall back to suppress.
        $code = "<?php\nfoo(\$obj->method());\n";
        $issue = $this->makeIssue(2, 'Argument 1 of foo expects non-empty-string, but parent type string provided');

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('@psalm-suppress ArgumentTypeCoercion', $output);
    }

    private function makeIssue(int $line, string $message): PsalmIssue {
        return new PsalmIssue(
            type: 'ArgumentTypeCoercion',
            message: $message,
            filePath: '/tmp/test.php',
            lineFrom: $line,
            lineTo: $line,
            columnFrom: 0,
            columnTo: 0,
            snippet: null,
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
