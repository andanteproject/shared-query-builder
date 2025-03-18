<?php

declare(strict_types=1);

namespace Andante\Doctrine\ORM;

use Andante\Doctrine\ORM\Exception\CannotOverrideImmutableParameterException;
use Andante\Doctrine\ORM\Exception\CannotOverrideParametersException;
use Andante\Doctrine\ORM\Exception\DqlErrorException;
use Andante\Doctrine\ORM\Exception\LogicException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\QueryBuilder;

/**
 * @method Expr                 expr()
 * @method static               setCacheable(bool $cacheable)
 * @method bool                 isCacheable()
 * @method static               setCacheRegion(string $cacheRegion)
 * @method string|null          getCacheRegion()
 * @method int                  getLifetime()
 * @method static               setLifetime(int $lifetime)
 * @method int                  getCacheMode()
 * @method static               setCacheMode(int $cacheMode)
 * @method int                  getType()
 * @method EntityManager        getEntityManager()
 * @method int                  getState()
 * @method string               getDQL()
 * @method Query                getQuery()
 * @method string               getRootAlias()
 * @method array                getRootAliases()
 * @method array                getRootEntities()
 * @method ArrayCollection      getParameters()
 * @method Parameter|null       getParameter(int|string $key)
 * @method static               setFirstResult(int|null $firstResult)
 * @method int|null             getFirstResult()
 * @method static               setMaxResults(int|null $maxResults)
 * @method int|null             getMaxResults()
 * @method static               add(string $dqlPartName, string|object|array $dqlPart, bool $append = false)
 * @method static               select(mixed ...$select)
 * @method static               distinct(bool $flag = true)
 * @method static               addSelect(mixed ...$select)
 * @method static               delete(string|null $delete = null, string|null $alias = null)
 * @method static               update(string|null $update = null, string|null $alias = null)
 * @method static               from(string $from, string $alias, string|null $indexBy = null)
 * @method static               indexBy(string $alias, string $indexBy)
 * @method static               set(string $key, mixed $value)
 * @method static               where(mixed ...$predicates)
 * @method static               andWhere(mixed ...$where)
 * @method static               orWhere(mixed ...$where)
 * @method static               groupBy(string ...$groupBy)
 * @method static               addGroupBy(string ...$groupBy)
 * @method static               having(mixed ...$having)
 * @method static               andHaving(mixed ...$having)
 * @method static               orHaving(mixed ...$having)
 * @method static               orderBy(string|Expr\OrderBy $sort, string|null $order = null)
 * @method static               addOrderBy(string|Expr\OrderBy $sort, string|null $order = null)
 * @method static               addCriteria(Criteria $criteria)
 * @method array<string, mixed> getDQLPart(string $queryPartName)
 * @method array<string, mixed> getDQLParts()
 * @method static               resetDQLParts(array|null $parts = null)
 * @method static               resetDQLPart(string $part)
 */
class SharedQueryBuilder
{
    private QueryBuilder $qb;

    /** @var array<string, array> */
    private array $lazyJoinRegistry = [];

    /** @var array<string, array> */
    private array $joinRegistry = [];

    /** @var ArrayCollection<int, Query\Parameter> */
    private ArrayCollection $immutableParameters;

    private array $lazyJoinsCheckAfterMethods = [
        'orWhere',
        'where',
        'andWhere',
        'add',
        'select',
        'addSelect',
        'delete',
        'update',
        'from',
        'groupBy',
        'addGroupBy',
        'having',
        'andHaving',
        'orHaving',
        'orderBy',
        'addOrderBy',
    ];

    protected const ALIAS_ARG_INDEX = 2;

    public function __construct(QueryBuilder $queryBuilder)
    {
        self::assertQueryBuilderIsVirgin($queryBuilder);
        $this->qb = $queryBuilder;
        $this->immutableParameters = new ArrayCollection();
    }

    public static function wrap(QueryBuilder $qb): self
    {
        return new self($qb);
    }

    public function hasEntity(string $entityClass): bool
    {
        return null !== $this->getAliasForEntity($entityClass);
    }

    public function getAliasForEntity(string $entityClass): ?string
    {
        $aliasIndex = \array_search($entityClass, $this->qb->getRootEntities(), true);
        if (\is_numeric($aliasIndex)) {
            return $this->qb->getRootAliases()[$aliasIndex];
        }
        if (\array_key_exists($entityClass, $this->joinRegistry)) {
            return $this->joinRegistry[$entityClass][self::ALIAS_ARG_INDEX];
        }
        if (\array_key_exists($entityClass, $this->lazyJoinRegistry)) {
            return $this->lazyJoinRegistry[$entityClass][self::ALIAS_ARG_INDEX];
        }

        return null;
    }

