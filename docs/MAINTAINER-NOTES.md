# Maintainer notes

These notes were the repository README up to version 1.7.0. They record the owner's rules, decisions and test status for anyone changing the code. The public overview is now [README.md](../README.md).

Paths below are relative to the repository root.


A WordPress plugin for one website. It finds news, reads accessible publisher text, writes articles through OpenRouter, and creates posts on a schedule, in the background, with featured images from Wikimedia Commons.

**Current version: 1.9.0 (test release: Multiple users mode)**

## What is here

| Path | What it is |
|---|---|
| `dist/inkrush-1.9.0.zip` | The plugin, ready to install in WordPress. The folder inside is `zzz-copy-das-sobur` |
| `dist/zzz-copy-das-sobur-1.7.0.zip` | The previous version |
| `dist/zzz-copy-das-sobur-1.6.0.zip` | The previous version |
| `dist/zzz-copy-das-sobur-1.5.0.zip` | An earlier version |
| `dist/zzz-copy-das-sobur-1.4.2.zip` | An earlier version |
| `dist/zzz-copy-das-sobur-1.4.1.zip` | An earlier version |
| `dist/zzz-copy-das-sobur-1.4.0.zip` | An earlier version |
| `dist/zzz-copy-das-sobur-1.3.4.zip` | An earlier version |
| `dist/zzz-copy-das-sobur-1.3.3.zip` | An earlier version |
| `dist/zzz-copy-das-sobur-1.3.2.zip` | An earlier version |
| `dist/zzz-copy-das-sobur-1.3.1.zip` | An earlier version |
| `dist/zzz-copy-das-sobur-1.3.0 WITH IMAGES SEARCH.zip` | The first version with images |
| `dist/zzz-copy-das-sobur-1.2.1.zip` | The last version without images |
| `dist/InkRush-User-Guide.pdf` | User guide: every feature and setting, with numbered workflows |
| `zzz-copy-das-sobur/` | Plugin source code |
| `zzz-copy-das-sobur/docs/` | Install, cron, troubleshooting, operations, architecture, QA and test results |
| `tests/` | Automated tests and a mock publisher and OpenRouter server. Not part of the ZIP |

## Past-Hour Posting

On by default for all enabled categories. The switch, the story age and the preparation time are on the **Schedule** page. Turned off, the earlier workflow runs exactly as before. The name stays "Past-Hour Posting", although stories can now be up to 2 hours old.

| Setting | Default | Range |
|---|---|---|
| Use Past-Hour Posting | On | On or off |
| Maximum story age | 120 minutes | 60 to 180 minutes |
| Start preparing before each post | 120 minutes | 15 to 180 minutes, and no more than the story age |

Neither the story age nor the preparation time is ever longer than the interval between posts (6 hours by default), so posts stay 6 hours apart at 10:00, 16:00 and 22:00. The 15-minute search interval is fixed.

For a 10:00 post, with the defaults:

| Time | What happens |
|---|---|
| 08:00 | Preparation starts. The planned category is searched in Google News and its publisher feeds |
| 08:15 to 08:45 | Searched again every 15 minutes, if no story has been chosen |
| 09:00 | Halfway. The same, and from now on other enabled categories are tried too, one at a time, stopping at the first with a usable story |
| 09:45 | The last search |
| As soon as a story is found | The newest usable story is chosen, trying publisher feeds before Google News. One article is written and its image is found, using the existing code. Searching stops |
| 10:00 | The story's age is checked once more. If it is no more than 120 minutes old, the post is published. If not, the post is skipped |

**Why the 08:00 search usually finds little:** the limit is measured when the post goes out, so a 10:00 post can only use a story published after 08:00. A story from 07:30 would pass an 08:00 search and then fail at 10:00, after paying for an article that could never publish. The plugin starts only stories published from 08:05, because a worker run can be a minute or two late.

