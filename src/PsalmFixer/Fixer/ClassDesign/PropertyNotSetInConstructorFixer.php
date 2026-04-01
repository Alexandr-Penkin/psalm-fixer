<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\ClassDesign;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Property;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Adds default values to properties not set in constructor.
 */
final class PropertyNotSetInConstructorFixer extends AbstractFixer {
    #[\Override]
    public function getSupportedTypes(): array {
        return ['PropertyNotSetInConstructor'];
    }

    #[\Override]
    public function getName(): string {
        return 'PropertyNotSetInConstructorFixer';
    }

    #[\Override]
    public function getDescription(): string {
        return 'Adds default value to properties not initialized in constructor';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult {
        $replaced = $this->replaceNodeAtLine($stmts, $issue->getLineFrom(), static function (Node $node): ?Node {
            if (!($node instanceof Property)) {
                return null;
            }

            // Only handle single-property declarations
            if (count($node->props) !== 1) {
                return null;
            }

            $prop = $node->props[0];

            // Already has a default
            if ($prop->default !== null) {
                return null;
            }

            $default = self::getDefaultForType($node->type);
            if ($default === null) {
                return null;
            }

            $prop->default = $default;

            return $node;
        });

        if ($replaced) {
            return FixResult::fixed('Added default value to property');
        }

        return FixResult::notFixed('Could not add default value to property');
    }

    private static function getDefaultForType(Node\ComplexType|Identifier|Name|null $type): ?Expr {
        if ($type === null) {
            return new Expr\ConstFetch(new Name('null'));
        }

        // Nullable types default to null
        if ($type instanceof NullableType) {
            return new Expr\ConstFetch(new Name('null'));
        }

        if ($type instanceof Identifier) {
            return match ($type->name) {
                'int' => new Node\Scalar\Int_(0),
                'float' => new Node\Scalar\Float_(0.0),
                'string' => new Node\Scalar\String_(''),
                'bool' => new Expr\ConstFetch(new Name('false')),
                'array' => new Expr\Array_([]),
                default => null,
            };
        }

        return null;
    }
}
