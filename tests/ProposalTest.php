<?php

declare(strict_types=1);

namespace Andante\Doctrine\ORM\Tests;

use Andante\Doctrine\ORM\SharedQueryBuilder\Proposal;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Query\Expr\Orx;
use Doctrine\ORM\Query\Parameter as QueryParameter;

class ProposalTest extends TestCase
{
    public function testCreateEmptyProposalWithName(): void
    {
        $sqb = $this->createSqb();
        $proposal = $sqb->createEmptyProposal('my_proposal');
        self::assertInstanceOf(Proposal::class, $proposal);
        self::assertSame('my_proposal', $proposal->getName());
    }

    public function testCreateEmptyProposalWithoutNameGeneratesUniqueId(): void
    {
        $sqb = $this->createSqb();
        $proposal = $sqb->createEmptyProposal();
        self::assertInstanceOf(Proposal::class, $proposal);
        $name = $proposal->getName();
        self::assertNotSame('', $name);
        self::assertStringStartsWith('proposal_', $name);
        self::assertMatchesRegularExpression(
            '/^proposal_[0-9a-f]+\.[0-9]+$/',
            $name,
            'Name should be uniqid("proposal_", true) format'
        );
    }

    public function testProposalCollectAndWhereHasNoSideEffectsUntilUsed(): void
    {
        $sqb = $this->createSqb();
        $sqb->select('u')->from(\stdClass::class, 'u');
        $proposal = $sqb->createEmptyProposal('p');
        $proposal->andWhere('u.id = 1');
        self::assertStringNotContainsString('u.id = 1', $sqb->getDQL());
        $sqb->andWhere($proposal);
        self::assertStringContainsString('u.id = 1', $sqb->getDQL());
    }

    public function testProposalEmptyConditionExpandsToNeutral(): void
    {
        $sqb = $this->createSqb();
        $sqb->select('u')->from(\stdClass::class, 'u');
        $proposal = $sqb->createEmptyProposal('p');
        $sqb->andWhere($proposal);
        self::assertStringContainsString('1=1', $sqb->getDQL());
    }

    public function testProposalWithParameterExpandsAndAppliesParameter(): void
    {
        $sqb = $this->createSqb();
        $sqb->select('u')->from(\stdClass::class, 'u');
        $proposal = $sqb->createEmptyProposal('p');
        $ph = $proposal->withUniqueImmutableParameter('status', 1);
        $proposal->andWhere('u.status = ' . $ph);
        $sqb->andWhere($proposal);
        self::assertNotEmpty($sqb->getParameters());
        self::assertNotEmpty($sqb->getImmutableParameters());
        $dql = $sqb->getDQL();
        self::assertStringContainsString('u.status =', $dql);
    }

    public function testProposalIsConsumedAfterExpansion(): void
    {
        $sqb = $this->createSqb();
        $sqb->select('u')->from(\stdClass::class, 'u');
        $proposal = $sqb->createEmptyProposal('p');
        $proposal->andWhere('u.id = 1');
        self::assertFalse($proposal->isConsumed());
        $sqb->andWhere($proposal);
        self::assertTrue($proposal->isConsumed());
    }

    public function testProposalSecondUseExpandsToNeutral(): void
    {
        $sqb = $this->createSqb();
        $sqb->select('u')->from(\stdClass::class, 'u');
        $proposal = $sqb->createEmptyProposal('p');
        $proposal->andWhere('u.id = 1');
        $sqb->andWhere($proposal);
        self::assertStringContainsString('u.id = 1', $sqb->getDQL());
        $sqb->andWhere($proposal);
        self::assertStringContainsString('u.id = 1', $sqb->getDQL());
        self::assertStringContainsString('1=1', $sqb->getDQL());
    }

    public function testProposalIntrospection(): void
    {
        $sqb = $this->createSqb();
        $proposal = $sqb->createEmptyProposal('p');
        self::assertFalse($proposal->hasConditions());
        self::assertFalse($proposal->hasJoins());
        self::assertFalse($proposal->hasParameters());
        self::assertTrue($proposal->isEmpty());
        $proposal->andWhere('1=1');
        self::assertTrue($proposal->hasConditions());
        self::assertFalse($proposal->isEmpty());
        $proposal->clearWhere();
        self::assertFalse($proposal->hasConditions());
        self::assertTrue($proposal->isEmpty());
    }

