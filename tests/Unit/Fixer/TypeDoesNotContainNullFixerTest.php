<?php

declare(strict_types=1);

namespace PsalmFixer\Tests\Unit\Fixer;

use PHPUnit\Framework\TestCase;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PsalmFixer\Fixer\TypeSafety\TypeDoesNotContainNullFixer;
use PsalmFixer\Parser\PsalmIssue;

final class TypeDoesNotContainNullFixerTest extends TestCase {
    private TypeDoesNotContainNullFixer $fixer;
    private Standard $printer;

    protected function setUp(): void {
        $this->fixer = new TypeDoesNotContainNullFixer();
        $this->printer = new Standard();
    }

    public function testFixesIdenticalWithNullAsDeadBranch(): void {
        // if ($x === null) { ... } else { return $x; } → keep the else body
        $code = "<?php\nif (\$x === null) {\n    return 0;\n} else {\n    return \$x;\n}\n";
        $issue = $this->makeIssue(2);

        $output = $this->runFixer($code, $issue);

        self::assertStringNotContainsString('if (', $output);
        self::assertStringContainsString('return $x', $output);
        self::assertStringNotContainsString('return 0', $output);
    }

    public function testFixesNotIdenticalWithNullAsUnwrap(): void {
        // if ($x !== null) { return $x; } → return $x  (always true → unwrap body)
        $code = "<?php\nif (\$x !== null) {\n    return \$x;\n}\n";
        $issue = $this->makeIssue(2);

        $output = $this->runFixer($code, $issue);

        self::assertStringNotContainsString('if (', $output);
        self::assertStringContainsString('return $x', $output);
    }

    public function testStripsRedundantNotNullOperandFromAndChain(): void {
        $code = "<?php\nif (\$x instanceof Foo && \$x->prop !== null) {\n    return \$x;\n}\n";
        $issue = $this->makeIssue(2);

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('if ($x instanceof Foo) {', $output);
        self::assertStringNotContainsString('!== null', $output);
    }

    public function testAlwaysFalseOperandInAndChainMakesWholeIfDead(): void {
        // if (A && $x === null) → whole && always false → dead branch → keep else
        $code = "<?php\nif (\$x instanceof Foo && \$x->prop === null) {\n    return 0;\n} else {\n    return 1;\n}\n";
        $issue = $this->makeIssue(2);

        $output = $this->runFixer($code, $issue);

        self::assertStringNotContainsString('if (', $output);
        self::assertStringContainsString('return 1', $output);
        self::assertStringNotContainsString('return 0', $output);
    }

    public function testWorksWithoutMessage(): void {
        // No message text — pure AST-driven, so works with baseline input.
        $code = "<?php\nif (\$x !== null) {\n    return \$x;\n}\n";
        $issue = new PsalmIssue(
            type: 'TypeDoesNotContainNull',
            message: 'From baseline: TypeDoesNotContainNull',
            filePath: '/tmp/t.php',
            lineFrom: 2,
            lineTo: 2,
            columnFrom: 0,
            columnTo: 0,
            snippet: null,
            severity: 'error',
        );

        $output = $this->runFixer($code, $issue);

        self::assertStringNotContainsString('if (', $output);
    }

    public function testGracefullySkipsNonNullComparison(): void {
        // Condition is not a null comparison — fixer can't decide direction.
        $code = "<?php\nif (\$x->call()) {\n    return 1;\n}\n";
        $issue = $this->makeIssue(2);

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        $result = $this->fixer->fix($issue, $stmts);

        self::assertFalse($result->isFixed());
    }

    public function testDeadBranchWithoutElseDropsTheIfEntirely(): void {
        $code = "<?php\nif (\$x === null) {\n    return 0;\n}\necho 'after';\n";
        $issue = $this->makeIssue(2);

        $output = $this->runFixer($code, $issue);

        self::assertStringNotContainsString('if (', $output);
        self::assertStringNotContainsString('return 0', $output);
        self::assertStringContainsString("echo 'after'", $output);
    }

    public function testNullOnLeftSideOfComparison(): void {
        // Yoda condition: `null === $x` — null on the left side.
        $code = "<?php\nif (null === \$x) {\n    return 0;\n} else {\n    return \$x;\n}\n";
        $issue = $this->makeIssue(2);

        $output = $this->runFixer($code, $issue);

        self::assertStringNotContainsString('if (', $output);
        self::assertStringContainsString('return $x', $output);
    }

    private function makeIssue(int $line): PsalmIssue {
        return new PsalmIssue(
            type: 'TypeDoesNotContainNull',
            message: 'Type X does not contain null',
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
