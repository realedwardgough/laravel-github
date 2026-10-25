<?php

declare(strict_types=1);

namespace Egough\LaravelGitHub\Facades;

use Egough\LaravelGitHub\GitHubClient;
use Egough\LaravelGitHub\Queries\OpenPullRequestQuery;
use Egough\LaravelGitHub\Queries\PullRequestQuery;
use Illuminate\Support\Facades\Facade;

/**
 * @method static bool configured()
 * @method static list<array{full_name: string, url: string}> repositories()
 * @method static PullRequestQuery pullRequests()
 * @method static PullRequestQuery pullRequests(null $repository = null, string $search = '', bool $includeClosed = false, int $page = 1)
 * @method static array{data: list<array{url: string, title: string, number: int, state: string}>, next_page: int|null, incomplete: bool} pullRequests(string $repository, string $search = '', bool $includeClosed = false, int $page = 1)
 * @method static array{url: string, title: string, number: int, state: string}|null pullRequest(string $repository, int $number)
 * @method static OpenPullRequestQuery openPullRequests()
 * @method static OpenPullRequestQuery openPullRequests(null $repository = null, int $page = 1)
 * @method static array{data: list<array{url: string, title: string, number: int, state: string, draft: bool, waiting_for_review: bool, created_at: string, updated_at: string}>, next_page: int|null} openPullRequests(string $repository, int $page = 1)
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
