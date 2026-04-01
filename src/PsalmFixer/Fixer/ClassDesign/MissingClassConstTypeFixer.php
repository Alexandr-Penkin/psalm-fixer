<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\ClassDesign;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Scalar;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Array_;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Adds type declarations to class constants (PHP 8.3+).
 */
final class MissingClassConstTypeFixer extends AbstractFixer {
    #[\Override]
    public function getSupportedTypes(): array {
        return ['MissingClassConstType'];
    }

    #[\Override]
    public function getName(): string {
        return 'MissingClassConstTypeFixer';
    }

    #[\Override]
    public function getDescription(): string {
        return 'Adds type to class constants (PHP 8.3+)';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult {
        $replaced = $this->replaceNodeAtLine($stmts, $issue->getLineFrom(), static function (Node $node): ?Node {
            if (!($node instanceof ClassConst)) {
                return null;
            }

            // Already has a type
            if ($node->type !== null) {
                return null;
            }

            // Only handle single-const declarations
            if (count($node->consts) !== 1) {
                return null;
            }

            $value = $node->consts[0]->value;
            $type = self::inferTypeFromValue($value);
            if ($type === null) {
                return null;
            }

            $node->type = new Identifier($type);

            return $node;
        });

        if ($replaced) {
            return FixResult::fixed('Added type to class constant');
        }

        return FixResult::notFixed('Could not determine constant type');
    }

    /** @return non-empty-string|null */
    private static function inferTypeFromValue(Node\Expr $value): ?string {
        if ($value instanceof Scalar\Int_ || $value instanceof Scalar\LNumber) {
            return 'int';
        }
        if ($value instanceof Scalar\Float_ || $value instanceof Scalar\DNumber) {
            return 'float';
        }
        if ($value instanceof Scalar\String_) {
            return 'string';
        }
        if ($value instanceof ConstFetch) {
            $name = strtolower($value->name->toString());
            if ($name === 'true' || $name === 'false') {
                return 'bool';
            }
            if ($name === 'null') {
                return 'null';
            }
        }
        if ($value instanceof Array_) {
            return 'array';
        }

        return null;
    }
}
