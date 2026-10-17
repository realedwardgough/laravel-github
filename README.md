# Laravel GitHub

A small Laravel package for connecting a GitHub App installation to one organisation and browsing its repositories and pull requests. Requires PHP 8.4+, OpenSSL and Laravel 13.

## Installation

```sh
composer require egough/laravel-github
php artisan vendor:publish --tag=github-config
```

Laravel discovers the service provider automatically. Publishing configuration is optional.

## GitHub App setup

Create a GitHub App with **Repository metadata: read** and **Pull requests: read** permissions. Install it on the organisation and grant access to the repositories you want to browse. No webhooks or OAuth callback are needed for this read-only integration.

Generate a private key, store it outside your repository and public web directory, and make it readable by the PHP process. Configure:

```dotenv
GITHUB_ORGANISATION=your-organisation
GITHUB_APP_ID=123456
GITHUB_INSTALLATION_ID=789012
GITHUB_PRIVATE_KEY_PATH=/absolute/path/to/github-app.pem
```

Use the App ID, not the OAuth client ID. The installation ID belongs to the organisation installation. The package supports one configured installation; personal tokens, multiple connections, routes, storage and UI are outside its initial scope.

## Usage

```php
use Egough\LaravelGitHub\GitHubClient;

$github = app(GitHubClient::class); // Or constructor-inject it.
$github->configured(); // Settings are present; does not test connectivity.
$repositories = $github->repositories();
$results = $github->pullRequests('your-organisation/repository', 'checkout', false, 1);
$pull = $github->pullRequest('your-organisation/repository', 42);
```

Repositories return a list of `full_name` and `url` pairs. PRs contain `url`, `title`, `number`, and `state` (`open`, `closed`, or `merged`). Search returns `data`, `next_page`, and `incomplete`; a missing individual PR returns `null`.

Search is limited to accessible repositories and matches titles. Use `42` or `#42` for an exact lookup. Pass `true` as the third search argument to include closed/merged PRs. Results are ordered by most recently updated, with 30 per page. GitHub search is limited to 1,000 results; use `incomplete` to prompt a narrower search. Merged status depends on the upstream response including merge information.

Catch `Egough\LaravelGitHub\RepositoryUnavailable` for repositories outside the configured installation/organisation, and `Egough\LaravelGitHub\GitHubUnavailable` for authentication, configuration or upstream failures. Exceptions do not expose upstream response bodies. Your application owns input validation, authorization, endpoint throttling and exception rendering. For search endpoints validate page 1–34, positive PR numbers, a bounded search string, and `owner/repository` input.

The package uses Laravel's default cache store. Repository lists are cached for 300 seconds and PR results for 60 seconds; change these through `github.cache.repositories` and `github.cache.pull_requests` (seconds). Installation tokens are cached until one minute before expiry and refreshed once after a 401. Use a protected cache backend because tokens are sensitive. Cache keys are scoped to connection settings. Requests connect within 3 seconds and time out after 10 seconds.

Change `github.user_agent` and `github.api_version` in the published configuration if required.

## Local development

In the consuming project's `composer.json`, add:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../packages/laravel-github",
            "options": { "symlink": true, "versions": { "egough/laravel-github": "dev-main" } }
        }
    ]
}
```

Then run `composer require egough/laravel-github:dev-main`. PHP source changes are immediately available through the symlink. Rerun Composer after metadata or dependency changes. This local path must exist wherever the consuming application installs dependencies; replace it with a published release or a reachable VCS repository before deployment/CI.

Run the package's standalone tests with `composer install` then `composer test`. Tests fake HTTP and need no GitHub credentials. Publishing and release automation are deferred until local integration is verified.
