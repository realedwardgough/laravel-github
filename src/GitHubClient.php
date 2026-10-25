<?php

declare(strict_types=1);

namespace Egough\LaravelGitHub;

use Egough\LaravelGitHub\Auth\InstallationTokenManager;
use Egough\LaravelGitHub\Http\AuthenticatedTransport;
use Egough\LaravelGitHub\Http\RequestFactory;
use Egough\LaravelGitHub\Queries\OpenPullRequestQuery;
use Egough\LaravelGitHub\Queries\PullRequestQuery;
use Egough\LaravelGitHub\Resources\PullRequests;
use Egough\LaravelGitHub\Resources\Repositories;
use Egough\LaravelGitHub\Support\CacheKey;
use Illuminate\Contracts\Config\Repository;

final readonly class GitHubClient
{
    private InstallationTokenManager $tokens;

    private Repositories $repositories;

    private PullRequests $pullRequests;

    public function __construct(Repository $config)
    {
        $cacheKey = new CacheKey($config);
        $requests = new RequestFactory($config);
        $this->tokens = new InstallationTokenManager($config, $requests, $cacheKey);
        $transport = new AuthenticatedTransport($requests, $this->tokens);
        $this->repositories = new Repositories($config, $transport, $cacheKey);
        $this->pullRequests = new PullRequests($config, $transport, $cacheKey, $this->repositories);
    }

    public function configured(): bool
    {
        return $this->tokens->configured();
    }

    /** @return list<array{full_name: string, url: string}> */
    public function repositories(): array
    {
        return $this->repositories->all();
    }

    /**
     * @return ($repository is null ? PullRequestQuery : array{data: list<array{url: string, title: string, number: int, state: string}>, next_page: int|null, incomplete: bool})
     */
    public function pullRequests(?string $repository = null, string $search = '', bool $includeClosed = false, int $page = 1): array|PullRequestQuery
    {
        if ($repository === null) {
            return new PullRequestQuery(
                pullRequests: $this->pullRequests,
                searchTerm: $search,
                includeClosed: $includeClosed,
                pageNumber: $page,
            );
        }

        return $this->pullRequests->search($repository, $search, $includeClosed, $page);
    }

    /** @return array{url: string, title: string, number: int, state: string}|null */
    public function pullRequest(string $repository, int $number): ?array
    {
        return $this->pullRequests->find($repository, $number);
    }

    /**
     * @return ($repository is null ? OpenPullRequestQuery : array{data: list<array{url: string, title: string, number: int, state: string, draft: bool, waiting_for_review: bool, created_at: string, updated_at: string}>, next_page: int|null})
     */
    public function openPullRequests(?string $repository = null, int $page = 1): array|OpenPullRequestQuery
    {
        if ($repository === null) {
            return new OpenPullRequestQuery(pullRequests: $this->pullRequests, pageNumber: $page);
        }

        return $this->pullRequests->open($repository, $page);
    }
}
