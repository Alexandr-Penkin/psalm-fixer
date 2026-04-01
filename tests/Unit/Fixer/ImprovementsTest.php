<?php

declare(strict_types=1);

namespace PsalmFixer\Tests\Unit\Fixer;

use PHPUnit\Framework\TestCase;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PsalmFixer\Ast\TypeStringParser;
use PsalmFixer\Fixer\ClassDesign\PropertyNotSetInConstructorFixer;
use PsalmFixer\Fixer\Mixed\MixedArgumentFixer;
use PsalmFixer\Fixer\Mixed\MixedAssignmentFixer;
use PsalmFixer\Fixer\Mixed\MixedReturnStatementFixer;
use PsalmFixer\Fixer\NullSafety\PossiblyNullArgumentFixer;
use PsalmFixer\Fixer\TypeSafety\RedundantConditionFixer;
use PsalmFixer\Fixer\TypeSafety\TypeDoesNotContainNullFixer;
use PsalmFixer\Parser\PsalmIssue;

final class ImprovementsTest extends TestCase {
    private function parse(string $code): array {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        return $stmts;
    }

    private function print(array $stmts): string {
        return (new Standard())->prettyPrintFile($stmts);
    }

    // --- TypeStringParser improvements ---

    public function testExtractExpectedTypeExpectingFormat(): void {
        $parser = new TypeStringParser();
        $type = $parser->extractExpectedType('Argument 1 of strlen cannot be mixed, expecting string');
        self::assertSame('string', $type);
    }

    public function testExtractExpectedTypeGenericFormat(): void {
        $parser = new TypeStringParser();
        $type = $parser->extractExpectedType('expects array<array-key, mixed>, list<string> provided');
        self::assertSame('array<array-key, mixed>', $type);
    }

    public function testExtractExpectedTypeShouldBeFormat(): void {
        $parser = new TypeStringParser();
        $type = $parser->extractExpectedType('Return value should be int');
        self::assertSame('int', $type);
    }

    // --- MixedArgument with "expecting" format ---

    public function testMixedArgumentWithExpectingFormat(): void {
        $code = <<<'PHP'
<?php
function test(mixed $data): void {
    strlen($data);
}
PHP;
        $stmts = $this->parse($code);
        $fixer = new MixedArgumentFixer();

        $result = $fixer->fix(new PsalmIssue(
            type: 'MixedArgument',
            message: 'Argument 1 of strlen cannot be mixed, expecting string',
            filePath: '/tmp/test.php',
            lineFrom: 3,
            lineTo: 3,
            columnFrom: 0,
            columnTo: 0,
            snippet: 'strlen($data)',
            severity: 'error',
        ), $stmts);

        self::assertTrue($result->isFixed());
        $output = $this->print($stmts);
        self::assertStringContainsString('assert(is_string($data))', $output);
    }

    // --- RedundantCondition always-false ---

    public function testRedundantConditionAlwaysFalse(): void {
        $code = <<<'PHP'
<?php
function test(int $x): int {
    if ($x === null) {
        return 0;
    } else {
        return $x;
    }
}
PHP;
        $stmts = $this->parse($code);
        $fixer = new RedundantConditionFixer();

        $result = $fixer->fix(new PsalmIssue(
            type: 'RedundantCondition',
            message: 'Type int for $x is always false',
            filePath: '/tmp/test.php',
            lineFrom: 3,
            lineTo: 3,
            columnFrom: 0,
            columnTo: 0,
            snippet: null,
            severity: 'error',
        ), $stmts);

        self::assertTrue($result->isFixed());
        $output = $this->print($stmts);
        self::assertStringNotContainsString('=== null', $output);
        self::assertStringContainsString('return $x', $output);
    }

    // --- RedundantCondition in namespace ---

    public function testRedundantConditionInNamespace(): void {
        $code = <<<'PHP'
<?php
namespace App;

class Foo {
    public function bar(bool $x): void {
        if ($x) {
            echo 'yes';
        }
    }
}
PHP;
        $stmts = $this->parse($code);
        $fixer = new RedundantConditionFixer();

        $result = $fixer->fix(new PsalmIssue(
            type: 'RedundantCondition',
            message: 'Type true for $x is always true',
            filePath: '/tmp/test.php',
            lineFrom: 6,
            lineTo: 6,
            columnFrom: 0,
            columnTo: 0,
            snippet: null,
            severity: 'error',
        ), $stmts);

        self::assertTrue($result->isFixed());
        $output = $this->print($stmts);
        self::assertStringNotContainsString('if ($x)', $output);
        self::assertStringContainsString("echo 'yes'", $output);
    }