    public function testProposalCloneIsNotConsumed(): void
    {
        $sqb = $this->createSqb();
        $sqb->select('u')->from(\stdClass::class, 'u');
        $proposal = $sqb->createEmptyProposal('p');
        $proposal->andWhere('u.id = 1');
        $sqb->andWhere($proposal);
        self::assertTrue($proposal->isConsumed());
        $clone = clone $proposal;
        self::assertFalse($clone->isConsumed());
        self::assertTrue($clone->hasConditions());
    }

    public function testProposalClearAll(): void
    {
        $sqb = $this->createSqb();
        $proposal = $sqb->createEmptyProposal('p');
        $proposal->andWhere('1=1')->withUniqueImmutableParameter('x', 1);
        self::assertFalse($proposal->isEmpty());
        $proposal->clearAll();
        self::assertTrue($proposal->isEmpty());
        self::assertFalse($proposal->hasConditions());
        self::assertFalse($proposal->hasParameters());
    }

    public function testExpandIntoReturnsConditionStringUsableInAndWhere(): void
    {
        $sqb = $this->createSqb();
        $sqb->select('u')->from(\stdClass::class, 'u');
        $proposal = $sqb->createEmptyProposal('p');
        $proposal->andWhere('u.a = 1');
        $condition = $proposal->expandInto($sqb);
        self::assertSame('u.a = 1', $condition);
        $sqb->andWhere($condition);
        self::assertStringContainsString('u.a = 1', $sqb->getDQL());
    }

    public function testProposalFromStaticFactory(): void
    {
        $sqb = $this->createSqb();
        $proposal = Proposal::from($sqb, 'custom');
        self::assertSame('custom', $proposal->getName());
    }

    public function testNestedProposalExpandsWhenParentIsUsed(): void
    {
        $sqb = $this->createSqb();
        $sqb->select('u')->from(\stdClass::class, 'u');
        $inner = $sqb->createEmptyProposal('inner');
        $inner->andWhere('u.b = 2');
        $outer = $sqb->createEmptyProposal('outer');
        $outer->andWhere('u.a = 1')->andWhere($inner);
        $sqb->andWhere($outer);
        $dql = $sqb->getDQL();
        self::assertStringContainsString('u.a = 1', $dql);
        self::assertStringContainsString('u.b = 2', $dql);
        self::assertTrue($outer->isConsumed());
        self::assertTrue($inner->isConsumed());
    }

    public function testProposalGetParametersDelegatesToSqb(): void
    {
        $sqb = $this->createSqb();
        $sqb->setParameter('p1', 1);
        $proposal = $sqb->createEmptyProposal('p');
        $params = $proposal->getParameters();
        self::assertInstanceOf(ArrayCollection::class, $params);
        self::assertSame($sqb->getParameters(), $params);
        self::assertCount(1, $params);
    }

    public function testProposalGetParameterDelegatesToSqb(): void
    {
        $sqb = $this->createSqb();
        $sqb->setParameter('p1', 42);
        $proposal = $sqb->createEmptyProposal('p');
        $param = $proposal->getParameter('p1');
        self::assertInstanceOf(QueryParameter::class, $param);
        self::assertSame(42, $param->getValue());
        self::assertNull($proposal->getParameter('nonexistent'));
    }

    public function testProposalGetImmutableParametersDelegatesToSqb(): void
    {
        $sqb = $this->createSqb();
        $sqb->setImmutableParameter('im1', 10);
        $proposal = $sqb->createEmptyProposal('p');
        $params = $proposal->getImmutableParameters();
        self::assertInstanceOf(ArrayCollection::class, $params);
        self::assertSame($sqb->getImmutableParameters(), $params);
        self::assertCount(1, $params);
    }

    public function testProposalGroupByAndAddGroupByExpandIntoDql(): void
    {
        $sqb = $this->createSqb();
        $sqb->select('u')->from(\stdClass::class, 'u');
        $proposal = $sqb->createEmptyProposal('p');
        $proposal->addGroupBy('u.id')->groupBy('u.name');
        $sqb->andWhere($proposal);
        $dql = $sqb->getDQL();
        self::assertStringContainsString('GROUP BY', $dql);
        self::assertStringContainsString('u.id', $dql);
        self::assertStringContainsString('u.name', $dql);
    }

