<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\ClassDesign;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\ClassMethod;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Adds #[\Override] attribute to methods that override parent methods.
 */
final class MissingOverrideAttributeFixer extends AbstractFixer
{
    #[\Override]
    public function getSupportedTypes(): array
    {
        return ['MissingOverrideAttribute'];
    }

    #[\Override]
    public function getName(): string
    {
        return 'MissingOverrideAttributeFixer';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Adds #[\\Override] attribute to overriding methods';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult
    {
        $replaced = $this->replaceNodeAtLine($stmts, $issue->getLineFrom(), static function (Node $node): ?Node {
            if (!$node instanceof ClassMethod) {
                return null;
            }

            // Check if already has Override attribute
            foreach ($node->attrGroups as $attrGroup) {
                foreach ($attrGroup->attrs as $attr) {
                    if ($attr->name->toString() === 'Override' || $attr->name->toString() === '\\Override') {
                        return null;
                    }
                }
            }

            $attribute = new Attribute(new FullyQualified('Override'));
            $attrGroup = new AttributeGroup([$attribute]);
            array_unshift($node->attrGroups, $attrGroup);

            return $node;
        });

        if ($replaced) {
            return FixResult::fixed('Added #[\\Override] attribute');
        }

        return FixResult::notFixed('Could not find method declaration');
    }
}