    public function withAlias(string $entity, string $property): string
    {
        $entityAlias = $this->getAliasForEntity($entity);
        if (null === $entityAlias) {
            throw new LogicException(\sprintf('Cannot find alias for "%s"', $entity));
        }
        $property = \ltrim($property, '.');

        return \sprintf('%s.%s', $entityAlias, $property);
    }

    public function getEntityForAlias(string $alias): ?string
    {
        $entityIndex = \array_search($alias, $this->qb->getRootAliases(), true);
        if (\is_numeric($entityIndex)) {
            return $this->qb->getRootEntities()[$entityIndex];
        }
        foreach ($this->joinRegistry as $entityClass => $args) {
            if ($args[self::ALIAS_ARG_INDEX] === $alias) {
                return $entityClass;
            }
        }
        foreach ($this->lazyJoinRegistry as $entityClass => $args) {
            if ($args[self::ALIAS_ARG_INDEX] === $alias) {
                return $entityClass;
            }
        }

        return null;
    }

    public function unwrap(): QueryBuilder
    {
        return $this->qb;
    }

    /**
     * @param string|null $condition
     * @param string      $join
     * @param string      $alias
     * @param ?string     $conditionType
     * @param ?string     $indexBy
     */
    public function lazyJoin(
        string $join,
        string $alias,
        string|null $conditionType = null,
        string|Expr\Composite|Expr\Comparison|Expr\Func|null $condition = null,
        string|null $indexBy = null
    ): static {
        return $this->lazyInnerJoin($join, $alias, $conditionType, $condition, $indexBy);
    }

    public function lazyInnerJoin(
        string $join,
        string $alias,
        string|null $conditionType = null,
        string|Expr\Composite|Expr\Comparison|Expr\Func|null $condition = null,
        string|null $indexBy = null
    ): static {
        $entityClass = $this->getEntityClassFromFirstJoinStringArgument($join);
        $this->assertHasNotLazyJoinForClass($entityClass);
        $this->lazyJoinRegistry[$entityClass] = [
            Expr\Join::INNER_JOIN,
            ...\func_get_args(),
        ];

        return $this;
    }

    public function lazyLeftJoin(
        string $join,
        string $alias,
        string|null $conditionType = null,
        string|Expr\Composite|Expr\Comparison|Expr\Func|null $condition = null,
        string|null $indexBy = null
    ): static {
        $entityClass = $this->getEntityClassFromFirstJoinStringArgument($join);
        $this->assertHasNotLazyJoinForClass($entityClass);
        $this->lazyJoinRegistry[$entityClass] = [
            Expr\Join::LEFT_JOIN,
            ...\func_get_args(),
        ];

        return $this;
    }

    /**
     * @param string|null $condition
     * @param string      $join
     * @param string      $alias
     * @param ?string     $conditionType
     * @param ?string     $indexBy
     */
    public function join(
        string $join,
        string $alias,
        string|null $conditionType = null,
        string|Expr\Composite|Expr\Comparison|Expr\Func|null $condition = null,
        string|null $indexBy = null
    ): static {
        return $this->innerJoin($join, $alias, $conditionType, $condition, $indexBy);
    }

    /**
     * @param string|null $condition
     * @param string      $join
     * @param string      $alias
     * @param ?string     $conditionType
     * @param ?string     $indexBy
     */
    public function innerJoin(
        string $join,
        string $alias,
        string|null $conditionType = null,
        string|Expr\Composite|Expr\Comparison|Expr\Func|null $condition = null,
        string|null $indexBy = null
    ): static {
        $entityClass = $this->getEntityClassFromFirstJoinStringArgument($join);
        $this->assertHasNotJoinForClass($entityClass);
        $args = \func_get_args();
        $this->joinRegistry[$entityClass] = [
            Expr\Join::INNER_JOIN,
            ...$args,
        ];

        \call_user_func_array([$this->qb, 'innerJoin'], $args);

        return $this;
    }

    /**
     * @param string|null $condition
     * @param string      $join
     * @param string      $alias
     * @param ?string     $conditionType
     * @param ?string     $indexBy
     */
    public function leftJoin(
        string $join,
        string $alias,
        string|null $conditionType = null,
        string|Expr\Composite|Expr\Comparison|Expr\Func|null $condition = null,
        string|null $indexBy = null
    ): static {
        $entityClass = $this->getEntityClassFromFirstJoinStringArgument($join);
        $this->assertHasNotJoinForClass($entityClass);
        $args = \func_get_args();
        $this->joinRegistry[$entityClass] = [
            Expr\Join::LEFT_JOIN,
            ...$args,
        ];

        \call_user_func_array([$this->qb, 'leftJoin'], $args);

        return $this;
    }

