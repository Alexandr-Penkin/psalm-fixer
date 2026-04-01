<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\CodeQuality;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Foreach_;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Renames unused foreach value variable to $_.
 */
final class UnusedForeachValueFixer extends AbstractFixer {
    #[\Override]
    public function getSupportedTypes(): array {
        return ['UnusedForeachValue'];
    }

    #[\Override]
    public function getName(): string {
        return 'UnusedForeachValueFixer';
    }

    #[\Override]
    public function getDescription(): string {
        return 'Renames unused foreach value to $_';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult {
        $replaced = $this->replaceNodeAtLine($stmts, $issue->getLineFrom(), static function (Node $node): ?Node {
            if ($node instanceof Foreach_ && $node->valueVar instanceof Variable && is_string($node->valueVar->name)) {
                $node->valueVar->name = '_';
                return $node;
            }

            return null;
        });

        if ($replaced) {
            return FixResult::fixed('Renamed unused foreach value to $_');
        }

        return FixResult::notFixed('Could not find foreach statement');
    }
}
