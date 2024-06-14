<?php

declare(strict_types=1);

namespace Andante\Doctrine\ORM\Tests;

use Andante\Doctrine\ORM\Exception\CannotOverrideImmutableParameterException;
use Andante\Doctrine\ORM\Exception\CannotOverrideParametersException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Query;

class SharedQueryBuilderParametersTest extends TestCase
{
    public function testCannotOverrideImmutableParameter(): void
    {
        $sqb = $this->createSqb();
        $sqb->setImmutableParameter('parameter1', 1);
        self::assertParametersEquals(['parameter1' => 1], $sqb->getParameters());
        $this->expectException(CannotOverrideImmutableParameterException::class);
        $sqb->setImmutableParameter('parameter1', 1);
    }

    public function testCanOverrideNotImmutableParameter(): void
    {
        $sqb = $this->createSqb();
        $sqb->setParameter('parameter1', 1);
        self::assertParametersEquals(['parameter1' => 1], $sqb->getParameters());
        $sqb->setImmutableParameter('parameter1', 2);
        self::assertParametersEquals(['parameter1' => 2], $sqb->getParameters());
    }

    public function testCanOverrideParametersWhenNoImmutableParametersDefined(): void
    {
        $sqb = $this->createSqb();
        $sqb->setParameter('parameter1', 1);
        $sqb->setParameter('parameter2', 2);
        $sqb->setParameters(new ArrayCollection([
            new Query\Parameter('parameter3', 3),
            new Query\Parameter('parameter4', 4),
        ]));
        self::assertParametersEquals([
            'parameter3' => 3,
            'parameter4' => 4,
        ], $sqb->getParameters());
    }

    public function testCannotOverrideParametersWithParametersWhenImmutableParametersDefined(): void
    {
        $sqb = $this->createSqb();
        $sqb->setParameter('parameter1', 1);
        $sqb->setImmutableParameter('parameter2', 2);
        $this->expectException(CannotOverrideParametersException::class);
        $sqb->setParameters(new ArrayCollection([
            new Query\Parameter('parameter3', 3),
            new Query\Parameter('parameter4', 4),
        ]));
    }

    public function testCannotOverrideParametersWithImmutableParametersWhenImmutableParametersDefined(): void
    {
        $sqb = $this->createSqb();
        $sqb->setParameter('parameter1', 1);
        $sqb->setImmutableParameter('parameter2', 2);
        $this->expectException(CannotOverrideParametersException::class);
        $sqb->setImmutableParameters(new ArrayCollection([
            new Query\Parameter('parameter3', 3),
            new Query\Parameter('parameter4', 4),
        ]));
    }

    public function testGetImmutableParameters(): void
    {
        $sqb = $this->createSqb();
        $sqb->setParameters(new ArrayCollection([
            new Query\Parameter('parameter1', 1),
            new Query\Parameter('parameter2', 2),
        ]));
        $sqb->setImmutableParameter('parameter3', 3);
        $sqb->setImmutableParameter('parameter2', 2);
        self::assertParametersEquals([
            'parameter3' => 3,
            'parameter2' => 2,
        ], $sqb->getImmutableParameters());
        self::assertParametersEquals([
            'parameter1' => 1,
            'parameter2' => 2,
            'parameter3' => 3,
        ], $sqb->getParameters());
        self::assertInstanceOf(Query\Parameter::class, $sqb->getImmutableParameter('parameter3'));
        self::assertInstanceOf(Query\Parameter::class, $sqb->getImmutableParameter('parameter2'));
        self::assertNull($sqb->getImmutableParameter('parameter1'));
    }

    public function testWithParameter(): void
    {
        $sqb = $this->createSqb();
        self::assertParametersEquals([], $sqb->getParameters());
        $parameterName = $sqb->withParameter('timestamp', 1);
        self::assertSame(':timestamp', $parameterName);
        self::assertFalse($sqb->getParameters()->isEmpty());
        self::assertTrue($sqb->getImmutableParameters()->isEmpty());
        self::assertInstanceOf(Query\Parameter::class, $sqb->getParameter($parameterName));
        self::assertNull($sqb->getImmutableParameter($parameterName));
        self::assertSame(1, $sqb->getParameter($parameterName)->getValue());
    }

