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
}
