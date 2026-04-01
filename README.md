# psalm-fixer

Автоматическое исправление ошибок [Psalm](https://psalm.dev/) через AST-трансформации с использованием [nikic/php-parser](https://github.com/nikic/PHP-Parser).

## Установка

```bash
composer require zoon/psalm-fixer --dev
```

## Использование

```bash
# Запустить Psalm с JSON-выводом и передать фиксеру
vendor/bin/psalm --output-format=json | bin/psalm-fixer fix -

# Или через файл
vendor/bin/psalm --output-format=json > psalm-issues.json
bin/psalm-fixer fix psalm-issues.json
```

### Опции

| Опция | Описание |
|-------|----------|
| `--dry-run` | Показать что будет исправлено, без изменения файлов |
| `--diff` | Показать diff изменений (включает `--dry-run`) |
| `--backup` | Создать `.bak` файлы перед изменением |
| `--issue-type=X,Y` | Исправлять только указанные типы ошибок |
| `--file=pattern` | Исправлять только в файлах, содержащих паттерн |

```bash
# Посмотреть все доступные фиксеры
bin/psalm-fixer list-fixers
```

## Поддерживаемые типы ошибок

**CodeQuality** — RedundantCast, RedundantIdentityWithTrue, UnusedForeachValue, UnusedClosureParam

**ClassDesign** — MissingOverrideAttribute, MissingClassConstType, PropertyNotSetInConstructor

**NullSafety** — PossiblyNullReference, PossiblyNullPropertyFetch, PossiblyNullArgument, PossiblyNullArrayAccess

**TypeSafety** — InvalidScalarArgument, RedundantCondition, TypeDoesNotContainNull, ArgumentTypeCoercion

**Mixed** — MixedArgument, MixedAssignment, MixedMethodCall, MixedReturnStatement, MixedPropertyFetch, MixedArrayAccess

**Docblock** — MismatchingDocblockPropertyType

## Требования

- PHP >= 8.0
- Psalm >= 6.15 (для генерации JSON-вывода)

## Лицензия

MIT
