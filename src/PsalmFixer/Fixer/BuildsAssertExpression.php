<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PsalmFixer\Ast\TypeStringParser;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Shared helpers for fixers that synthesise runtime type assertions:
 *  - extracting a `$var` reference from a Psalm message or snippet,
 *  - building an `assert(...)` argument for a scalar (`is_int($x)`) or class
 *    (`$x instanceof Foo`) expectation,
 *  - mapping a scalar type to the matching `Cast\*` AST node.
 *
 * Centralises six near-identical copies that used to live in MixedArgument /
 * MixedAssignment / MixedMethodCall / MixedPropertyFetch / MixedArrayAccess /
 * MixedReturnStatement / PossiblyNullArgument / ArgumentTypeCoercion fixers.
 */
trait BuildsAssertExpression
{
    /**
     * Extract the first `$varName` referenced in $text. Most Psalm messages
     * name one variable per issue; for the rare two-variable phrasing (e.g.
     * "X provided to $bar from $baz"), the caller should fall back to AST
     * inspection via the argument-index path.
     *
     * @return non-empty-string|null
     */
    private static function extractVarNameFromText(string $text): ?string
    {
        if (preg_match('/\$(\w+)/', $text, $matches) === 1 && $matches[1] !== '') {
            return $matches[1];
        }

        return null;
    }

    /**
     * Convenience: try the message first, then the snippet. Returns null when
     * neither carries a variable reference.
     *
     * @return non-empty-string|null
     */
    private static function extractVarName(PsalmIssue $issue): ?string
    {
        return (
            self::extractVarNameFromText($issue->getMessage()) ?? self::extractVarNameFromText(
                $issue->getSnippet() ?? '',
            )
        );
    }

    /**
     * Build the inner expression for an `assert(...)` call:
     *  - scalar / built-in type → `is_int($x)` etc.
     *  - class type → `$x instanceof \Foo\Bar`
     *  - anything else → null (caller should fall back to suppress).
     *
     * @param non-empty-string $varName
     * @param non-empty-string $type
     */
    private function buildAssertExpr(string $varName, string $type, TypeStringParser $typeParser): ?Node\Expr
    {
        $isFunc = $typeParser->getIsTypeFunction($type);
        if ($isFunc !== null) {
            return new FuncCall(new Name($isFunc), [new Arg(new Variable($varName))]);
        }

        if ($typeParser->isClassType($type)) {
            return new Instanceof_(new Variable($varName), new Name\FullyQualified(ltrim($type, '\\')));
        }

        // Degrade generic collections (`array<...>`, `list<...>`, `array{...}`,
        // `non-empty-array<...>`, `non-empty-list<...>`) to `is_array($x)` —
        // template arguments are lost but the assertion is still valid and
        // helps Psalm rule out `mixed` at the call site.
        $degraded = $typeParser->getDegradedArrayType($type);
        if ($degraded === 'array') {
            return new FuncCall(new Name('is_array'), [new Arg(new Variable($varName))]);
        }

        return null;
    }

    /**
     * Wrap an assertion expression in an `assert(...)` FuncCall ready to be
     * inserted as a Stmt\Expression.
     */
    private function wrapInAssert(Node\Expr $condition): FuncCall
    {
        return new FuncCall(new Name('assert'), [new Arg($condition)]);
    }

    /**
     * @param non-empty-string $castType
     * @return class-string<Cast>|null
     */
    private static function castClassFor(string $castType): ?string
    {
        return match ($castType) {
            'int' => Cast\Int_::class,
            'float' => Cast\Double::class,
            'string' => Cast\String_::class,
            'bool' => Cast\Bool_::class,
            'array' => Cast\Array_::class,
            default => null,
        };
    }
}
