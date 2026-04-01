# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Is

psalm-fixer — a CLI tool that automatically fixes Psalm static analysis issues via PHP AST transformations. It reads Psalm's JSON output, parses issues, matches them to fixers, and rewrites source files using nikic/php-parser's format-preserving printer.

## Commands

```bash
# Run tests
vendor/bin/phpunit

# Run a single test
vendor/bin/phpunit tests/Unit/Fixer/RedundantCastFixerTest.php

# Run Psalm (level 1, strict)
vendor/bin/psalm

# Use the tool itself
bin/psalm-fixer fix <psalm-json-file>
bin/psalm-fixer fix - < psalm-output.json        # from STDIN
bin/psalm-fixer fix output.json --dry-run --diff  # preview changes
bin/psalm-fixer fix output.json --issue-type=RedundantCast,MissingOverrideAttribute
bin/psalm-fixer fix output.json --file=src/Foo.php
bin/psalm-fixer list-fixers                       # show all registered fixers
```

## Architecture

**Pipeline:** Psalm JSON -> `PsalmOutputParser` -> `PsalmIssue` objects -> `FileProcessor` groups by file -> `FixerRegistry` maps issue types to fixers -> fixer modifies AST -> format-preserving print back to file.

Key components:

- **`Parser/PsalmOutputParser`** — parses Psalm's JSON array into `PsalmIssue` value objects
- **`Ast/FileProcessor`** — central engine: parses PHP files, clones AST, applies fixers bottom-up (descending line order to avoid position shifts), writes back with format-preserving printing. Falls back to full pretty-print when format-preserving fails (e.g., node type changes like `->` to `?->`)
- **`Fixer/FixerRegistry`** — maps Psalm issue type strings to fixer instances via `createDefault()`. All built-in fixers are registered here
- **`Fixer/FixerInterface`** — contract: `getSupportedTypes()`, `canFix()`, `fix()`. Fixers receive AST by reference, return `FixResult`
- **`Fixer/AbstractFixer`** — base class with `NodeFinder`, helpers for `insertStatementBefore/After` and `replaceNodeAtLine`
- **`Ast/NodeFinder`** — locates/replaces AST nodes by line number
- **`Ast/DocblockHelper`** / **`Ast/TypeStringParser`** — PHPDoc manipulation utilities

**Fixer categories** (`src/PsalmFixer/Fixer/`):
- `CodeQuality/` — RedundantCast, RedundantIdentityWithTrue, UnusedForeachValue, UnusedClosureParam
- `ClassDesign/` — MissingOverrideAttribute, MissingClassConstType, PropertyNotSetInConstructor
- `NullSafety/` — PossiblyNull{Reference,PropertyFetch,Argument,ArrayAccess}
- `TypeSafety/` — InvalidScalarArgument, RedundantCondition, TypeDoesNotContainNull, ArgumentTypeCoercion
- `Mixed/` — MixedArgument, MixedAssignment, MixedMethodCall, MixedReturnStatement, MixedPropertyFetch, MixedArrayAccess
- `Docblock/` — MismatchingDocblockPropertyType

## Adding a New Fixer

1. Create a class in the appropriate `Fixer/` subdirectory extending `AbstractFixer`
2. Implement `getSupportedTypes()` returning Psalm issue type strings (e.g., `['RedundantCast']`)
3. Implement `getName()`, `getDescription()`, `fix()`
4. Register it in `FixerRegistry::createDefault()`
5. Add tests in `tests/Unit/Fixer/`
