# Configuration reference

Every InkRush setting is on the plugin's admin pages, under the **InkRush** menu. The defaults and ranges below come from the plugin code (`includes/Settings.php`). A value outside its range is refused with a message, and nothing is saved for that field.

## AI Settings

| Setting | Default | Range or choices |
|---|---|---|
| API key | none | Your OpenRouter key. Stored encrypted |
| Model identifier | `openai/gpt-6-luna` | Any OpenRouter model identifier. The plugin never picks another model by itself |
| Request timeout (seconds) | 120 | 10 to 600 |
| Maximum attempts | 3 | 1 to 10 |
| Output token limit | 8000 | 256 to 200,000 |
| Minimum article words | 600 | 50 to 20,000. Shorter articles fail validation |
| If more than 20 tags come back | Keep the first 20 | Keep the first 20, or retry and then hold the article. Fewer than 20 always means a retry, then a hold |
| If the forbidden "—" appears | Replace it with a comma | Replace, fail validation, or allow and warn |
| Article prompt and tag prompt | The owner's prompts | Editable. Earlier versions are kept |

Optional, in `wp-config.php`: `define( 'ZZZCOPY_SECRET_KEY', '...' );` encrypts the API key with a secret kept outside the database. Enter the key again after adding or changing it.

## News Sources

### Each category

| Setting | What it does |
|---|---|
| Category name, Enabled | The category and whether it is used |
| Sources to use | Google News and publisher feeds, Google News only, or publisher feeds only |
| Weight | 1 to 100. Used by weighted rotation |
| Google News topic addresses | One per line. These pages do not guarantee full article text |
| Search queries | One per line. Each runs as a Google News search |
| Publisher RSS or Atom feeds | One per line |
| Required keywords | One per line. A story must contain at least one. Empty means no requirement |
| Excluded keywords | One per line. A story containing any of them is filtered out |
| Recency filter | Past hour, past 24 hours (default), past 7 days, or a custom start and end |
| Language and region | For example `en-US` and `US` (the defaults) |
| Days this category can be chosen | Any days of the week |

### Rules for all sources

| Setting | Default | Range |
|---|---|---|
| Use Google News RSS | On | On or off |
| Minimum source words | 250 | 20 to 20,000 |
| Partial source words | 80 | 1 to 20,000 |
| Maximum source characters | 30,000 | 1,000 to 500,000 |
| Fetch timeout (seconds) | 20 | 3 to 120 |
| Gap between requests to one publisher (seconds) | 5 | 0 to 3,600 |
| Gap between requests to Google News (seconds) | 30 | 1 to 3,600 |
| Fetch attempts | 3 | 1 to 10 |
| Stories kept per fetch | 30 | 1 to 200 |
| Headline similarity threshold | 0.75 | 0.30 to 1.00 |
| Text similarity threshold | 0.85 | 0.30 to 1.00 |
| Publisher headline match threshold | 0.70 | 0.30 to 1.00 |
| Duplicate check window (days) | 14 | 1 to 365 |

## Schedule

| Setting | Default | Range or choices |
|---|---|---|
| Timezone | `+10:30` | A name such as `Australia/Adelaide`, or a fixed offset such as `+10:30`. A fixed offset ignores daylight saving. WordPress's own site timezone is not used |
| Daily target | 3 | 1 to 48 |
| Interval (hours) | 6 | 1 to 24 |
| First post time | 10:00 | A 24-hour time |
| Maximum posts per day | 3 | 1 to 96. Never exceeded, counted by local calendar day |
| What counts toward the maximum | Drafts and published posts | Drafts and published posts, or published posts only |
| When a slot has no article | Try again at the next slot time | Try again at the next slot, or skip the missed slot |
| Slot grace period (minutes) | 30 | 1 to 720 |
| Minimum gap before a catch-up post (minutes) | 30 | 0 to 720 |

### Past-Hour Posting

| Setting | Default | Range |
|---|---|---|
| Use Past-Hour Posting | On | On or off |
| Maximum story age (minutes) | 120 | 60 to 180, and never longer than the interval between posts |
| Start preparing before each post (minutes) | 120 | 15 to 180, and no more than the maximum story age |

