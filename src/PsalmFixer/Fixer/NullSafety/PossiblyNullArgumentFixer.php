<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\NullSafety;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Expr\Throw_;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Adds null guard before function/method calls with possibly null arguments.
 * Inserts: if ($var === null) { throw new \InvalidArgumentException(...); }
 */
final class PossiblyNullArgumentFixer extends AbstractFixer {
    #[\Override]
    public function getSupportedTypes(): array {
        return ['PossiblyNullArgument'];
    }

    #[\Override]
    public function getName(): string {
        return 'PossiblyNullArgumentFixer';
    }

    #[\Override]
    public function getDescription(): string {
        return 'Adds null guard (throw) before calls with possibly null arguments';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult {
        $varName = $this->extractVarName($issue->getMessage());
        if ($varName === null) {
            $varName = $this->extractVarName($issue->getSnippet() ?? '');
        }
        if ($varName === null) {
            $varName = $this->extractVarFromAst($stmts, $issue);
        }
        if ($varName === null) {
            return FixResult::notFixed('Could not extract variable name from message');
        }

        $guard = $this->createNullGuard($varName);
        $inserted = $this->insertStatementBefore($stmts, $issue->getLineFrom(), $guard);

        if ($inserted) {
            return FixResult::fixed("Added null guard for \${$varName}");
        }

        return FixResult::notFixed('Could not insert null guard');
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

    /**
     * Try to extract variable name from AST by parsing argument number from message.
     * Message format: "Argument N of Foo::bar cannot be null"
     *
     * @param list<Node> $stmts
     * @return non-empty-string|null
     */
    private function extractVarFromAst(array $stmts, PsalmIssue $issue): ?string {
        // Extract argument number
        if (preg_match('/Argument\s+(\d+)\s+of/', $issue->getMessage(), $matches) !== 1) {
            return null;
        }
        $argIndex = (int) $matches[1] - 1;

        // Find function/method call at the issue line
        $nodes = $this->nodeFinder->findAllNodesAtLine($stmts, $issue->getLineFrom());
        foreach ($nodes as $node) {
            if ($node instanceof FuncCall || $node instanceof MethodCall || $node instanceof StaticCall) {
                $args = $node->getArgs();
                if (array_key_exists($argIndex, $args)) {
                    $argValue = $args[$argIndex]->value;
                    if ($argValue instanceof Variable && is_string($argValue->name) && $argValue->name !== '') {
                        return $argValue->name;
                    }
                }
            }
        }

        return null;
    }

    private function createNullGuard(string $varName): If_ {
        $condition = new Identical(
            new Variable($varName),
            new ConstFetch(new Name('null')),
        );

        $throw = new Throw_(
            new Node\Expr\New_(
                new Name\FullyQualified('InvalidArgumentException'),
                [
                    new Arg(
                        new Node\Scalar\String_("Argument \${$varName} cannot be null"),
                    ),
                ],
            ),
        );

        return new If_($condition, [
            'stmts' => [new Expression($throw)],
        ]);
    }
}