**Why publisher feeds go first:** a story found through Google News often cannot be read, because the plugin cannot find the publisher's page or the publisher refuses it. Publisher-feed stories link straight to the page. Every story tried is still within the age limit.

**Skipped means skipped.** With no usable story, the post is skipped, the exact reason goes in Logs and on the Dashboard, no article is written, and nothing is caught up later. Older news is never used. The next post carries on as normal.

Details, including the rules for dates, replacements and limits, are in `zzz-copy-das-sobur/docs/ARCHITECTURE.md`. Test results and known limits are in `zzz-copy-das-sobur/docs/TEST-RESULTS.md`.

## Authors

Posts are credited only to WordPress users a plugin administrator authorised on the **Authors** page. Nobody gets access through their WordPress role, administrators included, and the plugin never changes roles or capabilities.

| Status | Meaning |
|---|---|
| Active | Can be chosen as Post author and credited with posts |
| Paused | Access kept, but nothing is credited to them |
| Revoked | Access removed |
| User missing | The WordPress user was deleted. Shown, never stored |
| Not authorised | Never granted |

Right before each post, the plugin checks that the Post author is active. If not, the article is held with the reason, and no new articles are written until an author is active. A held article can be retried on the Articles page. Every grant, pause and revoke is logged with who made it, and so is a plugin draft published by hand.

Phase 1 (1.7.0) of the owner's author plan is the access list above. Phase 2 (1.9.0) is **Multiple users mode**: a Posting mode on the Authors page, and per author a timezone, weekdays, From and Until, dates to skip, up to 24 post times, 1 to 5 posts at each time, categories, day, week and month limits, and a missed rule. The code is in `includes/AuthorSchedules.php`; slots carry `author_user_id`, and in Single user mode every slot query keeps to `author_user_id = 0`, so Single user mode is unchanged. Phase 3 (Dashboard Authors card, history per author, 7-day preview) is not built yet.

## For an AI or developer picking this up

Read these first:

1. `zzz-copy-das-sobur/docs/ARCHITECTURE.md`: files, data model, article states and design decisions
2. `zzz-copy-das-sobur/docs/TEST-RESULTS.md`: what was tested, what was not, and known limits
3. `tests/README.md`: how to run the tests

### Rules the owner set

- **No outside integrations** beyond news sources, OpenRouter and Wikimedia Commons. No third-party plugins, libraries, CDNs, web fonts or telemetry. The job queue is built in.
- **The owner's prompts are stored exactly as written** in `zzz-copy-das-sobur/defaults/`. Do not correct or rewrite them, even where they contain typos. Any added instruction goes in the separate system message.
- **AI is only for rewriting and tags.** Images are chosen by rules, with no AI. See `zzz-copy-das-sobur/docs/IMAGES.md`.
- **Do not push to GitHub** unless the owner asks for that push.
- **The admin screens stay monochrome**, minimal and fast.
- **Never invent facts.** A story without enough accessible source text is held, and no article is written.
- **Do not bypass** paywalls, logins or publisher robots rules. The plugin identifies itself honestly.
- **Failed content is held, never published,** even in Automatic Publish.

### Decisions the owner made

