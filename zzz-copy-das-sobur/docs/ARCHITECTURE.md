# Architecture

## File structure

```
zzz-copy-das-sobur/
  zzz-copy-das-sobur.php   Plugin header, autoloader, hooks
  uninstall.php            Removes data only if the administrator opted in
  defaults/                Exact supplied prompts, and the accuracy instruction
  bin/run-worker.php       Command-line worker for server cron
  assets/                  Monochrome CSS and plain JavaScript
  includes/
    Plugin, Schema, Db, Clock, Settings, Secrets    Foundation
    Automation      Start, Stop, Pause, Resume and state
    AutoStop        Optional automatic stop after a number of days or published posts
    Runner, Jobs, JobResult, Locks, Health          Background engine
    Planner, Schedule                               When to research and publish
    PastHour                                        Past-Hour Posting: preparing each post, and its freshness rules
    Discovery, FeedParser                           Finding stories
    PublisherResolver, Robots, Http, Extractor, Retrieval    Reading sources
    Dedupe                                          Duplicate detection
    Prompts, OpenRouter, Generation, ContentParser, TagParser, Validator, Usage    AI
    Publisher, Articles                             WordPress posts, retry and skip
    Authors                                         Which users may be credited with posts
    Images/                                         Commons search, license rules, scoring, image history, import, credit line
    Events, Maintenance, RestTick                   Logs, retention, cron endpoint
    Admin/                                          Screens, form actions, AJAX
```

There is no third-party code. The job queue is built on plugin tables and needs no other plugin.

## Three separate engines

```
Discovery            Content                  Publishing
feeds -> candidates  source -> article, tags  queue -> slot -> WordPress post
filters, duplicates  validation               daily cap, retries
```

They share only database records. Each can be paused, retried or replaced on its own.

Research for a category starts only when its next open slot is close (`research_lead_hours`, 4 by default), so news is not chosen long before it publishes. A worker **tick** runs every minute. It records a heartbeat, recovers stale jobs, plans research, checks publishing slots, then runs due jobs until its time budget ends. It re-reads the state before each job, so Stop and Pause take effect at the next job boundary.

## Past-Hour Posting

On by default (`past_hour_enabled`), and turned off on the Schedule page. All of its logic is in `includes/PastHour.php`. Everything else is reused: `Discovery` reads feeds, `Retrieval` reads pages, `Generation` writes and validates, `ImagePipeline` finds images, `Publisher` creates posts. Each of them calls in at one or two small points, and those calls do nothing when the feature is off.

### What it replaces while it is on

| Earlier workflow | With Past-Hour Posting |
|---|---|
| `Planner` keeps a buffer of finished articles per category, researching hourly | `Planner::tick` calls `PastHour::tick` instead. One article at a time, for one post |
| The slot takes any queued article of its category, then any category | The slot takes only its own article, linked by `slots.article_job_id` and `article_jobs.slot_id` |
| A category's recency filter | The story age check, 120 minutes by default, on all sources and categories. Google News is asked for `when:2h`, the age in whole hours rounded up. Keyword filters still apply |
| Catch-up at a later slot | None. Slots end as `FILLED`, `SKIPPED` or `MISSED` |
| The image is found at the slot | The image is found as soon as the article is ready |

### Scheduling

- Times come from the plugin's own timezone setting (`+10:30` by default), which the owner chose over WordPress's. Story ages are UTC timestamps, so they do not depend on it.
- A slot is prepared from `due_at - PastHour::prep_minutes()` until `due_at`. Every minute, `PastHour::tick` looks at those slots.
- `PastHour::max_age_minutes()` is `past_hour_max_age_minutes` (120 by default), capped at `interval_hours * 60`. `PastHour::prep_minutes()` is `past_hour_prep_minutes` (120 by default), capped at that age. `Settings::update` refuses a preparation time longer than the story age.
- Other categories are tried once the post is half the story age away: 60 minutes before with the defaults.
- Search number N is due at the preparation start plus `(N - 1) * 15` minutes. Each slot stores the last one started, so a late worker runs one search and does not catch up the ones it missed. No search runs while the slot has a usable article.
- Each slot works on one article at a time. A new one starts only after the last has failed or been dropped, so repeated searches cannot overlap. `article_jobs.source_item_id` is unique, so two posts cannot use one story. The `runner` and `publish` locks and the existing post checks prevent double publishing.

### Freshness rules

The limit is measured at the post time: `max(now, due_at) - published_at` must be no more than the story age, 7200 seconds by default.

