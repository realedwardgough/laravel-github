<?php

declare(strict_types=1);

namespace Egough\LaravelGitHub\Support;

use Illuminate\Contracts\Config\Repository;

final readonly class CacheKey
{
    public function __construct(private Repository $config) {}

    public function make(string $suffix): string
    {
        return 'github:'.hash('sha256', json_encode([$this->config->get('github.organisation'), $this->config->get('github.app_id'),
            $this->config->get('github.installation_id'), $this->config->get('github.private_key_path')])).':'.hash('sha256', $suffix);
    }
}
