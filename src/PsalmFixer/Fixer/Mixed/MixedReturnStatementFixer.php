<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\Mixed;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use PsalmFixer\Ast\NodeFinder;
use PsalmFixer\Ast\TypeStringParser;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Adds assert or cast before return statements with mixed values.
 */
final class MixedReturnStatementFixer extends AbstractFixer {
    private TypeStringParser $typeParser;

    public function __construct() {
        parent::__construct();
        $this->typeParser = new TypeStringParser();
    }

    #[\Override]
    public function getSupportedTypes(): array {
        return ['MixedReturnStatement'];
    }

    #[\Override]
    public function getName(): string {
        return 'MixedReturnStatementFixer';
    }

    #[\Override]
    public function getDescription(): string {
        return 'Adds assert or cast before return with mixed values';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult {
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
        $varName = $this->extractVarName($issue->getSnippet() ?? $issue->getMessage());
        if ($varName !== null && $this->typeParser->isClassType($expectedType)) {
            $assertExpr = new Instanceof_(
                new Variable($varName),
                new Name\FullyQualified(ltrim($expectedType, '\\')),
            );
            $assertStmt = new Expression(
                new FuncCall(new Name('assert'), [new Arg($assertExpr)]),
            );
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
    private function addCastToReturn(array &$stmts, int $line, string $castType): FixResult {
        $castClass = match ($castType) {
            'int' => Node\Expr\Cast\Int_::class,
            'float' => Node\Expr\Cast\Double::class,
            'string' => Node\Expr\Cast\String_::class,
            'bool' => Node\Expr\Cast\Bool_::class,
            'array' => Node\Expr\Cast\Array_::class,
            default => null,
        };

        if ($castClass === null) {
            return FixResult::notFixed("Unknown cast type: {$castType}");
        }

        $replaced = $this->replaceNodeAtLine($stmts, $line, static function (Node $node) use ($castClass): ?Node {
            if ($node instanceof Return_ && $node->expr !== null) {
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
    private function extractReturnTypeFromMethod(array $stmts, int $line): ?string {
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
            $name = $returnType->toString();
            return $name;
        }
        if ($returnType instanceof Node\NullableType) {
            $inner = $returnType->type;
            if ($inner instanceof Node\Identifier) {
                $name = $inner->name;
                return $name;
            }
            $name = $inner->toString();
            return $name;
        }

        return null;
    }

    /** @return non-empty-string|null */
    private function extractVarName(string $text): ?string {
        if (preg_match('/\$(\w+)/', $text, $matches) === 1 && $matches[1] !== '') {
            return $matches[1];
        }

        return null;
    }
}
