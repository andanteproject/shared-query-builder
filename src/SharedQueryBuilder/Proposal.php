<?php

declare(strict_types=1);

namespace Andante\Doctrine\ORM\SharedQueryBuilder;

use Andante\Doctrine\ORM\SharedQueryBuilder as SQB;
use Andante\Doctrine\ORM\SharedQueryBuilder\Expr as SQBExpr;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query\Parameter;

/**
 * Temporary container for conditions, joins, parameters (and optionally select/groupBy/orderBy/having)
 * that can be merged into a SharedQueryBuilder by using the proposal in andWhere/orWhere (or inside orX/andX).
 *
 * Collect API mirrors SharedQueryBuilder method names. Nothing is written to the SQB until the proposal
 * is used in an expression (expansion). Supports nested proposals, clone, clear parts, introspection, consumed state.
 */
class Proposal
{
    private SQB $sqb;

    private string $name;

    /** @var list<mixed> Expr|string|self */
    private array $whereConditions = [];

    /** @var list<array{type: string, args: array}> */
    private array $joins = [];

    /** @var array<string, array{value: mixed, type: ArrayParameterType|int|ParameterType|string|null}> */
    private array $parameters = [];

    /** @var list<mixed> */
    private array $selects = [];

    /** @var list<string> */
    private array $groupBy = [];

    /** @var list<mixed> */
    private array $orderBy = [];

    /** @var list<mixed> */
    private array $having = [];

    private bool $consumed = false;

    private int $parameterIndex = 0;

    private const JOIN_INNER = 'innerJoin';
    private const JOIN_LEFT = 'leftJoin';
    private const LAZY_INNER = 'lazyInnerJoin';
    private const LAZY_LEFT = 'lazyLeftJoin';

    private function __construct(SQB $sqb, string $name)
    {
        $this->sqb = $sqb;
        $this->name = $name !== '' ? $name : self::generateUniqueName();
    }

