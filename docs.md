# Laravel GitHub Documentation

Documentation for the upcoming **v1.0.4** release, following **v1.0.3**.

## Contents

- [Introduction](#introduction)
- [Changes in v1.0.4](#changes-in-v104)
- [When To Use This Package](#when-to-use-this-package)
- [Installation](#installation)
- [GitHub App Setup](#github-app-setup)
    - [Creating and Installing the App](#creating-and-installing-the-app)
    - [Connecting Your Laravel Application](#connecting-your-laravel-application)
    - [Checking Configuration](#checking-configuration)
- [Configuration](#configuration)
- [Repositories](#repositories)
- [Pull Requests](#pull-requests)
    - [Fluent Query API](#fluent-query-api)
    - [Reusing Queries](#reusing-queries)
    - [Listing Open Pull Requests](#listing-open-pull-requests)
    - [Searching Pull Requests](#searching-pull-requests)
    - [Finding a Pull Request by Number](#finding-a-pull-request-by-number)
    - [Retrieving a Pull Request](#retrieving-a-pull-request)
    - [Understanding Pull Request States](#understanding-pull-request-states)
- [Dependency Injection](#dependency-injection)
- [Authentication and Caching](#authentication-and-caching)
- [Package Structure](#package-structure)
- [Error Handling](#error-handling)
    - [Exception Factory Methods](#exception-factory-methods)
    - [Deprecated Exception Names and v2.0.0 Migration](#deprecated-exception-names-and-v200-migration)
- [Testing](#testing)
    - [Running the Tests](#running-the-tests)
    - [Testing Your Own Application](#testing-your-own-application)
- [Local Development](#local-development)
- [Requirements](#requirements)

---

<a name="introduction"></a>
## Introduction

Laravel GitHub is a Laravel package for connecting your application to a GitHub organisation through a GitHub App installation. It provides a straightforward way to browse repositories, list open pull requests, search pull requests and retrieve individual pull request details without implementing GitHub App authentication yourself.

The package handles installation token generation, renewal and response caching, allowing your application to focus on how repository and pull request information is presented and used.

```php
use Egough\LaravelGitHub\Facades\GitHub;

// Browse repositories accessible to the installation.
$repositories = GitHub::repositories();

// List open pull requests, including draft and review-request metadata.
$openPulls = GitHub::openPullRequests()
    ->repository('acme/backend')
    ->get();

// Search by title, including closed and merged pull requests.
$results = GitHub::pullRequests()
    ->repository('acme/backend')
    ->search('checkout')
    ->withClosed()
    ->page(1)
    ->get();

// Retrieve an individual pull request.
$pull = GitHub::pullRequest('acme/backend', 42);
```

Existing direct calls remain supported:

```php
$results = GitHub::pullRequests('acme/backend', 'checkout', false, 1);
$openPulls = GitHub::openPullRequests('acme/backend', 1);
```

The direct search above excludes closed PRs; add `->withClosed()` to a fluent search when you want to include closed and merged PRs.

---

<a name="changes-in-v104"></a>
## Changes in v1.0.4

Version **v1.0.4** adds the following changes to **v1.0.3**:

- Immutable fluent queries are available through `pullRequests()` and `openPullRequests()`, with named setters and an explicit `get()` execution method.
- `openPullRequests()` lists open pull requests with draft flags, requested-review status, creation/update timestamps and pagination.
- Package internals are organised into authentication, resource, HTTP transport, exception and cache-key classes. `GitHubClient` remains the public entry point.
- Fixed exception messages are owned by named factory methods on their exception classes.
- Exception classes now live under `Egough\LaravelGitHub\Exceptions`. The previous `Egough\LaravelGitHub\GitHubUnavailable` and `Egough\LaravelGitHub\RepositoryUnavailable` names are **deprecated from v1.0.4**, but remain supported through Composer-loaded aliases throughout the remaining v1.x releases.

Existing client construction, direct facade calls, named arguments, response shapes, configuration and caching behaviour remain supported. Chaining is additive in v1.0.4; applications do not need to migrate existing calls.

**Breaking change planned for v2.0.0:** `src/Exceptions/aliases.php` and its Composer autoload registration will be removed. Applications must update their exception imports to `Egough\LaravelGitHub\Exceptions\GitHubUnavailable` and `Egough\LaravelGitHub\Exceptions\RepositoryUnavailable` before upgrading. See [Deprecated Exception Names and v2.0.0 Migration](#deprecated-exception-names-and-v200-migration).

---

<a name="when-to-use-this-package"></a>
## When To Use This Package

Use Laravel GitHub when your application needs read-only access to an organisation's repositories and pull requests, particularly when integrating GitHub information into an existing business workflow.

**Common use cases:**

- Linking GitHub repositories to internal projects
- Associating pull requests with support tickets or development tasks
- Displaying pull request titles and current states
- Building repository selectors and pull request search interfaces
- Surfacing development activity in an internal application

**When not to use this package:**

Laravel GitHub currently supports one configured organisation and one GitHub App installation. It does not provide personal access token authentication, user OAuth, multiple connections or webhook handling.

It is also a read-only integration: creating repositories, modifying pull requests and merging code are outside its scope. Your application remains responsible for its own routes, authorization, user interface and storage of any selected repository or pull request links.

---

<a name="installation"></a>
## Installation

Install the package via Composer:

```bash
composer require egough/laravel-github
```

Laravel automatically discovers the package's service provider, so no manual provider registration is required.

### Publishing Configuration

Optionally publish the configuration file:

```bash
php artisan vendor:publish --tag=github-config
```

This creates `config/github.php`, where you can adjust the GitHub connection and caching settings.

No migrations are required.

---

<a name="github-app-setup"></a>
## GitHub App Setup

Laravel GitHub authenticates through a GitHub App installed on the organisation whose repositories you want to access. This requires a GitHub App ID, installation ID and private key.

<a name="creating-and-installing-the-app"></a>
### Creating and Installing the App

1. Create a GitHub App and grant it **Repository metadata: read** and **Pull requests: read** permissions.
2. Install the app on your GitHub organisation, selecting the repositories it should be allowed to access.
3. Generate a private key for the app.
4. Store the private key outside your source repository and publicly accessible web directories, in a location readable by your PHP process.

The package does not require a webhook endpoint or OAuth callback for this integration.

<a name="connecting-your-laravel-application"></a>
### Connecting Your Laravel Application

Add the following settings to your application's `.env` file:

```dotenv
GITHUB_ORGANISATION=acme
GITHUB_APP_ID=123456
GITHUB_INSTALLATION_ID=789012
GITHUB_PRIVATE_KEY_PATH=/absolute/path/to/github-app.pem
```

| Variable | Description |
| --- | --- |
| `GITHUB_ORGANISATION` | The GitHub organisation whose repositories will be browsed. |
| `GITHUB_APP_ID` | The GitHub App ID, not the OAuth client ID. |
| `GITHUB_INSTALLATION_ID` | The ID of the app's installation on the organisation. |
| `GITHUB_PRIVATE_KEY_PATH` | Absolute path to the GitHub App private key file. |

Keep the private key secure and do not commit it to version control.

<a name="checking-configuration"></a>
### Checking Configuration

Use the facade to check whether the required connection settings are present:

```php
use Egough\LaravelGitHub\Facades\GitHub;

$configured = GitHub::configured();
```

`configured()` checks for configuration values only. It does **not** verify that the private key is valid, that GitHub is reachable or that the installation has permission to access a repository.

---

<a name="configuration"></a>
## Configuration

After publishing, the configuration file will be available at `config/github.php`.

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

### Options

**`organisation`**

The GitHub organisation associated with the app installation. Repository access is restricted to this configured organisation.

**`app_id`**

The identifier of your GitHub App, used during application authentication.

**`installation_id`**

The installation identifier used to request an installation access token.

**`private_key_path`**

An absolute filesystem path to the app's private key. The PHP process must have permission to read this file.

**`user_agent`**

The User-Agent value sent to GitHub. Defaults to `egough-laravel-github`.

**`api_version`**

The GitHub API version sent with requests. The repository currently specifies `2026-03-10`.

**`cache.repositories`**

How long repository lists are cached, in seconds. Defaults to `300` (five minutes).

**`cache.pull_requests`**

How long pull request searches, individual pull request details and open pull request pages are cached, in seconds. Defaults to `60` (one minute).

---

<a name="repositories"></a>
## Repositories

Use the `GitHub` facade to retrieve repositories accessible through the configured GitHub App installation.

```php
use Egough\LaravelGitHub\Facades\GitHub;

$repositories = GitHub::repositories();
```

The method returns repositories belonging to the configured organisation, sorted by full name:

```php
[
    [
        'full_name' => 'acme/backend',
        'url' => 'https://github.com/acme/backend',
    ],
    [
        'full_name' => 'acme/frontend',
        'url' => 'https://github.com/acme/frontend',
    ],
]
```

| Field | Description |
| --- | --- |
| `full_name` | Repository identifier in `owner/repository` format. |
| `url` | The repository's GitHub URL. |

Repository pagination is handled automatically, so you do not need to request each page yourself.

Private repositories are included when the GitHub App installation has access to them. Repositories outside the configured organisation or outside the installation's granted access are not available through the package.

---

<a name="pull-requests"></a>
## Pull Requests

The package provides methods for listing open pull requests, searching pull requests within an accessible repository and retrieving a specific pull request by number.

<a name="fluent-query-api"></a>
### Fluent Query API

From **v1.0.4**, calling `pullRequests()` or `openPullRequests()` without a repository returns a new query builder. Calling either method with a repository executes immediately and returns the existing result array.

```php
$results = GitHub::pullRequests()
    ->repository('acme/backend')
    ->search('checkout')
    ->withClosed()
    ->page(1)
    ->get();

$openPulls = GitHub::openPullRequests()
    ->repository('acme/backend')
    ->page(1)
    ->get();
```

Builder creation and setters do not send HTTP requests. `get()` validates the query, then retrieves **one page** through the same resources, access checks, authentication and cache used by direct calls. Equivalent fluent and direct calls share cache entries.

Search queries support:

| Method | Purpose and Default |
| --- | --- |
| `repository(string $repository)` | Set the repository in `owner/repository` format. Required before `get()`. |
| `search(string $search)` | Set the title search or exact PR number. Defaults to an empty search. |
| `withClosed(bool $include = true)` | Include closed and merged PRs. Closed PRs are excluded unless enabled; `withClosed(false)` excludes them again. |
| `page(int $page)` | Set the search page, from 1 to 34. Defaults to 1. |
| `get()` | Execute and return the existing `data`, `next_page` and `incomplete` result array. |

Open-list queries support `repository()`, `page()` and `get()`. Their page must be positive, with no search-specific upper limit. They return the existing `data` and `next_page` result array, including draft and review-request metadata. Drafts remain included, and these builders do not expose `search()` or `withClosed()`.

All setters are optional except that a repository must be supplied before execution. `get()` throws `MissingRepository` for a missing or blank repository, and `InvalidPage` for a page outside the fluent query's bounds. Both exceptions live under `Egough\LaravelGitHub\Exceptions` and extend `InvalidArgumentException`. Validation happens before authentication or HTTP requests; building an invalid query alone does not throw.

The fluent search page bounds do not change the existing direct search method's input-validation behaviour. Applications should continue validating input passed to direct calls.

You can also initialise options with named arguments while omitting the repository:

```php
$query = GitHub::pullRequests(search: 'checkout', includeClosed: true, page: 2);
$results = $query->repository('acme/backend')->get();

$query = GitHub::openPullRequests(page: 2);
$openPulls = $query->repository('acme/backend')->get();
```

The same fluent API is available on an injected `GitHubClient`. Individual PR lookups through `pullRequest()` and repository browsing through `repositories()` retain their existing APIs.

<a name="reusing-queries"></a>
### Reusing Queries

Queries are immutable: each setter returns a new query without modifying its source. A base query can safely be reused for different searches or pages:

```php
$base = GitHub::pullRequests()->repository('acme/backend');
$checkout = $base->search('checkout');

$openCheckout = $checkout->get();
$allCheckout = $checkout->withClosed()->get();
$nextCheckoutPage = $checkout->page(2)->get();
$otherSearch = $base->search('shipping')->get();
```

`$base` still has an empty search, and `$checkout` still excludes closed PRs and uses page 1. Each fresh facade call also starts with the defaults.

Capture the returned builder when updating a variable:

```php
$query = GitHub::openPullRequests()->repository('acme/backend');
$query = $query->page(2);
$results = $query->get();
```

If a setter is repeated in a chain, its last value takes effect. `get()` does not advance the page or modify the builder; use the returned `next_page` value to construct the next query.

<a name="listing-open-pull-requests"></a>
### Listing Open Pull Requests

Available from **v1.0.4**, `openPullRequests()` lists open pull requests in an accessible repository:

```php
$results = GitHub::openPullRequests(
    repository: 'acme/backend',
    page: 1,
);
```

The equivalent fluent call is:

```php
$results = GitHub::openPullRequests()
    ->repository('acme/backend')
    ->get();
```

The `page` argument is optional and defaults to `1`. It must be greater than zero; invalid pages throw `Egough\LaravelGitHub\Exceptions\InvalidPage`, which extends PHP's `InvalidArgumentException`.

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

| Field | Description |
| --- | --- |
| `draft` | Whether the pull request is a draft. Drafts are included in the results. |
| `waiting_for_review` | Whether GitHub currently lists any requested reviewers or requested teams. This does not mean that the PR has no approvals. |
| `created_at` | Creation timestamp returned by GitHub. |
| `updated_at` | Last update timestamp returned by GitHub. |

Results are ordered by least recently updated first, with up to 100 pull requests per page. This method uses the repository pull request listing endpoint rather than search, so the search API's 1,000-result limit does not apply. Its response has `data` and `next_page`; it does not contain the search method's `incomplete` field.

Pass `next_page` into the next call until it is `null`:

```php
$page = 1;

do {
    $results = GitHub::openPullRequests('acme/backend', $page);

    foreach ($results['data'] as $pull) {
        if ($pull['draft']) {
            continue;
        }

        // Present or process this non-draft pull request.
    }

    $page = $results['next_page'];
} while ($page !== null);
```

Filtering drafts, associating pull requests with projects or tickets, and deciding which users should see them remain application responsibilities. `pullRequests()` and `pullRequest()` retain their existing response fields; the additional metadata above is provided by `openPullRequests()`.

<a name="searching-pull-requests"></a>
### Searching Pull Requests

Use `pullRequests()` to search by title:

```php
$results = GitHub::pullRequests(
    repository: 'acme/backend',
    search: 'checkout',
    includeClosed: false,
    page: 1,
);
```

| Argument | Description |
| --- | --- |
| `repository` | Repository full name in `owner/repository` format. |
| `search` | Title search term. An empty search retrieves recently updated pull requests. |
| `includeClosed` | Set to `true` to include closed and merged pull requests. |
| `page` | The search results page to retrieve. |

The result contains the matching pull requests and pagination information:

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

**`data`** contains the matching pull requests. Each item includes the URL, pull request number, title and state.

**`next_page`** identifies the next results page, or is `null` when no further page is available. Pass this value as the `page` argument in a subsequent call to retrieve more results.

**`incomplete`** indicates that GitHub did not return a complete search result. When this is `true`, consider asking users to narrow their search.

Results are ordered by most recently updated, with 30 results per page. GitHub search exposes a maximum of 1,000 results, so applications should validate page input and avoid unbounded searches. The fluent search builder enforces pages 1 to 34 at `get()`.

<a name="finding-a-pull-request-by-number"></a>
### Finding a Pull Request by Number

The search method also supports exact-number lookups. Pass either a number such as `42` or a hash-prefixed value such as `#42` as the search term:

```php
$results = GitHub::pullRequests(
    repository: 'acme/backend',
    search: '#42',
    includeClosed: true,
    page: 1,
);
```

The `includeClosed` filter still applies to exact-number searches. If you want to find a merged or closed pull request, set it to `true`.

<a name="retrieving-a-pull-request"></a>
### Retrieving a Pull Request

Use `pullRequest()` when you already know the pull request number:

```php
$pull = GitHub::pullRequest('acme/backend', 42);
```

A matching pull request is returned with its URL, number, title and state:

```php
[
    'url' => 'https://github.com/acme/backend/pull/42',
    'number' => 42,
    'title' => 'Fix checkout',
    'state' => 'open',
]
```

The method returns `null` when the individual pull request cannot be found. Repository access is still restricted to the configured organisation and installation.

<a name="understanding-pull-request-states"></a>
### Understanding Pull Request States

The package returns the following state values:

| State | Description |
| --- | --- |
| `open` | The pull request is currently open. |
| `closed` | The pull request was closed without a confirmed merge. |
| `merged` | The pull request was merged, when GitHub provides merge information in its response. |

This allows you to display a simplified state in your application without interpreting GitHub's raw response yourself.

---

<a name="dependency-injection"></a>
## Dependency Injection

In addition to the facade, you can inject the GitHub client into your controllers or services through Laravel's service container.

```php
use Egough\LaravelGitHub\GitHubClient;

public function __construct(
    private GitHubClient $github,
) {}
```

You can then call the same methods through the injected client:

```php
$repositories = $this->github->repositories();
```

The facade and dependency-injected client resolve the same shared container instance. Choose whichever approach best fits your application's architecture.

---

<a name="authentication-and-caching"></a>
## Authentication and Caching

Laravel GitHub handles GitHub App authentication automatically. It signs a JSON Web Token (JWT) using your app's private key and exchanges that token for an installation access token.

Installation tokens are cached until one minute before their expiry. If GitHub returns a `401` response, the package refreshes the token and retries the request once.

### Response Caching

The package uses Laravel's default cache store:

| Cached Item | Default Duration |
| --- | --- |
| Repository lists | 300 seconds (five minutes) |
| Pull request searches | 60 seconds (one minute) |
| Individual pull request details | 60 seconds (one minute) |
| Open pull request pages | 60 seconds (one minute) |
| Installation access tokens | Until one minute before expiry |

Cache keys are scoped to the configured connection settings. Because installation tokens are sensitive credentials, use a protected cache backend with appropriate access controls.

### Request Timeouts

Requests use a three-second connection timeout and a ten-second overall timeout. These limits help prevent an unavailable GitHub API from indefinitely blocking your application.

---

<a name="package-structure"></a>
## Package Structure

From **v1.0.4**, the source is organised by responsibility:

```text
src/
├── Auth/
│   └── InstallationTokenManager.php
├── Exceptions/
│   ├── GitHubUnavailable.php
│   ├── InvalidPage.php
│   ├── MissingRepository.php
│   ├── RepositoryUnavailable.php
│   └── aliases.php
├── Facades/
│   └── GitHub.php
├── Http/
│   ├── AuthenticatedTransport.php
│   └── RequestFactory.php
├── Queries/
│   ├── OpenPullRequestQuery.php
│   └── PullRequestQuery.php
├── Resources/
│   ├── PullRequests.php
│   └── Repositories.php
├── Support/
│   └── CacheKey.php
├── GitHubClient.php
└── GitHubServiceProvider.php
```

`InstallationTokenManager` handles JWT signing, installation tokens, expiry and invalidation. The immutable query builders collect fluent options and delegate execution to the resource classes, which handle repository access and pull request queries. The HTTP classes share request configuration, translate connection errors and handle authenticated requests with one token refresh after a `401`. `CacheKey` keeps connection-scoped cache keys consistent across these responsibilities.

Applications should continue to use the facade or inject `GitHubClient`; they do not need to construct these internal collaborators. The existing `new GitHubClient($config)` constructor remains supported.

---

<a name="error-handling"></a>
## Error Handling

The package raises exceptions for connection, authentication and repository access problems, leaving your application responsible for how errors are presented to users.

```php
use Egough\LaravelGitHub\Facades\GitHub;
use Egough\LaravelGitHub\Exceptions\GitHubUnavailable;
use Egough\LaravelGitHub\Exceptions\RepositoryUnavailable;

try {
    $pull = GitHub::pullRequest('acme/backend', 42);
} catch (RepositoryUnavailable $exception) {
    // The repository is outside the configured organisation
    // or the GitHub App installation's access.
} catch (GitHubUnavailable $exception) {
    // Configuration, authentication or an upstream request failed.
}
```

| Exception | Meaning |
| --- | --- |
| `RepositoryUnavailable` | The requested repository is not available to the configured organisation and installation. |
| `GitHubUnavailable` | A configuration, authentication or upstream GitHub request failed. |
| `InvalidPage` | An open-list page is below 1, or a fluent search page is outside 1 to 34. Extends `InvalidArgumentException`. |
| `MissingRepository` | A fluent query was executed without a non-blank repository. Extends `InvalidArgumentException`. |

A missing **individual pull request** returns `null` rather than throwing `RepositoryUnavailable`.

Upstream response bodies are not included in exception messages.

<a name="exception-factory-methods"></a>
### Exception Factory Methods

Exception classes provide named static factories so each fixed error message is defined in one place:

```php
use Egough\LaravelGitHub\Exceptions\GitHubUnavailable;
use Egough\LaravelGitHub\Exceptions\InvalidPage;
use Egough\LaravelGitHub\Exceptions\MissingRepository;
use Egough\LaravelGitHub\Exceptions\RepositoryUnavailable;

throw GitHubUnavailable::notConfigured();
throw RepositoryUnavailable::notAccessible();
throw InvalidPage::mustBePositive();
throw MissingRepository::required();
```

Each factory returns an exception instance; calling it alone does not throw. Package error messages retain their existing wording.

| Factory | Condition |
| --- | --- |
| `GitHubUnavailable::notConfigured()` | Required connection settings are missing. |
| `GitHubUnavailable::privateKeyUnavailable()` | The private key file cannot be read. |
| `GitHubUnavailable::privateKeyInvalid()` | The private key cannot be parsed. |
| `GitHubUnavailable::signingFailed()` | Signing the GitHub App JWT failed. |
| `GitHubUnavailable::authenticationFailed()` | The installation token request failed or returned an invalid response. |
| `GitHubUnavailable::connectionFailed()` | An HTTP request could not be sent successfully. |
| `GitHubUnavailable::upstreamUnavailable()` | An authenticated GitHub request returned an unsuccessful response. |
| `RepositoryUnavailable::notAccessible()` | The repository is outside the configured organisation or installation access. |
| `InvalidPage::mustBePositive()` | An open-list or fluent search page is below 1. |
| `InvalidPage::searchLimitExceeded()` | A fluent search page exceeds 34. |
| `MissingRepository::required()` | A fluent query was executed without a non-blank repository. |

The standard exception constructors remain available if your application needs to construct an exception with its own message.

<a name="deprecated-exception-names-and-v200-migration"></a>
### Deprecated Exception Names and v2.0.0 Migration

The old root-level exception names are deprecated starting in **v1.0.4**:

| Deprecated import | Replacement import |
| --- | --- |
| `Egough\LaravelGitHub\GitHubUnavailable` | `Egough\LaravelGitHub\Exceptions\GitHubUnavailable` |
| `Egough\LaravelGitHub\RepositoryUnavailable` | `Egough\LaravelGitHub\Exceptions\RepositoryUnavailable` |

In v1.0.4 and the remaining v1.x releases, Composer automatically loads `src/Exceptions/aliases.php`. It uses `class_alias()` so each old name and its replacement refer to the same exception class. Existing imports, exception construction and catch blocks continue to work during that period. The deprecation is documented; the aliases do not emit runtime deprecation warnings.

**In v2.0.0, `aliases.php` and its Composer autoload registration will be removed. The old exception names will no longer be provided.** Update imports before upgrading:

```diff
-use Egough\LaravelGitHub\GitHubUnavailable;
-use Egough\LaravelGitHub\RepositoryUnavailable;
+use Egough\LaravelGitHub\Exceptions\GitHubUnavailable;
+use Egough\LaravelGitHub\Exceptions\RepositoryUnavailable;
```

Catch blocks using these imported short names can stay as they are:

```php
try {
    $pull = GitHub::pullRequest('acme/backend', 42);
} catch (RepositoryUnavailable $exception) {
    // Repository access failed.
} catch (GitHubUnavailable $exception) {
    // Configuration, authentication or an upstream request failed.
}
```

Also update fully qualified exception references, PHPDoc types and exception class strings in tests or configuration that still use the deprecated paths. Existing `catch (\InvalidArgumentException $exception)` handlers catch both `InvalidPage` and `MissingRepository`; neither is a legacy alias.

### Validating Application Input

Your application remains responsible for validating input, authorizing users and throttling any endpoints that expose package functionality.

For browsing endpoints, validate repository identifiers in `owner/repository` format, positive pull request numbers, bounded search strings and search page numbers from 1 to 34.

---

<a name="testing"></a>
## Testing

The package includes tests that fake HTTP requests and use an in-memory cache. GitHub credentials, network access and an external database are not required to run the suite after installing dependencies.

<a name="running-the-tests"></a>
### Running the Tests

Install development dependencies:

```bash
composer install
```

Run the package test suite:

```bash
composer test
```

Or run PHPUnit directly:

```bash
vendor/bin/phpunit
```

<a name="testing-your-own-application"></a>
### Testing Your Own Application

When testing an application that uses Laravel GitHub, you can use Laravel's HTTP testing utilities to fake GitHub API responses:

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    'api.github.com/*' => Http::response([
        'repositories' => [],
    ]),
]);

Http::preventStrayRequests();
```

This is an illustrative HTTP fake; configure the mocked response shape for the specific GitHub endpoint exercised by your test. You may also need to fake authentication endpoints and clear cached values between test cases, depending on the code path being tested.

---

<a name="local-development"></a>
## Local Development

To use a local checkout of Laravel GitHub in another Laravel application, add a Composer path repository to that application's `composer.json`:

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

Then require the package:

```bash
composer require egough/laravel-github:^1.0.4
```

Adjust the path to match your local directory layout. With symlinking enabled, PHP source changes in the package checkout become available to the consuming application immediately.

The local version override is a concrete version (`1.0.4`), not a version constraint. The `reference: config` setting lets Composer detect changes to the package manifest even before a commit is made. Rerun `composer update egough/laravel-github` when package metadata or dependencies change so the consuming application registers changes such as the exception alias autoload file. Before using the application in CI or production, replace the local path dependency with a published release or an accessible Git repository.

---

<a name="requirements"></a>
## Requirements

| Requirement | Version |
| --- | --- |
| PHP | 8.2+ |
| Laravel | 10, 11, 12 or 13 (subject to each version's PHP requirements) |
| PHP extension | OpenSSL |

The package is released under the MIT license.
