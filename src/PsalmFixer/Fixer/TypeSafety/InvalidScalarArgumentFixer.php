<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\TypeSafety;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Arg;
use PsalmFixer\Ast\TypeStringParser;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Adds scalar casts for type mismatches: (int)$var, (string)$var, etc.
 */
final class InvalidScalarArgumentFixer extends AbstractFixer {
    private TypeStringParser $typeParser;

    public function __construct() {
        parent::__construct();
        $this->typeParser = new TypeStringParser();
    }

    #[\Override]
    public function getSupportedTypes(): array {
        return ['InvalidScalarArgument'];
    }

    #[\Override]
    public function getName(): string {
        return 'InvalidScalarArgumentFixer';
    }

    #[\Override]
    public function getDescription(): string {
        return 'Adds scalar type casts (int), (string), etc. for type mismatches';
    }

    #[\Override]
    public function canFix(PsalmIssue $issue, array $stmts): bool {
        $expectedType = $this->typeParser->extractExpectedType($issue->getMessage());

        return $expectedType !== null && $this->typeParser->isScalarType($expectedType);
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult {
        $expectedType = $this->typeParser->extractExpectedType($issue->getMessage());
        if ($expectedType === null) {
            return FixResult::notFixed('Could not extract expected type');
        }

        $castType = $this->typeParser->getCastType($expectedType);
        if ($castType === null) {
            return FixResult::notFixed('Not a scalar type');
        }

        $replaced = $this->replaceNodeAtLine($stmts, $issue->getLineFrom(), static function (Node $node) use ($castType): ?Node {
            if (!($node instanceof Arg)) {
                return null;
            }

            $castClass = self::getCastClass($castType);
            if ($castClass === null) {
                return null;
            }

            $node->value = new $castClass($node->value);

            return $node;
        });

        if ($replaced) {
            return FixResult::fixed("Added ({$castType}) cast");
        }

        return FixResult::notFixed('Could not find argument to cast');
    }

    /**
     * @param non-empty-string $type
     * @return class-string<Cast>|null
     */
    private static function getCastClass(string $type): ?string {
        return match ($type) {
            'int' => Cast\Int_::class,
            'float' => Cast\Double::class,
            'string' => Cast\String_::class,
            'bool' => Cast\Bool_::class,
            'array' => Cast\Array_::class,
            default => null,
        };
    }
}
