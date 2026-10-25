<?php

declare(strict_types=1);

namespace Egough\LaravelGitHub\Tests;

use Egough\LaravelGitHub\Facades\GitHub;
use Egough\LaravelGitHub\GitHubClient;
use Egough\LaravelGitHub\GitHubUnavailable;
use Egough\LaravelGitHub\RepositoryUnavailable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class GitHubClientTest extends TestCase
{
    private Repository $config;

    private GitHubClient $github;

    private ?string $privateKeyPath = null;

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container;
        $container->instance('cache', new CacheRepository(new ArrayStore));
        $container->singleton(Factory::class);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $this->config = new Repository(['github' => []]);
        $this->github = new GitHubClient($this->config);
    }

    protected function tearDown(): void
    {
        if ($this->privateKeyPath !== null) {
            unlink($this->privateKeyPath);
        }
        Carbon::setTestNow();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_facade_browses_repositories_and_pull_requests_through_the_bound_client(): void
    {
        $this->configure();
        Facade::getFacadeApplication()->instance(GitHubClient::class, $this->github);
        $this->fakeAccess();
        Http::fake([
            'https://api.github.com/repos/acme/backend/pulls/42' => Http::response([
                'number' => 42, 'title' => 'Fix checkout', 'state' => 'open',
            ]),
        ]);

        $this->assertTrue(GitHub::configured());
        $this->assertSame([['full_name' => 'acme/backend', 'url' => 'https://github.com/acme/backend']], GitHub::repositories());
        $pull = GitHub::pullRequest('acme/backend', 42);
        $this->assertSame(['url' => 'https://github.com/acme/backend/pull/42', 'number' => 42, 'title' => 'Fix checkout', 'state' => 'open'], $pull);
        $this->assertSame(['data' => [$pull], 'next_page' => null, 'incomplete' => false], GitHub::pullRequests('acme/backend', '#42', false, 1));
    }

    public function test_authenticates_paginates_filters_sorts_and_caches_repositories(): void
    {
        $this->configure();
        Carbon::setTestNow('2026-10-17 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'https://api.github.com/app/installations/123/access_tokens' => Http::sequence()
                ->push(['token' => 'initial', 'expires_at' => Carbon::now()->addHour()->toIso8601String()], 201)
                ->push(['token' => 'renewed', 'expires_at' => Carbon::now()->addHours(2)->toIso8601String()], 201),
            'https://api.github.com/installation/repositories*' => Http::sequence()
                ->push(['repositories' => [$this->repository('acme/zebra'), $this->repository('other/private')]], 200, ['Link' => '<https://api.github.com/installation/repositories?page=2>; rel="next"'])
                ->push(['repositories' => [$this->repository('acme/backend')]])
                ->push(['repositories' => []])->push(['repositories' => []]),
        ]);

        $repositories = $this->github->repositories();

        $this->assertSame([
            ['full_name' => 'acme/backend', 'url' => 'https://github.com/acme/backend'],
            ['full_name' => 'acme/zebra', 'url' => 'https://github.com/acme/zebra'],
        ], $repositories);
        $this->assertSame($repositories, $this->github->repositories());
        Http::assertSentCount(3);
        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/access_tokens')) {
                return false;
            }
            $jwt = explode('.', substr($request->header('Authorization')[0], 7));
            $claims = json_decode(base64_decode(strtr($jwt[1], '-_', '+/')), true);

            return openssl_verify($jwt[0].'.'.$jwt[1], base64_decode(strtr($jwt[2], '-_', '+/')), $this->publicKey, OPENSSL_ALGO_SHA256) === 1
                && $claims['iss'] === '456'
                && $claims['iat'] === Carbon::now()->timestamp - 60
                && $claims['exp'] === Carbon::now()->timestamp + 540;
        });
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'page=2') && $request->hasHeader('Authorization', 'Bearer initial'));
        Carbon::setTestNow(Carbon::now()->addMinutes(6));
        $this->github->repositories();
        Http::assertSentCount(4);
        Carbon::setTestNow(Carbon::now()->addMinutes(55));
        $this->github->repositories();
        Http::assertSentCount(6);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer renewed'));
    }

    public function test_searches_titles_and_exact_numbers_with_pagination_and_merged_details(): void
    {
        $this->configure();
        $this->fakeAccess();
        Http::fake([
            'https://api.github.com/search/issues*' => Http::response(['items' => [['number' => 42, 'title' => 'Fix checkout', 'state' => 'open']], 'total_count' => 31]),
            'https://api.github.com/repos/acme/backend/pulls/42' => Http::response(['number' => 42, 'title' => 'Fix checkout', 'state' => 'closed', 'merged_at' => '2026-10-16T12:00:00Z']),
        ]);

        $results = $this->github->pullRequests('ACME/backend', 'checkout', false, 1);

        $this->assertSame(['data' => [['url' => 'https://github.com/acme/backend/pull/42', 'number' => 42, 'title' => 'Fix checkout', 'state' => 'open']], 'next_page' => 2, 'incomplete' => false], $results);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/search/issues') && $request['q'] === 'repo:acme/backend is:pr is:open "checkout" in:title');
        $this->assertSame('merged', $this->github->pullRequests('acme/backend', '#42', true, 1)['data'][0]['state']);
        $this->assertSame([], $this->github->pullRequests('acme/backend', '42', false, 1)['data']);
        $this->assertSame('merged', $this->github->pullRequest('acme/backend', 42)['state']);
        Http::assertSentCount(4);
    }

    public function test_rejects_repositories_outside_the_installation_without_fetching_details(): void
    {
        $this->configure();
        $this->fakeAccess();

        try {
            $this->github->pullRequest('other/private', 42);
            $this->fail('Expected inaccessible repository to be rejected.');
        } catch (RepositoryUnavailable $exception) {
            $this->assertSame('Repository unavailable.', $exception->getMessage());
        }
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/repos/other/'));
    }

    public function test_returns_missing_details_and_reports_search_limits(): void
    {
        $this->configure();
        $this->fakeAccess();
        Http::fake([
            'https://api.github.com/search/issues*' => Http::response(['items' => [], 'total_count' => 1200, 'incomplete_results' => true]),
            'https://api.github.com/repos/acme/backend/pulls/99' => Http::response([], 404),
        ]);

        $this->assertSame(['data' => [], 'next_page' => null, 'incomplete' => true], $this->github->pullRequests('acme/backend', '', true, 34));
        $this->assertNull($this->github->pullRequest('acme/backend', 99));
    }

    public function test_refreshes_revoked_tokens_once(): void
    {
        $this->configure();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.github.com/app/installations/123/access_tokens' => Http::sequence()
                ->push(['token' => 'revoked', 'expires_at' => Carbon::now()->addHour()->toIso8601String()], 201)
                ->push(['token' => 'replacement', 'expires_at' => Carbon::now()->addHour()->toIso8601String()], 201),
            'https://api.github.com/installation/repositories*' => Http::sequence()->push([], 401)->push(['repositories' => []]),
        ]);

        $this->assertSame([], $this->github->repositories());
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer replacement'));
    }

    #[TestWith([403])]
    #[TestWith([429])]
    #[TestWith([500])]
    #[TestWith([401])]
    public function test_raises_safe_exceptions_for_upstream_errors(int $status): void
    {
        $this->configure();
        $this->fakeAccess();
        Http::fake(['https://api.github.com/search/issues*' => Http::response(['message' => 'sensitive upstream response'], $status)]);

        $this->expectException(GitHubUnavailable::class);
        $this->expectExceptionMessage('GitHub is unavailable. Check app access or try again later.');
        $this->github->pullRequests('acme/backend', '', false, 1);
    }

    public function test_missing_settings_and_unreadable_keys_do_not_send_requests(): void
    {
        Http::preventStrayRequests();
        $this->assertFalse($this->github->configured());
        try {
            $this->github->repositories();
            $this->fail('Expected missing configuration to be rejected.');
        } catch (GitHubUnavailable $exception) {
            $this->assertSame('GitHub is not configured.', $exception->getMessage());
        }
        $this->config->set('github', ['organisation' => 'acme', 'app_id' => '456', 'installation_id' => '123', 'private_key_path' => '/missing/private-key.pem']);
        $this->assertTrue($this->github->configured());
        try {
            $this->github->repositories();
            $this->fail('Expected unreadable key to be rejected.');
        } catch (GitHubUnavailable $exception) {
            $this->assertSame('GitHub private key is unavailable. Check the server configuration.', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_connection_failures_are_reported_safely(): void
    {
        $this->configure();
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/app/installations/123/access_tokens' => function (): never {
            throw new ConnectionException('Connection failed.');
        }]);

        $this->expectException(GitHubUnavailable::class);
        $this->expectExceptionMessage('Unable to reach GitHub. Please try again.');
        $this->github->repositories();
    }

    public function test_honours_cache_duration_and_request_headers(): void
    {
        $this->configure();
        $this->fakeAccess();
        $this->config->set('github.cache.repositories', 0);
        $this->config->set('github.cache.pull_requests', 0);
        $this->config->set('github.user_agent', 'ExampleApplication');
        $this->config->set('github.api_version', '2026-03-10');
        Http::fake(['https://api.github.com/repos/acme/backend/pulls/42' => Http::response(['number' => 42, 'title' => 'Fix', 'state' => 'open'])]);

        $this->github->pullRequest('acme/backend', 42);
        $this->github->pullRequest('acme/backend', 42);

        Http::assertSentCount(5);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('User-Agent', 'ExampleApplication') && $request->hasHeader('X-GitHub-Api-Version', '2026-03-10'));
    }

    public function test_lists_open_pull_requests_with_draft_and_requested_review_metadata_and_pagination(): void
    {
        $this->configure();
        $this->fakeAccess();
        Http::fake([
            'https://api.github.com/repos/acme/backend/pulls*' => Http::sequence()
                ->push([
                    $this->openPull(1),
                    $this->openPull(2, ['draft' => true, 'requested_reviewers' => [['login' => 'reviewer']]]),
                    $this->openPull(3, ['requested_teams' => [['slug' => 'engineering']]]),
                ], 200, ['Link' => '<https://api.github.com/repos/acme/backend/pulls?page=2>; rel="next"'])
                ->push([$this->openPull(4)]),
        ]);

        $result = $this->github->openPullRequests('ACME/backend');

        $this->assertSame([
            'url' => 'https://github.com/acme/backend/pull/1', 'number' => 1, 'title' => 'Pull request 1',
            'state' => 'open', 'draft' => false, 'waiting_for_review' => false,
            'created_at' => '2026-10-20T12:00:00Z', 'updated_at' => '2026-10-21T12:00:00Z',
        ], $result['data'][0]);
        $this->assertTrue($result['data'][1]['draft']);
        $this->assertTrue($result['data'][1]['waiting_for_review']);
        $this->assertTrue($result['data'][2]['waiting_for_review']);
        $this->assertSame(2, $result['next_page']);
        $this->assertSame($result, $this->github->openPullRequests('acme/backend'));
        $last = $this->github->openPullRequests('acme/backend', 2);
        $this->assertSame(4, $last['data'][0]['number']);
        $this->assertNull($last['next_page']);
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/repos/acme/backend/pulls')
            && $request['state'] === 'open' && $request['per_page'] === 100 && $request['page'] === 2);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/search/issues'));
    }

    public function test_open_pull_request_cache_refreshes_review_and_draft_changes(): void
    {
        $this->configure();
        $this->fakeAccess();
        Carbon::setTestNow('2026-10-24 12:00:00');
        Http::fake([
            'https://api.github.com/repos/acme/backend/pulls*' => Http::sequence()
                ->push([$this->openPull(1, ['draft' => true])])
                ->push([$this->openPull(1, ['requested_reviewers' => [['login' => 'reviewer']]])]),
        ]);

        $this->assertTrue($this->github->openPullRequests('acme/backend')['data'][0]['draft']);
        Carbon::setTestNow(Carbon::now()->addSeconds(61));
        $refreshed = $this->github->openPullRequests('acme/backend')['data'][0];

        $this->assertFalse($refreshed['draft']);
        $this->assertTrue($refreshed['waiting_for_review']);
        Http::assertSentCount(4);
    }

    public function test_open_pull_requests_reject_inaccessible_repositories(): void
    {
        $this->configure();
        $this->fakeAccess();

        try {
            $this->github->openPullRequests('other/private');
            $this->fail('Expected inaccessible repository to be rejected.');
        } catch (RepositoryUnavailable) {
            Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/repos/other/'));
        }
    }

    #[TestWith([403])]
    #[TestWith([429])]
    #[TestWith([500])]
    public function test_open_pull_request_errors_are_safe_and_not_cached(int $status): void
    {
        $this->configure();
        $this->fakeAccess();
        Http::fake([
            'https://api.github.com/repos/acme/backend/pulls*' => Http::sequence()
                ->push(['message' => 'private upstream details'], $status)->push([]),
        ]);

        try {
            $this->github->openPullRequests('acme/backend');
            $this->fail('Expected upstream error to be reported.');
        } catch (GitHubUnavailable $exception) {
            $this->assertSame('GitHub is unavailable. Check app access or try again later.', $exception->getMessage());
        }
        $this->assertSame(['data' => [], 'next_page' => null], $this->github->openPullRequests('acme/backend'));
        Http::assertSentCount(4);
    }

    public function test_open_pull_requests_reject_invalid_pages_before_sending_requests(): void
    {
        Http::preventStrayRequests();

        try {
            $this->github->openPullRequests('acme/backend', 0);
            $this->fail('Expected invalid page to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('The page must be greater than zero.', $exception->getMessage());
            Http::assertNothingSent();
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function openPull(int $number, array $overrides = []): array
    {
        return [...[
            'number' => $number, 'title' => 'Pull request '.$number, 'state' => 'open', 'draft' => false,
            'requested_reviewers' => [], 'requested_teams' => [],
            'created_at' => '2026-10-20T12:00:00Z', 'updated_at' => '2026-10-21T12:00:00Z',
        ], ...$overrides];
    }

    public function test_clients_with_different_configurations_keep_repository_access_and_caches_separate(): void
    {
        $this->configure();
        $otherConfig = new Repository(['github' => [
            'organisation' => 'other', 'app_id' => '789', 'installation_id' => '999',
            'private_key_path' => $this->privateKeyPath,
        ]]);
        $otherClient = new GitHubClient(config: $otherConfig);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.github.com/app/installations/123/access_tokens' => Http::response([
                'token' => 'secret', 'expires_at' => Carbon::now()->addHour()->toIso8601String(),
            ], 201),
            'https://api.github.com/app/installations/999/access_tokens' => Http::response([
                'token' => 'other-secret', 'expires_at' => Carbon::now()->addHour()->toIso8601String(),
            ], 201),
            'https://api.github.com/installation/repositories*' => fn (Request $request) => Http::response([
                'repositories' => [$this->repository($request->hasHeader('Authorization', 'Bearer other-secret') ? 'other/frontend' : 'acme/backend')],
            ]),
            'https://api.github.com/repos/acme/backend/pulls/42' => Http::response([
                'number' => 42, 'title' => 'Backend change', 'state' => 'open',
            ]),
            'https://api.github.com/repos/other/frontend/pulls/42' => Http::response([
                'number' => 42, 'title' => 'Frontend change', 'state' => 'closed',
            ]),
        ]);

        $first = $this->github->pullRequest('acme/backend', 42);
        $second = $otherClient->pullRequest('other/frontend', 42);

        $this->assertSame('Backend change', $first['title']);
        $this->assertSame('Frontend change', $second['title']);
        $this->assertSame($first, $this->github->pullRequest('acme/backend', 42));
        $this->assertSame($second, $otherClient->pullRequest('other/frontend', 42));
        Http::assertSentCount(6);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/repos/other/frontend/')
            && $request->hasHeader('Authorization', 'Bearer other-secret'));

        $this->expectException(RepositoryUnavailable::class);
        $otherClient->pullRequest('acme/backend', 42);
    }

    public function test_clients_with_the_same_configuration_reuse_cached_tokens_and_results(): void
    {
        $this->configure();
        $this->fakeAccess();
        Http::fake([
            'https://api.github.com/repos/acme/backend/pulls/42' => Http::response([
                'number' => 42, 'title' => 'Fix checkout', 'state' => 'open',
            ]),
        ]);
        $first = $this->github->pullRequest('acme/backend', 42);

        $second = (new GitHubClient(config: $this->config))->pullRequest('acme/backend', 42);

        $this->assertSame($first, $second);
        Http::assertSentCount(3);
    }

    private function configure(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        $this->publicKey = openssl_pkey_get_details($key)['key'];
        $this->privateKeyPath = tempnam(sys_get_temp_dir(), 'laravel-github-test-');
        file_put_contents($this->privateKeyPath, $pem);
        $this->config->set('github', ['organisation' => 'acme', 'app_id' => '456', 'installation_id' => '123', 'private_key_path' => $this->privateKeyPath]);
    }

    private function fakeAccess(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.github.com/app/installations/123/access_tokens' => Http::response(['token' => 'secret', 'expires_at' => Carbon::now()->addHour()->toIso8601String()], 201),
            'https://api.github.com/installation/repositories*' => Http::response(['repositories' => [$this->repository('acme/backend')]]),
        ]);
    }

    /** @return array{full_name: string, owner: array{login: string}} */
    private function repository(string $name): array
    {
        return ['full_name' => $name, 'owner' => ['login' => explode('/', $name)[0]]];
    }
}
