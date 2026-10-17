<?php

declare(strict_types=1);

namespace Egough\LaravelGitHub\Facades;

use Egough\LaravelGitHub\GitHubClient;
use Illuminate\Support\Facades\Facade;

/**
 * @method static bool configured()
 * @method static list<array{full_name: string, url: string}> repositories()
 * @method static array{data: list<array{url: string, title: string, number: int, state: string}>, next_page: int|null, incomplete: bool} pullRequests(string $repository, string $search, bool $includeClosed, int $page)
 * @method static array{url: string, title: string, number: int, state: string}|null pullRequest(string $repository, int $number)
 *
 * @see GitHubClient
 */
final class GitHub extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return GitHubClient::class;
    }
}
