<?php

declare(strict_types=1);

namespace PsalmFixer\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PhpParser\ParserFactory;
use PsalmFixer\Ast\FileProcessor;
use PsalmFixer\Fixer\FixerRegistry;
use PsalmFixer\Parser\PsalmOutputParser;

/**
 * End-to-end convergence guard for the full fixer pipeline.
 *
 * Loads a fixture file (`tests/Fixtures/Convergence/sample.php`) together with
 * a pre-captured Psalm-style issue list (`issues.json`) and runs the real
 * `FileProcessor` over it. The test asserts three convergence properties:
 *
 *   1. The rewritten source still parses (no fixer produced syntactically
 *      invalid PHP).
 *   2. The patterns the issues were originally flagging are no longer present
 *      in the source (the fixers actually did something).
 *   3. Re-running the fixer on its own output is a no-op (idempotence — a
 *      fixer that keeps changing the same file would diverge in CI loops).
 *
 * The fixture exercises a representative mix of fixers:
 *   - `MissingOverrideAttribute` (Override attribute insertion)
 *   - `RedundantCondition` literal-true (AST-only direction inference)
 *   - `RedundantCondition` "can never contain" (message + AST inference)
 *
 * The test is not run against live Psalm: a real Psalm run is slow and
 * environment-sensitive, so the property checks are validated cheaply at the
 * AST level instead. The fixture is small on purpose — when adding fixers,
 * extend `sample.php` + `issues.json` to cover the new path.
 */
final class ConvergenceTest extends TestCase {
    /**
     * @return array{stmts: list<\PhpParser\Node\Stmt>, source: string}
     */
    private function loadFixture(): array {
        $sourcePath = __DIR__ . '/../Fixtures/Convergence/sample.php';
        $source = file_get_contents($sourcePath);
        self::assertNotFalse($source);

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($source);
        self::assertNotNull($stmts);
        /** @var list<\PhpParser\Node\Stmt> $stmts */

        return ['stmts' => $stmts, 'source' => $source];
    }

    /**
     * @return string Path to the rewritten source file.
     */
    private function runPipeline(string $tmpFile): string {
        $issuesJson = file_get_contents(__DIR__ . '/../Fixtures/Convergence/issues.json');
        self::assertNotFalse($issuesJson);

        $sourceContents = file_get_contents(__DIR__ . '/../Fixtures/Convergence/sample.php');
        self::assertNotFalse($sourceContents);
        file_put_contents($tmpFile, $sourceContents);

        $issuesJson = str_replace('__SAMPLE__', $tmpFile, $issuesJson);

        $parser = new PsalmOutputParser();
        $issues = $parser->parseJson($issuesJson);

        $registry = FixerRegistry::createDefault();
        $processor = new FileProcessor($registry);

        $report = $processor->processIssues($issues);
        self::assertGreaterThan(0, $report->getFixedCount(), 'Fixture should produce at least one fix');

        return $tmpFile;
    }

    public function testRewrittenSourceStillParses(): void {
        $tmp = $this->makeTempFile();
        try {
            $this->runPipeline($tmp);

            $rewritten = file_get_contents($tmp);
            self::assertNotFalse($rewritten);
            self::assertNotSame('', $rewritten);

            $parser = (new ParserFactory())->createForNewestSupportedVersion();
            $reparsed = $parser->parse($rewritten);
            self::assertNotNull($reparsed, "Rewritten file failed to parse:\n{$rewritten}");
        } finally {
            @unlink($tmp);
        }
    }

    public function testKnownIssuePatternsAreGone(): void {
        $tmp = $this->makeTempFile();
        try {
            $this->runPipeline($tmp);
            $rewritten = file_get_contents($tmp);
            self::assertNotFalse($rewritten);

            // `if (true) {` should be unwrapped — the literal-true condition is gone.
            self::assertStringNotContainsString('if (true)', $rewritten);

            // `if ($name !== '')` was always-true given Psalm context — should be unwrapped.
            self::assertStringNotContainsString("if (\$name !== '')", $rewritten);

            // Override attributes should have appeared on the two overriding methods.
            self::assertStringContainsString('#[\Override]', $rewritten);
        } finally {
            @unlink($tmp);
        }
    }

    public function testPipelineIsIdempotent(): void {
        // Running the same issue list a second time on the already-fixed file
        // should leave the file unchanged. Non-idempotent fixers would loop in
        // CI / pre-commit setups.
        $tmp = $this->makeTempFile();
        try {
            $this->runPipeline($tmp);
            $afterFirstRun = file_get_contents($tmp);
            self::assertNotFalse($afterFirstRun);

            // Re-run by parsing the already-fixed source against the SAME issue
            // list. Issues now point at lines that no longer match the original
            // patterns — fixers should report `notFixed` rather than alter the
            // file further.
            $issuesJson = file_get_contents(__DIR__ . '/../Fixtures/Convergence/issues.json');
            self::assertNotFalse($issuesJson);
            $issuesJson = str_replace('__SAMPLE__', $tmp, $issuesJson);

            $parser = new PsalmOutputParser();
            $issues = $parser->parseJson($issuesJson);

            $registry = FixerRegistry::createDefault();
            $processor = new FileProcessor($registry);
            $processor->processIssues($issues);

            $afterSecondRun = file_get_contents($tmp);
            self::assertSame($afterFirstRun, $afterSecondRun, 'Pipeline is not idempotent');
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @return non-empty-string
     */
    private function makeTempFile(): string {
        $tmp = tempnam(sys_get_temp_dir(), 'psalm_fixer_conv_');
        self::assertNotFalse($tmp);
        // Rename with `.php` suffix so any subsequent psalm/parser invocation
        // recognises the file as PHP.
        $php = $tmp . '.php';
        rename($tmp, $php);

        return $php;
    }
}
