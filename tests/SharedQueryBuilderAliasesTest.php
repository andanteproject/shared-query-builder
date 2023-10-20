<?php

declare(strict_types=1);

namespace Andante\Doctrine\ORM\Tests;

use Andante\Doctrine\ORM\Exception\LogicException;
use Andante\Doctrine\ORM\Tests\Model\Employee;
use Andante\Doctrine\ORM\Tests\Model\Organization;
use Andante\Doctrine\ORM\Tests\Model\Person;

class SharedQueryBuilderAliasesTest extends TestCase
{
    public function testWithAlias(): void
    {
        $sqb = $this->createSqb();
        $sqb
            ->from(Organization::class, 'o')
            ->join(Person::class, 'p');
        self::assertSame('o.name', $sqb->withAlias(Organization::class, 'name'));
        self::assertSame('p.name', $sqb->withAlias(Person::class, 'name'));

        $this->expectException(LogicException::class);
        $sqb->withAlias(Employee::class, 'name');
    }
}
