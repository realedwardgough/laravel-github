<?php

declare(strict_types=1);

namespace Egough\LaravelGitHub\Http;

use Egough\LaravelGitHub\Auth\InstallationTokenManager;
use Egough\LaravelGitHub\Exceptions\GitHubUnavailable;
use Illuminate\Http\Client\Response;

final readonly class AuthenticatedTransport
{
    public function __construct(private RequestFactory $requests, private InstallationTokenManager $tokens) {}

    /** @param array<string, mixed> $query */
    public function get(string $path, array $query = [], bool $allowMissing = false): Response
    {
        $response = $this->requests->send(
            request: $this->requests->create()->withToken($this->tokens->token()),
            method: 'GET',
            path: $path,
            query: $query
        );

        if ($response->status() === 401) {
            $this->tokens->invalidate();
            $response = $this->requests->send(
                request: $this->requests->create()->withToken($this->tokens->token()),
                method: 'GET',
                path: $path,
                query: $query
            );
        }

        if (! $response->successful() && ! ($allowMissing && $response->status() === 404)) {
            throw GitHubUnavailable::upstreamUnavailable();
        }

        return $response;
    }
}
