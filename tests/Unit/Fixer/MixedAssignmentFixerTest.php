<?php

declare(strict_types=1);

namespace PsalmFixer\Tests\Unit\Fixer;

use PHPUnit\Framework\TestCase;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PsalmFixer\Fixer\Mixed\MixedAssignmentFixer;
use PsalmFixer\Parser\PsalmIssue;

final class MixedAssignmentFixerTest extends TestCase {
    private MixedAssignmentFixer $fixer;
    private Standard $printer;

    protected function setUp(): void {
        $this->fixer = new MixedAssignmentFixer();
        $this->printer = new Standard();
    }

    public function testAddsScalarAssertWhenTypeIsKnown(): void {
        // Assignment is in a function whose return type is `int` and $value is
        // returned — the existing inferTypeFromContext fallback picks `int`.
        $code = <<<'PHP'
<?php
function foo(array $data): int {
    $value = $data['k'];
    return $value;
}
PHP;
        $issue = $this->makeIssue(3, 'Unable to determine the type that $value is being assigned to');

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('assert(is_int($value))', $output);
    }

    public function testFallsBackToSuppressForGenuineMixed(): void {
        // No type info in message, $value isn't returned anywhere, no other
        // type signal — fixer must drop down to @psalm-suppress.
        $code = "<?php\n\$value = \$data['k'] ?? 0;\n";
        $issue = $this->makeIssue(2, 'Unable to determine the type that $value is being assigned to');

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('@psalm-suppress MixedAssignment', $output);
        self::assertStringContainsString("\$value = \$data['k'] ?? 0", $output);
    }

    public function testFallbackSuppressOnForeachAssignment(): void {
        // foreach ($x as $item) — $item is mixed when $x is mixed.
        // The "assignment" line is the foreach line itself.
        $code = "<?php\nforeach (\$comments as \$comment) {\n    echo \$comment;\n}\n";
        $issue = $this->makeIssue(2, 'Unable to determine the type that $comment is being assigned to');

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('@psalm-suppress MixedAssignment', $output);
        self::assertStringContainsString('foreach ($comments as $comment)', $output);
    }

    public function testForeachAssertGoesInsideBodyWhenTypeKnown(): void {
        // The iteration variable's type can be inferred from the function
        // return type; the assert must live inside the foreach body so the
        // variable is in scope when the assertion is evaluated.
        $code = <<<'PHP'
<?php
function sum(array $items): int {
    $total = 0;
    foreach ($items as $item) {
        $total += $item;
    }
    return $total;
}
PHP;
        $issue = $this->makeIssue(4, 'expects int, $item is mixed');

        $output = $this->runFixer($code, $issue);

        // assert must appear inside the foreach body, before the body uses $item.
        $foreachPos = strpos($output, 'foreach (');
        $assertPos = strpos($output, 'assert(is_int($item))');
        $usagePos = strpos($output, '$total += $item');

        self::assertNotFalse($foreachPos);
        self::assertNotFalse($assertPos, 'Expected assert inside foreach body');
        self::assertNotFalse($usagePos);
        self::assertGreaterThan($foreachPos, $assertPos);
        self::assertLessThan($usagePos, $assertPos);
    }

    public function testAssertInsertionIsIdempotent(): void {
        // Running the fixer twice on the same source with the same issue must
        // not duplicate the assert.
        $code = <<<'PHP'
<?php
function foo(array $data): int {
    $value = $data['k'];
    return $value;
}
PHP;
        $issue = $this->makeIssue(3, 'Unable to determine the type that $value is being assigned to');

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        $first = $this->fixer->fix($issue, $stmts);
        self::assertTrue($first->isFixed());

        $second = $this->fixer->fix($issue, $stmts);
        self::assertFalse($second->isFixed(), 'Second run must report not-fixed (guard already present)');

        $output = $this->printer->prettyPrintFile($stmts);
        self::assertSame(1, substr_count($output, 'assert(is_int($value))'));
    }

    public function testSuppressMergesIntoExistingDocblock(): void {
        $code = "<?php\n/** @var int \$other */\n\$value = \$data['k'];\n";
        $issue = $this->makeIssue(3, 'Unable to determine the type that $value is being assigned to');

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('@psalm-suppress MixedAssignment', $output);
        self::assertStringContainsString('@var int $other', $output);
    }

    public function testSuppressIsIdempotent(): void {
        $code = "<?php\n/** @psalm-suppress MixedAssignment */\n\$value = \$data['k'];\n";
        $issue = $this->makeIssue(3, 'Unable to determine the type that $value is being assigned to');

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        $result = $this->fixer->fix($issue, $stmts);

        self::assertFalse($result->isFixed());
        self::assertSame('Statement already carries @psalm-suppress MixedAssignment', $result->getDescription());
    }

    public function testReturnsNotFixedWhenNoStatementAtLine(): void {
        $code = "<?php\n\$value = 1;\n";
        $issue = $this->makeIssue(99, 'Unable to determine the type that $value is being assigned to');

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        $result = $this->fixer->fix($issue, $stmts);

        self::assertFalse($result->isFixed());
    }

    public function testFallbackForResultArrayAppend(): void {
        // `$result[] = $value;` pattern (mid-expression mixed pushing).
        $code = "<?php\n\$result = [];\nforeach (\$xs as \$x) {\n    \$result[] = \$x;\n}\n";
        $issue = $this->makeIssue(4, 'Unable to determine the type of this assignment');

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('@psalm-suppress MixedAssignment', $output);
        self::assertStringContainsString('$result[] = $x', $output);
    }

    private function makeIssue(int $line, string $message): PsalmIssue {
        return new PsalmIssue(
            type: 'MixedAssignment',
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