    public function testWithNumericParameter(): void
    {
        $sqb = $this->createSqb();
        self::assertParametersEquals([], $sqb->getParameters());
        $parameterName = $sqb->withParameter(0, 1);
        self::assertSame('0', $parameterName);
        self::assertFalse($sqb->getParameters()->isEmpty());
        self::assertTrue($sqb->getImmutableParameters()->isEmpty());
        self::assertInstanceOf(Query\Parameter::class, $sqb->getParameter($parameterName));
        self::assertNull($sqb->getImmutableParameter($parameterName));
        self::assertSame(1, $sqb->getParameter($parameterName)->getValue());
    }

    public function testWithImmutableParameter(): void
    {
        $sqb = $this->createSqb();
        self::assertParametersEquals([], $sqb->getParameters());
        $parameterName = $sqb->withImmutableParameter('timestamp', 1);
        self::assertSame(':timestamp', $parameterName);
        self::assertFalse($sqb->getParameters()->isEmpty());
        self::assertFalse($sqb->getImmutableParameters()->isEmpty());
        self::assertInstanceOf(Query\Parameter::class, $sqb->getParameter($parameterName));
        self::assertInstanceOf(Query\Parameter::class, $sqb->getImmutableParameter($parameterName));
        self::assertSame(1, $sqb->getParameter($parameterName)->getValue());
        self::assertSame(1, $sqb->getImmutableParameter($parameterName)->getValue());
    }

    public function testWithUniqueParameter(): void
    {
        $sqb = $this->createSqb();
        self::assertParametersEquals([], $sqb->getParameters());
        $parameterName = $sqb->withUniqueParameter('timestamp', 1);
        self::assertNotSame(':timestamp', $parameterName);
        self::assertStringContainsString('timestamp', $parameterName);
        self::assertStringStartsWith(':', $parameterName);
        self::assertFalse($sqb->getParameters()->isEmpty());
        self::assertTrue($sqb->getImmutableParameters()->isEmpty());
        self::assertInstanceOf(Query\Parameter::class, $sqb->getParameter($parameterName));
        self::assertNull($sqb->getImmutableParameter($parameterName));
        self::assertSame(1, $sqb->getParameter($parameterName)->getValue());
    }

    public function testWithUniqueNumericParameter(): void
    {
        $sqb = $this->createSqb();
        self::assertParametersEquals([], $sqb->getParameters());
        $parameterName = $sqb->withUniqueParameter(0, 1);
        self::assertNotSame('0', $parameterName);
        self::assertStringContainsString('0', $parameterName);
        self::assertStringStartsWith(':', $parameterName);
        self::assertFalse($sqb->getParameters()->isEmpty());
        self::assertTrue($sqb->getImmutableParameters()->isEmpty());
        self::assertInstanceOf(Query\Parameter::class, $sqb->getParameter($parameterName));
        self::assertNull($sqb->getImmutableParameter($parameterName));
        self::assertSame(1, $sqb->getParameter($parameterName)->getValue());
    }

    public function testWithUniqueImmutableParameter(): void
    {
        $sqb = $this->createSqb();
        self::assertParametersEquals([], $sqb->getParameters());
        $parameterName = $sqb->withUniqueImmutableParameter('timestamp', 1);
        self::assertNotSame(':timestamp', $parameterName);
        self::assertStringContainsString('timestamp', $parameterName);
        self::assertStringStartsWith(':', $parameterName);
        self::assertFalse($sqb->getParameters()->isEmpty());
        self::assertFalse($sqb->getImmutableParameters()->isEmpty());
        self::assertInstanceOf(Query\Parameter::class, $sqb->getParameter($parameterName));
        self::assertInstanceOf(Query\Parameter::class, $sqb->getImmutableParameter($parameterName));
        self::assertSame(1, $sqb->getParameter($parameterName)->getValue());
        self::assertSame(1, $sqb->getImmutableParameter($parameterName)->getValue());
    }

    /**
     * @param array<int|string, mixed>              $expected
     * @param ArrayCollection<int, Query\Parameter> $parameters
     */
    private static function assertParametersEquals(
        array $expected,
        ArrayCollection $parameters
    ): void {
        $arrayParameters = [];
        /** @var Query\Parameter $param */
        foreach ($parameters as $param) {
            $arrayParameters[$param->getName()] = $param->getValue();
        }
        self::assertSame($expected, $arrayParameters);
    }
}
