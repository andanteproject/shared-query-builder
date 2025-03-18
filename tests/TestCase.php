<?php

declare(strict_types=1);

namespace Andante\Doctrine\ORM\Tests;

use Andante\Doctrine\ORM\SharedQueryBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

class TestCase extends \PHPUnit\Framework\TestCase
{
    protected function createSqb(): SharedQueryBuilder
    {
        return new SharedQueryBuilder(
            new QueryBuilder(
                self::createStub(EntityManagerInterface::class)
            )
        );
    }
}
