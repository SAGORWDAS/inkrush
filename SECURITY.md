# Security policy

## Reporting a vulnerability

Please report security problems privately, by email to **das.sagorwork@gmail.com**. Do not open a public issue, discussion or pull request for a security problem.

Please include:

- the plugin version (shown on the WordPress Plugins page)
- your WordPress and PHP versions
- what the problem is, and the steps to show it
- what someone could do with it

Do not include real API keys, passwords or personal data in the report. If a log is needed, export it from the plugin's Logs page and check it first.

You will get a reply as soon as the developer can manage. This is a one-person project, so no response time is promised.

## Supported versions

Fixes are made to the latest version only. Please update to the latest version before reporting.

## What this policy covers

The plugin code in this repository: the PHP, JavaScript and CSS in `zzz-copy-das-sobur/`.

Not covered: WordPress itself, your hosting, other plugins and themes, and the outside services the plugin uses (OpenRouter, Wikimedia Commons, Google News and publisher websites). Report problems with those to their owners.

## Security measures in the plugin

These are what the code does. They have not been reviewed by an independent security auditor.

- Admin pages, form actions and background requests require the `manage_options` capability, and form actions and background requests check a WordPress nonce.
- The OpenRouter API key is stored encrypted: with libsodium when available, otherwise AES-256-GCM. The key used for encryption comes from the WordPress salts, or from a `ZZZCOPY_SECRET_KEY` constant in `wp-config.php` if you define one. The saved key is never shown again, and API keys and authorization values are removed from logs before they are saved.
- Outbound requests accept only `http` and `https` addresses on ports 80 and 443, refuse addresses with a user name or password, and refuse private and local network addresses, including IPv6 forms. The connection is pinned to the address that passed the check, and every redirect is checked again before it is followed.
- The server cron endpoint (`/wp-json/zzzc/v1/tick`) needs a secret token, compared in constant time. The token can only trigger a worker run, and can be replaced on the Schedule page.
- The command-line worker (`bin/run-worker.php`) refuses to run from a web request.
- Database queries with outside values use prepared statements.

## Keeping your site safe

- Never share your OpenRouter API key or the cron token. If one is exposed, replace it: create a new OpenRouter key and save it in the plugin, and use **Create new token** on the Schedule page.
- Prefer sending the cron token in the `X-ZZZC-Token` header rather than in the address, because addresses are often written to server logs.
- Give the `manage_options` capability only to people you trust with the plugin.
- Never commit `wp-config.php`, API keys or passwords to a repository.
