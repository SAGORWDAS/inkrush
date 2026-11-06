# Installation and configuration

## Requirements

- WordPress 6.5 or newer. PHP 8.1 or newer, with the `dom`, `libxml`, `mbstring` and `curl` extensions.
- MySQL or MariaDB with `utf8mb4`.
- One WordPress site. Network-wide activation on a multisite is not supported.
- An OpenRouter API key with credits.
- Permission to add a cron job on your server (strongly recommended).

The release was tested on WordPress 7.1.4, PHP 8.3.36 and MariaDB 11.4.5. Older versions listed above are the declared minimum, not tested combinations.

## Install

1. In WordPress, go to Plugins > Add New > Upload Plugin.
2. Choose `inkrush-1.8.0.zip`, install it and activate it.
3. A new menu item, **InkRush**, appears.

## First-time setup

Work through these in order.

### 1. AI Settings

1. Enter your OpenRouter API key and press **Save key**. It is encrypted and never shown again.
2. Press **Test API Connection**.
3. Check the model identifier. The default is `openai/gpt-6-luna`. The plugin never swaps it for another model.
4. Review the article and tag prompts. Your supplied prompts are already loaded, exactly as written.
5. Optional: use **Test generation** on sample text, then **Create test draft** to see the result as a post. These make real calls, which count in Usage.

For stronger key protection, add this to `wp-config.php` before you save the key:

```php
define( 'ZZZCOPY_SECRET_KEY', 'a-long-random-string-only-you-know' );
```

If you add or change this later, enter the API key again.

### 2. News Sources

The 21 starting categories are your own list. Edit, disable, reorder or delete them as you like, and press **Add category** at any time to add more. A new category starts being used once its Status column says Ready. For each one:

- **Sources to use**: Google News, publisher feeds or both.
- **Topic addresses**, **search queries** and **publisher feeds**: one per line.
- **Excluded** and **required keywords**.
- **Recency filter**, **language**, **region**, **days** and **weight**.

Press **Test search** on a category to see what it finds right now. Nothing is stored by a test.

Read "How stories are found, and the limits" on that page. It explains what Google News RSS can and cannot do.

### 3. Schedule

Defaults: 3 posts a day, 6 hours apart, from 10:00, at UTC+10:30.

**Check the timezone.** A fixed `+10:30` never changes. If your location has daylight saving, enter its name instead, such as `Australia/Adelaide` or `Australia/Lord_Howe`, so the posts stay at the same local time all year.

**Past-Hour Posting** is on by default. Each post uses one story no more than 2 hours old when it goes out, or is skipped. Set the maximum story age (120 minutes by default, 60 to 180) and how long before each post preparation starts (120 minutes by default, 15 to 180, never more than the story age). Turn it off to use the earlier workflow. Expect some skipped posts, especially for categories with little news. A server cron job matters more with this on, because a late worker can make a story too old.

Also set rotation, what counts toward the daily maximum, and what happens when a slot has no article.

Then set up the server cron job shown on this page. See `SERVER-CRON.md`.

### 4. Authors and Publishing

On the **Authors** page, find the user who should be credited with posts and press **Grant access**. Nobody has access until you grant it, administrators included. Their WordPress role is not changed, so note what the role lets them do: an Author can edit and delete published posts credited to them.

Then on the **Publishing** page, choose that user as **Post author**. Start refuses to run until an active author is chosen.

- **Automatic Publish** is the default. After you press Start, articles that pass validation go live with no approval, and articles that fail are held. Switch to **Draft** here at any time, for all categories or just some.
- Missing WordPress categories are created automatically. To use your own instead, map them here. If you turn automatic creation off, Start refuses to run until every enabled category has a match.
- Choose how tags and source attribution behave. By default, the source is stored with the post and not shown to readers. Turning on the visible source line is recommended for news that summarizes other publishers.

### 5. Start

On the Dashboard, press **Start Automation**. Anything still missing is listed there. The state shows Starting until the first worker run, then Running.

## Daily use

| Control | What it does |
|---|---|
| Start Automation | Checks the setup, then begins background work |
| Stop Automation | Cancels future jobs. A job in progress finishes its current step. Queue and history are kept |
| Automatic stop (Schedule page) | Optional. Stops automation by itself after a number of days, or a number of published posts, whichever comes first. It stops exactly like Stop Automation. Both are counted from the last Start, and are off at 0 |
| Pause Research | Stops discovery and source retrieval. Queued articles can still publish |
| Pause Publishing | Stops post creation, including finishing a post that a crash left unfinished. That post finishes after Resume. Research and article writing can keep filling the queue |
| Resume | Clears pauses and AI blocks. There are also separate Resume Research and Resume Publishing buttons |

## Monitoring

- **Dashboard**: state, today's counts, worker health, next times, recent errors. It refreshes by itself every 30 seconds.
- **Articles**: every article and its state, with **Retry** and **Skip**. Open one to see its source, validation result and history.
- **Usage**: tokens and the cost OpenRouter reported. "Unavailable" means none was reported. The plugin never estimates cost.
- **Logs**: filter by date, category, stage, status, severity, job or post, and export to CSV.

## What the plugin does not promise

- The word count, readability score, "plagiarism free" and "high CPC" goals in the prompt are instructions to the model. They are not guarantees. Word count and readability are measured for diagnostics only and are never printed in the article.
- Three posts a day needs three suitable stories. If sources are blocked or nothing passes validation, fewer posts appear, and the reasons are in Logs.
- Google, publishers and OpenRouter can change, limit or block access at any time.