    public static function from(SQB $sqb, string $name = ''): self
    {
        return new self($sqb, $name);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isConsumed(): bool
    {
        return $this->consumed;
    }

    // ---- Read-only delegation to SQB ----

    public function expr(): SQBExpr
    {
        return $this->sqb->expr();
    }

    public function hasEntity(string $entityClass): bool
    {
        return $this->sqb->hasEntity($entityClass);
    }

    public function getAliasForEntity(string $entityClass): ?string
    {
        return $this->sqb->getAliasForEntity($entityClass);
    }

    public function withAlias(string $entityClass, string $property): string
    {
        return $this->sqb->withAlias($entityClass, $property);
    }

    public function getEntityForAlias(string $alias): ?string
    {
        return $this->sqb->getEntityForAlias($alias);
    }

    /**
     * @return array<int, string>
     * @param  bool               $includeLazy
     */
    public function getAllAliases(bool $includeLazy = false): array
    {
        return $this->sqb->getAllAliases($includeLazy);
    }

    /**
     * @return ArrayCollection<int, Parameter>
     */
    public function getParameters(): ArrayCollection
    {
        return $this->sqb->getParameters();
    }

    public function getParameter(int|string $key): ?Parameter
    {
        return $this->sqb->getParameter($key);
    }

    /**
     * @return ArrayCollection<int, Parameter>
     */
    public function getImmutableParameters(): ArrayCollection
    {
        return $this->sqb->getImmutableParameters();
    }

    // ---- Introspection ----

    public function hasConditions(): bool
    {
        return \count($this->whereConditions) > 0;
    }

    public function hasJoins(): bool
    {
        return \count($this->joins) > 0;
    }

    public function hasParameters(): bool
    {
        return \count($this->parameters) > 0;
    }

    public function isEmpty(): bool
    {
        return ! $this->hasConditions() && ! $this->hasJoins() && ! $this->hasParameters()
            && \count($this->selects) === 0 && \count($this->groupBy) === 0 && \count($this->orderBy) === 0
            && \count($this->having) === 0;
    }

    // ---- Collect API (mirror SQB) ----

    public function where(mixed ...$predicates): self
    {
        $this->whereConditions = [];
        foreach ($predicates as $p) {
            $this->whereConditions[] = $this->normalizeWherePart($p);
        }

        return $this;
    }

    public function andWhere(mixed ...$where): self
    {
        foreach ($where as $w) {
            $this->whereConditions[] = $this->normalizeWherePart($w);
        }

        return $this;
    }

    public function orWhere(mixed ...$where): self
    {
        foreach ($where as $w) {
            $this->whereConditions[] = $this->normalizeWherePart($w);
        }

        return $this;
    }

    /**
     * @param ArrayParameterType|int|ParameterType|string|null $type
     * @param int|string                                       $key
     * @param mixed                                            $value
     */
    public function withUniqueImmutableParameter(string|int $key, mixed $value, ParameterType|ArrayParameterType|string|int|null $type = null): string
    {
        $name = 'proposal_' . $this->name . '_' . $this->parameterIndex . '_' . \bin2hex(\random_bytes(4));
        $this->parameterIndex++;
        $this->parameters[$name] = ['value' => $value, 'type' => $type];

        return ':' . $name;
    }

    public function join(
        string $join,
        string $alias,
        ?string $conditionType = null,
        string|Expr\Composite|Expr\Comparison|Expr\Func|null $condition = null,
        ?string $indexBy = null
    ): self {
        $this->joins[] = ['type' => self::JOIN_INNER, 'args' => \func_get_args()];

        return $this;
    }

    public function innerJoin(
        string $join,
        string $alias,
        ?string $conditionType = null,
        string|Expr\Composite|Expr\Comparison|Expr\Func|null $condition = null,
        ?string $indexBy = null
    ): self {
        $this->joins[] = ['type' => self::JOIN_INNER, 'args' => \func_get_args()];

        return $this;
    }

    public function leftJoin(
        string $join,
        string $alias,
        ?string $conditionType = null,
        string|Expr\Composite|Expr\Comparison|Expr\Func|null $condition = null,
        ?string $indexBy = null
    ): self {
        $this->joins[] = ['type' => self::JOIN_LEFT, 'args' => \func_get_args()];

        return $this;
    }

    public function lazyJoin(
        string $join,
        string $alias,
        ?string $conditionType = null,
        string|Expr\Composite|Expr\Comparison|Expr\Func|null $condition = null,
        ?string $indexBy = null
    ): self {
        $this->joins[] = ['type' => 'lazyJoin', 'args' => \func_get_args()];

        return $this;
    }

    public function lazyInnerJoin(
        string $join,
        string $alias,
        ?string $conditionType = null,
        string|Expr\Composite|Expr\Comparison|Expr\Func|null $condition = null,
        ?string $indexBy = null
    ): self {
        $this->joins[] = ['type' => self::LAZY_INNER, 'args' => \func_get_args()];

        return $this;
    }

    public function lazyLeftJoin(
        string $join,
        string $alias,
        ?string $conditionType = null,
        string|Expr\Composite|Expr\Comparison|Expr\Func|null $condition = null,
        ?string $indexBy = null
    ): self {
        $this->joins[] = ['type' => self::LAZY_LEFT, 'args' => \func_get_args()];

        return $this;
    }

    public function addSelect(mixed ...$select): self
    {
        foreach ($select as $s) {
            $this->selects[] = $s;
        }

        return $this;
    }

    public function addGroupBy(string ...$groupBy): self
    {
        foreach ($groupBy as $g) {
            $this->groupBy[] = $g;
        }

        return $this;
    }

    public function groupBy(string ...$groupBy): self
    {
        $this->groupBy = \array_values(\array_merge($this->groupBy, $groupBy));

        return $this;
    }

    public function addOrderBy(string|Expr\OrderBy $sort, ?string $order = null): self
    {
        $this->orderBy[] = [$sort, $order];

        return $this;
    }

    public function orderBy(string|Expr\OrderBy $sort, ?string $order = null): self
    {
        $this->orderBy[] = [$sort, $order];

        return $this;
    }

    public function having(mixed ...$having): self
    {
        foreach ($having as $h) {
            $this->having[] = $h;
        }

        return $this;
    }

    public function andHaving(mixed ...$having): self
    {
        foreach ($having as $h) {
            $this->having[] = $h;
        }

        return $this;
    }

    public function orHaving(mixed ...$having): self
    {
        foreach ($having as $h) {
            $this->having[] = $h;
        }

        return $this;
    }

    // ---- Clear ----

    public function clearWhere(): self
    {
        $this->whereConditions = [];

        return $this;
    }

    public function clearJoins(): self
    {
        $this->joins = [];

        return $this;
    }

    public function clearParameters(): self
    {
        $this->parameters = [];

        return $this;
    }

    public function clearSelect(): self
    {
        $this->selects = [];

        return $this;
    }

    public function clearGroupBy(): self
    {
        $this->groupBy = [];

        return $this;
    }

    public function clearOrderBy(): self
    {
        $this->orderBy = [];

        return $this;
    }

    public function clearHaving(): self
    {
        $this->having = [];

        return $this;
    }

    public function clearAll(): self
    {
        $this->clearWhere();
        $this->clearJoins();
        $this->clearParameters();
        $this->clearSelect();
        $this->clearGroupBy();
        $this->clearOrderBy();
        $this->clearHaving();

        return $this;
    }

    // ---- Clone ----

    public function __clone()
    {
        $this->whereConditions = $this->cloneWhereConditions($this->whereConditions);
        $this->joins = $this->joins; // arrays of scalars/args, no deep clone needed
        $this->parameters = $this->parameters;
        $this->consumed = false;
    }

    /**
     * Expand this proposal into the given SharedQueryBuilder: apply joins, params, select, groupBy, orderBy, having,
     * then return the DQL condition string (andX of where list, with nested proposals expanded and param names replaced).
     * Marks this proposal as consumed. If already consumed, returns neutral condition (no-op).
     *
     * @internal Used by SharedQueryBuilder when expanding expression trees
     * @param SQB $sqb
     */
    public function expandInto(SQB $sqb): string
    {
        if ($this->consumed) {
            return '1=1';
        }

        $this->applyJoinsTo($sqb);
        $paramMap = $this->applyParametersTo($sqb);
        $this->applySelectGroupOrderHavingTo($sqb);

        $conditionDql = $this->buildConditionDql($sqb, $paramMap);
        $this->consumed = true;

        return $conditionDql;
    }

    private function normalizeWherePart(mixed $part): mixed
    {
        if ($part instanceof self) {
            return $part;
        }

        return $part;
    }

    /**
     * @param list<mixed> $conditions
     *
     * @return list<mixed>
     */
    private function cloneWhereConditions(array $conditions): array
    {
        $out = [];
        foreach ($conditions as $c) {
            $out[] = $c instanceof self ? clone $c : $c;
        }

        return $out;
    }

    private static function generateUniqueName(): string
    {
        return \uniqid('proposal_', true);
    }

    private function applyJoinsTo(SQB $sqb): void
    {
        foreach ($this->joins as $join) {
            $type = $join['type'];
            $args = $join['args'];
            if ($type === self::JOIN_INNER) {
                $sqb->innerJoin(...$args);
            } elseif ($type === self::JOIN_LEFT) {
                $sqb->leftJoin(...$args);
            } elseif ($type === 'lazyJoin') {
                $sqb->lazyJoin(...$args);
            } elseif ($type === self::LAZY_INNER) {
                $sqb->lazyInnerJoin(...$args);
            } elseif ($type === self::LAZY_LEFT) {
                $sqb->lazyLeftJoin(...$args);
            }
        }
    }

    /**
     * @return array<string, string> oldParamName => newPlaceholder (e.g. ':param_xyz')
     * @param  SQB                   $sqb
     */
    private function applyParametersTo(SQB $sqb): array
    {
        $paramMap = [];
        foreach ($this->parameters as $name => $data) {
            $newPlaceholder = $sqb->withUniqueImmutableParameter($name, $data['value'], $data['type']);
            $paramMap[$name] = $newPlaceholder;
        }

        return $paramMap;
    }

    private function applySelectGroupOrderHavingTo(SQB $sqb): void
    {
        if (\count($this->selects) > 0) {
            $sqb->addSelect(...$this->selects);
        }
        if (\count($this->groupBy) > 0) {
            $sqb->addGroupBy(...$this->groupBy);
        }
        foreach ($this->orderBy as $o) {
            if (\is_array($o)) {
                $sqb->addOrderBy($o[0], $o[1] ?? null);
            }
        }
        if (\count($this->having) > 0) {
            $sqb->andHaving(...$this->having);
        }
    }

    /**
     * @param array<string, string> $paramMap oldName => newName (no colon)
     * @param SQB                   $sqb
     */
    private function buildConditionDql(SQB $sqb, array $paramMap): string
    {
        if (\count($this->whereConditions) === 0) {
            return '1=1';
        }

        $parts = [];
        foreach ($this->whereConditions as $part) {
            if ($part instanceof self) {
                $parts[] = '(' . $part->expandInto($sqb) . ')';
            } elseif (\is_object($part) && \method_exists($part, '__toString')) {
                $parts[] = (string) $part;
            } elseif (\is_string($part)) {
                $parts[] = $part;
            }
        }

        if (\count($parts) === 0) {
            return '1=1';
        }
        if (\count($parts) === 1) {
            $dql = $parts[0];
        } else {
            $dql = '(' . \implode(' AND ', $parts) . ')';
        }

        foreach ($paramMap as $oldName => $newPlaceholder) {
            $dql = \str_replace(':' . $oldName, $newPlaceholder, $dql);
        }

        return $dql;
    }
}
