<?php

declare(strict_types=1);

namespace PsalmFixer\Ast;

use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;

/**
 * Converts Psalm type strings into PHP AST type nodes.
 *
 * @psalm-api Public helper for fixer implementations.
 */
final class TypeStringParser
{
    /**
     * Regex pattern for matching Psalm type strings including generics.
     * Matches: int, string, array<array-key, mixed>, Foo\Bar, list<string>, non-empty-string, etc.
     * Uses balanced angle brackets for generics (one level of nesting).
     */
    private const TYPE_PATTERN = '[A-Za-z0-9_|\\\\-]+(?:<[A-Za-z0-9_|\\\\, -]+(?:<[A-Za-z0-9_|\\\\, -]+>)?[A-Za-z0-9_|\\\\, -]*>)?';

    /**
     * Map of scalar type names to their cast equivalents.
     *
     * @var array<non-empty-string, non-empty-string>
     */
    private const SCALAR_CASTS = [
        'int' => 'int',
        'integer' => 'int',
        'float' => 'float',
        'double' => 'float',
        'string' => 'string',
        'bool' => 'bool',
        'boolean' => 'bool',
        'array' => 'array',
    ];

    /**
     * Map of is_* functions for assertions.
     *
     * @var array<non-empty-string, non-empty-string>
     */
    private const IS_TYPE_FUNCTIONS = [
        'int' => 'is_int',
        'integer' => 'is_int',
        'float' => 'is_float',
        'double' => 'is_float',
        'string' => 'is_string',
        'bool' => 'is_bool',
        'boolean' => 'is_bool',
        'array' => 'is_array',
        'object' => 'is_object',
        'null' => 'is_null',
        'numeric' => 'is_numeric',
        'callable' => 'is_callable',
    ];

    /**
     * Check if a type string represents a scalar type that can be cast.
     *
     * @param non-empty-string $type
     */
    public function isScalarType(string $type): bool
    {
        return array_key_exists(strtolower($type), self::SCALAR_CASTS);
    }

    /**
     * Get the cast type for a scalar type string.
     *
     * @param non-empty-string $type
     * @return non-empty-string|null
     */
    public function getCastType(string $type): ?string
    {
        return self::SCALAR_CASTS[strtolower($type)] ?? null;
    }

    /**
     * Get the is_* function name for a type.
     *
     * @param non-empty-string $type
     * @return non-empty-string|null
     */
    public function getIsTypeFunction(string $type): ?string
    {
        return self::IS_TYPE_FUNCTIONS[strtolower($type)] ?? null;
    }

    /**
     * For generic collection types (`array<...>`, `list<...>`, `non-empty-array<...>`,
     * `non-empty-list<...>`, `iterable<...>`, `array{...}`), return the base PHP
     * type (currently `'array'`) so callers can emit a runtime check that drops
     * the parameter info but is still useful (e.g. `is_array($x)`). Returns null
     * for non-generic / non-array types.
     *
     * @param non-empty-string $type
     * @return 'array'|null
     */
    public function getDegradedArrayType(string $type): ?string
    {
        $trimmed = trim($type);
        if ($trimmed === '') {
            return null;
        }

        // array{...} shape syntax → still an array at runtime.
        if (str_starts_with($trimmed, 'array{')) {
            return 'array';
        }

        if (preg_match('/^(array|list|non-empty-array|non-empty-list|iterable)\b/i', $trimmed, $matches) === 1) {
            $base = strtolower($matches[1]);
            // iterable<...> covers Traversable too — degrade is not safe, skip.
            if ($base === 'iterable') {
                return null;
            }

            return 'array';
        }

        return null;
    }

    /**
     * Check if a type is a class/interface type (not scalar, not built-in).
     *
     * @param non-empty-string $type
     */
    public function isClassType(string $type): bool
    {
        $lower = strtolower($type);

        return (
            !array_key_exists($lower, self::SCALAR_CASTS)
            && !array_key_exists($lower, self::IS_TYPE_FUNCTIONS)
            && !in_array($lower, ['void', 'never', 'mixed', 'self', 'static', 'parent', 'iterable', 'resource'], true)
            && preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $type) === 1
        );
    }

    /**
     * Parse a simple type string into an AST type node.
     * Handles: scalar types, class names, nullable types.
     *
     * @param non-empty-string $typeString
     * @return Identifier|Name|NullableType|UnionType|null
     */
    public function parseType(string $typeString): Identifier|Name|NullableType|UnionType|null
    {
        $typeString = trim($typeString);

        // Nullable
        if (str_starts_with($typeString, '?')) {
            $inner = $this->parseSimpleType(substr($typeString, 1));
            if ($inner === null) {
                return null;
            }

            return new NullableType($inner);
        }

        // Union types
        if (str_contains($typeString, '|')) {
            $parts = explode('|', $typeString);
            $types = [];
            foreach ($parts as $part) {
                $parsed = $this->parseSimpleType(trim($part));
                if ($parsed === null) {
                    return null;
                }
                $types[] = $parsed;
            }

            return new UnionType($types);
        }

        return $this->parseSimpleType($typeString);
    }

    /**
     * @return Identifier|Name|null
     */
    private function parseSimpleType(string $type): Identifier|Name|null
    {
        $type = trim($type);
        if ($type === '') {
            return null;
        }

        $builtins = [
            'int',
            'float',
            'string',
            'bool',
            'array',
            'object',
            'void',
            'never',
            'null',
            'false',
            'true',
            'mixed',
            'iterable',
            'callable',
            'self',
            'static',
            'parent',
        ];

        if (in_array(strtolower($type), $builtins, true)) {
            return new Identifier(strtolower($type));
        }

        // Class name
        if (str_contains($type, '\\')) {
            return new Name\FullyQualified(ltrim($type, '\\'));
        }

        return new Name($type);
    }

    /**
     * Extract expected type from a Psalm error message.
     * Examples:
     * - "Argument 1 of Foo::bar expects int, string provided"
     * - "expects int, float|string provided"
     * - "cannot be mixed, expecting string"
     * - "expects array<array-key, mixed>, list<string> provided"
     * - "should be int"
     * - "of type int"
     *
     * @return non-empty-string|null
     */
    public function extractExpectedType(string $message): ?string
    {
        /** @var list<non-empty-string> $patterns */
        $patterns = [
            // "expects TYPE," or "expects TYPE but" — with generic support
            '/expects\s+(' . self::TYPE_PATTERN . ')\s*[,\s]/i',
            // "expecting TYPE" (e.g., "cannot be mixed, expecting string")
            '/expecting\s+(' . self::TYPE_PATTERN . ')/i',
            // "should be TYPE"
            '/should be\s+(' . self::TYPE_PATTERN . ')/i',
            // "of type TYPE"
            '/of type\s+(' . self::TYPE_PATTERN . ')/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) === 1) {
                $type = $matches[1];
                if ($type !== '') {
                    return $type;
                }
            }
        }

        return null;
    }

    /**
     * Extract provided/actual type from a Psalm error message.
     *
     * @return non-empty-string|null
     */
    public function extractProvidedType(string $message): ?string
    {
        // "TYPE provided" or "TYPE given"
        if (preg_match('/\b(' . self::TYPE_PATTERN . ')\s+(?:provided|given)/i', $message, $matches) === 1) {
            $type = $matches[1];
            if ($type !== '') {
                return $type;
            }
        }

        return null;
    }
}
