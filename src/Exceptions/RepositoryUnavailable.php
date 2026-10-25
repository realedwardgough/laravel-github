<?php

declare(strict_types=1);

namespace Egough\LaravelGitHub\Exceptions;

use RuntimeException;

final class RepositoryUnavailable extends RuntimeException
{
    public static function notAccessible(): self
    {
        return new self(message: 'Repository unavailable.');
    }
}