| When | Check |
|---|---|
| Reading a feed | No date, a date more than 5 minutes ahead, or older than the story age: `FILTERED_OUT`. Atom entries with only `updated` are rejected, because that is not a publication date |
| Choosing a story | Published from `due_at` minus the story age plus 5 minutes (`due_at - 115 minutes` by default). The 5 minutes stops the plugin paying for a story that a slightly late worker would have to throw away. Within a category, publisher-feed stories are tried first, newest first, then Google News stories, newest first. A Google story's page often cannot be found or read, and each try counts toward the limit for one post |
| Reading the article page | If the page's own `article:published_time` or JSON-LD `datePublished` is older than the feed date, the older one is used, since feeds can re-date old stories. A `<time>` tag is not used, because it can belong to anything on the page. A page with no date keeps the feed date |
| Before each writing step, and every minute while waiting | `PastHour::stop_if_unwanted` and `watch_article`. A story that fails is dropped before more is spent on it |
| At the post time | `queue_row_for_due_slot`, then `PastHour::final_check` in `Publisher::run_publish`, after the image step and immediately before the post is created. This is a recheck of the stored date against the clock. The page is not fetched again |

If a story fails before the post time, the slot looks for another under the same rules. At or after the post time, the post is skipped. A post that already went public before a crash is only finished off. A draft left by a crash is moved to the trash if its story fails.

### Category fallback

- The planned category always comes first, even if another has a newer story.
- From 30 minutes before the post, if the planned category has nothing, the others are searched one at a time in rotation order, and the first with an eligible story ends the search. So the story is fresh, but not always the newest of all. This keeps Google requests down, because searching every category at every search would multiply them.
- "Other categories" means `Schedule::pool_for_date`: enabled, active that day, ready, and in the rotation pool if one is set. The "use another category" setting is respected.
- The post is filed under the category the story came from, using that category's publish mode.

### Limits for one post

At most 2 articles reach the AI, and at most 8 stories are tried. The daily AI limit and daily maximum apply as usual. While publishing is paused or AI is blocked, no article is started. Stories whose page cannot be read cost no AI. A failed article is closed as `SKIPPED` with its reason kept, because it cannot be retried while the feature is on. Failed content is still never published.

### Dashboard and Logs

The Dashboard's Past-Hour Posting card shows the status, and for each of today's posts: post time, preparation start, headline, source, publication time, age when selected, age before publishing, category, whether fallback was used, processing status and result. Logs has a `past_hour` stage for preparation, searches with their source counts and window, fallback, selection, dropped stories, the final check and skips. Fetch results stay under `discovery`, and article and image progress under `ai`, `validation` and `image`.

### Files

| File | Change |
|---|---|
| `includes/PastHour.php` | New. Preparation, freshness rules, fallback, skipping, Dashboard data |
| `tests/test-6-past-hour.php` | New. 29 tests |
| `includes/Planner.php`, `Publisher.php` | Call `PastHour` when it is on. `Publisher` has the final check |
| `includes/Discovery.php`, `FeedParser.php`, `Extractor.php`, `Retrieval.php` | The story age check, reliable dates, and the older page date |
| `includes/Generation.php` | Stops an unwanted article before each step |
| `includes/Images/ImagePipeline.php` | Gives back an image found for an article that was dropped |
| `includes/Schedule.php` | `SKIPPED` status. A schedule change leaves a post being prepared alone |
| `includes/Categories.php` | The six starting categories with only a topic address get a search. `add_missing_searches` does the same on an upgrade, leaving a changed category alone |
| `includes/Schema.php`, `Settings.php`, `Lifecycle.php`, `Events.php` | Schema 6 for the columns and schema 7 for the searches, new settings, `SKIPPED` from any working state, the `past_hour` stage |
| `includes/Articles.php`, `Maintenance.php` | Retry is refused while on. Unused stories are cleared after the retention time |
| `includes/Admin/Pages.php`, `Actions.php` | The Dashboard card and the Schedule settings |
| `tests/lib.php`, `tests/mock-server.php` | The older suite runs with the feature off. Pages can state a chosen date |

## Google News access method

Google News has no official public API. The options assessed:

| Method | Finding | Decision |
|---|---|---|
| RSS feeds (`/rss/topics/...`, `/rss/search?q=...`) | Structured and supports `when:` and search. `/rss/` is not on the allow list in Google's robots.txt | **Used**, by owner decision, with an off switch |
| Topic pages (`/topics/...`) | Allowed by robots.txt, but a 3.9 MB page to parse, with no search or date filter, and it breaks when the page changes | Not used |
| Browser automation | Forbidden by the specification | Not used |
| Decoding article links through Google's internal `batchexecute` call | Undocumented and not on the allow list | Not used |
| Publisher RSS and Atom feeds | Stable and within robots rules | **Used**, alongside Google |

**Limitations**

