<?php

declare(strict_types=1);

namespace Egough\LaravelGitHub;

use Illuminate\Support\ServiceProvider;

final class GitHubServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/github.php', 'github');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/github.php' => $this->app->configPath('github.php'),
            ], 'github-config');
        }
    }
}