- Sources are Google News RSS plus publisher feeds. Google's `/rss/` path is not on its robots.txt allow list; the owner chose to use it anyway, and there is an off switch under News Sources.
- The default mode is Automatic Publish, for all categories, with no human review.
- WordPress categories are created automatically.
- CC BY-SA images are accepted, with an off switch on the Images page. The owner approved this on 2026-11-05, knowing these images must not be edited.
- The default timezone stays a fixed `+10:30`.
- On 2026-11-06 the owner made InkRush the official name, with the tagline "Discover. Create. Publish.", and the attribution "Developed by: Sagor Das" with the contact das.sagorwork@gmail.com. Never use the earlier name, or variations such as "InkRush Bot". The release ZIP is named `inkrush-{version}.zip` (from 1.8.0; earlier ZIPs keep their old names). Compatibility-sensitive identifiers keep their original values: the `zzz-copy-das-sobur` folder inside the ZIP, slug and text domain, the `ZZZCopy` namespace, the `ZZZC_` constants, the `zzzc` options, tables, hooks, page slugs and capability, and the `ZZZCopyDasSobur` user agent and robots token.
- Past-Hour Posting is the default. The owner chose these details on 2026-11-05: a story must be no more than 60 minutes old at the post time, so the window is counted back from the post time; other categories are tried only from 30 minutes before the post, one at a time, stopping at the first with a usable story; the schedule uses the plugin's own timezone setting, not the WordPress one. The owner also chose to add a Google News search to the six starting categories that only had a topic address.
- On 2026-11-06 the owner widened the window to 2 hours, keeping the 6-hour gap between posts: stories up to 120 minutes old, preparation 120 minutes before each post, a setting from 60 to 180 minutes, other categories tried from halfway (60 minutes before), and neither window longer than the gap between posts. The name "Past-Hour Posting" stays.
- On 2026-11-06 the owner chose "Author profiles": the plugin keeps writing posts automatically and credits each to a user the owner authorised, rather than users triggering posts themselves. Plugin administrators stay as everyone with `manage_options`, and nobody is authorised by role. Phase 1 (1.7.0) is built. For Phase 2 the owner accepted these defaults: a timezone per author, the site-wide daily maximum and AI limit as ceilings above every author's limits, and clashing schedules refused with a 15-minute minimum gap.
- The starting list is the owner's 21 categories, and more can be added in the admin screens.
- The target is 3 posts a day at 10:00, 16:00 and 22:00.

### Current status

- **Automated tests:** see the 1.9.0, 1.8.0, 1.7.0, 1.6.0, 1.5.0, 1.4.2, 1.4.1, 1.4.0, 1.3.4, 1.3.3, 1.3.2 and 1.3.1 sections of `zzz-copy-das-sobur/docs/TEST-RESULTS.md`. Version 1.3.0 passed its 133 tests on WordPress 7.1.4, PHP 8.3.36 and MariaDB 11.4.5.
- **Versions 1.3.1 to 1.3.4, 1.4.1, 1.4.2, 1.5.0, 1.6.0 and 1.7.0** have only run in a local test setup. **1.4.0** ran on the owner's test website on 2026-11-06, for one post. See the 1.4.1 section of `TEST-RESULTS.md`.
- **Images (1.3.0):** real Commons searches, downloads and credit lines were tested on a local site, not yet on the owner's test website or with Elementor. Some chosen images will not suit their story.
- **Live test on the owner's separate test website, version 1.2.0:** a real story was found and read, then written with the real model, published at its slot, and the limit of 1 post held.
- **Not confirmed:** whether that article's facts matched its source, whether stories arrive through Google News in live use, and behavior on the owner's main website or over several days.
- **Known gaps against the prompt's goals:** articles often come in under 1,500 words because the model may only use source facts. In the live test, reading ease was about 50 against the prompt's 85 to 90. Both are warnings and do not block publishing.

### Before changing code

- Keep the code compatible with PHP 8.1. See `zzz-copy-das-sobur/docs/TEST-RESULTS.md` for which versions have actually been run.
- Run the full test suite. Add a test with every behavior change.
- Bump the version in `zzz-copy-das-sobur/zzz-copy-das-sobur.php` (two places), `readme.txt` and `tests/build-zip.php`, then rebuild the ZIP with `tests/build-zip.php`.
- A database change needs a new `Schema::DB_VERSION` and an upgrade step, because live sites will upgrade over older versions.

## Security

- **Never commit an API key, password or `wp-config.php`.** The OpenRouter key belongs only in the plugin's AI Settings page on a website, where it is stored encrypted.
- Keys in `tests/` are made-up values for the tests.