    // --- TypeDoesNotContainNull in namespace ---

    public function testTypeDoesNotContainNullInNamespace(): void {
        $code = <<<'PHP'
<?php
namespace App;

class Foo {
    public function bar(int $x): int {
        if ($x === null) {
            return 0;
        }
        return $x;
    }
}
PHP;
        $stmts = $this->parse($code);
        $fixer = new TypeDoesNotContainNullFixer();

        $result = $fixer->fix(new PsalmIssue(
            type: 'TypeDoesNotContainNull',
            message: 'Type int does not contain null',
            filePath: '/tmp/test.php',
            lineFrom: 6,
            lineTo: 6,
            columnFrom: 0,
            columnTo: 0,
            snippet: null,
            severity: 'error',
        ), $stmts);

        self::assertTrue($result->isFixed());
        $output = $this->print($stmts);
        self::assertStringNotContainsString('=== null', $output);
    }

    // --- MixedReturnStatement fallback to method return type ---

    public function testMixedReturnStatementFallbackToReturnType(): void {
        $code = <<<'PHP'
<?php
class Foo {
    public function bar(mixed $data): int {
        return $data;
    }
}
PHP;
        $stmts = $this->parse($code);
        $fixer = new MixedReturnStatementFixer();

        $result = $fixer->fix(new PsalmIssue(
            type: 'MixedReturnStatement',
            message: 'Could not infer a return type',
            filePath: '/tmp/test.php',
            lineFrom: 4,
            lineTo: 4,
            columnFrom: 0,
            columnTo: 0,
            snippet: 'return $data;',
            severity: 'error',
        ), $stmts);

        self::assertTrue($result->isFixed());
        $output = $this->print($stmts);
        self::assertStringContainsString('(int)', $output);
    }

    // --- PropertyNotSetInConstructor with union type ---

    public function testPropertyNotSetInConstructorUnionWithNull(): void {
        $code = <<<'PHP'
<?php
class Foo {
    private string|null $name;
    public function __construct() {}
}
PHP;
        $stmts = $this->parse($code);
        $fixer = new PropertyNotSetInConstructorFixer();

        $result = $fixer->fix(new PsalmIssue(
            type: 'PropertyNotSetInConstructor',
            message: 'Property Foo::$name is not defined in constructor',
            filePath: '/tmp/test.php',
            lineFrom: 3,
            lineTo: 3,
            columnFrom: 0,
            columnTo: 0,
            snippet: null,
            severity: 'error',
        ), $stmts);

        self::assertTrue($result->isFixed());
        $output = $this->print($stmts);
        self::assertStringContainsString('= null', $output);
    }

    // --- PossiblyNullArgument with argument number extraction ---

    public function testPossiblyNullArgumentExtractsFromAst(): void {
        $code = <<<'PHP'
<?php
function test(?int $time): void {
    date('Y-m-d', $time);
}
PHP;
        $stmts = $this->parse($code);
        $fixer = new PossiblyNullArgumentFixer();

        $result = $fixer->fix(new PsalmIssue(
            type: 'PossiblyNullArgument',
            message: 'Argument 2 of date cannot be null, possibly null value provided',
            filePath: '/tmp/test.php',
            lineFrom: 3,
            lineTo: 3,
            columnFrom: 0,
            columnTo: 0,
            snippet: "date('Y-m-d', \$time)",
            severity: 'error',
        ), $stmts);

        self::assertTrue($result->isFixed());
        $output = $this->print($stmts);
        self::assertStringContainsString('$time === null', $output);
    }

    // --- MixedAssignment fallback to return type ---

    public function testMixedAssignmentInfersFromReturnType(): void {
        $code = <<<'PHP'
<?php
class Foo {
    public function bar(mixed $input): int {
        $result = $input;
        return $result;
    }
}
PHP;
        $stmts = $this->parse($code);
        $fixer = new MixedAssignmentFixer();

        $result = $fixer->fix(new PsalmIssue(
            type: 'MixedAssignment',
            message: 'Cannot assign $result to a mixed type',
            filePath: '/tmp/test.php',
            lineFrom: 4,
            lineTo: 4,
            columnFrom: 0,
            columnTo: 0,
            snippet: '$result = $input',
            severity: 'error',
        ), $stmts);

        self::assertTrue($result->isFixed());
        $output = $this->print($stmts);
        self::assertStringContainsString('assert(is_int($result))', $output);
    }
}
