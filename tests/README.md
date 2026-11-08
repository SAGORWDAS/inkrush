# Automated tests

These are not part of the plugin ZIP. They need no third-party libraries.

They run inside a real WordPress install, using a separate `test_` table prefix so site data is not touched.

## Setup

1. Have a WordPress site with this plugin in `wp-content/plugins/zzz-copy-das-sobur`.
2. In `wp-config.php`, set the prefix like this:

```php
$table_prefix = getenv( 'ZZZC_TEST' ) ? 'test_' : 'wp_';
```

3. Start the mock publisher, OpenRouter and Wikimedia Commons server from this folder. It needs PHP's GD extension to make test images:

```bash
php -S 127.0.0.1:8091 mock-server.php
```

## Run

Install the test tables once:

```bash
ZZZC_TEST=1 ZZZC_TEST_INSTALL=1 ZZZC_WP=/path/to/wordpress php run.php
```

Then run the suite. Results are written to `results.json`.

```bash
ZZZC_TEST=1 ZZZC_WP=/path/to/wordpress php run.php
```

Set `ZZZT_FILTER=AC-13` to run only matching tests.

## Unit tests, with no WordPress

The social distribution logic that needs no database and no HTTP has its own tests, which run
anywhere PHP does:

```bash
php tests/unit/run-unit.php
php tests/unit/run-images.php
```

They need the `mbstring`, `openssl` and `sodium` extensions. On a PHP build without them loaded by
default, name them on the command line:

```bash
php -d extension=mbstring -d extension=openssl -d extension=sodium tests/unit/run-unit.php
```

These cover character budgets, Bluesky's byte offsets, LinkedIn's reserved-character escaping,
hashtag cleaning, the idempotency key, whether a failed request is safe to retry, credential
redaction and the token cipher. Everything else about social distribution is in
`test-11-social.php`, which needs the site and the mock server.

## Other files

- `build-zip.php` builds the release ZIP. Run it with `php -d extension=zip build-zip.php`.
- `live-sources.php` checks real publishers with no API key. It refuses to contact Google.
- `inspect-site.php` prints facts from a local site's database during browser testing.
- `prepare-live.php` clears mock data from a local site. It deletes plugin records, so use it on test sites only.
- `mock-social.php` is the stand-in for the six social media platforms, included by `mock-server.php`. Requests arrive at `/social/{platform}/{the platform's own path}`, and each one is recorded in `mock-state/social.jsonl` so a test can check exactly what was sent. Set `social_mode` in `mock-state/state.json` to make a platform refuse a credential, rate limit, fail temporarily, answer with no identifier, or accept the request and never answer at all.
- `unit/` holds the tests that need no WordPress, and `unit/stubs.php` the few WordPress functions they use. Neither is part of the plugin or the release ZIP.
