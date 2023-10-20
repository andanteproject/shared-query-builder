<?php

declare(strict_types=1);

namespace Andante\Doctrine\ORM\Exception;

class InvalidArgumentException extends \InvalidArgumentException implements SharedQueryBuilderException
{
}