    /**
     * @param class-string $entityClass
     */
    public function getAliasForLazyJoinClass(string $entityClass): ?string
    {
        return $this->lazyJoinRegistry[$entityClass][self::ALIAS_ARG_INDEX] ?? null;
    }

    public function getLazyJoinClassForAlias(string $alias): ?string
    {
        foreach ($this->lazyJoinRegistry as $entityClass => $args) {
            if ($args[self::ALIAS_ARG_INDEX] === $alias) {
                return $entityClass;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function getLazyJoinsAliases(): array
    {
        return \array_values(\array_map(
            static fn (array $args): string => (string) $args[self::ALIAS_ARG_INDEX],
            $this->lazyJoinRegistry
        ));
    }

    private function performLazyJoin(string $lazyJoinAlias): void
    {
        $entityClass = $this->getLazyJoinClassForAlias($lazyJoinAlias);
        if (null !== $entityClass) {
            $args = $this->lazyJoinRegistry[$entityClass];
            $joinType = \array_shift($args);
            $aliases = $this->getLazyJoinsAliasesInJoinArgs($args, $lazyJoinAlias);
            foreach ($aliases as $alias) {
                $this->performLazyJoin($alias);
            }

            switch ($joinType) {
                case Expr\Join::LEFT_JOIN:
                    \call_user_func_array([$this, 'leftJoin'], $args);
                    break;
                case Expr\Join::INNER_JOIN:
                    \call_user_func_array([$this, 'innerJoin'], $args);
                    break;
                default:
                    throw new LogicException(\sprintf('Unsupported "%s" join type', $joinType));
            }
            unset($this->lazyJoinRegistry[$entityClass]);
        }
    }

    /**
     * @return class-string
     * @param  string       $join
     */
    protected function getEntityClassFromFirstJoinStringArgument(string $join): string
    {
        $entity = null;
        if (\class_exists($join) || \interface_exists($join)) {
            // First argument is entityClass
            $entity = $join;
        } else {
            $matches = [];
            // First argument is a DQL string.
            if (\preg_match(
                '/(?P<alias>' . \implode('|', $this->getAllAliases(true)) . ')\./',
                $join,
                $matches
            )) {
                if (isset($matches['alias']) && \is_string($matches['alias'])) {
                    $alias = $matches['alias'];
                    $rootEntity = $this->getEntityForAlias($alias);
                    if (null !== $rootEntity) {
                        $rootClassMetadata = $this->qb->getEntityManager()->getClassMetadata($rootEntity);
                        $fieldName = \preg_replace('/^' . $alias . '\./', '', $join);
                        if (\is_string($fieldName)) {
                            $assMapping = $rootClassMetadata->getAssociationMapping(
                                $fieldName
                            );
                            if (isset($assMapping['targetEntity']) && \is_string($assMapping['targetEntity'])) {
                                $entity = $assMapping['targetEntity'];
                            }
                            if (null === $entity) {
                                throw new LogicException(
                                    \sprintf(
                                        'Cannot find target entity for "%s". Try using extended join syntax',
                                        $join
                                    )
                                );
                            }
                        }
                    }
                }
            }
        }
        if (null === $entity) {
            throw new LogicException(
                \sprintf(
                    'Cannot add lazy join to %s because join entity has not been found for "%s".',
                    static::class,
                    $join
                )
            );
        }

        if (!\class_exists($entity) && !\interface_exists($entity)) {
            throw new LogicException(
                \sprintf(
                    'Found string for string join "%s" in %s is expected to be a class-string',
                    $join, static::class,
                )
            );
        }

        return $entity;
    }

    private static function assertQueryBuilderIsVirgin(QueryBuilder $qb): void
    {
        if (\count($qb->getDQLPart('join')) > 0) {
            throw new DqlErrorException(
                \sprintf(
                    'You cannot use %s with a %s that has already declared JOINs.',
                    static::class,
                    QueryBuilder::class
                )
            );
        }
    }

    /**
     * @param class-string $entityClass
     */
    private function assertHasNotLazyJoinForClass(string $entityClass): void
    {
        if (isset($this->lazyJoinRegistry[$entityClass])) {
            throw new DqlErrorException(
                \sprintf(
                    '%s supports only one lazy join per class. %s has already been used.',
                    static::class,
                    $entityClass
                )
            );
        }
    }

    /**
     * @param class-string $entityClass
     */
    private function assertHasNotJoinForClass(string $entityClass): void
    {
        if (isset($this->joinRegistry[$entityClass])) {
            throw new DqlErrorException(
                \sprintf(
                    '%s supports only one join per class. %s has already been used.',
                    static::class,
                    $entityClass
                )
            );
        }
    }

    /**
     * @return array<int, string>
     * @param  array              $args
     * @param  ?string            $excludeAlias
     */
    private function getLazyJoinsAliasesInJoinArgs(array $args, ?string $excludeAlias = null): array
    {
        $results = [];
        foreach ($args as $arg) {
            if (\is_object($arg) && \method_exists($arg, '__toString')) {
                $arg = (string) $arg;
            }
            if (\is_string($arg)) {
                $results[] = $this->getLazyJoinsAliasesInDqlString($arg);
            }
        }
        $results = \array_merge(...$results);

        return \array_filter($results, static fn (string $alias) => $alias !== $excludeAlias);
    }

    /**
     * @return array<int, string>
     * @param  string             $dql
     */
    private function getLazyJoinsAliasesInDqlString(string $dql): array
    {
        $aliases = $this->getLazyJoinsAliases();
        if (\count($aliases) > 0) {
            $matches = [];
            \preg_match_all(
                '/\b(?<aliases>' . \implode('|', $this->getLazyJoinsAliases()) . ')\.?\b/',
                $dql,
                $matches
            );
            if (isset($matches['aliases']) && \is_array($matches['aliases']) && ! empty($matches['aliases'])) {
                return \array_unique($matches['aliases']);
            }
        }

        return [];
    }

    private function performLazyJoinsIfNeeded(): void
    {
        if (\count($this->getLazyJoinsAliases()) > 0) {
            do {
                $aliases = $this->getLazyJoinsAliasesInDqlString($this->qb->getDQL());
                foreach ($aliases as $alias) {
                    $this->performLazyJoin($alias);
                }
            } while (! empty($aliases));
        }
    }

    /**
     * @return array<int, string>
     * @param  bool               $includeLazy
     */
    public function getAllAliases(bool $includeLazy = false): array
    {
        $aliases = $this->qb->getAllAliases();
        if ($includeLazy) {
            $aliases = \array_merge(
                $aliases,
                $this->getLazyJoinsAliases()
            );
        }

        return $aliases;
    }

    /**
     * @param int|string                                       $key   the parameter position or name
     * @param mixed                                            $value the parameter value
     * @param ArrayParameterType|int|ParameterType|string|null $type  ParameterType::*, ArrayParameterType::* or \Doctrine\DBAL\Types\Type::* constant
     */
    public function withParameter(string|int $key, mixed $value, ParameterType|ArrayParameterType|string|int|null $type = null): string
    {
        $this->setParameter($key, $value, $type);
        /** @var Query\Parameter $param */
        $param = $this->qb->getParameter($key);

        return $this->getPrefixedParameterNameIfString($param);
    }

    /**
     * @param int|string                                       $key   the parameter position or name
     * @param mixed                                            $value the parameter value
     * @param ArrayParameterType|int|ParameterType|string|null $type  ParameterType::*, ArrayParameterType::* or \Doctrine\DBAL\Types\Type::* constant
     */
    public function withImmutableParameter(string|int $key, mixed $value, ParameterType|ArrayParameterType|string|int|null $type = null): string
    {
        $this->setImmutableParameter($key, $value, $type);
        /** @var Query\Parameter $param */
        $param = $this->qb->getParameter($key);

        return $this->getPrefixedParameterNameIfString($param);
    }

    /**
     * @param int|string                                       $key   the parameter position or name
     * @param mixed                                            $value the parameter value
     * @param ArrayParameterType|int|ParameterType|string|null $type  ParameterType::*, ArrayParameterType::* or \Doctrine\DBAL\Types\Type::* constant
     */
    public function withUniqueParameter(string|int $key, mixed $value, ParameterType|ArrayParameterType|string|int|null $type = null): string
    {
        return $this->withParameter($this->generateUniqueParameterName((string) $key), $value, $type);
    }

    /**
     * @param int|string                                       $key   the parameter position or name
     * @param mixed                                            $value the parameter value
     * @param ArrayParameterType|int|ParameterType|string|null $type  ParameterType::*, ArrayParameterType::* or \Doctrine\DBAL\Types\Type::* constant
     */
    public function withUniqueImmutableParameter(string|int $key, mixed $value, ParameterType|ArrayParameterType|string|int|null $type = null): string
    {
        return $this->withImmutableParameter($this->generateUniqueParameterName((string) $key), $value, $type);
    }

    protected function generateUniqueParameterName(string $paramName): string
    {
        $paramName = Query\Parameter::normalizeName($paramName);
        do {
            $paramName = \uniqid(\sprintf('param_%s_', $paramName), false);
        } while (null !== $this->qb->getParameter($paramName));

        return $paramName;
    }

    protected function getPrefixedParameterNameIfString(Query\Parameter $param): string
    {
        $paramName = $param->getName();

        return \is_numeric($paramName) ? $paramName : \sprintf(':%s', $paramName);
    }

    /**
     * @param int|string                                       $key   the parameter position or name
     * @param mixed                                            $value the parameter value
     * @param ArrayParameterType|int|ParameterType|string|null $type  ParameterType::*, ArrayParameterType::* or \Doctrine\DBAL\Types\Type::* constant
     */
    public function setParameter(string|int $key, mixed $value, ParameterType|ArrayParameterType|string|int|null $type = null): static
    {
        $parameter = $this->qb->getParameter($key);
        if (null !== $parameter && $this->isImmutableParameter($parameter)) {
            throw new CannotOverrideImmutableParameterException($parameter);
        }
        $this->qb->setParameter($key, $value, $type);

        return $this;
    }

    /**
     * @param int|string                                       $key   the parameter position or name
     * @param mixed                                            $value the parameter value
     * @param ArrayParameterType|int|ParameterType|string|null $type  ParameterType::*, ArrayParameterType::* or \Doctrine\DBAL\Types\Type::* constant
     */
    public function setImmutableParameter(string|int $key, mixed $value, ParameterType|ArrayParameterType|string|int|null $type = null): static
    {
        $parameter = $this->qb->getParameter($key);
        if (null !== $parameter && $this->isImmutableParameter($parameter)) {
            throw new CannotOverrideImmutableParameterException($parameter);
        }
        $this->qb->setParameter($key, $value, $type);
        /** @var Query\Parameter $parameter */
        $parameter = $this->qb->getParameter($key);
        $this->immutableParameters->add($parameter);

        return $this;
    }

    protected function isImmutableParameter(Query\Parameter $parameter): bool
    {
        return $this->immutableParameters->contains($parameter);
    }

    /**
     * @param ArrayCollection<int, Parameter> $parameters
     */
    public function setParameters(ArrayCollection $parameters): static
    {
        if (! $this->immutableParameters->isEmpty()) {
            throw new CannotOverrideParametersException();
        }
        $this->qb->setParameters($parameters);

        return $this;
    }

    /**
     * @param ArrayCollection<int, Parameter> $parameters
     */
    public function setImmutableParameters(ArrayCollection $parameters): static
    {
        if (! $this->immutableParameters->isEmpty()) {
            throw new CannotOverrideParametersException();
        }
        $this->qb->setParameters($parameters);
        foreach ($this->qb->getParameters()->getValues() as $param) {
            $this->immutableParameters->add($param);
        }

        return $this;
    }

    /**
     * @param int|string $key the parameter position or name
     */
    public function getImmutableParameter(int|string $key): ?Query\Parameter
    {
        $param = $this->qb->getParameter($key);
        if (null !== $param && $this->immutableParameters->contains($param)) {
            return $param;
        }

        return null;
    }

    /**
     * @return ArrayCollection<int, Query\Parameter>
     */
    public function getImmutableParameters(): ArrayCollection
    {
        return $this->immutableParameters;
    }

    /**
     * @return mixed|self
     * @param  string     $method
     * @param  array      $args
     */
    public function __call(string $method, array $args)
    {
        $callable = [$this->qb, $method];
        if (\is_callable($callable)) {
            $returnObj = \call_user_func_array($callable, $args);
            if (\in_array($method, $this->lazyJoinsCheckAfterMethods, true)) {
                $this->performLazyJoinsIfNeeded();
            }

            return $returnObj === $this->qb ? $this : $returnObj;
        }
        throw new LogicException(sprintf('Undefined method - %s::%s', \get_class($this->qb), $method));
    }

    public function __clone()
    {
        $this->qb = clone $this->qb;

        $immutableParameters = [];

        foreach ($this->immutableParameters as $immutableParameter) {
            $immutableParameters[] = clone $immutableParameter;
        }

        $this->immutableParameters = new ArrayCollection($immutableParameters);
    }
}
