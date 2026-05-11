<?php

declare(strict_types=1);

namespace PsalmFixer\Tests\Unit\Fixer;

use PHPUnit\Framework\TestCase;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PsalmFixer\Fixer\Purity\MissingPureAnnotationFixer;
use PsalmFixer\Parser\PsalmIssue;

final class MissingPureAnnotationFixerTest extends TestCase {
    private MissingPureAnnotationFixer $fixer;
    private Standard $printer;

    protected function setUp(): void {
        $this->fixer = new MissingPureAnnotationFixer();
        $this->printer = new Standard();
    }

    public function testAddsPsalmPureToMethod(): void {
        $code = <<<'PHP'
<?php
class Foo {
    private static function bar(string $value): string {
        return $value;
    }
}
PHP;
        $issue = $this->makeIssue(
            'MissingPureAnnotation',
            3,
            'bar must be marked @psalm-pure to aid security analysis',
        );

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('@psalm-pure', $output);
        self::assertStringContainsString('private static function bar', $output);
    }

    public function testAddsPsalmMutationFreeToMethod(): void {
        $code = <<<'PHP'
<?php
class Foo {
    public function getName(): string {
        return $this->name;
    }
}
PHP;
        $issue = $this->makeIssue(
            'MissingPureAnnotation',
            3,
            'getName must be marked @psalm-mutation-free to aid security analysis',
        );

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('@psalm-mutation-free', $output);
    }

    public function testAddsPsalmExternalMutationFreeToMethod(): void {
        $code = <<<'PHP'
<?php
class Foo {
    public function setName(string $name): self {
        $this->name = $name;
        return $this;
    }
}
PHP;
        $issue = $this->makeIssue(
            'MissingPureAnnotation',
            3,
            'setName must be marked @psalm-external-mutation-free to aid security analysis',
        );

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('@psalm-external-mutation-free', $output);
    }

    public function testHandlesMessageWithoutLeadingAt(): void {
        $code = "<?php\nclass Foo {\n    public function bar(): void {}\n}\n";
        $issue = $this->makeIssue(
            'MissingPureAnnotation',
            3,
            'bar must be marked psalm-mutation-free to aid security analysis',
        );

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('@psalm-mutation-free', $output);
    }

    public function testAddsImmutableAnnotationToClass(): void {
        $code = "<?php\nfinal class IdUrlParser {\n    public function fromUrl(string \$url): ?string { return null; }\n}\n";
        $issue = $this->makeIssue(
            'MissingImmutableAnnotation',
            2,
            'Zoon\Helper\IdUrlParser must be marked psalm-pure to aid security analysis',
        );

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('@psalm-immutable', $output);
        self::assertStringContainsString('final class IdUrlParser', $output);
    }

    public function testMergesIntoExistingDocblock(): void {
        $code = <<<'PHP'
<?php
class Foo {
    /**
     * Compute the thing.
     */
    public function compute(): string {
        return 'x';
    }
}
PHP;
        $issue = $this->makeIssue(
            'MissingPureAnnotation',
            6,
            'compute must be marked @psalm-pure to aid security analysis',
        );

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('@psalm-pure', $output);
        self::assertStringContainsString('Compute the thing', $output);
    }

    public function testIdempotentWhenTagAlreadyPresent(): void {
        $code = <<<'PHP'
<?php
class Foo {
    /** @psalm-pure */
    public function bar(): string {
        return 'x';
    }
}
PHP;
        $issue = $this->makeIssue(
            'MissingPureAnnotation',
            4,
            'bar must be marked @psalm-pure to aid security analysis',
        );

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        $result = $this->fixer->fix($issue, $stmts);

        self::assertFalse($result->isFixed());
        self::assertSame('Statement already carries @psalm-pure', $result->getDescription());
    }

    public function testDoesNotFalseMatchSimilarTag(): void {
        // Existing tag is @psalm-external-mutation-free; we ask to add
        // @psalm-mutation-free — these are different annotations. Strict
        // boundary check must not consider them the same.
        $code = <<<'PHP'
<?php
class Foo {
    /** @psalm-external-mutation-free */
    public function bar(): void {}
}
PHP;
        $issue = $this->makeIssue(
            'MissingPureAnnotation',
            4,
            'bar must be marked @psalm-mutation-free to aid security analysis',
        );

        $output = $this->runFixer($code, $issue);

        self::assertStringContainsString('@psalm-external-mutation-free', $output);
        self::assertStringContainsString('@psalm-mutation-free', $output);
    }

    public function testNotFixedWhenMessageMissingTagHint(): void {
        $code = "<?php\nclass Foo {\n    public function bar(): void {}\n}\n";
        $issue = $this->makeIssue(
            'MissingPureAnnotation',
            3,
            'bar should be pure somehow',
        );

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        $result = $this->fixer->fix($issue, $stmts);

        self::assertFalse($result->isFixed());
    }

    public function testSupportedTypes(): void {
        $types = $this->fixer->getSupportedTypes();
        self::assertContains('MissingPureAnnotation', $types);
        self::assertContains('MissingImmutableAnnotation', $types);
    }

    /**
     * @param non-empty-string $type
     * @param non-empty-string $message
     */
    private function makeIssue(string $type, int $line, string $message): PsalmIssue {
        return new PsalmIssue(
            type: $type,
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
