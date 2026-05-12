<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\NullSafety;

use PhpParser\Node;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Converts $obj->prop to $obj?->prop for possibly null objects.
 */
final class PossiblyNullPropertyFetchFixer extends AbstractFixer
{
    #[\Override]
    public function getSupportedTypes(): array
    {
        return ['PossiblyNullPropertyFetch'];
    }

    #[\Override]
    public function getName(): string
    {
        return 'PossiblyNullPropertyFetchFixer';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Converts ->prop to ?->prop for possibly null objects';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult
    {
        $replaced = $this->replaceNodeAtLine($stmts, $issue->getLineFrom(), static function (Node $node): ?Node {
            if (!$node instanceof PropertyFetch) {
                return null;
            }

            return new NullsafePropertyFetch($node->var, $node->name, $node->getAttributes());
        });

        if ($replaced) {
            return FixResult::fixed('Converted to null-safe property fetch ?->');
        }

        return FixResult::notFixed('Could not find property fetch');
    }
}
