![Laravel GitHub](https://banners.beyondco.de/egough%2Flaravel-github.png?theme=dark&packageManager=composer+require&packageName=egough%2Flaravel-github&pattern=texture&style=style_2&description=GitHub+Repository+%26+PR+Browsing&md=1&showWatermark=0&fontSize=100px&images=https%3A%2F%2Flaravel.com%2Fimg%2Flogomark.min.svg "Laravel GitHub")

[![Latest Version on Packagist](https://img.shields.io/packagist/v/egough/laravel-github.svg?style=flat-square)](https://packagist.org/packages/egough/laravel-github)
[![Total Downloads](https://img.shields.io/packagist/dt/egough/laravel-github.svg?style=flat-square)](https://packagist.org/packages/egough/laravel-github)
[![License](https://img.shields.io/packagist/l/egough/laravel-github.svg?style=flat-square)](https://packagist.org/packages/egough/laravel-github)
[![Tests](https://github.com/realedwardgough/laravel-github/actions/workflows/tests.yml/badge.svg)](https://github.com/realedwardgough/laravel-github/actions/workflows/tests.yml)

GitHub App connections, repository browsing and pull request search for Laravel.

Connect your application to a GitHub organisation and browse the repositories and pull requests available to its installation. Authentication, token renewal and response caching are handled automatically.

---

## Quick Example

```php
use Egough\LaravelGitHub\Facades\GitHub;

// Browse the organisation's accessible repositories.
$repositories = GitHub::repositories();

// List open pull requests with draft and review-request metadata.
$openPulls = GitHub::openPullRequests()
    ->repository('acme/backend')
    ->get();

// Search open pull requests by title.
$results = GitHub::pullRequests()
    ->repository('acme/backend')
    ->search('checkout')
    ->get();

// Get a pull request's title and current state.
$pull = GitHub::pullRequest('acme/backend', 42);
```

---

## Changes in v1.0.4

The **v1.0.4** release adds immutable fluent queries and open pull request listings with draft flags, requested-review status and timestamps. Existing direct calls, named arguments, response shapes, configuration and caching behaviour remain supported.

Internals are now organised into authentication, resource, HTTP transport, exception and cache-key classes. Continue using the facade or `GitHubClient`; existing client construction remains supported. Exceptions now live under `Egough\LaravelGitHub\Exceptions` and provide named factories for fixed messages. The old exception imports are deprecated but supported throughout v1.x; see [migration guidance](#exception-migration).

See [docs.md](docs.md) for the full documentation and release changes.

---

## When Should I Use This Package?

Use this package when your Laravel application needs to browse an organisation's repositories and pull requests through a GitHub App.

Typical use cases include:

* Linking repositories to projects
* Attaching pull requests to support tickets or tasks
* Showing pull request titles and open, closed or merged states
* Building repository selectors and pull request search interfaces

Your application controls the routes, permissions, UI and storage of selected links.

### Connection Support

The package currently supports one GitHub App installation and one configured organisation. It provides read-only repository and pull request browsing. Personal access tokens, user OAuth, multiple connections and webhook handling are outside its current scope.

---

## Installation

```bash
composer require egough/laravel-github
```

Laravel discovers the service provider automatically.

Optionally publish the configuration:

```bash
php artisan vendor:publish --tag=github-config
```

No migrations are required.

---

## GitHub App Setup

1. Create a GitHub App with **Repository metadata: read** and **Pull requests: read** permissions.
2. Install the app on your organisation and grant access to the repositories you want to browse.
3. Generate a private key and store it outside your repository and public web directory. The PHP process must be able to read it.
4. Add the connection settings to your application's `.env` file:

```dotenv
GITHUB_ORGANISATION=acme
GITHUB_APP_ID=123456
GITHUB_INSTALLATION_ID=789012
GITHUB_PRIVATE_KEY_PATH=/absolute/path/to/github-app.pem
```

Use the GitHub **App ID**, rather than its OAuth client ID. The installation ID identifies the app's installation on your organisation.

No webhook endpoint or OAuth callback is needed for this integration.

Check whether the required settings are present:

```php
GitHub::configured();
```

This checks configuration values only. It does not verify the private key, connectivity or repository permissions.

---

## Repositories

```php
use Egough\LaravelGitHub\Facades\GitHub;

$repositories = GitHub::repositories();
```

Returns all repositories accessible to the installation that belong to the configured organisation, sorted by full name:

```php
[
    ['full_name' => 'acme/backend', 'url' => 'https://github.com/acme/backend'],
    ['full_name' => 'acme/frontend', 'url' => 'https://github.com/acme/frontend'],
]
```

Repository pagination is handled automatically. Private repositories are included when the installation has access to them.

---

## Pull Requests

### Fluent Queries

Calling `pullRequests()` or `openPullRequests()` without a repository returns a query builder. Passing a repository executes immediately, so existing calls still work:

```php
$results = GitHub::pullRequests('acme/backend', 'checkout', false, 1);
$openPulls = GitHub::openPullRequests('acme/backend', 1);
```

Search builders support `repository()`, `search()`, `withClosed()`, `page()` and `get()`. They default to an empty search, open PRs only and page 1. Use `withClosed()` to include closed and merged PRs, or `withClosed(false)` to exclude them again. Open-list builders support `repository()`, `page()` and `get()`.

Setters return new builders without changing the source, so queries can be reused:

```php
$base = GitHub::pullRequests()->repository('acme/backend');
$checkout = $base->search('checkout');

$openCheckout = $checkout->get();
$allCheckout = $checkout->withClosed()->get();
$nextPage = $checkout->page(2)->get();
```

Capture the returned builder when changing a variable: `$query = $query->page(2);`.

Only `get()` executes a request, retrieving one page through the same authentication, access checks and cache as equivalent direct calls. It does not advance the page. Before execution, it validates that the repository is non-blank and the page is valid: search pages must be 1 to 34; open-list pages must be positive. Invalid queries throw `MissingRepository` or `InvalidPage` from `Egough\LaravelGitHub\Exceptions`, both extending `InvalidArgumentException`.

### List Open Pull Requests

```php
$results = GitHub::openPullRequests()
    ->repository('acme/backend')
    ->page(1)
    ->get();
```

Returns open pull requests, including drafts:

```php
[
    'data' => [
        [
            'url' => 'https://github.com/acme/backend/pull/42',
            'number' => 42,
            'title' => 'Fix checkout',
            'state' => 'open',
            'draft' => false,
            'waiting_for_review' => true,
            'created_at' => '2026-10-20T12:00:00Z',
            'updated_at' => '2026-10-21T12:00:00Z',
        ],
    ],
    'next_page' => null,
]
```

`waiting_for_review` indicates that GitHub lists requested reviewers or teams; it does not mean the PR has no approvals. Timestamps are returned as supplied by GitHub. Filtering drafts is your application's responsibility.

Results are ordered by least recently updated first, with up to 100 PRs per page. Pass `next_page` to the next call until it is `null`. This listing uses the repository pull request endpoint, so the search API's 1,000-result limit does not apply and there is no `incomplete` field. The additional metadata is specific to `openPullRequests()`; search and individual lookup fields remain unchanged.

### Search by Title

```php
$results = GitHub::pullRequests(
    repository: 'acme/backend',
    search: 'checkout',
    includeClosed: false,
    page: 1,
);
```

An empty search returns the most recently updated pull requests. Set `includeClosed` to `true` to include closed and merged pull requests.

The result contains the pull requests and pagination information:

```php
[
    'data' => [
        [
            'url' => 'https://github.com/acme/backend/pull/42',
            'number' => 42,
            'title' => 'Fix checkout',
            'state' => 'open',
        ],
    ],
    'next_page' => null,
    'incomplete' => false,
]
```

Search results are ordered by most recently updated, with 30 results per page. Pass `next_page` into the next call to load more results; `null` means there are no further pages.

GitHub search exposes up to 1,000 results. When `incomplete` is `true`, ask users to narrow their search.

### Find by Number

Search using `42` or `#42` for an exact pull request lookup:

```php
$results = GitHub::pullRequests('acme/backend', '#42', true, 1);
```

The open/closed filter still applies to an exact-number search.

### Retrieve Details

```php
$pull = GitHub::pullRequest('acme/backend', 42);
```

Returns a single pull request with `url`, `number`, `title` and `state`, or `null` when it cannot be found.

States are `open`, `closed` or `merged`. Merged status is returned when GitHub includes merge information in the response.

All pull request lookups are restricted to repositories accessible through the configured organisation and installation.

---

## Dependency Injection

You can also inject the client into a controller or service:

```php
use Egough\LaravelGitHub\GitHubClient;

public function __construct(
    private GitHubClient $github,
) {}
```

```php
$repositories = $this->github->repositories();
```

The facade and injected client resolve the same shared container instance.

---

## Configuration

Publishing configuration creates `config/github.php`:

```php
return [
    'organisation' => env('GITHUB_ORGANISATION'),
    'app_id' => env('GITHUB_APP_ID'),
    'installation_id' => env('GITHUB_INSTALLATION_ID'),
    'private_key_path' => env('GITHUB_PRIVATE_KEY_PATH'),
    'user_agent' => 'egough-laravel-github',
    'api_version' => '2026-03-10',
    'cache' => [
        'repositories' => 300,
        'pull_requests' => 60,
    ],
];
```

Cache durations are in seconds. The user agent and GitHub API version can be changed here when needed.

---

## Caching and Authentication

The package uses Laravel's default cache store:

* Repository lists are cached for five minutes.
* Pull request searches, details and open pull request pages are cached for one minute.
* Installation tokens are cached until one minute before expiry.

Cache keys are scoped to the connection settings. Use a protected cache backend because installation tokens are sensitive.

The client signs a JWT using your app's private key and exchanges it for an installation token. A `401` response triggers one token refresh and retry. Requests have a three-second connection timeout and a ten-second overall timeout.

---

## Error Handling

The package throws exceptions and lets your application decide how to present them:

```php
use Egough\LaravelGitHub\Facades\GitHub;
use Egough\LaravelGitHub\Exceptions\GitHubUnavailable;
use Egough\LaravelGitHub\Exceptions\RepositoryUnavailable;

try {
    $pull = GitHub::pullRequest('acme/backend', 42);
} catch (RepositoryUnavailable $exception) {
    // The repository is outside the configured organisation or installation access.
} catch (GitHubUnavailable $exception) {
    // Configuration, authentication or an upstream request failed.
}
```

Upstream response bodies are not included in exception messages. A missing individual pull request returns `null` rather than throwing `RepositoryUnavailable`.

Your application is responsible for input validation, authorization and endpoint throttling. For browsing endpoints, validate `owner/repository` input, positive PR numbers, bounded search strings and search pages from 1 to 34.

### Exception Migration

From **v1.0.4**, use `Egough\LaravelGitHub\Exceptions\GitHubUnavailable` and `Egough\LaravelGitHub\Exceptions\RepositoryUnavailable`. The previous root-level names remain supported through Composer-loaded aliases during v1.x, without runtime deprecation warnings. **Those aliases will be removed in v2.0.0.** Update imports, fully qualified references, PHPDoc types and class strings before upgrading; catch blocks using the imported short names can stay unchanged.

Exceptions also expose named factories such as `GitHubUnavailable::notConfigured()`, `RepositoryUnavailable::notAccessible()`, `InvalidPage::mustBePositive()` and `MissingRepository::required()`. Each returns an exception instance to throw; standard constructors remain available. See [the exception factory reference](docs.md#exception-factory-methods) for all factories.

---

## Testing

Install development dependencies and run the package tests:

```bash
composer install
composer test
```

Or run PHPUnit directly:

```bash
vendor/bin/phpunit
```

Tests fake HTTP requests and use an in-memory cache. No GitHub credentials, network access or external database are required to run them after dependencies are installed.

In your application's tests, use Laravel's `Http::fake()` to provide GitHub responses and `Http::preventStrayRequests()` to prevent unexpected real requests.

---

## Local Development

To use a local checkout, add a path repository to the consuming application's `composer.json`. Adjust the path to match your directory layout:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../packages/laravel-github",
            "options": {
                "symlink": true,
                "reference": "config",
                "versions": {
                    "egough/laravel-github": "1.0.4"
                }
            }
        }
    ]
}
```

```bash
composer require egough/laravel-github:^1.0.4
```

The version override is a concrete version (`1.0.4`), rather than a constraint. PHP source changes are immediately available through the symlink. The `reference: config` setting lets Composer detect manifest changes before a commit. Run `composer update egough/laravel-github` when package metadata or dependencies change, including the exception alias autoload registration.

The path must exist wherever the consuming application installs dependencies. Replace the local path dependency with a published release or a reachable Git repository before using it in CI or deployment.

---

## Requirements

* PHP 8.2+
* Laravel 10, 11, 12, or 13 (subject to each Laravel version's PHP requirements)
* OpenSSL PHP extension

---

## License

MIT — see [LICENSE](LICENSE).
