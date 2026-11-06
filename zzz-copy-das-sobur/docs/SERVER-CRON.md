# Server cron setup

WordPress's built-in scheduler (WP-Cron) only runs when someone visits the site. On a quiet site, posts can be late. A server cron job makes timing reliable.

The plugin works without one, using WP-Cron as a fallback, but the Dashboard shows "Server cron: Not detected" until one is running.

Set the job to run **every minute**. Each run is short and bounded, and only one worker runs at a time, so overlapping runs are safe.

## Option A: PHP command line (preferred)

```
* * * * * php /path/to/wordpress/wp-content/plugins/zzz-copy-das-sobur/bin/run-worker.php >/dev/null 2>&1
```

The Schedule page shows the exact path for your site. The script refuses to run from a web request.

## Option B: web request

Use this if your host only allows URL-based cron. The Schedule page shows your exact address and token.

```
* * * * * curl -s -m 170 -X POST -H "X-ZZZC-Token: YOUR_TOKEN" https://example.com/wp-json/zzzc/v1/tick >/dev/null 2>&1
```

Treat the token like a password. It can only trigger a worker run. Use **Create new token** on the Schedule page if it is ever exposed.

Keep the `-m 170` timeout above the worker's run time. A long AI call can take as long as the AI timeout setting.

## Turning off WP-Cron (optional)

Once the server job is confirmed, you can stop WP-Cron from running on visitor requests by adding this to `wp-config.php`:

```php
define( 'DISABLE_WP_CRON', true );
```

This affects all of WordPress. If you use only Option A or B, also make sure something runs WordPress's own cron for other plugins, for example:

```
*/5 * * * * curl -s https://example.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

Daily maintenance in this plugin (retention) uses WordPress's own cron, so it needs this as well.

## Checking that it works

On the Dashboard, **Last heartbeat** should update every minute and show `server` or `cli`, and **Server cron** should say Active.

If the heartbeat stops for 5 minutes while automation is on, Worker health shows **Stalled**.
