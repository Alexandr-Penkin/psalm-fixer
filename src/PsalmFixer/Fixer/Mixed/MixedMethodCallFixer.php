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
 * Adds assert($var instanceof Class) before mixed method calls.
 */
final class MixedMethodCallFixer extends AbstractFixer {
    private TypeStringParser $typeParser;

    public function __construct() {
        parent::__construct();
        $this->typeParser = new TypeStringParser();
    }

    #[\Override]
    public function getSupportedTypes(): array {
        return ['MixedMethodCall'];
    }

    #[\Override]
    public function getName(): string {
        return 'MixedMethodCallFixer';
    }

    #[\Override]
    public function getDescription(): string {
        return 'Adds assert($var instanceof Class) before mixed method calls';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult {
        $varName = $this->extractVarName($issue->getMessage());
        if ($varName === null) {
            $varName = $this->extractVarName($issue->getSnippet() ?? '');
        }
        if ($varName === null) {
            return FixResult::notFixed('Could not extract variable name');
        }

        // Try to extract expected class from message
        $expectedType = $this->extractClassFromMessage($issue->getMessage());
        if ($expectedType === null) {
            // Without knowing the type, we can only add assert(is_object())
            $assertExpr = new FuncCall(
                new Name('is_object'),
                [new Arg(new Variable($varName))],
            );
        } elseif ($this->typeParser->isClassType($expectedType)) {
            $assertExpr = new Instanceof_(
                new Variable($varName),
                new Name\FullyQualified(ltrim($expectedType, '\\')),
            );
        } else {
            $assertExpr = new FuncCall(
                new Name('is_object'),
                [new Arg(new Variable($varName))],
            );
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

    /** @return non-empty-string|null */
    private function extractVarName(string $message): ?string {
        if (preg_match('/\$(\w+)/', $message, $matches) === 1 && $matches[1] !== '') {
            return $matches[1];
        }

        return null;
    }

    /** @return non-empty-string|null */
    private function extractClassFromMessage(string $message): ?string {
        // Pattern: "Cannot call method on TYPE" or similar
        if (preg_match('/on\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)/', $message, $matches) === 1 && $matches[1] !== '') {
            return $matches[1];
        }

        return null;
    }
}
