<?php

declare(strict_types=1);

namespace Egough\LaravelGitHub\Exceptions;

use InvalidArgumentException;

final class MissingRepository extends InvalidArgumentException
{
    public static function required(): self
    {
        return new self(message: 'A repository must be specified before retrieving pull requests.');
    }
}
