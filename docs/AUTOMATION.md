# How the automation works

This guide explains what InkRush does in the background after you press **Start Automation**, and how to keep it running on time. The technical design is in [ARCHITECTURE.md](../zzz-copy-das-sobur/docs/ARCHITECTURE.md).

## The worker

All the work is done by a worker that runs in short, bounded steps, 50 seconds per run by default. Only one worker runs at a time, so overlapping runs are safe. Closing your browser or logging out does not stop it.

The worker can be started in three ways:

| Method | How | Notes |
|---|---|---|
| Server cron, command line | `php .../wp-content/plugins/zzz-copy-das-sobur/bin/run-worker.php` every minute | Preferred. The script refuses to run from a web request |
| Server cron, web request | `POST https://your-site/wp-json/zzzc/v1/tick` every minute, with the token in the `X-ZZZC-Token` header | For hosts that only allow URL-based cron. The token can only trigger a worker run |
| WP-Cron | Automatic, every minute | The fallback. It runs only when someone visits the site, so posts can be late on a quiet site |

The Schedule page shows the exact command, address and token for your site. Setup steps are in [SERVER-CRON.md](../zzz-copy-das-sobur/docs/SERVER-CRON.md).

A separate daily task, run by WordPress's own cron, removes old data according to the retention settings.

## Controls and states

| Control | What it does |
|---|---|
| Start Automation | Checks the setup, then begins background work |
| Stop Automation | Cancels future jobs. A job in progress finishes its current step. The queue and history are kept |
| Automatic stop (Schedule page) | Optional. Stops automation by itself after a number of days, or a number of published posts, whichever comes first. It stops exactly like Stop Automation. Both are counted from the last Start, and are off at 0 |
| Pause Research | Stops finding and reading stories. Queued articles can still publish |
| Pause Publishing | Stops post creation. Research and writing can keep filling the queue |
| Resume | Clears pauses and AI blocks |

| State | Meaning |
|---|---|
| Not configured | Something required is missing. The Dashboard lists what |
| Stopped | Not running |
| Starting | Started, waiting for the first worker run |
| Running | Working normally |
| Paused | Research or publishing is paused |
| Error | AI jobs are blocked, for example by a rejected key, too few credits or an unavailable model |

## The steps for each article

1. **Discovery.** Each category's Google News addresses, searches and publisher feeds are read, every 60 minutes by default, starting 4 hours before the category's next post.
2. **Filters.** Stories outside the recency filter, missing a required keyword, containing an excluded keyword, or too similar to a recent story are dropped.
3. **Retrieval.** For a Google News story, the plugin looks for the same headline in the publisher's own feeds or news sitemap to find the publisher's page. It then reads the page only if robots.txt allows it. Paywalled, refused and too-short pages are recorded and skipped.
4. **Writing.** The source text and your article prompt go to your OpenRouter model. A second request makes the tags with your tag prompt.
5. **Validation.** The result is checked: title, length, cut-off text, commentary aimed at the prompt writer, provider error text, the table and FAQ the prompt asks for, and the tag count. An article that fails is retried where that can help, and otherwise held with the reason. Word-count targets and readability are recorded as warnings only.
6. **Image.** A featured image is found on Wikimedia Commons by rules. With no usable image, the article is held.
7. **Publishing.** At its post time, the article becomes a WordPress post, as a draft or published, credited to the chosen active author. The post goes public only after its featured image is set and its file opens.

The Articles page shows every article's state and history, with **Retry** and **Skip** buttons.

## The schedule

With the defaults, posts are planned 3 times a day, 6 hours apart, at 10:00, 16:00 and 22:00 in the plugin's timezone. The daily maximum is never exceeded. Rotation decides which category each post uses.

When a post time has no ready article, the plugin either tries again at the next post time or skips it, depending on the "When a slot has no article" setting.

## Past-Hour Posting

Past-Hour Posting is on by default. Each post then uses one story that is no more than 2 hours old when the post goes out. With the defaults, for a 10:00 post:

| Time | What happens |
|---|---|
| 08:00 | Preparation starts. The planned category is searched |
| Every 15 minutes | Searched again, until a story is chosen |
| 09:00 | Halfway. Other enabled categories are tried too, one at a time |
| When a story is found | The newest usable story is chosen, trying publisher feeds before Google News. One article is written and its image is found |
| 10:00 | The story's age is checked again. If it is still within the limit, the post is published. If not, the post is skipped |

A skipped post is never caught up later, and older news is never used. The reason is shown on the Dashboard and in Logs. Expect some skipped posts, especially in quiet categories. A server cron job matters more with this on, because a late worker can make a story too old.

Turn it off on the Schedule page to use the earlier workflow.

## Automatic stop

Under **Automatic stop** on the Schedule page you can set:

- **Stop after this many days:** 0 to 365. Counted in full 24-hour days from the Start.
- **Stop after this many published posts:** 0 to 10000. Only posts the plugin publishes count. Drafts do not, so in Draft mode only the days limit can stop automation.

0 means no limit, and both are 0 by default. When both are set, whichever comes first stops automation. The worker checks at the start of every run, before each job, and right after each post it publishes, so no post follows the one that reaches the limit.

Reaching a limit stops automation exactly like the Stop button. The Dashboard then says when and why it stopped. Press **Start Automation** to run again, and both limits count again from 0.

## Limits that protect your costs

- **Maximum article generations per day:** 18 by default. Every attempt counts, the failed ones included. One generation is held back for each post time still to come that day, so one post having a bad run of unusable stories can never leave the later posts of the day unable to write anything.
- **Articles to keep ready per category:** 1 by default, so spare articles do not go stale.
- **Skip queued articles older than:** 48 hours by default.
- With Past-Hour Posting, a post stops paying for articles once **Articles to write before giving up on a post** have been written for it, which is 3 by default.

## Monitoring

- **Dashboard:** state, today's counts, worker health, next times and recent errors. It refreshes every 30 seconds.
- **Worker health:** shows **Stalled** if no worker has run for 5 minutes while automation is on.
- **Usage:** tokens and the cost OpenRouter reported. The plugin never estimates cost.
- **Logs:** every stage records its result and reason. Filter by date, category, stage, status, severity, job, post or person, and export to CSV.
