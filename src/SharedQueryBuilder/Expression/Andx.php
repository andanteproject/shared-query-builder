<?php

declare(strict_types=1);

namespace Andante\Doctrine\ORM\SharedQueryBuilder\Expression;

/**
 * AND composite that can hold Proposal instances; expanded by SharedQueryBuilder when used in andWhere/orWhere.
 * Doctrine's Expr\Andx does not accept Proposal (strict parameter types), so we use this custom node type.
 *
 * @internal
 */
final class Andx
{
    /** @var list<mixed> */
    private array $parts;

    public function __construct(mixed ...$parts)
    {
        $this->parts = \array_values($parts);
    }

    /**
     * @return list<mixed>
     */
    public function getParts(): array
    {
        return $this->parts;
    }
}
