# Troubleshooting

Start with the **Logs** page. Filter by severity "error" or by the job id. Every stage records its result and reason.

## State shows Not configured

The Dashboard lists exactly what is missing. Usually it is the API key. If you turned off automatic category creation, it can also be a news category with no matching WordPress category.

## "Posts are not being published" or "Not published: The post author..."

The Post author is not an active author. The message says which: none chosen, paused, revoked, never authorised, or deleted from WordPress. Grant or resume access on the **Authors** page, or choose another active author on the **Publishing** page. No new articles are written meanwhile. Retry a held article on the Articles page.

## Automation stopped by itself

The Dashboard says "Automation stopped by itself" with the date and the reason: the number of days or published posts set under **Automatic stop** on the Schedule page was reached. This is working as designed. Queued articles and history are kept. To run again, press **Start Automation**: both limits then count again from 0. To stop it happening, set the limits to 0, or raise them, on the Schedule page.

If you save a limit that the current run has already reached, the page warns you, and automation stops on the next worker run.

## State shows Error, "AI jobs are paused"

| Message | Do this |
|---|---|
| OpenRouter rejected the API key | Enter a valid key on AI Settings |
| Too few credits | Add credits at OpenRouter, then press Resume |
| Model is not available | Choose or confirm a model on AI Settings. The plugin never picks another by itself |

Research and publishing of already-queued articles keep running during an AI block.

## Worker health shows Stalled

No worker has run for 5 minutes. Check that the server cron job exists and runs (`SERVER-CRON.md`). Run it by hand to see any error:

```
php wp-content/plugins/zzz-copy-das-sobur/bin/run-worker.php
```

If there is no server cron, WP-Cron only runs on visits, or may be disabled with `DISABLE_WP_CRON`.

## No articles appear

Check these in order:

1. **News Sources > Test search**: are stories returned, and do they pass the filters? A "Past hour" filter on a topic feed often finds nothing. Try "Past 24 hours".
2. **Articles > Failed or held**: look at the reason on each.
3. **Logs > stage: retrieval**.

Common retrieval results:

| Result | Meaning |
|---|---|
| `publisher_url_unresolved` | A Google News story whose headline could not be found in the publisher's own feeds. Normal for part of the stories. The next candidate is tried |
| `robots_disallowed` | The publisher's robots.txt does not allow it. Not bypassed |
| `robots_unavailable` | The publisher's robots.txt could not be read right now, so nothing was fetched. It tries again after about 16 minutes |
| `resolver_unavailable` | The publisher could not be reached to match a Google News story. It tries again after about 16 minutes |
| `http_403` | The publisher refused automated access. Not bypassed |
| `paywalled` | Behind a paywall. Not bypassed |
| `insufficient_source` | Less text than the minimum. Lower "Minimum source words" only if you accept thinner articles |

If most stories fail, add publisher feeds from sites that allow access. Stories from a publisher feed need no headline matching.

## Google News returns nothing or errors

Google offers no official API and can limit or change these feeds. Publisher feeds keep working. You can turn Google off on the News Sources page, under "Use Google News RSS".

## Articles fail validation

Open the article to see each error. Typical causes:

- **Truncated**: raise the output token limit on AI Settings.
- **Missing table or FAQ**: the model did not follow the prompt. It retries by itself with the same prompt.
- **Tag count**: the model gave too few tags, so the article is held. Use Retry.

Readability and word-count misses are warnings only, and do not stop an article.

## Posts are skipped (Past-Hour Posting)

A skipped post is working as designed, and it is never caught up. The Dashboard shows the reason, and in Logs the stage is `past_hour`.

| Reason starts with | What it means | What you can do |
|---|---|---|
| No usable story published since... | No category that was searched had a story new enough | Add publisher feeds that post often, since their stories are tried first and are the most likely to be readable. Topic feeds rarely have news under an hour old, and many stories found by Google search cannot be read. Rotate quiet categories less, or remove them from the rotation |
| The chosen story could not be used | The page was blocked, paywalled or too short, or the article failed its checks, and nothing else was available in time | Read the entries before it in Logs |
| ... stories tried, none could be used | Several stories were tried and each failed. The last problem is named after this. "No headline in the publisher feeds matched" and "The publisher has no feed or news sitemap" mean a Google story's page could not be found | Add publisher feeds that post often. Read the entries before it in Logs |
| The story was published at... it would be... minutes old | The article was ready, but the worker ran too late | Set up the server cron job. See `SERVER-CRON.md` |
| The article was not ready within... | Writing took longer than the grace period | Check OpenRouter's speed, and the worker's health |
| Preparation did not run | The worker was not running, or research was paused, before the post time | Check Worker health on the Dashboard |
| The daily AI article limit / The daily maximum | A limit you set was reached | Raise it on the Schedule page |
| The 2 articles written for this post | Two articles failed for this post, so a third was not paid for | Read the validation reasons in Logs |

**Off-topic articles:** a broad search such as `science news` also returns stories that only mention those words, and one that can be read is written and filed under that category. Make the search narrower on the News Sources page, use required keywords, or remove the search.

The Retry button is refused while Past-Hour Posting is on, because the story would be too old by then. To retry an article, turn the feature off first.

## Fewer than 3 posts a day

Check the Schedule page: are all 3 slots shown? Only slots before midnight are created. Then read the slot reasons in Logs (stage: publishing). The usual causes are no article being ready, or the daily AI limit being reached.

To keep more articles ready, raise "Articles to keep ready per category" and "Maximum article generations per day". Both can raise your OpenRouter costs.

## Posts at the wrong time

Check the timezone on the Schedule page. A fixed offset does not follow daylight saving. WordPress's own site timezone is not used by the schedule. The Schedule page shows each slot in local time and UTC.

## "The saved API key cannot be decrypted"

The WordPress salts or `ZZZCOPY_SECRET_KEY` changed, often after a site migration. Enter the key again.

## Reporting a problem

Export the relevant logs to CSV from the Logs page. API keys and authorization values are removed from logs before they are saved.
