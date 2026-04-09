# psalm-fixer

Automatic fixing of [Psalm](https://psalm.dev/) static analysis issues via AST transformations using [nikic/php-parser](https://github.com/nikic/PHP-Parser).

psalm-fixer reads Psalm's JSON output, matches each issue to a registered fixer, and rewrites source files using a format-preserving printer so diffs stay minimal.

## Installation

```bash
composer require zoon/psalm-fixer --dev
```

## Usage

```bash
# Run Psalm with JSON output and pipe to the fixer
vendor/bin/psalm --output-format=json | vendor/bin/psalm-fixer fix -

# Or via file
vendor/bin/psalm --output-format=json > psalm-issues.json
vendor/bin/psalm-fixer fix psalm-issues.json

# Preview without writing (shows a unified diff)
vendor/bin/psalm-fixer fix psalm-issues.json --diff

# Fix only specific issue types
vendor/bin/psalm-fixer fix psalm-issues.json --issue-type=RedundantCast,MissingOverrideAttribute

# Fix only files matching a pattern
vendor/bin/psalm-fixer fix psalm-issues.json --file=src/Foo.php
```

`fix` is the default command, so `vendor/bin/psalm-fixer psalm-issues.json` also works.

### Options

| Option | Description |
|--------|-------------|
| `--dry-run` | Show what would be fixed without modifying files |
| `--diff` | Show diff of changes (implies `--dry-run`) |
| `--backup` | Create `.bak` files before modifying |
| `--issue-type=X,Y` | Fix only the specified issue types (comma-separated) |
| `--file=pattern` | Fix only files matching the pattern (comma-separated) |

### Other commands

```bash
# List all registered fixers with descriptions
vendor/bin/psalm-fixer list-fixers
```

## Report output

After a run, psalm-fixer prints a summary grouped by:

- **Fixed** — issues successfully rewritten
- **Unfixed** — a fixer matched but could not apply the change (reason included)
- **No fixer** — no fixer is registered for the issue type

## Supported Issue Types

**CodeQuality** — RedundantCast, RedundantIdentityWithTrue, UnusedForeachValue, UnusedClosureParam

**ClassDesign** — MissingOverrideAttribute, MissingClassConstType, PropertyNotSetInConstructor

**NullSafety** — PossiblyNullReference, PossiblyNullPropertyFetch, PossiblyNullArgument, PossiblyNullArrayAccess

**TypeSafety** — InvalidScalarArgument, RedundantCondition, TypeDoesNotContainNull, ArgumentTypeCoercion

**Mixed** — MixedArgument, MixedAssignment, MixedMethodCall, MixedReturnStatement, MixedPropertyFetch, MixedArrayAccess

**Docblock** — MismatchingDocblockPropertyType, UnusedPsalmSuppress

## How it works

```
Psalm JSON
   -> PsalmOutputParser  (JSON -> PsalmIssue value objects)
   -> FileProcessor      (groups issues by file, parses AST)
   -> FixerRegistry      (maps issue type -> fixer)
   -> Fixer              (mutates AST, returns FixResult)
   -> format-preserving printer (writes file back)
```

Fixes are applied bottom-up (descending line order) so earlier edits don't shift positions of later ones. When a fixer changes the node type (e.g. `->` to `?->`) the format-preserving printer is bypassed and a full pretty-print is used as fallback.

## Adding a new fixer

1. Create a class under `src/PsalmFixer/Fixer/<Category>/` extending `AbstractFixer`.
2. Implement `getSupportedTypes()` returning the Psalm issue type strings it handles.
3. Implement `getName()`, `getDescription()`, and `fix()`.
4. Register it in `FixerRegistry::createDefault()`.
5. Add unit tests in `tests/Unit/Fixer/`.

## Development

```bash
# Run the test suite
vendor/bin/phpunit

# Run a single test
vendor/bin/phpunit tests/Unit/Fixer/RedundantCastFixerTest.php

# Run Psalm on the project itself
vendor/bin/psalm
```

## Requirements

- PHP >= 8.0
- Psalm >= 6.15 (for JSON output generation)

## License

MIT