### Automatic stop

| Setting | Default | Range |
|---|---|---|
| Stop after this many days | 0 (no limit) | 0 to 365. Full 24-hour days from the last Start |
| Stop after this many published posts | 0 (no limit) | 0 to 10,000. Posts the plugin published since the last Start. Drafts do not count |

These have their own **Save automatic stop** button. Whichever limit comes first stops automation, exactly like the Stop button.

### Category rotation

| Setting | Default | Choices |
|---|---|---|
| Rotation mode | Round robin | Round robin, weighted random, or choose per slot with round robin for the rest |
| Avoid repeating a category on the same day | On | On or off |
| Use another category's article if a slot's category has none ready | On | On or off |
| Categories in rotation | All enabled | Tick the ones to use |

### Research

| Setting | Default | Range |
|---|---|---|
| Discovery every (minutes) | 60 | 5 to 1,440 |
| Start research before a slot (hours) | 4 | 1 to 48 |
| Articles to keep ready per category | 1 | 1 to 20 |
| Maximum article generations per day | 12 | 1 to 500 |
| Skip queued articles older than (hours) | 48 | 1 to 720 |
| Worker run length (seconds) | 50 | 10 to 280 |

The Schedule page also shows the server cron command, the web address and token for the cron endpoint, and a **Create new token** button. See [AUTOMATION.md](AUTOMATION.md).

## Authors

Grant, pause or revoke access for WordPress users, one at a time or in bulk. Only active authors can be credited with posts. WordPress roles are never changed.

## Publishing

| Setting | Default | Choices or range |
|---|---|---|
| Publishing mode | Automatic Publish | Draft, or Automatic Publish. Can also be set per category |
| Tags | Reuse matching tags and create missing ones | Or only use tags that already exist |
| Source attribution | Store in post data only | Or also add a visible source line |
| Post author | None chosen | Active authors only |
| Create a WordPress category when none matches | On | On or off. With it off, every enabled category needs a mapped WordPress category |
| Keep publisher text and rejected stories (days) | 30 | 1 to 3,650 |
| Keep logs (days) | 90 | 1 to 3,650 |
| Keep raw diagnostics of failed articles (days) | 30 | 1 to 3,650 |
| Delete all plugin data when the plugin is deleted | Off | On or off. Deactivating never deletes data, and posts are never deleted |

## Images

| Setting | Default | Range or choices |
|---|---|---|
| Searches for each article | 4 | 3 to 5 |
| Files checked for each search | 20 | 5 to 50 |
| Minimum width (pixels) | 1200 | 600 to 10,000 |
| Minimum height (pixels) | 675 | 300 to 10,000 |
| Preferred width-to-height shape | 1.78 (16:9) | 1.00 to 3.00 |
| Minimum relevance score (out of 100) | 65 | 1 to 100 |
| Largest file to download (MB) | 15 | 1 to 100 |
| Attempts for each article | 3 | 1 to 10 |
| Seconds between Wikimedia requests | 3 | 0 to 60. Keep it at 3 or more |
| Show the credit line | On every image | Or only when the license requires it (CC BY and CC BY-SA) |
| Accept CC BY-SA images | On | On or off |

A featured image is required on every post and cannot be turned off. CC0, public domain and CC BY are always accepted. Non-commercial, no-derivatives, unknown and conflicting licenses are always refused.

## Settings for developers

These WordPress filters exist in the code. They are meant for testing and special setups.

| Filter | What it changes |
|---|---|
| `zzzc_openrouter_base_url` | The OpenRouter API address |
| `zzzc_commons_api_url` | The Wikimedia Commons API address |
| `zzzc_commons_media_hosts` | Which hosts image files may be downloaded from |
| `zzzc_http_allowed_hosts` | `host:port` pairs allowed past the private-address check |
| `zzzc_image_sharpness_limit` | The blur check on downloaded images. A file with less detail than this value (15 by default) is refused |
| `zzzc_now` | The current time, for tests |

The action `zzzc_after_post_created` runs after the plugin creates a post, with the post ID and the article job.
