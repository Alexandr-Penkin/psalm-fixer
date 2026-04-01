<?php

declare(strict_types=1);

namespace PsalmFixer\Tests\Unit\Fixer;

use PHPUnit\Framework\TestCase;
use PsalmFixer\Fixer\FixerRegistry;

final class FixerRegistryTest extends TestCase {
    public function testCreateDefaultRegistersAllFixers(): void {
        $registry = FixerRegistry::createDefault();

        $fixers = $registry->getAllFixers();
        self::assertGreaterThan(10, count($fixers));

        $types = $registry->getSupportedTypes();
        self::assertContains('RedundantCast', $types);
        self::assertContains('PossiblyNullReference', $types);
        self::assertContains('MixedArgument', $types);
        self::assertContains('MissingOverrideAttribute', $types);
    }

    public function testGetFixersForType(): void {
        $registry = FixerRegistry::createDefault();

        $fixers = $registry->getFixersForType('RedundantCast');
        self::assertCount(1, $fixers);
        self::assertSame('RedundantCastFixer', $fixers[0]->getName());
    }

    public function testGetFixersForUnknownTypeReturnsEmpty(): void {
        $registry = FixerRegistry::createDefault();

        $fixers = $registry->getFixersForType('UnknownIssueType');
        self::assertCount(0, $fixers);
    }
}
