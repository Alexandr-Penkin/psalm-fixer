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
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Adds assert(is_type()) or assert($var instanceof Type) for mixed arguments.
 */
final class MixedArgumentFixer extends AbstractFixer {
    private TypeStringParser $typeParser;

    public function __construct() {
        parent::__construct();
        $this->typeParser = new TypeStringParser();
    }

    #[\Override]
    public function getSupportedTypes(): array {
        return ['MixedArgument'];
    }

    #[\Override]
    public function getName(): string {
        return 'MixedArgumentFixer';
    }

    #[\Override]
    public function getDescription(): string {
        return 'Adds type assertion for mixed arguments';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult {
        $expectedType = $this->typeParser->extractExpectedType($issue->getMessage());
        if ($expectedType === null) {
            return FixResult::notFixed('Could not extract expected type from message');
        }

        $varName = $this->extractVarName($issue->getMessage());
        if ($varName === null) {
            $varName = $this->extractVarName($issue->getSnippet() ?? '');
        }
        if ($varName === null) {
            return FixResult::notFixed('Could not extract variable name');
        }

        $assertExpr = $this->buildAssertExpr($varName, $expectedType);
        if ($assertExpr === null) {
            return FixResult::notFixed("Cannot create assert for type: {$expectedType}");
        }

        $assertStmt = new Expression(
            new FuncCall(new Name('assert'), [new Arg($assertExpr)]),
        );

        $inserted = $this->insertStatementBefore($stmts, $issue->getLineFrom(), $assertStmt);

        if ($inserted) {
            return FixResult::fixed("Added assert for \${$varName}");
        }

        return FixResult::notFixed('Could not insert assert statement');
    }

    private function buildAssertExpr(string $varName, string $type): ?Node\Expr {
        assert($type !== '');
        $isFunc = $this->typeParser->getIsTypeFunction($type);
        if ($isFunc !== null) {
            return new FuncCall(
                new Name($isFunc),
                [new Arg(new Variable($varName))],
            );
        }

        if ($this->typeParser->isClassType($type)) {
            return new Instanceof_(
                new Variable($varName),
                new Name\FullyQualified(ltrim($type, '\\')),
            );
        }

        return null;
    }

    /** @return non-empty-string|null */
    private function extractVarName(string $message): ?string {
        if (preg_match('/\$(\w+)/', $message, $matches) === 1 && $matches[1] !== '') {
            return $matches[1];
        }

        return null;
    }
}
