# Contributing to InkRush

Thank you for helping. InkRush is a one-person project by Sagor Das, so please be patient with replies.

## Before you start

- **Security problems:** do not open an issue. Follow [SECURITY.md](SECURITY.md).
- **Bugs:** open an issue with the bug report template. Include the plugin version, WordPress and PHP versions, what you expected, what happened, and the relevant lines from the Logs page. Remove API keys, tokens and personal data first.
- **Ideas:** open an issue with the feature request template before writing code, so we can agree on it first.

## Project layout

| Path | What it is |
|---|---|
| `zzz-copy-das-sobur/` | The plugin. This folder is what goes into the release ZIP |
| `zzz-copy-das-sobur/includes/` | PHP classes in the `ZZZCopy` namespace, loaded by the autoloader in the main plugin file |
| `zzz-copy-das-sobur/defaults/` | The owner's article and tag prompts, stored exactly as written |
| `zzz-copy-das-sobur/docs/` | Detailed docs, including architecture and test results |
| `tests/` | Automated tests and a mock server. Not part of the ZIP |
| `docs/` | User guides and maintainer notes for this repository |

Read [ARCHITECTURE.md](zzz-copy-das-sobur/docs/ARCHITECTURE.md) and [docs/MAINTAINER-NOTES.md](docs/MAINTAINER-NOTES.md) before changing code.

## Rules for code changes

- Keep the code compatible with PHP 8.1 and WordPress 6.5.
- Do not add third-party libraries, CDNs, web fonts or telemetry, and no outside services beyond news sources, OpenRouter and Wikimedia Commons.
- Do not change the prompts in `zzz-copy-das-sobur/defaults/`, even to fix typos, without the owner's agreement.
- AI is used only for writing articles and tags. Images are chosen by rules.
- Keep the admin screens monochrome, minimal and fast.
- Do not rename the plugin folder, slug, text domain, namespace, hooks, options or database tables. Live sites depend on them.
- The plugin must never invent facts, bypass paywalls, logins or robots.txt rules, or publish content that failed its checks.
- Follow the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/): escape all output, sanitize all input, check capabilities and nonces, and use prepared statements.
- A database change needs a new `Schema::DB_VERSION` and an upgrade step.

## Tests

Every behavior change needs a test. Run the whole suite before you open a pull request. [tests/README.md](tests/README.md) explains the setup: a WordPress install with a separate `test_` table prefix, and the mock server in `tests/mock-server.php`.

Do not put real API keys in tests. The keys in `tests/` are made-up values.

## Pull requests

1. Fork the repository and make a branch from `main`.
2. Keep each pull request to one change.
3. Fill in the pull request template, including which tests you ran and their results.
4. Update the docs and add a line under `[Unreleased]` in [CHANGELOG.md](CHANGELOG.md).
5. Do not change the version number. The maintainer does that when making a release.

## License of contributions

The plugin is declared as GPL-2.0-or-later in its header. By submitting a contribution, you agree that it may be distributed under that license.

## Contact

Sagor Das, das.sagorwork@gmail.com
