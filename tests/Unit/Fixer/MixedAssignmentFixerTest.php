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
        self::assertSame('Statement already has the suppress annotation', $result->getDescription());
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
