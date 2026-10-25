<?php

declare(strict_types=1);

namespace Egough\LaravelGitHub\Queries;

use Egough\LaravelGitHub\Exceptions\InvalidPage;
use Egough\LaravelGitHub\Exceptions\MissingRepository;
use Egough\LaravelGitHub\Resources\PullRequests;

final readonly class OpenPullRequestQuery
{
    public function __construct(
        private PullRequests $pullRequests,
        private ?string $repositoryName = null,
        private int $pageNumber = 1,
    ) {}

    public function repository(string $repository): self
    {
        return new self(
            pullRequests: $this->pullRequests,
            repositoryName: $repository,
            pageNumber: $this->pageNumber,
        );
    }

    public function page(int $page): self
    {
        return new self(
            pullRequests: $this->pullRequests,
            repositoryName: $this->repositoryName,
            pageNumber: $page,
        );
    }

    /**
     * @return array{data: list<array{url: string, title: string, number: int, state: string, draft: bool, waiting_for_review: bool, created_at: string, updated_at: string}>, next_page: int|null}
     */
    public function get(): array
    {
        if ($this->repositoryName === null || trim($this->repositoryName) === '') {
            throw MissingRepository::required();
        }

        if ($this->pageNumber < 1) {
            throw InvalidPage::mustBePositive();
        }

        return $this->pullRequests->open($this->repositoryName, $this->pageNumber);
    }
}
