<?php

declare(strict_types=1);

namespace Egough\LaravelGitHub\Exceptions;

use InvalidArgumentException;

final class InvalidPage extends InvalidArgumentException
{
    public static function mustBePositive(): self
    {
        return new self(message: 'The page must be greater than zero.');
    }

    public static function searchLimitExceeded(): self
    {
        return new self(message: 'Search pages must not exceed 34.');
    }
}
