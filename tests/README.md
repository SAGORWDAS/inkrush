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

## Other files

- `build-zip.php` builds the release ZIP. Run it with `php -d extension=zip build-zip.php`.
- `live-sources.php` checks real publishers with no API key. It refuses to contact Google.
- `inspect-site.php` prints facts from a local site's database during browser testing.
- `prepare-live.php` clears mock data from a local site. It deletes plugin records, so use it on test sites only.
