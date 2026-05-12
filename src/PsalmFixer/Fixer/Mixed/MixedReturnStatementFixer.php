<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\Mixed;

use PhpParser\Node;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use PsalmFixer\Ast\TypeStringParser;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\BuildsAssertExpression;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Adds assert or cast before return statements with mixed values.
 */
final class MixedReturnStatementFixer extends AbstractFixer
{
    use BuildsAssertExpression;

    private TypeStringParser $typeParser;

    public function __construct()
    {
        parent::__construct();
        $this->typeParser = new TypeStringParser();
    }

    #[\Override]
    public function getSupportedTypes(): array
    {
        return ['MixedReturnStatement'];
    }

    #[\Override]
    public function getName(): string
    {
        return 'MixedReturnStatementFixer';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Adds assert or cast before return with mixed values';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult
    {
        $expectedType = $this->typeParser->extractExpectedType($issue->getMessage());

        // Fallback: extract return type from containing method/function
        if ($expectedType === null) {
            $expectedType = $this->extractReturnTypeFromMethod($stmts, $issue->getLineFrom());
        }

        if ($expectedType === null) {
            return FixResult::notFixed('Could not extract expected return type');
        }

        // For scalar types, wrap the return expression with a cast
        $castType = $this->typeParser->getCastType($expectedType);
        if ($castType !== null) {
            return $this->addCastToReturn($stmts, $issue->getLineFrom(), $castType);
        }

        // For class types, add assert before return
        $varName = self::extractVarNameFromText($issue->getSnippet() ?? $issue->getMessage());
        if ($varName !== null && $this->typeParser->isClassType($expectedType)) {
            $assertExpr = new Instanceof_(new Variable($varName), new Name\FullyQualified(ltrim($expectedType, '\\')));
            $assertStmt = new Expression($this->wrapInAssert($assertExpr));
            if ($this->alreadyHasGuardBefore($stmts, $issue->getLineFrom(), $assertStmt)) {
                return FixResult::notFixed("assert for \${$varName} already present before return");
            }
            $inserted = $this->insertStatementBefore($stmts, $issue->getLineFrom(), $assertStmt);
            if ($inserted) {
                return FixResult::fixed("Added assert before return for \${$varName}");
            }
        }

        return FixResult::notFixed('Could not fix mixed return statement');
    }

    /**
     * @param list<Node> $stmts
     * @param non-empty-string $castType
     */
    private function addCastToReturn(array &$stmts, int $line, string $castType): FixResult
    {
        $castClass = self::castClassFor($castType);

        if ($castClass === null) {
            return FixResult::notFixed("Unknown cast type: {$castType}");
        }

        $replaced = $this->replaceNodeAtLine($stmts, $line, static function (Node $node) use ($castClass): ?Node {
            if ($node instanceof Return_ && $node->expr !== null && !$node->expr instanceof $castClass) {
                /** @psalm-suppress UnsafeInstantiation */
                $node->expr = new $castClass($node->expr);
                return $node;
            }

            return null;
        });

        if ($replaced) {
            return FixResult::fixed("Added ({$castType}) cast to return expression");
        }

        return FixResult::notFixed('Could not find return statement');
    }

    /**
     * Extract the return type from the containing method/function declaration.
     *
     * @param list<Node> $stmts
     * @return non-empty-string|null
     */
    private function extractReturnTypeFromMethod(array $stmts, int $line): ?string
    {
        $func = $this->nodeFinder->findContainingFunction($stmts, $line);
        if ($func === null || $func->returnType === null) {
            return null;
        }

        $returnType = $func->returnType;
        if ($returnType instanceof Node\Identifier) {
            $name = $returnType->name;
            if ($name !== 'void' && $name !== 'never' && $name !== 'mixed') {
                return $name;
            }
        }
        if ($returnType instanceof Node\Name) {
            return $returnType->toString();
        }
        if ($returnType instanceof Node\NullableType) {
            $inner = $returnType->type;
            if ($inner instanceof Node\Identifier) {
                return $inner->name;
            }
            return $inner->toString();
        }

        return null;
    }
}
