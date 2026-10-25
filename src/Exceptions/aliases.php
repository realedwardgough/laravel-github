<?php

declare(strict_types=1);

namespace Egough\LaravelGitHub;

if (! class_exists(class: GitHubUnavailable::class, autoload: false)) {
    class_alias(class: Exceptions\GitHubUnavailable::class, alias: GitHubUnavailable::class);
}

if (! class_exists(class: RepositoryUnavailable::class, autoload: false)) {
    class_alias(class: Exceptions\RepositoryUnavailable::class, alias: RepositoryUnavailable::class);
}
