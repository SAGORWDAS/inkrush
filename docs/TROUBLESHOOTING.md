# Troubleshooting

Start with the **Logs** page. Every stage records its result and its reason. Filter by severity "error", by stage, or by the article's job number. The complete guide, with every retrieval result and Past-Hour Posting reason, is [TROUBLESHOOTING.md](../zzz-copy-das-sobur/docs/TROUBLESHOOTING.md) in the plugin folder.

## Quick checks

| Symptom | Look at | Usual fix |
|---|---|---|
| State shows **Not configured** | The list on the Dashboard | Usually the API key or the Post author is missing |
| **Posts are not being published** | The reason on the Dashboard | The Post author is not active. Grant or resume access on the Authors page, or choose another active author on the Publishing page |
| State shows **Error**, "AI jobs are paused" | The message on the Dashboard | Fix the key, add OpenRouter credits, or confirm the model, then press Resume |
| Worker health shows **Stalled** | Your server cron job | Set it up, or run `php wp-content/plugins/zzz-copy-das-sobur/bin/run-worker.php` by hand to see the error |
| No articles appear | News Sources > Test search, then Articles > Failed or held, then Logs (stage: retrieval) | Add publisher feeds from sites that allow access |
| Articles fail validation | The article's own page | Raise the output token limit if text is cut off. Use Retry for a tag count failure |
| Posts are skipped | The reason on the Dashboard, Logs (stage: past_hour) | Expected with Past-Hour Posting when there is no new enough story. Add busy publisher feeds, or set up the server cron job |
| No featured image, article held | Logs (stage: image), the Images page | Use **Preview image search** on the Images page. The search runs again after 6 hours, or press Retry |
| Automation stopped by itself | The reason on the Dashboard | A limit under Automatic stop on the Schedule page was reached. Press Start Automation to run again, or set the limits to 0 |
| Fewer posts than planned | The Schedule page and Logs (stage: publishing) | Check the daily maximum and the daily AI limit |
| Posts at the wrong time | The timezone on the Schedule page | Use a named timezone if your location has daylight saving |
| "The saved API key cannot be decrypted" | Recent site migration or `wp-config.php` change | Enter the API key again |

## Common retrieval results

| Result | Meaning |
|---|---|
| `publisher_url_unresolved` | A Google News story's publisher page could not be found. Normal for some stories |
| `robots_disallowed` | The publisher's robots.txt does not allow it. Not bypassed |
| `robots_unavailable` | robots.txt could not be read right now. Tried again later |
| `http_403` | The publisher refused automated access. Not bypassed |
| `paywalled` | Behind a paywall. Not bypassed |
| `insufficient_source` | Less text than "Minimum source words" |

## Google News returns nothing

Google offers no official API and can limit or change these feeds. Publisher feeds keep working. Turn Google off on the News Sources page if you prefer.

## Asking for help

1. Export the relevant logs to CSV from the Logs page. API keys and authorization values are removed from logs before they are saved, but read the file before sharing it.
2. Open a bug report on GitHub with the plugin, WordPress and PHP versions, what you expected and what happened.
3. Report security problems privately, as described in [SECURITY.md](../SECURITY.md).
