<?php

declare(strict_types=1);

namespace Andante\Doctrine\ORM\SharedQueryBuilder;

use Andante\Doctrine\ORM\SharedQueryBuilder\Expression\Andx as ProposalAndx;
use Andante\Doctrine\ORM\SharedQueryBuilder\Expression\Orx as ProposalOrx;
use Doctrine\ORM\Query\Expr as DoctrineExpr;

/**
 * Expression builder that accepts Proposal in orX() and andX() so you can pass proposals directly
 * without calling expandInto(). All other methods delegate to Doctrine's Expr.
 *
 * Doctrine's Expr::orX() and andX() only accept Expr\Comparison|Expr\Func|Expr\Andx|Expr\Orx|string,
 * so they cannot hold Proposal. We use SharedQueryBuilder\Expression\Orx and Andx as node types
 * that accept mixed parts (including Proposal); expandExpressionTree() expands them before
 * delegating to the inner QueryBuilder.
 *
 * @method DoctrineExpr\Comparison eq(mixed $x, mixed $y)
 * @method DoctrineExpr\Comparison neq(mixed $x, mixed $y)
 * @method DoctrineExpr\Comparison lt(mixed $x, mixed $y)
 * @method DoctrineExpr\Comparison lte(mixed $x, mixed $y)
 * @method DoctrineExpr\Comparison gt(mixed $x, mixed $y)
 * @method DoctrineExpr\Comparison gte(mixed $x, mixed $y)
 * @method DoctrineExpr\Comparison like(string $x, mixed $y)
 * @method DoctrineExpr\Comparison notLike(string $x, mixed $y)
 * @method string                  isNull(string $x)
 * @method string                  isNotNull(string $x)
 * @method DoctrineExpr\Func       not(mixed $restriction)
 * @method DoctrineExpr\Func       in(string $x, mixed $y)
 * @method DoctrineExpr\Func       notIn(string $x, mixed $y)
 * @method string                  between(mixed $val, int|string $x, int|string $y)
 * @method DoctrineExpr\OrderBy    asc(mixed $expr)
 * @method DoctrineExpr\OrderBy    desc(mixed $expr)
 */
class Expr
{
    private DoctrineExpr $inner;

    public function __construct(DoctrineExpr $inner)
    {
        $this->inner = $inner;
    }

    /**
     * OR composite. Accepts Proposal instances in addition to string and Doctrine Expr types.
     * Proposals are expanded when the expression is used in andWhere/orWhere.
     *
     * @param DoctrineExpr\Comparison|DoctrineExpr\Composite|DoctrineExpr\Func|Proposal|string ...$parts
     */
    public function orX(mixed ...$parts): ProposalOrx
    {
        return new ProposalOrx(...$parts);
    }

    /**
     * AND composite. Accepts Proposal instances in addition to string and Doctrine Expr types.
     * Proposals are expanded when the expression is used in andWhere/orWhere.
     *
     * @param DoctrineExpr\Comparison|DoctrineExpr\Composite|DoctrineExpr\Func|Proposal|string ...$parts
     */
    public function andX(mixed ...$parts): ProposalAndx
    {
        return new ProposalAndx(...$parts);
    }

    /**
     * Delegate to inner Expr for all other methods (eq, neq, like, etc.).
     *
     * @return mixed
     * @param  string $name
     * @param  array  $arguments
     */
    public function __call(string $name, array $arguments)
    {
        return $this->inner->{$name}(...$arguments);
    }
}
