<?php

declare(strict_types=1);

namespace Egough\LaravelGitHub\Queries;

use Egough\LaravelGitHub\Exceptions\InvalidPage;
use Egough\LaravelGitHub\Exceptions\MissingRepository;
use Egough\LaravelGitHub\Resources\PullRequests;

final readonly class PullRequestQuery
{
    public function __construct(
        private PullRequests $pullRequests,
        private ?string $repositoryName = null,
        private string $searchTerm = '',
        private bool $includeClosed = false,
        private int $pageNumber = 1,
    ) {}

    public function repository(string $repository): self
    {
        return new self(
            pullRequests: $this->pullRequests,
            repositoryName: $repository,
            searchTerm: $this->searchTerm,
            includeClosed: $this->includeClosed,
            pageNumber: $this->pageNumber,
        );
    }

    public function search(string $search): self
    {
        return new self(
            pullRequests: $this->pullRequests,
            repositoryName: $this->repositoryName,
            searchTerm: $search,
            includeClosed: $this->includeClosed,
            pageNumber: $this->pageNumber,
        );
    }

    public function withClosed(bool $include = true): self
    {
        return new self(
            pullRequests: $this->pullRequests,
            repositoryName: $this->repositoryName,
            searchTerm: $this->searchTerm,
            includeClosed: $include,
            pageNumber: $this->pageNumber,
        );
    }

    public function page(int $page): self
    {
        return new self(
            pullRequests: $this->pullRequests,
            repositoryName: $this->repositoryName,
            searchTerm: $this->searchTerm,
            includeClosed: $this->includeClosed,
            pageNumber: $page,
        );
    }

    /** @return array{data: list<array{url: string, title: string, number: int, state: string}>, next_page: int|null, incomplete: bool} */
    public function get(): array
    {
        if ($this->repositoryName === null || trim($this->repositoryName) === '') {
            throw MissingRepository::required();
        }

        if ($this->pageNumber < 1) {
            throw InvalidPage::mustBePositive();
        }

        if ($this->pageNumber > 34) {
            throw InvalidPage::searchLimitExceeded();
        }

        return $this->pullRequests->search($this->repositoryName, $this->searchTerm, $this->includeClosed, $this->pageNumber);
    }
}