    /**
     * Two proposals combined with OR: both conditions and all parameters from both proposals
     * must be added to the SQB when expanded. We build Orx explicitly so expansion is exercised
     * regardless of the test double's expr() behaviour.
     */
    public function testTwoProposalsInOrBothConditionsAndParametersAddedToSqb(): void
    {
        $sqb = $this->createSqb();
        $sqb->select('u')->from(\stdClass::class, 'u');

        $proposal1 = $sqb->createEmptyProposal('p1');
        $ph1 = $proposal1->withUniqueImmutableParameter('status', 1);
        $proposal1->andWhere('u.status = ' . $ph1);

        $proposal2 = $sqb->createEmptyProposal('p2');
        $ph2 = $proposal2->withUniqueImmutableParameter('role', 'admin');
        $proposal2->andWhere('u.role = ' . $ph2);

        $condition1 = $proposal1->expandInto($sqb);
        $condition2 = $proposal2->expandInto($sqb);
        $sqb->andWhere(new Orx([$condition1, $condition2]));

        $dql = $sqb->getDQL();
        self::assertStringContainsString('u.status =', $dql);
        self::assertStringContainsString('u.role =', $dql);
        self::assertStringContainsString('OR', $dql);

        self::assertCount(2, $sqb->getParameters(), 'Both proposals parameters must be on SQB');
        self::assertCount(2, $sqb->getImmutableParameters(), 'Both proposals immutable parameters must be on SQB');
        $values = [];
        foreach ($sqb->getParameters() as $p) {
            $values[] = $p->getValue();
        }
        self::assertContains(1, $values);
        self::assertContains('admin', $values);
    }

    /**
     * Proposal with addSelect: expanded into SQB must add SELECT parts to DQL.
     */
    public function testProposalAddSelectIsAddedToSqbOnExpansion(): void
    {
        $sqb = $this->createSqb();
        $sqb->select('u')->from(\stdClass::class, 'u');
        $proposal = $sqb->createEmptyProposal('p');
        $proposal->addSelect('u.id', 'u.name')->andWhere('1=1');
        $sqb->andWhere($proposal);
        $dql = $sqb->getDQL();
        self::assertStringContainsString('u.id', $dql);
        self::assertStringContainsString('u.name', $dql);
    }

    /**
     * Proposal with addOrderBy: expanded into SQB must add ORDER BY to DQL.
     */
    public function testProposalAddOrderByIsAddedToSqbOnExpansion(): void
    {
        $sqb = $this->createSqb();
        $sqb->select('u')->from(\stdClass::class, 'u');
        $proposal = $sqb->createEmptyProposal('p');
        $proposal->addOrderBy('u.name', 'ASC')->addOrderBy('u.id', 'DESC')->andWhere('1=1');
        $sqb->andWhere($proposal);
        $dql = $sqb->getDQL();
        self::assertStringContainsString('ORDER BY', $dql);
        self::assertStringContainsString('u.name', $dql);
        self::assertStringContainsString('u.id', $dql);
        self::assertStringContainsString('ASC', $dql);
        self::assertStringContainsString('DESC', $dql);
    }

    /**
     * Proposal with innerJoin: expanded into SQB must add JOIN to DQL.
     * Uses entity class as join (first arg) so no association metadata is needed.
     */
    public function testProposalJoinIsAddedToSqbOnExpansion(): void
    {
        $sqb = $this->createSqb();
        $sqb->select('u')->from(\stdClass::class, 'u');
        $proposal = $sqb->createEmptyProposal('p');
        $proposal->innerJoin(\stdClass::class, 'a')->andWhere('1=1');
        $sqb->andWhere($proposal);
        $dql = $sqb->getDQL();
        self::assertStringContainsString('INNER JOIN', $dql);
        self::assertStringContainsString('stdClass', $dql);
        self::assertStringContainsString(' a ', $dql);
    }

    /**
     * Single proposal with groupBy + orderBy + addSelect: all must appear in SQB DQL after expansion.
     */
    public function testProposalGroupByOrderByAndSelectAllAddedToSqb(): void
    {
        $sqb = $this->createSqb();
        $sqb->select('u')->from(\stdClass::class, 'u');
        $proposal = $sqb->createEmptyProposal('p');
        $proposal
            ->addSelect('u.id')
            ->addGroupBy('u.id')
            ->addOrderBy('u.id', 'DESC')
            ->andWhere('u.id > 0');
        $sqb->andWhere($proposal);
        $dql = $sqb->getDQL();
        self::assertStringContainsString('GROUP BY', $dql);
        self::assertStringContainsString('ORDER BY', $dql);
        self::assertStringContainsString('u.id > 0', $dql);
        self::assertStringContainsString('DESC', $dql);
    }
}
