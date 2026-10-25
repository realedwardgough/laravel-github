<?php

declare(strict_types=1);

namespace Egough\LaravelGitHub\Resources;

use Egough\LaravelGitHub\Exceptions\InvalidPage;
use Egough\LaravelGitHub\Http\AuthenticatedTransport;
use Egough\LaravelGitHub\Support\CacheKey;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Cache;

final readonly class PullRequests
{
    public function __construct(
        private Repository $config,
        private AuthenticatedTransport $transport,
        private CacheKey $cacheKey,
        private Repositories $repositories,
    ) {}

    /** @return array{data: list<array{url: string, title: string, number: int, state: string}>, next_page: int|null, incomplete: bool} */
    public function search(string $repository, string $search, bool $includeClosed, int $page): array
    {
        $repository = $this->repositories->accessible($repository);

        return Cache::remember(
            key: $this->cacheKey->make('pulls:'.json_encode([$repository, $search, $includeClosed, $page])),
            ttl: $this->config->get('github.cache.pull_requests', 60),
            callback: function () use ($repository, $search, $includeClosed, $page): array {
                if (preg_match('/^#?([1-9][0-9]*)$/', $search, $match)) {
                    $pull = $this->find($repository, (int) $match[1]);

                    return ['data' => $pull && ($includeClosed || $pull['state'] === 'open') ? [$pull] : [], 'next_page' => null, 'incomplete' => false];
                }

                $term = trim(preg_replace('/[^\pL\pN\s_.-]/u', ' ', $search));
                $query = 'repo:'.$repository.' is:pr'.($includeClosed ? '' : ' is:open').($term !== '' ? ' "'.$term.'" in:title' : '');
                $response = $this->transport->get('/search/issues', ['q' => $query, 'sort' => 'updated', 'order' => 'desc', 'per_page' => 30, 'page' => $page]);
                $data = array_map(fn (array $pull): array => $this->mapPull($repository, $pull), $response->json('items', []));
                $total = (int) $response->json('total_count', 0);

                return [
                    'data' => $data,
                    'next_page' => $page * 30 < min($total, 1000) ? $page + 1 : null,
                    'incomplete' => $response->json('incomplete_results', false) || $total > 1000,
                ];
            }
        );
    }

    /** @return array{url: string, title: string, number: int, state: string}|null */
    public function find(string $repository, int $number): ?array
    {
        $repository = $this->repositories->accessible($repository);

        return Cache::remember(
            key: $this->cacheKey->make('pull:'.$repository.':'.$number),
            ttl: $this->config->get('github.cache.pull_requests', 60),
            callback: function () use ($repository, $number): ?array {
                $response = $this->transport->get('/repos/'.$repository.'/pulls/'.$number, allowMissing: true);

                return $response->status() === 404 ? null : $this->mapPull($repository, $response->json());
            }
        );
    }

    /**
     * @return array{data: list<array{url: string, title: string, number: int, state: string, draft: bool, waiting_for_review: bool, created_at: string, updated_at: string}>, next_page: int|null}
     */
    public function open(string $repository, int $page = 1): array
    {
        if ($page < 1) {
            throw InvalidPage::mustBePositive();
        }

        $repository = $this->repositories->accessible($repository);

        return Cache::remember(
            key: $this->cacheKey->make('open-pulls:'.json_encode([$repository, $page])),
            ttl: $this->config->get('github.cache.pull_requests', 60),
            callback: function () use ($repository, $page): array {
                $response = $this->transport->get('/repos/'.$repository.'/pulls', [
                    'state' => 'open', 'sort' => 'updated', 'direction' => 'asc', 'per_page' => 100, 'page' => $page,
                ]);

                return [
                    'data' => array_map(fn (array $pull): array => [
                        ...$this->mapPull($repository, $pull),
                        'draft' => (bool) ($pull['draft'] ?? false),
                        'waiting_for_review' => ! empty($pull['requested_reviewers']) || ! empty($pull['requested_teams']),
                        'created_at' => $pull['created_at'],
                        'updated_at' => $pull['updated_at'],
                    ], $response->json()),
                    'next_page' => str_contains($response->header('Link') ?? '', 'rel="next"') ? $page + 1 : null,
                ];
            }
        );
    }

    /** @param array<string, mixed> $pull
     * @return array{url: string, title: string, number: int, state: string}
     */
    private function mapPull(string $repository, array $pull): array
    {
        return [
            'url' => 'https://github.com/'.$repository.'/pull/'.$pull['number'],
            'number' => (int) $pull['number'],
            'title' => $pull['title'],
            'state' => ($pull['merged_at'] ?? $pull['pull_request']['merged_at'] ?? null) ? 'merged' : $pull['state'],
        ];
    }
}