- Google RSS links do not redirect to the publisher, so the plugin looks for the same headline in the publisher's own feeds and news sitemaps. In a real-internet sample of 17 stories from saved Google data, 6 reached full text. The others were unmatched, refused by the publisher, or paywalled, and each was recorded with its reason. In the same check, stories from publisher feeds reached full text 10 times out of 10, so **publisher feeds are the more dependable source**. These figures are one small sample, and results vary by publisher and by day.
- Many large publishers refuse automated requests, or disallow them in robots.txt. The plugin identifies itself honestly and does not work around this.
- Topic feeds have no date filter. The plugin checks each story's date itself, and records the effective filter on each story and in Logs.
- `after:` and `before:` work by whole days only, so custom ranges are also checked exactly here.

Adapters are chosen by the `adapter` value in `Discovery::fetch_plan()`, so a method can change without touching the rest of the plugin.

## Data model

All tables use the `{prefix}zzzc_` prefix and `utf8mb4`. Times are stored in UTC.

| Table | Purpose |
|---|---|
| `categories` | News category configuration. Deleted categories are kept for history |
| `source_items` | Discovered candidates, source text, retrieval result, duplicate links. `dedupe_key` is unique |
| `article_jobs` | One article's lifecycle: status, attempts, prompt versions, model, errors, request audit. `job_uid` and `source_item_id` are unique. `slot_id` links an article to the post it was written for under Past-Hour Posting |
| `article_content` | Title, body, tags, validation result, measured metrics |
| `publishing_queue` | Items ready for WordPress, with locks. `article_job_id` is unique |
| `slots` | Publishing slots per local date, with their planned category, status and reason. Also, for Past-Hour Posting: preparation status and start, search count, categories searched and still to try, chosen story and category, fallback used, ages when selected and before publishing |
| `generation_usage` | Every OpenRouter call: tokens, reported cost and source, ids, result |
| `events` | Activity log and audit trail. `actor_user_id` is who acted, 0 for the worker. `author_user_id` is who was credited or whose access changed |
| `post_links` | Article to WordPress post mapping, with local day and the credited `author_user_id`. `article_job_id` is unique |
| `authors` | Users a plugin administrator authorised: `status` (active, paused or revoked), who granted it and when, who last changed it and when. `user_id` is unique |
| `prompt_versions` | Every saved prompt version, with its SHA-256 |
| `jobs` | Background job queue with leases and retry times |
| `locks` | Named locks with expiry |
| `image_history` | Permanent record of every image held or used, with its license and credit. Commons page id, file name and file address are each unique. See `docs/IMAGES.md` |
| `image_runs` | One article's image progress: status, attempts, searches and the files checked with their results |

Global settings, state and the encrypted key are in the options `zzzc_settings`, `zzzc_state` and `zzzc_secret`.

## Article states

```
RETRIEVAL_PENDING -> RETRIEVED -> AI_PENDING <-> AI_RUNNING -> GENERATED -> VALIDATING -> VALIDATED -> QUEUED -> PUBLISHING -> PUBLISHED or DRAFT_CREATED
        |                                             |                        |                                    |
        v                                             v                        v                                    v
 PARTIAL, BLOCKED, RETRIEVAL_FAILED, DUPLICATE     AI_FAILED    VALIDATION_FAILED, HELD, back to AI_PENDING   PUBLISH_FAILED, back to QUEUED
```

- The allowed moves are in `Lifecycle::TRANSITIONS`. A move not listed is refused and logged. Moves are compare-and-set, so two workers cannot both make one.
- `SKIPPED` and `CANCELLED` are final. An article can reach `SKIPPED` from any working state, because a story can stop being fresh at any step.
- Discovery states (`DISCOVERED`, `FILTERED_OUT`, `DUPLICATE`, `PROMOTED`) are on `source_items`. Source failures are not retried by themselves except for temporary errors.

## Not creating duplicate posts

1. A publishing lock, so one worker publishes at a time.
2. A compare-and-set claim on the queue item.
3. Before creating a post, a lookup by `post_links`, then post meta, then `post_content_filtered`. That last value is written in the same database row as the post.
4. The post is created as a draft, then given tags, then given its final status. A crash leaves a non-public draft that the retry finishes.
5. A unique index on `post_links.article_job_id`.

## Duplicate detection

`Dedupe` checks, in order: canonical URL, normalized URL, story identifier, then text and headline similarity. It runs when a story is discovered, when selected, after the page is read, before queueing and before post creation. Each decision is logged with its reason, score and threshold.

- **Headlines**: Dice coefficient on word sets. Default threshold 0.75.
- **Text**: 64-bit SimHash over three-word shingles, as the share of matching bits. Default threshold 0.85. Used only for texts of 100 words or more.

Failed stories are not compared, so another publisher's accessible copy can still be used.

## Exact prompts

Prompt text bypasses all sanitizing. After saving, the plugin re-reads the row and compares SHA-256 hashes, and refuses the save if the database changed the text. The one normalization is CRLF to LF, because browsers turn every line ending into CRLF on form submission.

