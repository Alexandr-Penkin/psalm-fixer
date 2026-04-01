<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\NullSafety;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Converts $obj->method() to $obj?->method() for possibly null references.
 */
final class PossiblyNullReferenceFixer extends AbstractFixer {
    #[\Override]
    public function getSupportedTypes(): array {
        return ['PossiblyNullReference'];
    }

    #[\Override]
    public function getName(): string {
        return 'PossiblyNullReferenceFixer';
    }

    #[\Override]
    public function getDescription(): string {
        return 'Converts ->method() to ?->method() for possibly null objects';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult {
        $replaced = $this->replaceNodeAtLine($stmts, $issue->getLineFrom(), static function (Node $node): ?Node {
            if (!($node instanceof MethodCall)) {
                return null;
            }

            return new NullsafeMethodCall(
                $node->var,
                $node->name,
                $node->args,
                $node->getAttributes(),
            );
        });

        if ($replaced) {
            return FixResult::fixed('Converted to null-safe method call ?->');
        }

        return FixResult::notFixed('Could not find method call');
    }
}
