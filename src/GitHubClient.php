<?php

declare(strict_types=1);

namespace Egough\LaravelGitHub;

use Exception;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final readonly class GitHubClient
{
    public function __construct(private Repository $config) {}

    public function configured(): bool
    {
        return filled($this->config->get(key: 'github.organisation'))
            && filled($this->config->get(key: 'github.app_id'))
            && filled($this->config->get(key: 'github.installation_id'))
            && filled($this->config->get(key: 'github.private_key_path'));
    }

    /** @return list<array{full_name: string, url: string}> */
    public function repositories(): array
    {
        return Cache::remember(
            key: $this->cacheKey(suffix: 'repositories'),
            ttl: $this->config->get(key: 'github.cache.repositories', default: 300),
            callback: function (): array {
                $repositories = [];
                $page = 1;

                do {
                    $response = $this->get('/installation/repositories', ['per_page' => 100, 'page' => $page]);
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

    /** @return array{data: list<array{url: string, title: string, number: int, state: string}>, next_page: int|null, incomplete: bool} */
    public function pullRequests(string $repository, string $search, bool $includeClosed, int $page): array
    {
        $repository = $this->accessibleRepository($repository);

        return Cache::remember(
            key: $this->cacheKey('pulls:'.json_encode([$repository, $search, $includeClosed, $page])),
            ttl: $this->config->get('github.cache.pull_requests', 60),
            callback: function () use ($repository, $search, $includeClosed, $page): array {
                if (preg_match('/^#?([1-9][0-9]*)$/', $search, $match)) {
                    $pull = $this->pullRequest($repository, (int) $match[1]);

                    return ['data' => $pull && ($includeClosed || $pull['state'] === 'open') ? [$pull] : [], 'next_page' => null, 'incomplete' => false];
                }

                $term = trim(preg_replace('/[^\pL\pN\s_.-]/u', ' ', $search));
                $query = 'repo:'.$repository.' is:pr'.($includeClosed ? '' : ' is:open').($term !== '' ? ' "'.$term.'" in:title' : '');
                $response = $this->get('/search/issues', ['q' => $query, 'sort' => 'updated', 'order' => 'desc', 'per_page' => 30, 'page' => $page]);
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
    public function pullRequest(string $repository, int $number): ?array
    {
        $repository = $this->accessibleRepository($repository);

        return Cache::remember(
            key: $this->cacheKey('pull:'.$repository.':'.$number),
            ttl: $this->config->get('github.cache.pull_requests', 60),
            callback: function () use ($repository, $number): ?array {
                $response = $this->get('/repos/'.$repository.'/pulls/'.$number, allowMissing: true);

                return $response->status() === 404 ? null : $this->mapPull($repository, $response->json());
            }
        );
    }

    private function accessibleRepository(string $repository): string
    {
        foreach ($this->repositories() as $accessible) {
            if (strcasecmp($accessible['full_name'], $repository) === 0) {
                return $accessible['full_name'];
            }
        }

        throw new RepositoryUnavailable('Repository unavailable.');
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

    /** @param array<string, mixed> $query */
    private function get(string $path, array $query = [], bool $allowMissing = false): Response
    {
        $response = $this->send($this->request()->withToken($this->token()), 'GET', $path, $query);

        if ($response->status() === 401) {
            Cache::forget($this->cacheKey('token'));
            $response = $this->send($this->request()->withToken($this->token()), 'GET', $path, $query);
        }

        if (! $response->successful() && ! ($allowMissing && $response->status() === 404)) {
            throw new GitHubUnavailable('GitHub is unavailable. Check app access or try again later.');
        }

        return $response;
    }

    private function token(): string
    {
        if (! $this->configured()) {
            throw new GitHubUnavailable('GitHub is not configured.');
        }

        $key = $this->cacheKey('token');
        if ($token = Cache::get($key)) {
            return $token;
        }

        $path = $this->config->get('github.private_key_path');
        if (! is_readable($path) || ! is_file($path)) {
            throw new GitHubUnavailable('GitHub private key is unavailable. Check the server configuration.');
        }

        $privateKey = openssl_pkey_get_private(file_get_contents($path));
        if ($privateKey === false) {
            throw new GitHubUnavailable('GitHub private key is invalid. Check the server configuration.');
        }

        $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $payload = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$encode(json_encode([
            'iat' => Carbon::now()->timestamp - 60, 'exp' => Carbon::now()->timestamp + 540, 'iss' => (string) $this->config->get('github.app_id'),
        ]));

        if (! openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new GitHubUnavailable('Unable to authenticate the GitHub App.');
        }

        $response = $this->send(
            request: $this->request()->withToken($payload.'.'.$encode($signature)),
            method: 'POST',
            path: '/app/installations/'.$this->config->get('github.installation_id').'/access_tokens'
        );

        if (! $response->successful() || ! is_string($response->json('token')) || ! $response->json('expires_at')) {
            throw new GitHubUnavailable('Unable to authenticate the GitHub App. Check the server configuration.');
        }

        Cache::put($key, $response->json('token'), Carbon::parse($response->json('expires_at'))->subMinute());

        return $response->json('token');
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl('https://api.github.com')->accept('application/vnd.github+json')
            ->withHeaders([
                'X-GitHub-Api-Version' => $this->config->get('github.api_version', '2026-03-10'),
                'User-Agent' => $this->config->get('github.user_agent', 'egough-laravel-github')
            ])
            ->connectTimeout(3)->timeout(10)->withOptions(['allow_redirects' => false]);
    }

    /** @param array<string, mixed> $query */
    private function send(PendingRequest $request, string $method, string $path, array $query = []): Response
    {
        try {
            return $request->send($method, $path, $method === 'GET' ? ['query' => $query] : ['json' => (object) []]);
        } catch (ConnectionException|Exception) {
            throw new GitHubUnavailable(message: 'Unable to reach GitHub. Please try again.');
        }
    }

    private function cacheKey(string $suffix): string
    {
        return 'github:'.hash('sha256', json_encode([$this->config->get('github.organisation'), $this->config->get('github.app_id'),
            $this->config->get('github.installation_id'), $this->config->get('github.private_key_path')])).':'.hash('sha256', $suffix);
    }
}
