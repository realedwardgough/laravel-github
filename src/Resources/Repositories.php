<?php

declare(strict_types=1);

namespace Egough\LaravelGitHub\Resources;

use Egough\LaravelGitHub\Exceptions\RepositoryUnavailable;
use Egough\LaravelGitHub\Http\AuthenticatedTransport;
use Egough\LaravelGitHub\Support\CacheKey;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Cache;

final readonly class Repositories
{
    public function __construct(
        private Repository $config,
        private AuthenticatedTransport $transport,
        private CacheKey $cacheKey,
    ) {}

    /** @return list<array{full_name: string, url: string}> */
    public function all(): array
    {
        return Cache::remember(
            key: $this->cacheKey->make(suffix: 'repositories'),
            ttl: $this->config->get(key: 'github.cache.repositories', default: 300),
            callback: function (): array {
                $repositories = [];
                $page = 1;

                do {
                    $response = $this->transport->get('/installation/repositories', ['per_page' => 100, 'page' => $page]);
                    foreach ($response->json('repositories', []) as $repository) {
                        if (strcasecmp($repository['owner']['login'], $this->config->get('github.organisation')) === 0) {
                            $name = $repository['full_name'];
                            $repositories[] = ['full_name' => $name, 'url' => 'https://github.com/'.$name];
                        }
                    }
                    $page++;
                } while (str_contains($response->header('Link') ?? '', 'rel="next"'));

                usort($repositories, fn (array $a, array $b): int => strcasecmp($a['full_name'], $b['full_name']));

                return $repositories;
            }
        );
    }

    public function accessible(string $repository): string
    {
        foreach ($this->all() as $accessible) {
            if (strcasecmp($accessible['full_name'], $repository) === 0) {
                return $accessible['full_name'];
            }
        }

        throw RepositoryUnavailable::notAccessible();
    }
}
