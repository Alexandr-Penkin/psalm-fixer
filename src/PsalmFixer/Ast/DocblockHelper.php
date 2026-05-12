<?php

declare(strict_types=1);

namespace PsalmFixer\Ast;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

/**
 * Helper for reading and manipulating PHPDoc blocks.
 *
 * @psalm-api Public API for fixers — not all methods are used by the bundled
 * set, but they're part of the contract for third-party fixer authors.
 */
final class DocblockHelper
{
    private Lexer $lexer;
    private PhpDocParser $parser;

    public function __construct()
    {
        $config = new ParserConfig([]);
        $this->lexer = new Lexer($config);
        $constExprParser = new ConstExprParser($config);
        $typeParser = new TypeParser($config, $constExprParser);
        $this->parser = new PhpDocParser($config, $typeParser, $constExprParser);
    }

    /**
     * Parse a PHPDoc comment into an AST node.
     */
    public function parse(string $docComment): PhpDocNode
    {
        $tokens = $this->lexer->tokenize($docComment);
        $tokenIterator = new TokenIterator($tokens);

        return $this->parser->parse($tokenIterator);
    }

    /**
     * Get the PHPDoc comment from a node.
     */
    public function getDocComment(Node $node): ?Doc
    {
        return $node->getDocComment();
    }

    /**
     * Set or replace the PHPDoc comment on a node.
     */
    public function setDocComment(Node $node, string $docText): void
    {
        $node->setDocComment(new Doc($docText));
    }

    /**
     * Add a tag to an existing PHPDoc or create a new one.
     *
     * @param non-empty-string $tagName e.g. '@param', '@return'
     * @param non-empty-string $tagValue e.g. 'int $foo'
     */
    public function addTag(Node $node, string $tagName, string $tagValue): void
    {
        $doc = $node->getDocComment();
        if ($doc !== null) {
            $text = $doc->getText();
            // Insert before closing */
            $newText = preg_replace('/\s*\*\/\s*$/', "\n * {$tagName} {$tagValue}\n */", $text);
            if (is_string($newText)) {
                $node->setDocComment(new Doc($newText));
            }
        } else {
            $node->setDocComment(new Doc("/**\n * {$tagName} {$tagValue}\n */"));
        }
    }

    /**
     * Check if a node has a specific PHPDoc tag.
     *
     * @param non-empty-string $tagName e.g. '@param', '@return', '@template'
     */
    public function hasTag(Node $node, string $tagName): bool
    {
        $doc = $node->getDocComment();
        if ($doc === null) {
            return false;
        }

        $phpDoc = $this->parse($doc->getText());
        foreach ($phpDoc->getTags() as $tag) {
            if ($tag->name === $tagName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove a specific tag from the PHPDoc.
     *
     * @param non-empty-string $tagName
     */
    public function removeTag(Node $node, string $tagName): void
    {
        $doc = $node->getDocComment();
        if ($doc === null) {
            return;
        }

        $lines = explode("\n", $doc->getText());
        $filteredLines = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*\*\s*' . preg_quote($tagName, '/') . '(\s|$)/', $line) !== 1) {
                $filteredLines[] = $line;
            }
        }

        $newText = implode("\n", $filteredLines);
        $node->setDocComment(new Doc($newText));
    }
}
