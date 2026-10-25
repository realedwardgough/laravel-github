<?php

declare(strict_types=1);

namespace Egough\LaravelGitHub\Auth;

use Egough\LaravelGitHub\Exceptions\GitHubUnavailable;
use Egough\LaravelGitHub\Http\RequestFactory;
use Egough\LaravelGitHub\Support\CacheKey;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

final readonly class InstallationTokenManager
{
    public function __construct(
        private Repository $config,
        private RequestFactory $requests,
        private CacheKey $cacheKey,
    ) {}

    public function configured(): bool
    {
        return filled($this->config->get(key: 'github.organisation'))
            && filled($this->config->get(key: 'github.app_id'))
            && filled($this->config->get(key: 'github.installation_id'))
            && filled($this->config->get(key: 'github.private_key_path'));
    }

    public function token(): string
    {
        if (! $this->configured()) {
            throw GitHubUnavailable::notConfigured();
        }

        $key = $this->cacheKey->make(suffix: 'token');
        if ($token = Cache::get($key)) {
            return $token;
        }

        $path = $this->config->get(key: 'github.private_key_path');
        if (! is_readable($path) || ! is_file($path)) {
            throw GitHubUnavailable::privateKeyUnavailable();
        }

        $privateKey = openssl_pkey_get_private(file_get_contents($path));
        if ($privateKey === false) {
            throw GitHubUnavailable::privateKeyInvalid();
        }

        $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $payload = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$encode(json_encode([
            'iat' => Carbon::now()->timestamp - 60, 'exp' => Carbon::now()->timestamp + 540, 'iss' => (string) $this->config->get('github.app_id'),
        ]));

        if (! openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw GitHubUnavailable::signingFailed();
        }

        $response = $this->requests->send(
            request: $this->requests->create()->withToken($payload.'.'.$encode($signature)),
            method: 'POST',
            path: '/app/installations/'.$this->config->get('github.installation_id').'/access_tokens'
        );

        if (! $response->successful() || ! is_string($response->json('token')) || ! $response->json('expires_at')) {
            throw GitHubUnavailable::authenticationFailed();
        }

        Cache::put($key, $response->json('token'), Carbon::parse($response->json('expires_at'))->subMinute());

        return $response->json('token');
    }

    public function invalidate(): void
    {
        Cache::forget($this->cacheKey->make(suffix: 'token'));
    }
}
