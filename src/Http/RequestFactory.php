<?php

declare(strict_types=1);

namespace Egough\LaravelGitHub\Http;

use Egough\LaravelGitHub\Exceptions\GitHubUnavailable;
use Exception;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final readonly class RequestFactory
{
    public function __construct(private Repository $config) {}

    public function create(): PendingRequest
    {
        return Http::baseUrl('https://api.github.com')->accept('application/vnd.github+json')
            ->withHeaders([
                'X-GitHub-Api-Version' => $this->config->get('github.api_version', '2026-03-10'),
                'User-Agent' => $this->config->get('github.user_agent', 'egough-laravel-github'),
            ])
            ->connectTimeout(3)->timeout(10)->withOptions(['allow_redirects' => false]);
    }

    /** @param array<string, mixed> $query */
    public function send(PendingRequest $request, string $method, string $path, array $query = []): Response
    {
        try {
            return $request->send($method, $path, $method === 'GET' ? ['query' => $query] : ['json' => (object) []]);
        } catch (ConnectionException|Exception) {
            throw GitHubUnavailable::connectionFailed();
        }
    }
}
