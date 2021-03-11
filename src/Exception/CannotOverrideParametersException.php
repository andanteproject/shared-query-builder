<?php

declare(strict_types=1);


namespace Andante\Doctrine\ORM\Exception;

class CannotOverrideParametersException extends ImmutableParameterException
{
    public function __construct(int $code = 0, \Throwable $previous = null)
    {
        parent::__construct(
            'Cannot override query parameters because some defined parameters are immutable',
            $code,
            $previous
        );
    }
}
