<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\CodeQuality;

use PhpParser\Node;
use PhpParser\Node\Expr\Cast;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Removes redundant type casts.
 * e.g., (int)$alreadyInt → $alreadyInt
 */
final class RedundantCastFixer extends AbstractFixer {
    #[\Override]
    public function getSupportedTypes(): array {
        return ['RedundantCast', 'RedundantCastGivenDocblockType'];
    }

    #[\Override]
    public function getName(): string {
        return 'RedundantCastFixer';
    }

    #[\Override]
    public function getDescription(): string {
        return 'Removes redundant type casts where variable is already the target type';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult {
        $replaced = $this->replaceNodeAtLine($stmts, $issue->getLineFrom(), static function (Node $node): ?Node {
            if ($node instanceof Cast) {
                return $node->expr;
            }

            return null;
        });

        if ($replaced) {
            return FixResult::fixed('Removed redundant cast');
        }

        return FixResult::notFixed('Could not find cast node');
    }
}
