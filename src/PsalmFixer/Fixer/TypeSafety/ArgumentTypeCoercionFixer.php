<?php

declare(strict_types=1);

namespace PsalmFixer\Fixer\TypeSafety;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PsalmFixer\Ast\TypeStringParser;
use PsalmFixer\Fixer\AbstractFixer;
use PsalmFixer\Fixer\FixResult;
use PsalmFixer\Parser\PsalmIssue;

/**
 * Resolves `ArgumentTypeCoercion` warnings in three modes, in order of preference:
 *
 *   1. Insert `assert($var instanceof Type)` / `assert(is_*($var))` for class
 *      and scalar types.
 *   2. Insert `assert($var !== '')` for `non-empty-string` expectations.
 *   3. Fall back to `@psalm-suppress ArgumentTypeCoercion` on the call's
 *      statement when no safe runtime assertion can be generated (generic types,
 *      list shapes, non-variable argument expressions).
 *
 * The variable to assert against is taken from the message if it carries a name,
 * otherwise from the AST: the message's `Argument N` index points at the Nth
 * argument of the call located at the issue line.
 */
final class ArgumentTypeCoercionFixer extends AbstractFixer {
    private const SUPPRESS_TAG = '@psalm-suppress ArgumentTypeCoercion';

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
        return 'Adds assert($var instanceof Type) for type coercion, with @psalm-suppress fallback';
    }

    #[\Override]
    public function fix(PsalmIssue $issue, array &$stmts): FixResult {
        $message = $issue->getMessage();
        $expectedType = $this->typeParser->extractExpectedType($message);
        $varName = $this->resolveVarName($stmts, $issue, $message);

        if ($expectedType !== null && $varName !== null) {
            $assertResult = $this->tryAddAssert($stmts, $issue, $expectedType, $varName);
            if ($assertResult !== null) {
                return $assertResult;
            }
        }

        return $this->fallbackSuppress($stmts, $issue);
    }

    /**
     * @param list<Node> $stmts
     * @param non-empty-string $expectedType
     * @param non-empty-string $varName
     */
    private function tryAddAssert(array &$stmts, PsalmIssue $issue, string $expectedType, string $varName): ?FixResult {
        $isFunc = $this->typeParser->getIsTypeFunction($expectedType);
        if ($isFunc !== null) {
            return $this->insertAssert(
                $stmts,
                $issue,
                new FuncCall(new Name($isFunc), [new Arg(new Variable($varName))]),
                "assert({$isFunc}(\${$varName}))",
            );
        }

        if ($this->typeParser->isClassType($expectedType)) {
            $className = ltrim($expectedType, '\\');
            $instanceof = new Instanceof_(
                new Variable($varName),
                new Name\FullyQualified($className),
            );

            return $this->insertAssert(
                $stmts,
                $issue,
                $instanceof,
                "assert(\${$varName} instanceof \\{$className})",
            );
        }

        if (strtolower($expectedType) === 'non-empty-string') {
            $check = new BinaryOp\NotIdentical(
                new Variable($varName),
                new String_(''),
            );

            return $this->insertAssert(
                $stmts,
                $issue,
                $check,
                "assert(\${$varName} !== '')",
            );
        }

        return null;
    }

    /**
     * Find the variable name to assert against. Prefer the message (it names
     * `$varName` directly when Psalm has it), otherwise fall back to inspecting
     * the call's Nth argument in the AST.
     *
     * @param list<Node> $stmts
     * @return non-empty-string|null
     */
    private function resolveVarName(array $stmts, PsalmIssue $issue, string $message): ?string {
        if (preg_match('/\$(\w+)/', $message, $matches) === 1 && $matches[1] !== '') {
            return $matches[1];
        }

        $argIndex = $this->extractArgIndex($message);
        if ($argIndex === null) {
            return null;
        }

        $call = $this->findCallAtLine($stmts, $issue->getLineFrom());
        if ($call === null) {
            return null;
        }

        $args = $call instanceof Node\Expr\MethodCall
            || $call instanceof Node\Expr\StaticCall
            || $call instanceof Node\Expr\FuncCall
            || $call instanceof Node\Expr\NullsafeMethodCall
                ? $call->args
                : [];
        $arg = $args[$argIndex - 1] ?? null;
        if (!$arg instanceof Arg) {
            return null;
        }
        if (!$arg->value instanceof Variable || !is_string($arg->value->name) || $arg->value->name === '') {
            return null;
        }

        return $arg->value->name;
    }

    /**
     * @return positive-int|null
     */
    private function extractArgIndex(string $message): ?int {
        if (preg_match('/Argument\s+(\d+)\b/i', $message, $matches) === 1) {
            $n = (int)$matches[1];
            if ($n >= 1) {
                return $n;
            }
        }

        return null;
    }

    /**
     * @param list<Node> $stmts
     */
    private function findCallAtLine(array $stmts, int $line): ?Node\Expr {
        $found = null;
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($line, $found) extends NodeVisitorAbstract {
            public function __construct(
                private int $targetLine,
                private ?Node\Expr &$found,
            ) {
            }

            #[\Override]
            public function enterNode(Node $node): ?int {
                if ($node->getStartLine() !== $this->targetLine) {
                    return null;
                }
                if ($node instanceof FuncCall
                    || $node instanceof Node\Expr\MethodCall
                    || $node instanceof Node\Expr\StaticCall
                    || $node instanceof Node\Expr\NullsafeMethodCall
                ) {
                    // Prefer the outermost call at the line — it's the one the
                    // Psalm message points at when the offending argument is
                    // passed directly.
                    if ($this->found === null) {
                        $this->found = $node;
                    }
                }

                return null;
            }
        });
        $traverser->traverse($stmts);

        return $found;
    }

    /**
     * @param list<Node> $stmts
     * @param non-empty-string $description
     */
    private function insertAssert(array &$stmts, PsalmIssue $issue, Node\Expr $condition, string $description): FixResult {
        $assert = new FuncCall(new Name('assert'), [new Arg($condition)]);
        $inserted = $this->insertStatementBefore($stmts, $issue->getLineFrom(), new Expression($assert));

        if ($inserted) {
            return FixResult::fixed("Added {$description}");
        }

        return FixResult::notFixed('Could not insert assert');
    }

    /**
     * Last resort — annotate the offending statement with @psalm-suppress.
     * Behaves like `PropertyTypeCoercionFixer`: merges into an existing
     * docblock or creates a new one.
     *
     * @param list<Node> $stmts
     */
    private function fallbackSuppress(array &$stmts, PsalmIssue $issue): FixResult {
        $node = $this->findStatementAtLine($stmts, $issue->getLineFrom());
        if ($node === null) {
            return FixResult::notFixed('Could not find statement at target line for fallback suppress');
        }

        $existingDoc = $node->getDocComment();
        if ($existingDoc !== null && str_contains($existingDoc->getText(), self::SUPPRESS_TAG)) {
            return FixResult::notFixed('Statement already has the suppress annotation');
        }

        $node->setDocComment($this->makeDocComment($existingDoc));

        return FixResult::fixed('Added @psalm-suppress ArgumentTypeCoercion (fallback)');
    }

    /**
     * Locate the innermost `Stmt` whose line range covers $line. Prefer an exact
     * start-line match (the typical single-line statement case), but fall back
     * to a containing statement for multiline expressions where the offending
     * argument sits on an inner line of e.g. a multi-line `new Foo(...)` call.
     *
     * @param list<Node> $stmts
     */
    private function findStatementAtLine(array $stmts, int $line): ?Node\Stmt {
        $found = null;
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($line, $found) extends NodeVisitorAbstract {
            public function __construct(
                private int $targetLine,
                private ?Node\Stmt &$found,
            ) {
            }

            #[\Override]
            public function enterNode(Node $node): ?int {
                if (!$node instanceof Node\Stmt) {
                    return null;
                }
                $start = $node->getStartLine();
                $end = $node->getEndLine();
                if ($start > $this->targetLine || $end < $this->targetLine) {
                    return null;
                }

                // Prefer the deepest (smallest range) match. ClassLike and
                // ClassMethod outer Stmts span many lines, but we want the
                // statement that actually contains the call.
                if ($this->found === null) {
                    $this->found = $node;

                    return null;
                }
                $currentSpan = $node->getEndLine() - $node->getStartLine();
                $bestSpan = $this->found->getEndLine() - $this->found->getStartLine();
                if ($currentSpan <= $bestSpan) {
                    $this->found = $node;
                }

                return null;
            }
        });
        $traverser->traverse($stmts);

        return $found;
    }

    private function makeDocComment(?Doc $existing): Doc {
        if ($existing === null) {
            return new Doc('/** ' . self::SUPPRESS_TAG . ' */');
        }

        $text = $existing->getText();
        if (preg_match('#^/\*\*\s*(.*?)\s*\*/$#s', $text, $matches) !== 1) {
            return new Doc('/** ' . self::SUPPRESS_TAG . ' */');
        }

        $body = trim($matches[1]);
        if ($body === '') {
            return new Doc('/** ' . self::SUPPRESS_TAG . ' */');
        }

        $lines = preg_split('/\R/', $body);
        if ($lines === false) {
            $lines = [$body];
        }
        $normalised = [];
        foreach ($lines as $line) {
            $normalised[] = ltrim($line, " \t*");
        }
        $normalised[] = self::SUPPRESS_TAG;

        return new Doc("/**\n * " . implode("\n * ", $normalised) . "\n */");
    }
}
