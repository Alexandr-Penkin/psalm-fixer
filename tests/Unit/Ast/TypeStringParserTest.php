<?php

declare(strict_types=1);

namespace PsalmFixer\Tests\Unit\Ast;

use PHPUnit\Framework\TestCase;
use PsalmFixer\Ast\TypeStringParser;

final class TypeStringParserTest extends TestCase {
    private TypeStringParser $parser;

    protected function setUp(): void {
        $this->parser = new TypeStringParser();
    }

    public function testIsScalarType(): void {
        self::assertTrue($this->parser->isScalarType('int'));
        self::assertTrue($this->parser->isScalarType('string'));
        self::assertTrue($this->parser->isScalarType('float'));
        self::assertTrue($this->parser->isScalarType('bool'));
        self::assertTrue($this->parser->isScalarType('array'));
        self::assertFalse($this->parser->isScalarType('object'));
        self::assertFalse($this->parser->isScalarType('SomeClass'));
    }

    public function testGetCastType(): void {
        self::assertSame('int', $this->parser->getCastType('int'));
        self::assertSame('int', $this->parser->getCastType('integer'));
        self::assertSame('float', $this->parser->getCastType('float'));
        self::assertSame('string', $this->parser->getCastType('string'));
        self::assertSame('bool', $this->parser->getCastType('bool'));
        self::assertNull($this->parser->getCastType('object'));
    }

    public function testIsClassType(): void {
        self::assertTrue($this->parser->isClassType('SomeClass'));
        self::assertTrue($this->parser->isClassType('App\\Model\\User'));
        self::assertFalse($this->parser->isClassType('int'));
        self::assertFalse($this->parser->isClassType('void'));
        self::assertFalse($this->parser->isClassType('mixed'));
    }

    public function testExtractExpectedType(): void {
        self::assertSame('int', $this->parser->extractExpectedType('expects int, string provided'));
        self::assertSame('string', $this->parser->extractExpectedType('expects string, int given'));
        self::assertNull($this->parser->extractExpectedType('no type info here'));
    }

    public function testExtractProvidedType(): void {
        self::assertSame('string', $this->parser->extractProvidedType('expects int, string provided'));
        self::assertSame('int', $this->parser->extractProvidedType('expects string, int given'));
    }

    public function testGetIsTypeFunction(): void {
        self::assertSame('is_int', $this->parser->getIsTypeFunction('int'));
        self::assertSame('is_string', $this->parser->getIsTypeFunction('string'));
        self::assertSame('is_float', $this->parser->getIsTypeFunction('float'));
        self::assertSame('is_bool', $this->parser->getIsTypeFunction('bool'));
        self::assertSame('is_array', $this->parser->getIsTypeFunction('array'));
        self::assertSame('is_object', $this->parser->getIsTypeFunction('object'));
        self::assertNull($this->parser->getIsTypeFunction('SomeClass'));
    }
}
