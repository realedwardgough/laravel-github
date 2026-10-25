<?php

declare(strict_types=1);

namespace Egough\LaravelGitHub\Tests\Exceptions;

use Egough\LaravelGitHub\Exceptions\GitHubUnavailable;
use Egough\LaravelGitHub\Exceptions\RepositoryUnavailable;
use Egough\LaravelGitHub\GitHubUnavailable as LegacyGitHubUnavailable;
use Egough\LaravelGitHub\RepositoryUnavailable as LegacyRepositoryUnavailable;
use PHPUnit\Framework\TestCase;

final class ExceptionCompatibilityTest extends TestCase
{
    public function test_existing_github_exception_handlers_catch_the_namespaced_exception(): void
    {
        try {
            throw GitHubUnavailable::notConfigured();
        } catch (LegacyGitHubUnavailable $exception) {
            $this->assertSame('GitHub is not configured.', $exception->getMessage());
        }
    }

    public function test_existing_repository_exception_handlers_catch_the_namespaced_exception(): void
    {
        try {
            throw RepositoryUnavailable::notAccessible();
        } catch (LegacyRepositoryUnavailable $exception) {
            $this->assertSame('Repository unavailable.', $exception->getMessage());
        }
    }

    public function test_namespaced_github_exception_handlers_catch_the_existing_exception(): void
    {
        try {
            throw new LegacyGitHubUnavailable('GitHub is unavailable.');
        } catch (GitHubUnavailable $exception) {
            $this->assertSame('GitHub is unavailable.', $exception->getMessage());
        }
    }

    public function test_namespaced_repository_exception_handlers_catch_the_existing_exception(): void
    {
        try {
            throw new LegacyRepositoryUnavailable('Repository unavailable.');
        } catch (RepositoryUnavailable $exception) {
            $this->assertSame('Repository unavailable.', $exception->getMessage());
        }
    }
}
