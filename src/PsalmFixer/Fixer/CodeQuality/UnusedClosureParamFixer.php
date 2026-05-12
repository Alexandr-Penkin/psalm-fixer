<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\CodeQuality;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Prefixes unused closure parameters with $_.
 */
final class UnusedClosureParamFixer extends AbstractFixer
{
    #[\Override]
    public function getSupportedTypes(): array
    {
        return ['UnusedClosureParam'];
    }

    #[\Override]
    public function getName(): string
    {
        return 'UnusedClosureParamFixer';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Prefixes unused closure params with $_';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult
    {
        // Extract param name from message like "Param $foo is never used in this method"
        $paramName = $this->extractParamName($issue->getMessage());
        if ($paramName === null) {
            return FixResult::notFixed('Could not extract param name from message');
        }

        $replaced = $this->replaceNodeAtLine($stmts, $issue->getLineFrom(), static function (Node $node) use (
            $paramName,
        ): ?Node {
            if ($node instanceof Closure || $node instanceof ArrowFunction) {
                foreach ($node->params as $param) {
                    if (
                        $param->var instanceof Node\Expr\Variable
                        && is_string($param->var->name)
                        && $param->var->name === $paramName
                    ) {
                        $param->var->name = '_' . $paramName;
                        return $node;
                    }
                }
            }

            return null;
        });

        if ($replaced) {
            return FixResult::fixed("Renamed \${$paramName} to \$_{$paramName}");
        }

        return FixResult::notFixed('Could not find closure parameter');
    }

    /** @return non-empty-string|null */
    private function extractParamName(string $message): ?string
    {
        if (preg_match('/\$(\w+)/', $message, $matches) === 1) {
            $name = $matches[1];
            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }
}
