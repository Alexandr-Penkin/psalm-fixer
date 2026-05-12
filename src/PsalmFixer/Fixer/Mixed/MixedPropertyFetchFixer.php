<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\Mixed;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;
use PsalmFixer\Ast\TypeStringParser;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\BuildsAssertExpression;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Adds assert($var instanceof Class) before mixed property fetches.
 */
final class MixedPropertyFetchFixer extends AbstractFixer
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
        return ['MixedPropertyFetch'];
    }

    #[\Override]
    public function getName(): string
    {
        return 'MixedPropertyFetchFixer';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Adds assert($var instanceof Class) before mixed property fetches';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult
    {
        $varName = self::extractVarName($issue);
        if ($varName === null) {
            return FixResult::notFixed('Could not extract variable name');
        }

        $className = $this->inferClassFromParamType($stmts, $issue->getLineFrom(), $varName);

        /** @var Node\Expr $assertExpr */
        $assertExpr = $className !== null && $this->typeParser->isClassType($className)
            ? new Instanceof_(new Variable($varName), new Name\FullyQualified(ltrim($className, '\\')))
            : new FuncCall(new Name('is_object'), [new Arg(new Variable($varName))]);

        $assertStmt = new Expression($this->wrapInAssert($assertExpr));

        if ($this->alreadyHasGuardBefore($stmts, $issue->getLineFrom(), $assertStmt)) {
            return FixResult::notFixed("assert for \${$varName} already present");
        }

        $inserted = $this->insertStatementBefore($stmts, $issue->getLineFrom(), $assertStmt);

        if ($inserted) {
            $desc = $className !== null
                ? "Added assert(\${$varName} instanceof {$className})"
                : "Added assert(is_object(\${$varName}))";
            return FixResult::fixed($desc);
        }

        return FixResult::notFixed('Could not insert assert statement');
    }

    /**
     * Try to infer class type from method/function parameter type hints.
     *
     * @param list<Node> $stmts
     * @param non-empty-string $varName
     * @return non-empty-string|null
     */
    private function inferClassFromParamType(array $stmts, int $line, string $varName): ?string
    {
        $func = $this->nodeFinder->findContainingFunction($stmts, $line);
        if ($func === null) {
            return null;
        }

        foreach ($func->getParams() as $param) {
            if ($param->var instanceof Variable && $param->var->name === $varName && $param->type !== null) {
                if ($param->type instanceof Node\Name) {
                    return $param->type->toString();
                }
                if ($param->type instanceof Node\NullableType && $param->type->type instanceof Node\Name) {
                    return $param->type->type->toString();
                }
            }
        }

        return null;
    }
}
