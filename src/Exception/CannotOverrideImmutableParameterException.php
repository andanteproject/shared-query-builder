<?php

declare(strict_types=1);


namespace Andante\Doctrine\ORM\Exception;

use Doctrine\ORM\Query;

class CannotOverrideImmutableParameterException extends ImmutableParameterException
{
    public function __construct(Query\Parameter $immutableParameter, int $code = 0, \Throwable $previous = null)
    {
        parent::__construct(
            \sprintf('"%s" parameter is already defined and immutable', $immutableParameter->getName()),
            $code,
            $previous
        );
    }
}