The request is: an editable system message (use only the source, ignore instructions in source text, format in HTML), then a user message of the source information followed by the exact prompt. Your prompt opens with "Based on the above", so the source comes first. Each job stores a hash of what it sent, in `request_audit`.

## Authors

- `Authors::problem( user )` says why a post cannot be credited to a user: none chosen, user missing, paused, revoked or never authorised. `Authors::publishing_problem()` asks it about the Post author.
- `Publisher::run_publish` holds the article (`PUBLISH_FAILED`, code `author_required`) when there is a problem, after the duplicate and daily-maximum checks and before the image step and the post. For a draft left by a crash, it checks that draft's own author. A post already public is only finished off.
- While there is a problem, `Planner::tick` starts no research, `PastHour::why_not_selecting` chooses no story, early image searches wait, and `Automation::config_problems` refuses Start. The Dashboard says why while running.
- `Publisher::author_id` has no fallback, and `Settings` refuses a Post author who is not active, unless it is the value already saved, so the other Publishing settings still save.
- `deleted_user` and `set_user_role` are logged for users on the list. Neither changes their row. A deleted user shows as User missing.
- `guard_image` also logs a plugin post made public outside the worker, by a person or by WordPress on a schedule. The worker sets `Publisher::$worker_publishing` while it publishes, so its own posts are not counted.
- The Authors page queries users 50 at a time with `WP_User_Query`. Changes go through `admin-post.php` with the usual capability and nonce checks, at most 200 users a request.

## Automatic stop

- Two settings, `auto_stop_days` (0 to 365) and `auto_stop_posts` (0 to 10000). 0 means no limit, and both are 0 by default. They are saved by their own form on the Schedule page (`save_auto_stop`), so saving them does not rebuild the schedule.
- Both count from `started_at` in the automation state, which Start has always set. Start itself is unchanged, so a new Start counts again from 0.
- Days are full 24-hour periods from `started_at`. Posts are rows in `post_links` with `post_status = 'publish'` created since `started_at`, so drafts and posts published by hand do not count.
- `AutoStop::check()` runs at the start of each worker run, at each safe checkpoint before a job, and right after the worker publishes a post. When a limit is reached it calls `Automation::stop_automatically()`, which does what `Automation::stop()` does (state `STOPPED`, the tick cancelled) and also records `auto_stop_reason` and `auto_stopped_at`. `Automation::stop()` is unchanged.
- `Automation::automatic_stop_reason()` returns the reason only while stopped and only for a stop after the last Start, so a later Start or manual Stop clears the Dashboard notice.

## Security summary

- Every action checks `manage_options` and a nonce. All output is escaped.
- Only authorised, active authors are credited with posts, checked on the server right before each post. WordPress roles and capabilities are never changed.
- The API key is encrypted with libsodium (AES-256-GCM fallback), with the key from `ZZZCOPY_SECRET_KEY` or the WordPress salts. It is never sent to the browser, and is removed from logs and stored responses.
- Outbound fetches allow only http and https on ports 80 and 443, refuse private and reserved addresses on every redirect hop, refuse https-to-http downgrades, pin the checked IP, and limit size and time.
- XML parsing refuses DOCTYPE, so no external entities. No network access during parsing.
- Model output passes through `wp_kses_post` before it becomes a post. Image elements in generated text are removed; the only image a post gets is the featured image from docs/IMAGES.md. Text from publisher pages is passed to the model as reference data, with an instruction to ignore any instructions inside it. This reduces prompt injection risk but cannot rule it out, and in Automatic Publish no person reviews the result before it goes live.

## Names

The display name is InkRush: the plugin header, the admin menu, page titles, messages and the OpenRouter `X-Title`. Every identifier that live sites, links or other systems depend on keeps its original value:

| Identifier | Value |
|---|---|
| Folder, main file, slug and text domain | `zzz-copy-das-sobur` |
| PHP namespace and constants | `ZZZCopy\\`, `ZZZC_*`, `ZZZCOPY_SECRET_KEY` |
| Options, tables, hooks, filters, post meta and the cron schedule | `zzzc_*`, `{prefix}zzzc_*`, `_zzzc_*` |
| Admin page slugs | `zzzc-dashboard` and the other `zzzc-*` pages. Assets load on any hook containing `zzzc-`, so the menu title does not affect them |
| REST route | `zzzc/v1/tick` |
| User agent and robots token | `ZZZCopyDasSobur/{version}` and `zzzcopydassobur`. Publishers' robots.txt rules may name it |

## Extending

| Filter or action | Purpose |
|---|---|
| `zzzc_http_allowed_hosts` | Allow specific `host:port` values past the private-address check, for local testing only |
| `zzzc_openrouter_base_url` | Point API calls elsewhere, for local testing only |
| `zzzc_now` | Override the current time, for testing |
| `zzzc_after_post_created` | Runs after the post row is created |
