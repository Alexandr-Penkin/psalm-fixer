<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer;

/**
 * Registry mapping Psalm issue types to their fixers.
 */
final class FixerRegistry
{
    /** @var array<non-empty-string, list<FixerInterface>> */
    private array $fixersByType = [];

    /** @var list<FixerInterface> */
    private array $allFixers = [];

    public function register(FixerInterface $fixer): void
    {
        $this->allFixers[] = $fixer;
        foreach ($fixer->getSupportedTypes() as $type) {
            if (!array_key_exists($type, $this->fixersByType)) {
                $this->fixersByType[$type] = [];
            }
            $this->fixersByType[$type][] = $fixer;
        }
    }

    /**
     * @param non-empty-string $issueType
     * @return list<FixerInterface>
     */
    public function getFixersForType(string $issueType): array
    {
        return $this->fixersByType[$issueType] ?? [];
    }

    /** @return list<FixerInterface> */
    public function getAllFixers(): array
    {
        return $this->allFixers;
    }

    /** @return list<non-empty-string> */
    public function getSupportedTypes(): array
    {
        return array_keys($this->fixersByType);
    }

    /**
     * Create registry with all built-in fixers.
     */
    public static function createDefault(): self
    {
        $registry = new self();

        // Phase 2 — Simple fixers
        $registry->register(new \PsalmFixer\Fixer\CodeQuality\RedundantCastFixer());
        $registry->register(new \PsalmFixer\Fixer\CodeQuality\RedundantIdentityWithTrueFixer());
        $registry->register(new \PsalmFixer\Fixer\CodeQuality\UnusedForeachValueFixer());
        $registry->register(new \PsalmFixer\Fixer\CodeQuality\UnusedClosureParamFixer());
        $registry->register(new \PsalmFixer\Fixer\ClassDesign\MissingOverrideAttributeFixer());
        $registry->register(new \PsalmFixer\Fixer\ClassDesign\MissingClassConstTypeFixer());

        // Phase 3 — Null Safety
        $registry->register(new \PsalmFixer\Fixer\NullSafety\PossiblyNullReferenceFixer());
        $registry->register(new \PsalmFixer\Fixer\NullSafety\PossiblyNullPropertyFetchFixer());
        $registry->register(new \PsalmFixer\Fixer\NullSafety\PossiblyNullArgumentFixer());
        $registry->register(new \PsalmFixer\Fixer\NullSafety\PossiblyNullArrayAccessFixer());

        // Phase 4 — Type/Mixed
        $registry->register(new \PsalmFixer\Fixer\TypeSafety\InvalidScalarArgumentFixer());
        $registry->register(new \PsalmFixer\Fixer\TypeSafety\RedundantConditionFixer());
        $registry->register(new \PsalmFixer\Fixer\TypeSafety\TypeDoesNotContainNullFixer());
        $registry->register(new \PsalmFixer\Fixer\Mixed\MixedArgumentFixer());
        $registry->register(new \PsalmFixer\Fixer\Mixed\MixedAssignmentFixer());
        $registry->register(new \PsalmFixer\Fixer\Mixed\MixedMethodCallFixer());
        $registry->register(new \PsalmFixer\Fixer\Mixed\MixedReturnStatementFixer());
        $registry->register(new \PsalmFixer\Fixer\Mixed\MixedPropertyFetchFixer());
        $registry->register(new \PsalmFixer\Fixer\Mixed\MixedArrayAccessFixer());
        $registry->register(new \PsalmFixer\Fixer\TypeSafety\ArgumentTypeCoercionFixer());
        $registry->register(new \PsalmFixer\Fixer\TypeSafety\PropertyTypeCoercionFixer());
        $registry->register(new \PsalmFixer\Fixer\Suppress\SuppressFallbackFixer());
        $registry->register(new \PsalmFixer\Fixer\Suppress\LiteralKeyUnshapedArrayFixer());
        $registry->register(new \PsalmFixer\Fixer\Purity\MissingPureAnnotationFixer());

        // Docblock fixers
        $registry->register(new \PsalmFixer\Fixer\Docblock\MismatchingDocblockPropertyTypeFixer());
        $registry->register(new \PsalmFixer\Fixer\Docblock\UnusedPsalmSuppressFixer());

        // Class Design
        $registry->register(new \PsalmFixer\Fixer\ClassDesign\PropertyNotSetInConstructorFixer());

        return $registry;
    }
}
