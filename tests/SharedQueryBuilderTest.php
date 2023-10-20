<?php

namespace Andante\Doctrine\ORM\Tests;

use Andante\Doctrine\ORM\Exception\LogicException;
use Andante\Doctrine\ORM\SharedQueryBuilder;
use Andante\Doctrine\ORM\Tests\HttpKernel\TestKernel;
use Andante\Doctrine\ORM\Tests\Model\Address;
use Andante\Doctrine\ORM\Tests\Model\Document;
use Andante\Doctrine\ORM\Tests\Model\Employee;
use Andante\Doctrine\ORM\Tests\Model\Organization;
use Andante\Doctrine\ORM\Tests\Model\Paper;
use Andante\Doctrine\ORM\Tests\Model\Person;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SharedQueryBuilderTest extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testLazy(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::$container->get('doctrine.orm.entity_manager');
        /** @var EntityRepository<Organization> $organizationRepository */
        $organizationRepository = $entityManager->getRepository(Organization::class);
        $sqb = SharedQueryBuilder::wrap($organizationRepository->createQueryBuilder('organization'));
        $sqb->join(
            Address::class,
            'address',
            Join::WITH,
            $sqb->expr()->eq('organization.address', 'address')
        );

        $sqb->lazyLeftJoin('organization.persons', 'person');
        $sqb->lazyLeftJoin(Employee::class, 'employee', Join::WITH, $sqb->expr()->eq('person', 'employee'));

        self::assertSame('organization', $sqb->getAliasForEntity(Organization::class));
        self::assertSame('address', $sqb->getAliasForEntity(Address::class));
        self::assertSame('person', $sqb->getAliasForEntity(Person::class));
        self::assertSame('employee', $sqb->getAliasForEntity(Employee::class));

        self::assertSame(Organization::class, $sqb->getEntityForAlias('organization'));
        self::assertSame(Address::class, $sqb->getEntityForAlias('address'));
        self::assertSame(Person::class, $sqb->getEntityForAlias('person'));
        self::assertSame(Employee::class, $sqb->getEntityForAlias('employee'));

        self::assertNull($sqb->getAliasForEntity(Paper::class));
        self::assertSame(['organization', 'address'], $sqb->getAllAliases());

        $sqb
            ->andWhere($sqb->expr()->eq('person.id', ':id'))
            ->setParameter('id', 1);

        self::assertSame(['organization', 'address', 'person'], $sqb->getAllAliases());
        self::assertSame('organization', $sqb->getAliasForEntity(Organization::class));
        self::assertSame('address', $sqb->getAliasForEntity(Address::class));
        self::assertSame('person', $sqb->getAliasForEntity(Person::class));
        self::assertSame('employee', $sqb->getAliasForEntity(Employee::class));

        $sqb->andWhere($sqb->expr()->isNotNull('employee.id'));
        self::assertSame(['organization', 'address', 'person', 'employee'], $sqb->getAllAliases());
    }

    public function testRecursiveLazyDependency(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::$container->get('doctrine.orm.entity_manager');
        /** @var EntityRepository<Organization> $organizationRepository */
        $organizationRepository = $entityManager->getRepository(Organization::class);
        $sqb = SharedQueryBuilder::wrap($organizationRepository->createQueryBuilder('organization'));
        $sqb
            ->lazyJoin(
                Address::class,
                'address',
                Join::WITH,
                $sqb->expr()->eq('organization.address', 'address')
            )
            ->lazyLeftJoin('organization.persons', 'person')
            ->lazyLeftJoin(Employee::class, 'employee', Join::WITH, $sqb->expr()->eq('person', 'employee'))
            ->lazyLeftJoin('employee.papers', 'paper')
            ->lazyLeftJoin('paper.document', 'document')
        ;

        self::assertSame(['organization'], $sqb->getAllAliases());

        self::assertSame('organization', $sqb->getAliasForEntity(Organization::class));
        self::assertSame('address', $sqb->getAliasForEntity(Address::class));
        self::assertSame('person', $sqb->getAliasForEntity(Person::class));
        self::assertSame('employee', $sqb->getAliasForEntity(Employee::class));
        self::assertSame('paper', $sqb->getAliasForEntity(Paper::class));
        self::assertSame('document', $sqb->getAliasForEntity(Document::class));

        $sqb->andWhere($sqb->expr()->isNull('document'));

        self::assertSame(['organization', 'person', 'employee', 'paper', 'document'], $sqb->getAllAliases());
    }

    public function assertExplodeIfNotVirgin(): void
    {
        $this->expectException(LogicException::class);
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::$container->get('doctrine.orm.entity_manager');
        /** @var EntityRepository<Organization> $organizationRepository */
        $organizationRepository = $entityManager->getRepository(Organization::class);
        $qb = $organizationRepository->createQueryBuilder('organization');
        $qb->join('organization.address', 'address');
        SharedQueryBuilder::wrap($qb);
    }

    protected function createSchema(): void
    {
        /** @var ManagerRegistry $manager */
        $manager = self::$container->get('doctrine');
        /** @var EntityManagerInterface[] $ems */
        $ems = $manager->getManagers();
        /** @var EntityManagerInterface $em */
        $em = reset($ems);
        $metadatas = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($em);
        $schemaTool->dropSchema($metadatas);
        $schemaTool->createSchema($metadatas);
    }
}
