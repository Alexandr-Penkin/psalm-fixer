# psalm-fixer

Automatic fixing of [Psalm](https://psalm.dev/) static analysis issues via AST transformations using [nikic/php-parser](https://github.com/nikic/PHP-Parser).

## Installation

```bash
composer require zoon/psalm-fixer --dev
```

## Usage

```bash
# Run Psalm with JSON output and pipe to the fixer
vendor/bin/psalm --output-format=json | bin/psalm-fixer fix -

# Or via file
vendor/bin/psalm --output-format=json > psalm-issues.json
bin/psalm-fixer fix psalm-issues.json
```

### Options

| Option | Description |
|--------|-------------|
| `--dry-run` | Show what would be fixed without modifying files |
| `--diff` | Show diff of changes (implies `--dry-run`) |
| `--backup` | Create `.bak` files before modifying |
| `--issue-type=X,Y` | Fix only the specified issue types |
| `--file=pattern` | Fix only files matching the pattern |

```bash
# List all available fixers
bin/psalm-fixer list-fixers
```

## Supported Issue Types

**CodeQuality** — RedundantCast, RedundantIdentityWithTrue, UnusedForeachValue, UnusedClosureParam

**ClassDesign** — MissingOverrideAttribute, MissingClassConstType, PropertyNotSetInConstructor

**NullSafety** — PossiblyNullReference, PossiblyNullPropertyFetch, PossiblyNullArgument, PossiblyNullArrayAccess

**TypeSafety** — InvalidScalarArgument, RedundantCondition, TypeDoesNotContainNull, ArgumentTypeCoercion

**Mixed** — MixedArgument, MixedAssignment, MixedMethodCall, MixedReturnStatement, MixedPropertyFetch, MixedArrayAccess

**Docblock** — MismatchingDocblockPropertyType, UnusedPsalmSuppress

## Requirements

- PHP >= 8.0
- Psalm >= 6.15 (for JSON output generation)

## License

MIT
