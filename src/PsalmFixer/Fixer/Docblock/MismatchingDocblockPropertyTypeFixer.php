<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\Docblock;

use PhpParser\Node;
use PhpParser\Node\Stmt\Property;
use PsalmFixer\Ast\DocblockHelper;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Synchronizes PHPDoc @var with native property type.
 * Removes mismatching @var and lets the native type be the source of truth.
 */
final class MismatchingDocblockPropertyTypeFixer extends AbstractFixer
{
    private DocblockHelper $docblockHelper;

    public function __construct()
    {
        parent::__construct();
        $this->docblockHelper = new DocblockHelper();
    }

    #[\Override]
    public function getSupportedTypes(): array
    {
        return ['MismatchingDocblockPropertyType'];
    }

    #[\Override]
    public function getName(): string
    {
        return 'MismatchingDocblockPropertyTypeFixer';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Removes mismatching @var from properties with native types';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult
    {
        $replaced = $this->replaceNodeAtLine($stmts, $issue->getLineFrom(), function (Node $node): ?Node {
            if (!$node instanceof Property) {
                return null;
            }

            // If the property has a native type, remove the @var tag
            if ($node->type !== null) {
                $this->docblockHelper->removeTag($node, '@var');
                return $node;
            }

            return null;
        });

        if ($replaced) {
            return FixResult::fixed('Removed mismatching @var from typed property');
        }

        return FixResult::notFixed('Could not find property declaration');
    }
}
