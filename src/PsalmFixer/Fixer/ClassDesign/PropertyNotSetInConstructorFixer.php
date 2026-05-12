<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\ClassDesign;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\UnionType;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Adds `= null` default to nullable properties not initialized in the constructor.
 *
 * The fixer is intentionally conservative: it touches only properties whose
 * declared type already admits `null` (nullable shorthand or a union with null).
 * For non-nullable properties — even ones with a "safe" zero value like `int`
 * or `string` — the fixer refuses. Silently defaulting `private int $count;`
 * to `0` masks the constructor bug that Psalm flagged: code that reads the
 * property before initialization would have hit `Error: Typed property must
 * not be accessed before initialization` at runtime, and quietly turning it
 * into `0` removes that signal.
 */
final class PropertyNotSetInConstructorFixer extends AbstractFixer
{
    #[\Override]
    public function getSupportedTypes(): array
    {
        return ['PropertyNotSetInConstructor'];
    }

    #[\Override]
    public function getName(): string
    {
        return 'PropertyNotSetInConstructorFixer';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Adds `= null` default to nullable properties not initialized in constructor';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult
    {
        $replaced = $this->replaceNodeAtLine($stmts, $issue->getLineFrom(), static function (Node $node): ?Node {
            if (!$node instanceof Property) {
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

    /**
     * Returns `null` literal only for types that already admit null. Any other
     * type is rejected — silently defaulting non-nullable scalars to `0`/`''`/
     * `false` masks the real constructor bug Psalm is flagging.
     */
    private static function getDefaultForType(Node\ComplexType|Identifier|Name|null $type): ?Expr
    {
        if ($type === null) {
            return new Expr\ConstFetch(new Name('null'));
        }

        if ($type instanceof NullableType) {
            return new Expr\ConstFetch(new Name('null'));
        }

        if ($type instanceof UnionType) {
            foreach ($type->types as $unionMember) {
                if ($unionMember instanceof Identifier && strtolower($unionMember->name) === 'null') {
                    return new Expr\ConstFetch(new Name('null'));
                }
            }

            return null;
        }

        return null;
    }
}
