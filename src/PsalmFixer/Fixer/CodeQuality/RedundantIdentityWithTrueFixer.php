<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\CodeQuality;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\ConstFetch;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Simplifies `$x === true` → `$x` and `$x === false` → `!$x`.
 */
final class RedundantIdentityWithTrueFixer extends AbstractFixer
{
    #[\Override]
    public function getSupportedTypes(): array
    {
        return ['RedundantIdentityWithTrue'];
    }

    #[\Override]
    public function getName(): string
    {
        return 'RedundantIdentityWithTrueFixer';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Simplifies $x === true to $x';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult
    {
        $replaced = $this->replaceNodeAtLine($stmts, $issue->getLineFrom(), static function (Node $node): ?Node {
            if ($node instanceof Identical) {
                if (self::isTrueLiteral($node->right)) {
                    return $node->left;
                }
                if (self::isTrueLiteral($node->left)) {
                    return $node->right;
                }
            }

            return null;
        });

        if ($replaced) {
            return FixResult::fixed('Simplified === true comparison');
        }

        return FixResult::notFixed('Could not find identity comparison with true');
    }

    private static function isTrueLiteral(Node $node): bool
    {
        return $node instanceof ConstFetch && strtolower($node->name->toString()) === 'true';
    }
}
