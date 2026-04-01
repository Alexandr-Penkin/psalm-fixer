<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\TypeSafety;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;
use PsalmFixer\Ast\TypeStringParser;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Adds assert($var instanceof Type) for type coercion issues.
 */
final class ArgumentTypeCoercionFixer extends AbstractFixer {
    private TypeStringParser $typeParser;

    public function __construct() {
        parent::__construct();
        $this->typeParser = new TypeStringParser();
    }

    #[\Override]
    public function getSupportedTypes(): array {
        return ['ArgumentTypeCoercion'];
    }

    #[\Override]
    public function getName(): string {
        return 'ArgumentTypeCoercionFixer';
    }

    #[\Override]
    public function getDescription(): string {
        return 'Adds assert($var instanceof Type) for type coercion';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult {
        $expectedType = $this->typeParser->extractExpectedType($issue->getMessage());
        if ($expectedType === null) {
            return FixResult::notFixed('Could not extract expected type');
        }

        // For scalar types, use assert(is_type())
        $isFunc = $this->typeParser->getIsTypeFunction($expectedType);
        if ($isFunc !== null) {
            return $this->addScalarAssert($issue, $stmts, $isFunc);
        }

        // For class types, use assert($var instanceof Type)
        if ($this->typeParser->isClassType($expectedType)) {
            return $this->addInstanceofAssert($issue, $stmts, $expectedType);
        }

        return FixResult::notFixed("Cannot create assert for type: {$expectedType}");
    }

    /**
     * @param list<Node> $stmts
     * @param non-empty-string $isFunc
     */
    private function addScalarAssert(PsalmIssue $issue, array &$stmts, string $isFunc): FixResult {
        $varName = $this->extractVarName($issue->getMessage());
        if ($varName === null) {
            return FixResult::notFixed('Could not extract variable name');
        }

        $assert = $this->createAssertCall(
            new FuncCall(
                new Name($isFunc),
                [new Arg(new Variable($varName))],
            ),
        );

        $inserted = $this->insertStatementBefore($stmts, $issue->getLineFrom(), new Expression($assert));

        if ($inserted) {
            return FixResult::fixed("Added assert({$isFunc}(\${$varName}))");
        }

        return FixResult::notFixed('Could not insert assert');
    }

    /**
     * @param list<Node> $stmts
     * @param non-empty-string $className
     */
    private function addInstanceofAssert(PsalmIssue $issue, array &$stmts, string $className): FixResult {
        $varName = $this->extractVarName($issue->getMessage());
        if ($varName === null) {
            return FixResult::notFixed('Could not extract variable name');
        }

        $className = ltrim($className, '\\');
        $instanceof = new Instanceof_(
            new Variable($varName),
            new Name\FullyQualified($className),
        );

        $assert = $this->createAssertCall($instanceof);
        $inserted = $this->insertStatementBefore($stmts, $issue->getLineFrom(), new Expression($assert));

        if ($inserted) {
            return FixResult::fixed("Added assert(\${$varName} instanceof \\{$className})");
        }

        return FixResult::notFixed('Could not insert assert');
    }

    private function createAssertCall(Node\Expr $condition): FuncCall {
        return new FuncCall(
            new Name('assert'),
            [new Arg($condition)],
        );
    }

    /** @return non-empty-string|null */
    private function extractVarName(string $message): ?string {
        if (preg_match('/\$(\w+)/', $message, $matches) === 1) {
            $name = $matches[1];
            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }
}
