<?php

declare(strict_types=1);

namespace Egough\LaravelGitHub\Exceptions;

use RuntimeException;

final class GitHubUnavailable extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self(message: 'GitHub is not configured.');
    }

    public static function privateKeyUnavailable(): self
    {
        return new self(message: 'GitHub private key is unavailable. Check the server configuration.');
    }

    public static function privateKeyInvalid(): self
    {
        return new self(message: 'GitHub private key is invalid. Check the server configuration.');
    }

    public static function signingFailed(): self
    {
        return new self(message: 'Unable to authenticate the GitHub App.');
    }

    public static function authenticationFailed(): self
    {
        return new self(message: 'Unable to authenticate the GitHub App. Check the server configuration.');
    }

    public static function connectionFailed(): self
    {
        return new self(message: 'Unable to reach GitHub. Please try again.');
    }

    public static function upstreamUnavailable(): self
    {
        return new self(message: 'GitHub is unavailable. Check app access or try again later.');
    }
}
