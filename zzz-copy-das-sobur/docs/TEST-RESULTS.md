# Test results

## Version 1.10.3: the day's generation allowance

The 1.10.2 audit left the generation limit as a warning on a settings page, which only helps the
owner who reads it. This makes it a property of the code instead: the allowance is shared across the
day's post times rather than being first come, first served.

### What changed

| Where | What it does now |
|---|---|
| `PastHour::later_posts()` | Counts the PENDING post times still to come today after this one, with the same author filter the rest of the class uses |
| `PastHour::why_not_selecting()` | Holds one generation back for each of them. A post may use `limit - used - later` and no more, so no post time can be left unable to write a single article by an earlier one. The limit itself is untouched and still absolute |
| `Admin\Pages::ai_limit_note()` | Distinguishes a limit too low for one article per post time from one that allows an article each but no retries |
| `Settings::defaults()` | `daily_ai_limit` 12 → 18. Sites that have saved their own value keep it |

### Checks

| Layer | What it was | Result |
|---|---|---|
| Image tests, `tests/unit/run-images.php` | The 37 from 1.10.1, re-run | **37 passed, 0 failed** on PHP 8.3.36 |
| Social unit tests, `tests/unit/run-unit.php` | The 128 from 1.10.0, re-run | **128 passed, 0 failed** |
| PHP syntax | `php -l` across every plugin and test file | **No errors** |

### What was not tested

**The change itself has not been executed.** `PastHour::why_not_selecting()` needs the database, so
no unit harness can reach it. Two integration tests were written for it, PH-38 and PH-39 in
`tests/test-6-past-hour.php`, covering the reservation, that the later post times are left alone, and
that a fully spent limit still names the limit rather than the reservation. Neither has been run,
because the integration suite needs a WordPress install. The same is true of the Schedule page note
and the 1.10.2 Dashboard line.

The arithmetic is worth stating plainly so it can be checked against a real day: with a limit of 5
and three post times, the 10:00 post may use 3, and once 3 are spent it is told they are held for the
2 posts after it. With the shipped defaults, 18 and three post times at three articles each, the
reservation never binds at all — it only matters once a day's failures have eaten into the limit.
## Version 1.10.2: an audit of the skipped-post paths

Not a response to a failure on the site. The whole path from `Schedule::ensure_slots()` through
`PastHour::tick()` to `Publisher::process_past_hour()` and `PastHour::queue_row_for_due_slot()` was
read against the question "can a post time be missed without anyone being told". Eleven paths end in
a skipped post time. Nine are deliberate: the worker not running during the preparation window,
research paused, publishing paused, the daily cap reached, AI blocked, the daily generation limit,
eight stories tried, three articles written, and no story published inside the freshness window.
Two were defects and are fixed here.

### What was found

| Where | The defect |
|---|---|
| `Health::status()` | Staleness was gated on `Automation::is_active()`. Since 1.10.0 the worker also runs for social distribution alone, so a worker that died in that state reported no fault, and the Dashboard said it was not running while it was |
| `daily_ai_limit` against `past_hour_max_articles` | No stated relationship between the two. Every generation attempt counts, failures included, so three post times at three articles each need nine generations before any failure, against a default limit of twelve. A few OpenRouter failures exhausted it and the last post time was skipped for want of generations |

### Checks

| Layer | What it was | Result |
|---|---|---|
| Image tests, `tests/unit/run-images.php` | The 37 from 1.10.1, re-run | **37 passed, 0 failed** on PHP 8.3.36 |
| Social unit tests, `tests/unit/run-unit.php` | The 128 from 1.10.0, re-run | **128 passed, 0 failed** |
| PHP syntax | `php -l` across every plugin and test file | **No errors** |

### What was not tested

Both changes are in code the unit suites do not reach: `Health::status()` and `Admin\Pages`
both need WordPress. They were read and are syntax-clean, but neither has been executed. The
Dashboard worker line and the Schedule page warning have not been seen rendered.

The 1.10.1 image fixes still have not run against the real Wikimedia Commons, and the WordPress
integration suite still needs a site to run on.
## Version 1.10.1: wrong image, and a skipped post

### What went wrong on the site

| When | What happened |
|---|---|
| 2026-11-07 10:00 | Published "Trump offers press secretary position to UFC head Dana White" with `Boy feeling Barack Obama's hair.jpg`, which scored 81. The only search run was "White House staff" |
| 2026-11-07 22:18 | The administrator released that image. The post published with it anyway at 23:30 |
| 2026-11-08 16:00 | Skipped. Both articles written for it were validated and then found no image. One searched "Ghana and China", the other "Measure Achievers Challengers Aspirants Share" |

### Checks

| Layer | What it was | Result |
|---|---|---|
| Image tests, `tests/unit/run-images.php` | 37 checks built from the real articles, file titles and searches in the logs of 2026-11-07 and 2026-11-08, including the pictures that were chosen correctly and must keep being chosen | **37 passed, 0 failed** on PHP 8.3.36 |
| Social unit tests | The 128 from 1.10.0, re-run to confirm nothing regressed | **128 passed, 0 failed** |
| PHP syntax | `php -l` across all 74 classes and every test file | **No errors** |
| Static reference check | Every static call resolves to a declared method | **0 unresolved** |

With the real article text from the 10:00 post, the subject is now `Dana White` rather than the
building, the searches are `Dana White | Dana White trump | Dana White President Donald Trump |
UFC`, and the Obama photograph scores 62 instead of 81 and is refused outright with the reason
"The picture is of Barack Obama, who the article does not mention."

For the 16:00 post, the searches are now `Ghana | Ghana china | China | industrialisation`
instead of the phrase that found nothing, and the chart labels no longer become a subject.

The four fixes from 1.9.1 were re-tested as part of this, because they touch the same code: the
publisher and a quoted outlet are still not subjects, "Is" still does not begin a name, a plain
number is still not searched, and "Apple. What" is still two sentences.

### What was not tested

**None of this has run on the owner's site, or against the real Wikimedia Commons.** The tests
use the article text and the file titles taken from the logs, with the Commons responses written
to match what the logs record. Whether the new searches actually find a usable photograph for
these stories can only be known by running them.

**The integration suite has not been run.** `tests/test-5-images.php` and the other 251 tests need
a WordPress install, which the machine these fixes were written on does not have. The synthetic
analysis arrays in IMG-8 and IMG-28 were checked by hand against the new code: they carry no
`text` key, which is read with a null-coalescing default, and their file titles are in sentence
case, so the new check for a person's name in a title does not fire on them.

**The new setting's effect is a judgement, not a measurement.** Raising the articles written per
post from 2 to 3 gives a post one more chance at the cost of one more AI article. Whether 3 is the
right number for this site will only show over several days.

## Version 1.10.0: social media distribution

### What was tested, and how

| Layer | What it was | Result |
|---|---|---|
| Unit tests, `tests/unit/run-unit.php` | 128 checks on the logic that needs no database and no HTTP: the platform registry's completeness, character budgets including X's fixed 23-character link weight, text shortening, the model's reply being parsed, hashtag cleaning, LinkedIn's fifteen reserved characters, Bluesky's link and hashtag byte offsets, the idempotency key, whether a failed request can be safely retried, credential redaction, and the token cipher | **128 passed, 0 failed** on PHP 8.3.36 |
| PHP syntax | `php -l` on all 74 plugin classes and every test file | **No errors** |
| Static reference check | Every `ZZZCopy` class named in a static call resolves to a file, and every static call names a method declared on that class or an ancestor | **0 unresolved**, across 74 classes |
| Integration tests, `tests/test-11-social.php` | 44 tests against a real WordPress site and a local stand-in for all six platforms, covering the new tables, the upgrade being additive, credential encryption, when distribution happens and when it must not, independence from automation, publishing on each of the six, duplicate prevention, reconciliation, retries and rate limits, token renewal, pictures, approval, scheduling, caption editing, several accounts, and authorisation state safety | **Written, not yet run.** See below |

### What was not tested

**No adapter has published to a real platform.** Everything above was checked against a local
stand-in that answers with the shapes the platforms' documentation describes. That verifies the
plugin's own behaviour, including how it handles each failure, but it does not verify that any of
the six platforms accepts what is sent. Do not describe any platform as working until it has.

**The integration tests have not been executed.** They were written against the existing harness
but the machine they were written on has no WordPress install, so the 44 tests in
`test-11-social.php` are unrun. The 1.9.1 suite of 251 tests is also unrun on this release, so it
is not yet known whether this release leaves the existing behaviour untouched in practice, only
that nothing in the existing code was altered beyond the integration points listed in
`ARCHITECTURE.md`.

Run both before trusting this release:

```bash
php -S 127.0.0.1:8091 tests/mock-server.php          # in one terminal
ZZZC_TEST=1 ZZZC_WP=/path/to/wordpress php tests/run.php
php tests/unit/run-unit.php
```

The unit tests need the `mbstring`, `openssl` and `sodium` extensions. On a PHP build without them
loaded by default:

```bash
php -d extension=mbstring -d extension=openssl -d extension=sodium tests/unit/run-unit.php
```

**API versions and endpoints were checked against the platforms' current documentation on
2026-11-08, not against the platforms themselves.** The Meta Graph API version was corrected from
v23.0 to v26.0 during this work, and LinkedIn's version is a `YYYYMM` value that moves every
month, so both of those, and Instagram's and Threads', can be changed from their card on the
Social Media page without waiting for a plugin update. Threads' documented limits of 250 posts per
24 hours, 500 characters, and 8 MB JPEG or PNG were confirmed. Bluesky's 300-grapheme post limit
and its roughly 976.56 KB blob limit were not confirmed against its documentation in this pass.

**Not tested at all:** the OAuth authorisation flow end to end with any real platform, since that
needs a developer application and a browser; App Review behaviour; what a real platform does with
an unusual image; behaviour at a real rate limit; whether reconciliation correctly recognises the
plugin's own post on a busy account; and anything on the owner's live site.

### Known limits found while building it

- An unconfirmed LinkedIn post cannot be checked automatically, because the Posts API cannot be searched by content. It waits for a person, and the dashboard offers Retry and Cancel.
- A PNG featured image cannot be published to Instagram, which accepts JPEG only. The image is never converted, because of the rule against editing a share-alike image.
- Facebook, Instagram and Threads fetch the picture from the site's public address, so they cannot publish from a site that is not reachable from the internet. This is detected and reported rather than left to fail at the platform.
- Reconciliation recognises the plugin's own post by comparing text with the account's recent posts. An account posting heavily from elsewhere at the same moment could in principle confuse it.
- One image per post. No carousels and no video.

## Version 1.9.1: image search subjects

### Why

On the owner's site on 2026-11-07, the 16:00 Crime post was skipped. Both articles written for it found no usable image, because the searches were built around news outlets: "The Tokyo Reporter", the publisher, and "Nippon News Network", which the article quoted. The log also showed searches such as "Is Poundland", "Poundland 000" and "Apple What" for other articles.

### What changed

| Change | Where |
|---|---|
| The publisher's name, and a name ending like an outlet's (News, Network, Reporter, Times, Post and similar), are removed from the subjects unless the title or headline names them. An outlet tag cannot become the subject | `ArticleAnalyzer::analyze`, `is_outlet`; the publisher is passed from `ImagePipeline` |
| More sentence words are ignored at the start of a name (Is, Are, Was, According and similar), and also at its end. A longer word with a full stop ends a name, so "Apple. What" is two sentences. In an ordinary headline, a single first word counts only if the running text has it too | `ArticleAnalyzer::names`, `line_names` |
| Plain numbers are not search words | `ArticleAnalyzer::tokens` |
| When every search would use the main or second name, the last one is replaced by another name from the article, a tag from the title, or plain words from the headline | `ArticleAnalyzer::analyze` |
| The no-image log says "The relevance score is below the minimum of 65. The best score was 52." instead of using N | `ImagePipeline::no_match_reason` |

### Checks

| Layer | What it was | Result |
|---|---|---|
| Automated suite | 251 tests: the 247 from 1.9.0 and 4 new (IMG-60 to IMG-63) | **251 passed, 0 failed** on WordPress 7.1.4, PHP 8.1.34 and MariaDB 10.11.19, in a local test setup with the mock server |
| Do the tests catch mistakes? | 10 deliberate mistakes put back one at a time: the outlet check off, the publisher not passed, "Is" not ignored, numbers kept, no sentence split, the trailing word kept, the first headline word kept, no search kept free of the main names, the best score not logged, and an outlet named in the headline dropped | **All 10 caught.** Three were missed at first (numbers, sentence split, trailing word), so IMG-61 was made stricter and then caught them |
| Test harness | The check for PHP warnings matched any path containing `zzz-copy-das-sobur`, so in a checkout folder whose name starts that way it also flagged warnings from `tests/lib.php`, and M12 failed on unchanged 1.9.0 code. It now matches only the plugin folder itself | `tests/lib.php`. M12 passes |
| Release ZIP | `inkrush-1.9.1.zip`, built with `tests/build-zip.php` | 69 files, the same as 1.9.0. The fix and version 1.9.1 are inside |

**Not tested in 1.9.1:** it has not run on the test website. The fix was checked with articles written for the tests, not with the real 16:00 articles, whose text is not in the logs. A Crime story whose suspect is not named may still find no Commons photo that passes; the fix makes that less likely, not impossible.

## Version 1.9.0: Multiple users mode (Phase 2)

### What changed

| Change | Where |
|---|---|
| New setting `author_mode`: Single user mode (the default, unchanged) or Multiple users mode, in a Posting mode card on the Authors page. Multiple needs an active author with a usable schedule. A switch closes the other mode's unused times, plans the coming ones again and is logged | `AuthorSchedules::switch_mode`, `Admin\Actions::do_save_author_mode`, Authors page |
| A schedule per author: timezone, weekdays, From and Until, dates to skip, up to 24 times at least 15 minutes apart, 1 to 5 posts at each time, categories, day, week and month limits, and a missed rule. Validated with a message per field | `AuthorSchedules::validate`, `save`, `Admin\Actions::do_save_author_schedule`, Authors page |
| A save is refused when a time comes within 15 minutes of another active author's time over the next 14 days, compared in UTC. A warning when the times together exceed Maximum posts per day | `AuthorSchedules::conflicts`, `capacity_warnings` |
| Slots carry `author_user_id`. In Multiple users mode each ready author's times on the site day are planned, with their own categories. In Single user mode every slot query keeps to `author_user_id = 0` | `Schedule`, `Planner`, `PastHour`, `Publisher` |
| A due time whose author is not active or is at a limit is closed with the reason, and the article stays queued. The check is repeated right before the post. The post is credited to the slot's author. A missed time follows the author's rule | `Publisher::process`, `run_publish`, `release_for_author` |
| No research for an author who cannot post. Start Automation asks for a scheduled author in Multiple users mode | `Planner::planned_category_ids`, `Authors::publishing_problem` |
| Past-Hour Posting prepares author times, searches the author's categories and keeps the story age as set | `PastHour` |
| Schedule > Today's slots lists author times, the Dashboard's next slot names the author, and the Publishing page says when the Post author is not used | `Admin\Pages` |
| Database version 11: schedule columns on `authors`, `author_user_id` and a key on `slots`, `slot_key` widened to 64 characters. Existing rows are kept, with author 0 | `Schema` |

### Checks

| Layer | What it was | Result |
|---|---|---|
| Automated suite | 247 tests: the 231 from 1.8.0 and 16 for Multiple users mode (MA-1 to MA-16) | **247 passed, 0 failed** on WordPress 7.1.4, PHP 8.1.34 and MariaDB 10.11.19, in a local test setup with the mock server. The WordPress debug log stayed empty |
| Do the tests catch mistakes? | 23 deliberate mistakes put into a scratch copy, one at a time: the conflict gap off by one minute, an author's own times 15 minutes apart refused, the author's timezone ignored, the week starting on Sunday, limits ignored, the final check before the post removed, the slot's author ignored when crediting, an inactive author's time still used, the author's categories ignored when choosing and when planning, Single user mode altered, research for an author who cannot post, the missed rule ignored, catch-up at any later time, passed times planned mid-day, only one post at each time, switching with no scheduled author, switching while keeping the other mode's times, no warning above Maximum posts per day, Past-Hour searching every category, the site maximum ignored for authors, schedule saves not logged, and the form forgetting what was typed | **All 23 caught.** Two were missed at first (categories when planning, and Past-Hour categories), so MA-6 and MA-14 were made stricter and then caught them |
| User guide | The new F8 section, the notes in F7, F9 and F11, the quick reference and the new Start message, viewed page by page | 25 pages. The colour scan found 0 colours that are not grey |

**Not tested in 1.9.0:** it has not run on the test website. The Authors, Schedule, Publishing and Dashboard screens were checked through their HTML in the tests, not in a browser, so the schedule form has not been filled in by hand.

## Version 1.8.0: automatic stop and the InkRush name

### What changed

| Change | Where |
|---|---|
| New Automatic stop card on the Schedule page: stop after a number of days (0 to 365) or published posts (0 to 10000), whichever comes first. 0 means no limit, and both are 0 by default | `Settings`, Schedule page, `Actions::do_save_auto_stop` |
| Both count from the last Start. Start Automation is unchanged and already recorded the start time | `AutoStop` |
| Reaching a limit stops like the Stop button, and records why and when. The Stop button is unchanged | `Automation::stop_automatically` |
| Checked at the start of every worker run, at each checkpoint before a job, and right after each published post | `Runner::tick`, `Publisher::record_success` |
| The Dashboard shows the limits and progress, and says when and why automation stopped by itself. Saving a limit already reached warns that automation stops on the next worker run | Dashboard, Schedule page |
| The plugin is named InkRush: the plugin header (with Sagor Das as author), the menu, page titles, the page header with the tagline "Discover. Create. Publish.", a footer with the developer attribution, messages, the cron display name and the OpenRouter app name. Folder, slug, text domain, options, tables, hooks, page slugs, REST route, user agent and robots token are unchanged | Main file, `Admin\Menu`, `Admin\Pages`, `admin.css`, `Plugin`, `OpenRouter`, `bin/run-worker.php` |
| The log export downloads as `inkrush-logs-(date)-(time).csv`, with the same columns. The release ZIP is named `inkrush-{version}.zip`, with the same `zzz-copy-das-sobur` folder inside, so uploading it still upgrades the installed plugin | `Admin\Actions::export_filename`, `tests/build-zip.php` |

### Checks

| Layer | What it was | Result |
|---|---|---|
| Automated suite | 231 tests: the 216 from 1.7.0, 10 for the automatic stop (AS-1 to AS-10) and 5 for the name (BR-1 to BR-5) | **231 passed, 0 failed** on WordPress 7.1.4, PHP 8.1.34 and MariaDB 10.11.19, in a local test setup with the mock server. The WordPress debug log stayed empty |
| Do the tests catch mistakes? | 15 deliberate breaks in a scratch copy: no check after a post, at the start of a run or at the checkpoint, drafts counted, posts before the Start counted, off by one on posts and on days, days counted as hours, the tick kept, the state not stopped, an old reason shown after Start, negative limits accepted, no log, no warning, no Dashboard notice | **All 15 caught.** The checkpoint break was missed at first, so AS-10 was added |
| Do the name tests catch mistakes? | 15 deliberate breaks: the earlier name back in the header, the cron display, a variation of the name in a page title, the author changed, the tagline or footer removed, a page slug renamed, the OpenRouter app name changed, the user agent or robots token renamed, the export name reverted, the export ignoring that name, the time left out of it, the ZIP name reverted, and the folder inside the ZIP renamed | **All 15 caught** |

**Not tested in 1.8.0:** it has not run on the test website, and the Schedule page, the Dashboard and the new page header and footer were checked through their HTML in the tests, not in a browser.

## Version 1.7.0: authorised authors (Phase 1)

### What changed

| Change | Where |
|---|---|
| New Authors page: users fetched 50 at a time, with username, name, email, WordPress role, access status, who granted it and when, and posts credited. Search, role filter and access filter. Grant, pause, resume and revoke, one row or in bulk, with Revoke confirmed | `Admin/Pages::authors`, `Admin/Actions::do_author_access`, `assets/admin.js` |
| Nobody is authorised automatically, administrators included. WordPress roles and capabilities are never changed | `Authors` |
| Right before each post, the Post author must be active, or the article is held with the reason (`author_required`). Retry works once they are active | `Publisher::run_publish`, `Publisher::hold_for_author` |
| While there is no active author: no research, no Past-Hour story, no early image search, Start refused, and the Dashboard says why | `Planner`, `PastHour`, `Publisher`, `Automation` |
| The Post author list shows only active authors. "First administrator" and the fallback to it are gone | Publishing page, `Publisher::author_id`, `Settings` |
| Logs show names instead of numbers, a new Author column and a Person filter. Grants, pauses, revokes, deleted users, role changes and a plugin draft published by hand are recorded | `Events`, Logs page, `Authors`, `Publisher::guard_image` |
| New table `authors`, and `author_user_id` in `events` and `post_links`. The upgrade authorises the user already credited, and nobody else | `Schema::upgrade_to_10` |

### Checks

| Layer | What it was | Result |
|---|---|---|
| Automated suite | 216 tests: the 204 from 1.6.0 and 12 new ones (AU-1 to AU-12). The test site's administrator is authorised in each test, as on an upgraded site | **216 passed, 0 failed.** The WordPress debug log was empty |
| Do the tests catch mistakes? | 19 deliberate breaks in a scratch copy: no check before posting, a fallback to the first administrator, any user accepted as Post author, administrators authorised by role, a grant changing the role, the access log not naming who acted, research or Past-Hour not waiting, deleted users and role changes not watched, the upgrade ignoring the chosen author or not running, no nonce check, the row button ignored, hand publishing not recorded, the worker counted as by hand, the post link losing the author, the role filter ignored, and the Logs person filter ignored | All 19 were caught. The last one was missed at first, because the test log had only one entry. The test now adds an unrelated entry |

**Not tested in 1.7.0:** it has not run on the test website. The Authors page was checked through its HTML in the tests, not in a browser, so the select-all box and the Revoke confirmation have not been clicked. Schedules and limits per author are Phase 2 and are not built.

## Version 1.6.0: a 2-hour posting window

### What changed

| Change | Where |
|---|---|
| Stories can be up to 120 minutes old at the post time, set by the new "Maximum story age" (60 to 180 minutes) | `Settings`, `PastHour::max_age_minutes` |
| Preparation starts 120 minutes before each post by default, now 15 to 180 minutes, and never more than the story age. Saving a longer one is refused with the field marked | `Settings::update`, `PastHour::prep_minutes` |
| Neither is ever longer than the interval between posts, and the Schedule page says so when that applies. The 6-hour gap is unchanged | `PastHour`, Schedule page |
| Other categories are tried from halfway: 60 minutes before a post with the defaults, 30 before with 60 minutes | `PastHour::fallback_within` |
| Google News is asked for the window in whole hours, rounded up: `when:2h` by default | `Discovery::search_url` |
| The messages, Dashboard card and Schedule text give the configured minutes | `PastHour`, `Articles`, admin pages |
| The upgrade adds the story age at 120, and moves a preparation time still at the old default of 60 to 120. One the owner changed is kept | `Schema::upgrade_to_9` |

### Checks

| Layer | What it was | Result |
|---|---|---|
| Automated suite | 204 tests: the 198 from 1.5.0 and 6 new ones (PH-32 to PH-37). The earlier Past-Hour tests set a 60-minute age, so they still check the 60-minute rules. PH-1 and PH-25 check the new defaults and ranges, and PH-28 the 2-hour Google search | **204 passed, 0 failed.** The WordPress debug log was empty |
| Do the tests catch mistakes? | 13 deliberate breaks in a scratch copy: the age left at 60 minutes, the age or preparation time not capped, other categories still at 30 minutes, Google still asked for 1 hour or the hours rounded down, a too-long preparation time or too-short age saved, the Schedule form ignoring the new field, the upgrade leaving 60 minutes, overwriting the owner's time or not running, and the defaults left at 60 | All 13 were caught |
| Google News, real feed | A search for "news" with `when:1h` and with `when:2h` | Both returned 100 stories. The oldest was 60 minutes old with `when:1h` and 108 minutes with `when:2h`, so Google honours the 2-hour window. A broad search reaches Google's 100-story limit before 2 hours, but a category's own searches are narrower |

**Not tested in 1.6.0:** it has not run on the test website. A wider window lets a story be up to 2 hours old when it goes out, so posts are a little less fresh, and preparation runs up to 8 searches per post instead of 4.

## Version 1.5.0: every post needs a featured image

### What changed

| Change | Where |
|---|---|
| No usable image means no post. The article is held in Failed or held, with the reason | `Publisher::hold_for_image`, `ImagePipeline::give_up` |
| The settings that allowed a post without an image are removed: "Find images automatically", "Set the chosen image as the featured image" and "When no image can be used". Old saved values are ignored, and the upgrade deletes them | `Settings`, `Schema::upgrade_to_8`, Images page |
| A final check before a post goes public: a featured image is set, it is a picture in the Media Library, its file is on the server and opens, and WordPress has prepared it | `ImagePipeline::image_problem`, `Publisher::finish_post` |
| A post from this plugin, including a test draft, is set back to draft if a person publishes it, or WordPress publishes it on a schedule, without an image that passes the check. Posts already public, and posts not made by the plugin, are left alone | `Publisher::guard_image`, on `wp_after_insert_post` |
| Images are found as soon as an article is queued, one per worker run, newest first, so a post time takes an article that already has one. No early search runs once the daily maximum is reached | `Publisher::prepare_next_image`, `Publisher::pick` |
| A held article's image search runs once more, 6 hours later, when the saved Commons results expire | `Publisher::retry_held_image`, new column `image_runs.auto_retried` |
| If a strong match ends the searching but its file cannot be used, the skipped searches run before the article is held | `ImagePipeline::prepare` |
| Past-Hour Posting: a story with no usable image is set aside and another story is chosen. A temporary image problem at the post time no longer publishes the post without an image | `PastHour::prepare_image`, `Publisher::run_publish` |
| The Dashboard shows "Held: no usable image" and "Published posts with no image". The Images page lists posts from before this version that have no image, with Retry image | Admin pages |

### Checks

| Layer | What it was | Result |
|---|---|---|
| Automated suite | 198 tests: the 192 from 1.4.2 and 6 new ones (IMG-55 to IMG-59, PH-31). 22 earlier tests that expected a post without an image now expect the article to be held. The test stand-in for Commons now gives every search a fresh usable photo, because every test post needs one | **198 passed, 0 failed.** The WordPress debug log was empty |
| Do the tests catch mistakes? | 12 deliberate breaks in a scratch copy: the hand-publish check not hooked, a missing file accepted, no final check, a held article published anyway, no early search, slot order ignoring ready images, no 6-hour retry, the retry not limited to once, skipped searches not resumed, early searches after the daily maximum, a Past-Hour story kept with no image, and an old saved switch still turning images off | All 12 were caught |

**Not tested in 1.5.0:** it has not run on the test website or against real Commons. The hand-publish check was tested through WordPress functions, not by clicking Publish in the block editor. A real site with several queued articles will make more Commons searches ahead of time than before, one article per worker run.

## Version 1.4.2: faster, more accurate image search

### What changed

| Change | Why |
|---|---|
| A copy 1920 pixels wide is downloaded instead of the original | Originals are often 5 to 25 MB. The copy is ready at once on Commons and is large enough for a featured image |
| Each search asks Commons only for files at least as large as the minimum image size (1200 × 675 by default) | Small files were returned and then refused, so fewer usable files came back |
| Searching stops once a file passes every rule with a score of 75 or more | Each search waits 3 seconds for Wikimedia's pace limit. Most stories about one person are settled by the first search |
| The AI tags confirm names, and a single word is searched alone only when it is also a tag | On the test website, "Channel" from "Channel 4" was searched alone and found unrelated files |

### Checks

| Layer | What it was | Result |
|---|---|---|
| Automated suite | 192 tests: the 189 from 1.4.1 and 3 new ones (IMG-52, IMG-53, IMG-54). One test helper was changed so a test's own file address also applies to the copy | **192 passed, 0 failed.** The WordPress debug log was empty |
| Do the tests catch mistakes? | 6 deliberate breaks in a scratch copy: downloading the original, checking the copy against the original's byte size, no size filter, never stopping early, single words searched alone, and tags not confirming names | All 6 were caught |
| The Russell Brand post, real Commons | The post's title and opening paragraphs with its likely tags | The first search, "Russell Brand", found 20 files, all large enough, and 12 passed. The best scored 78, so the other 3 searches were skipped. Before, all 4 ran, and one was "Channel" |
| Download, real Commons | The plugin's download step on that file, 6000 × 3376 pixels, 5.9 MB as an original | **358 KB, 1920 × 1080, in 0.8 seconds.** It passed the completeness, size and blur checks. The admin preview, 330 pixels wide, was 25 KB |

**Not tested in 1.4.2:** it has not run on the test website. The full article text from the site was not available. The photos that pass for this post are CC BY-SA, so they are refused if "Accept CC BY-SA images" is off.

## Version 1.4.1: fixes from the first run on the test website

### What happened on the test website, with 1.4.0

On 2026-11-06 the owner scheduled posts at 01:35 and 02:35, website time (UTC+10:30), with no server cron job. From the owner's Dashboard and Logs screenshots:

| What | Result |
|---|---|
| Preparation for the 01:35 post | Due at 00:35, but the worker only ran when the owner opened the site at about 01:28. Nothing ran in between, because WP-Cron needs visits |
| Stories tried | 2 Google News stories could not be found at their publishers. No AI was used for them. The third story was read and written |
| Final freshness check | **Passed at 01:35, with the story 13 minutes old** |
| Publishing | Published on time, at 01:35, filed under Crime because Entertainment, the planned category, had no usable story |
| Image | **None.** The searches were "Brand", "Brand safeguarding", "Brand Channel" and "Channel", and none of 80 files passed. This was a bug, fixed below |
| The 02:35 post | Stopped by the site's daily AI limit, which was set lower than the default of 12. The result was not reported yet |
| Dashboard | Showed a story that had already been dropped, and a preparation time that had already passed. Fixed below |

### What changed

- **Names with 's.** The article said "Russell Brand's" and later "Brand". The plugin read the name as "Russell Brand's", which did not match "Russell Brand" in the title, so the title gave "Brand". Names now drop a final 's, and a surname used on its own counts toward the full name.
- **Dashboard.** When a story is dropped, its headline, source, age and category are cleared from the post's row until another is chosen. Next discovery says "Now, preparing the post at ..." once preparation has started.

### Checks

| Layer | What it was | Result |
|---|---|---|
| Automated suite | 189 tests: the 187 from 1.4.0 and 2 new ones (IMG-51, PH-30) | **189 passed, 0 failed.** The WordPress debug log was empty |
| Do the tests catch mistakes? | 4 deliberate breaks in a scratch copy: keeping 's, not counting a surname toward the full name, still showing a dropped story, and showing a past preparation time | All 4 were caught |
| Same post, real Commons, no download | The post's title and opening paragraphs with its likely tags, through the 1.4.0 and 1.4.1 code | 1.4.0 searched "Brand", as the site did. 1.4.1 searched "Russell Brand", and 10 files passed. The best scored 78, all CC BY-SA |

**Not tested in 1.4.1:** the full article text from the site, which was not available, so its searches may differ a little. The fix has not run on the test website yet. The photos that pass are CC BY-SA, so they are refused if "Accept CC BY-SA images" is off.

## Version 1.4.0: Past-Hour Posting

Run on 2026-11-05 on **WordPress 7.1.4, PHP 8.1.34 and MariaDB 10.11.19, on Windows**, in a temporary local setup. Nothing in 1.4.0 has run on the owner's test website yet, and no real AI article has been written under it.

| Layer | What it was | Result |
|---|---|---|
| Automated suite | 187 tests: the 158 from 1.3.4, unchanged and with the feature off, plus 29 new ones in `tests/test-6-past-hour.php`, against the local stand-ins | **187 passed, 0 failed.** The WordPress debug log was empty |
| Do the tests catch mistakes? | 16 deliberate breaks in a scratch copy, such as removing the final check, allowing older stories, several articles for one post, or overwriting a search the owner wrote | All 16 were caught by the new tests |
| Real sources, no AI | Real Google News and publisher feeds, and a real article page, through the plugin's own code. It stopped before any AI call and published nothing | A real TechCrunch story, 1 minute old, was chosen and 360 words were read. Older stories in the same feeds were filtered out |

**The 10 required scenarios:**

| # | Scenario | Tests | Result |
|---|---|---|---|
| 1 | On by default on a fresh install | PH-1 | Passed |
| 2 | A fresh story found at first search | PH-2, PH-3 | Passed |
| 3 | Nothing at first, then found on a 15-minute retry | PH-4 | Passed |
| 4 | Planned category empty, another has news | PH-5, PH-6 | Passed |
| 5 | No category has news | PH-7 | Passed |
| 6 | Story goes over 60 minutes during writing | PH-8, PH-9, PH-10 | Passed |
| 7 | Story goes over 60 minutes before publishing | PH-11, PH-12 | Passed |
| 8 | A skipped post is never published later | PH-13, PH-14 | Passed |
| 9 | No duplicate processing with several posts | PH-15 | Passed |
| 10 | Turned off, the earlier workflow returns | PH-18, PH-19, and the 158 older tests | Passed |

**What was measured on real feeds, one moment on 2026-11-05 only:** Google News **topic** feeds are thin for this. The Business, Science and Sports topic feeds had 1, 0 and 2 stories under 45 minutes old, and the first 30 stories in the Technology topic feed were all over 60 minutes old. One-hour **searches** on the same subjects had 65, 35 and 88. Six of the 21 starting categories use a topic feed and no search: Technology, Entertainment, Business, Science, Sports, and Health & Wellness. They now have a search as well.

**Searches find many stories, but few can be read.** Through the plugin's own code, with search as the only source for five of those categories, and no AI call:

| Category | Search | Stories finished | Could be read | Result within about 2.5 minutes |
|---|---|---|---|---|
| Business | `business news` | 5 | 0 | Still trying a sixth |
| Sports | `sports news` | 1 | 1 | Ready for AI |
| Technology | `technology news` | 0 | 0 | Still on its first |
| Science | `science news` | 5 | 0 | Looking for another story |
| Health & Wellness | `health news` | 3 | 1 | Ready for AI |
| **Total** | | **14** | **2** | |

Most failures were pages the plugin could not find from a Google link. Two were refused by one publisher. None of them cost any AI. This is why publisher-feed stories are tried first, and searches mostly help when the feeds have nothing. The sample is small, from one moment, and only Google searches were tried, so it does not predict how often posts will be skipped.

**Searches can be off-topic.** `science news` also returned local flooding and Met Gala stories, and the first readable `sports news` story was a sportsbook promo code. A readable story like these would be written and filed under that category. Nothing in the plugin checks that a story really fits its category beyond the keywords you set. Watch this on your test site, and narrow the searches or add required keywords if needed.

**Known limits:**

- **Some posts will be skipped.** How many depends on the news, the time of day and the category, and has not been measured over days. Quiet categories such as Insurance, Coupon Blogs and Myths & Legends will rarely publish on their own.
- **Publication dates are only as honest as the publisher.** A feed and page that both re-date an old story look fresh. The page date catches only a feed that differs from its page.
- **The final check rechecks the stored date** against the clock. It does not fetch the page again, to avoid extra requests.
- **Fallback gives a fresh story, not always the newest of all.** The search stops at the first category with one.
- **Draft mode:** the check happens when the draft is created. It cannot cover when someone publishes it later.
- **WP-Cron alone is risky.** With no server cron and few visits, the worker may not run in time, and posts will be skipped.
- **One full fallback pass is slow.** With a worker run every minute, each Google request takes about a minute, and the six categories with a topic and a search make two. All 21 categories take about 27 minutes. It stops at the post time.
- **Turned off, the six added searches stay.** Each of those categories then makes one more Google request per research round and may find more stories. The rest of the earlier workflow is unchanged. Remove the search on the News Sources page to undo this.
- **The newest story is not always tried first.** A publisher-feed story goes before a newer Google News story, though both are within the age limit.
- **Fresh stories have fewer images.** A story under an hour old often has no matching photo on Commons.

**Not tested in 1.4.0:**

| Not tested | What to do |
|---|---|
| A full run on a real site with the real AI model | Install on your test site and watch one post, using the Past-Hour section of `QA-CHECKLIST.md` |
| Skip rates over several days | Watch the Dashboard for a few days |
| Saving the settings by pressing Save in a browser | Change both on your test site, save, and check they stay. The page output and the settings code were tested, but not the two joined by a real form post |
| How the Dashboard table looks on a phone | Look at it. It scrolls sideways like the other tables |
| A real upgrade from 1.3.4 with live data | The upgrade step was tested by simulating an older database |
| PHP 8.2 and 8.3 | Only PHP 8.1 was run for this version |
| The release ZIP through WordPress's installer | The files were checked against the source. Install it on your test site |

## Version 1.3.4: CC BY-SA images

Run on 2026-11-05 on **WordPress 7.1.4, PHP 8.1.34 and MariaDB 10.11.19, on Windows**, in a temporary local setup. Nothing in 1.3.4 has run on the owner's test website yet.

| Layer | What it was | Result |
|---|---|---|
| Automated suite | 158 tests: the 154 from 1.3.3 plus 4 new ones, against the local stand-ins. IMG-4 now checks CC BY-SA with the setting turned off | **158 passed, 0 failed.** The WordPress debug log was empty |
| Real Commons search | The owner's Poundland headline, without the article text, at the default minimum score of 65 | CC BY-SA off: 0 of 21 files passed. CC BY-SA on: 1 of 21 passed, a CC BY-SA 4.0 photo scoring 75 |
| Real download | That file, downloaded and checked by the plugin's code, then removed | Passed. It was not added to a post |

**What this does not fix:** most of the other CC BY-SA Poundland files score below 65 and are still refused, so the relevance score remains a limit. The chosen photo dates from 2014, and nobody has judged whether it suits the story.

**Not tested in 1.3.4:**

| Not tested | What to do |
|---|---|
| A CC BY-SA image on a real post on a real theme | Look at the post. The credit should show once, with the license link |
| Saving the setting through the real admin form | Untick the box on your test site, save, and check it stays unticked |
| PHP 8.2 and 8.3 | Only PHP 8.1 was run for this version |
| Legal review of accepting CC BY-SA | Not done. The owner approved it knowing that these images must not be edited |
| The release ZIP through WordPress's installer | The files were checked against the source. Install it on your test site |

## Version 1.3.3: Wikimedia waits and rate limits

Run on 2026-11-05 on **WordPress 7.1.4, PHP 8.1.34 and MariaDB 10.11.19, on Windows**, in a temporary local setup. Nothing in 1.3.3 has run on the owner's test website yet.

| Layer | What it was | Result |
|---|---|---|
| Automated suite | 154 tests: the 148 from 1.3.2 plus 6 new ones, against the local stand-ins | **154 passed, 0 failed.** The WordPress debug log was empty |
| New tests on the old code | The 6 new tests run against the 1.3.2 plugin code | 5 failed, so they do detect these problems. The sixth, IMG-45c, passed on both, because it confirms that a good file found before a rate limit is still used |
| Real Commons search | One real search through the plugin's code, then one with a wait set by hand | The real search returned 5 files. During the wait, nothing was sent |

**A design choice to check:** an article now waits for a missed search instead of publishing with no image. A long wait from Wikimedia can therefore carry it past its slot's grace period, and the slot is then deferred or missed under the **catch-up** setting. The article stays queued for a later slot.

**Not tested in 1.3.3:**

| Not tested | What to do |
|---|---|
| A real rate limit from Wikimedia | Rate limits came from the local stand-in, and the plugin did not try to set one off. Watch the Logs page if one happens |
| PHP 8.2 and 8.3 | Only PHP 8.1 was run for this version |
| The release ZIP through WordPress's installer | The files were checked against the source. Install it on your test site |

## Version 1.3.2: image credits, and research close to the slot

Run on 2026-11-05 on **WordPress 7.1.4, PHP 8.1.34 and MariaDB 10.11.19, on Windows**, in a temporary local setup. Nothing in 1.3.2 has run on the owner's test website yet.

| Layer | What it was | Result |
|---|---|---|
| Automated suite | 148 tests: the 143 from 1.3.1 plus 5 new ones, against the local stand-ins | **148 passed, 0 failed.** The WordPress debug log was empty |
| New tests on the old code | The 5 new tests run against the 1.3.1 plugin code | All 5 failed, so they do detect these problems |
| Real deactivation | In the local site, a post saved the older way, the plugin deactivated through WordPress, then the post text read in a new process with no plugin code loaded | The credit was there, with its title and links. After reactivating, the saved copy no longer appeared in the text |

**Design choices to check:**

- The saved credit is visible in the post editor. If the plugin is off, it shows at the end of the article, not below the image.
- With research starting later, fewer spare articles are ready. If a slot's own category has nothing ready, another category may not have an article to fill in, so that slot is more likely to wait or be missed. Raise **Start research before a slot** on the Schedule page to change this.
- A queued article can still wait up to 48 hours (**Skip queued articles older than**) if its slot is missed, and that limit counts from when it was queued, not from when the story was published.

**Not tested in 1.3.2:**

| Not tested | What to do |
|---|---|
| PHP 8.2 and 8.3 | Only PHP 8.1 was run for this version |
| Removing the plugin through the Plugins screen | Only deactivation was run. The saved copy is ordinary post text, so it should stay |
| The credit on a real theme, or Elementor, with the plugin off | Deactivate it on your test site and look at a post |
| The release ZIP through WordPress's installer | The files were checked against the source. Install it on your test site |
| Whether research starting 4 hours ahead is enough on a real site | Watch one day of slots. Each should have an article ready |

## Version 1.3.1: network failures, Title Case headlines, people photos, Pause Publishing, private addresses

Run on 2026-11-05 on **WordPress 7.1.4, PHP 8.1.34 and MariaDB 10.11.19, on Windows**, in a temporary local setup. Nothing in 1.3.1 has run on the owner's test website yet.

| Layer | What it was | Result |
|---|---|---|
| Automated suite | 143 tests: the 133 earlier tests plus 10 new ones, against the local stand-ins | **143 passed, 0 failed.** The WordPress debug log was empty |
| New tests on the old code | 9 of the new tests run against the 1.3.0 plugin code. The tenth, NET-5, was not run this way, but the old logic was checked on its own and accepted the private addresses | All 9 failed, so they do detect these problems |
| Real Commons search | 5 hand-written Title Case headlines, searched as whole headlines and as the new first search. This counted search hits only | Whole headlines: 0 hits on all 5. New first search: hits on all 5 |

**A security bug on PHP 8.1, fixed in this version.** `Http::is_public_ip()` in 1.3.0 accepted private addresses written in some IPv6 forms, such as `::ffff:127.0.0.1`, so a crafted address could get past the address safety check. Test AC-16 failed on PHP 8.1 because of it. The check now reads the address bytes, so the way it is written no longer matters.

**A test corrected:** AC-18 built its story using the real date and then set the test clock to a fixed date, so it failed when run on 2026-11-05. It now sets the clock first. Only the test changed.

**Known limit:** when a Title Case headline has no article text to confirm its names, a verb can be taken as part of a name. "NASA Delays Artemis Moon Mission" gave "NASA Delays". With article text, the same headline gave "Artemis" and "NASA".

**Not tested in 1.3.1:**

| Not tested | What to do |
|---|---|
| PHP 8.2 and 8.3 | Only PHP 8.1 was run for this version |
| A real outage | Failed requests were simulated, and real failed name lookups used `.invalid` addresses. Watch the Logs page after a real outage |
| The release ZIP through WordPress's installer | The files were checked against the source, and the code was run from the source folder. Install it on your test site |
| Whether images suit their stories | Nobody has judged the pictures. Hit counts only show that a search finds files |
| Real Elementor, any classic theme, and a full run with real AI and real Commons | Same as 1.3.0 below |

## Version 1.3.0: featured images

Run on 2026-11-05 on **WordPress 7.1.4, PHP 8.3.36 and MariaDB 11.4.5, on Windows**. Nothing in 1.3.0 has run on the owner's test website yet.

| Layer | What it was | Result |
|---|---|---|
| Automated suite | 133 tests: the 95 earlier tests plus 38 image tests, against a local stand-in for Commons | **133 passed, 0 failed** |
| Real Commons search | 23 hand-written sample stories through the plugin's code | 20 found an image. See `docs/IMAGES.md` |
| Real install | The release ZIP through WordPress's own plugin installer, over the running older copy | Passed: version 1.3.0, still active, database upgraded |
| Real downloads | Real files imported into a local Media Library, set as featured, and shown on real pages, on the Twenty Twenty-Five block theme | Passed |
| Real browser | Images screen, Test Wikimedia connection, Preview image search and the credit line on a public page | Passed |

**Bugs that only real data found, now fixed:**

- The credit did not show at all on block themes, because they build the page before `wp_head`. The automated tests had passed.
- Links in the credit each took a full line, because of block theme styles.
- A real, complete photo was refused as incomplete, because of padding after the picture data.
- Admin preview pictures did not show, because Commons serves them from `thumb.wikimedia.org`.

**Not tested in 1.3.0:**

| Not tested | What to do |
|---|---|
| Real Elementor, or any classic theme | Look at a published post on your test site. The credit should show once |
| A full automatic run with real AI and real Commons together | Run one article on your test site. The AI and image steps were each tested for real, but separately |
| Whether images suit their stories | Look at each one. Nobody has judged the pictures themselves, only their file names |
| Legal review of the license rules | Not done. The rules follow the license fields on Commons, which can be wrong |
| PHP 8.1 and 8.2 | Only PHP 8.3 was run |

The sections below are from version 1.2.1 and still describe everything other than images.

## Version 1.2.1

Run on 2026-11-04 on **WordPress 7.1.4, PHP 8.3.36 and MariaDB 11.4.5, on Windows**, using PHP's built-in web server.

## What was tested, and how

| Layer | What it was | Result |
|---|---|---|
| Automated suite | 95 tests in real WordPress and a real database. Publishers and OpenRouter were a local mock reached over real HTTP | **95 passed, 0 failed**. The WordPress debug log was empty in the final run. An earlier run logged one database lock timeout from the test script's own `TRUNCATE` between tests, a command the plugin does not use |
| Real install | The release ZIP through WordPress's plugin uploader, activation, then upgrade from 1.0.0 through "Replace current with uploaded" | Passed |
| Real browser | Every screen opened and its controls used in a real browser against the mock, including Test generation and Create test draft | Passed |
| Real clock, no session | Pressed Start, logged out, a command-line worker ran each minute, and a slot fired at its real time | Passed: one draft, 20 tags, no image |
| Real internet, no key | Real publisher feeds and pages through the plugin's code, stopping before AI | See below |
| Owner's live test, on version 1.2.0 on a separate test website | A real run with the real OpenRouter key and model, limited to 1 post | Passed: real story found and read, article written and checked, published at its slot, limit of 1 post held |

## Owner's live test

- **Test generation** on short sample text: real tokens and reported cost were recorded. It stopped at the 600-word minimum (562 words), as expected for a sample of about 150 words.
- **Full run:** 90 stories discovered, 44 filtered out, 1 CNBC story read and written up, then published at the slot time. The article had 1,033 words and 20 tags, and cost about $0.0015 with its tags.
- **Warnings in that run:** under the 1,500-word target, and reading ease 50 against the prompt's 85 to 90.
- **Found by this test:** the Dashboard kept showing Starting after the worker was running. Fixed in 1.2.1.
- **Not confirmed:** whether that article's facts matched its source, and whether any story came through Google News. The stories processed came from publishers that also have feeds in the starting list.

## What was NOT tested

**Read this before relying on the plugin.**

| Not tested | Why | What to do |
|---|---|---|
| **Live Google News through the plugin, confirmed** | The owner's run did not show whether Google stories were received. Before the plugin was built, a manual check found all 6 topic feeds working, and the plugin parsed that saved real data correctly | Press **Test search** on a category on your site |
| Your main website | The live test ran on a separate test website, and the lab tests on Windows with PHP's built-in server. A different host, or a persistent object cache or security plugin, may behave differently | Watch the first day closely |
| Other versions | Only the versions above and the owner's test website were run. PHP 8.1 and 8.2 and WordPress 6.5 to 7.0 are declared minimums. The code was scanned for newer PHP features, but not run on them | Test first if your site uses older versions |
| Running for days | Real-clock runs lasted minutes. Multi-day behavior was tested with a simulated clock | Watch the first few days of posts closely |
| Accuracy of generated articles | Software checks structure, not truth. The AI can make mistakes, and Automatic Publish has no human review | Read posts against their sources, especially at first |
| Formal scans | No WordPress Coding Standards or third-party security scan was run. A manual review and pattern scan were done | Consider one if your policy requires it |

## First run on the real site

1. Install the ZIP. Publishing mode is **Automatic Publish**, but nothing is posted until you press Start, so steps 2 to 6 are safe.
2. AI Settings: save your key and press **Test API Connection**.
3. Press **Test generation** on real sample text. This is one article and one tag request. Check the title, sections, table and 20 tags.
4. Press **Create test draft** and read the result in WordPress.
5. Compare **Usage** with your OpenRouter account.
6. News Sources: press **Test search** to see what each category finds.
7. Set up server cron, then press Start.

If step 3 or 5 shows a problem, the rest of the plugin is unaffected. Press Stop and report what the Logs page shows.

## Real-internet results

Run with no API key and no AI calls. All requests used the plugin's honest user agent, and robots rules were checked first.

**The 21 starting categories**

Every publisher feed in the starting list was fetched through the plugin, and its robots.txt was checked. For most, the newest article was also read through the plugin. Feeds that disallowed access, were gone, or whose articles refused access were left out.

| Finding | Categories |
|---|---|
| Two dependable feeds with full-text articles | Technology, World, Entertainment, Celebrity, Business, Politics, Science, Sports, Medical, Insurance, War & Conflict, Crime, Celebrity News, Gaming, Health & Wellness, Finance & Retirement, Technology & Consumer News |
| **Thin: few feeds allow automated reading, so these may produce fewer articles.** They use a one-week recency window | Myths & Legends (one general science feed, filtered by keyword), Coupon Blogs (one feed), Referral Programs & Blogs (two feeds, and one often has short posts that get held), Fitness (one feed) |

Each category also has a Google News topic or search, so thin categories are not limited to their feeds. Those Google searches have not been run yet. Press **Test search** on each one, and add better feeds you know of.

**First source check** (with the original six categories)

- 10 of 11 starting feeds were readable. The eleventh, The Verge, disallows it in robots.txt, and the plugin refused to fetch it. It was replaced in the starting list.
- 10 of 10 stories tried reached full text, from 328 to 1,964 words.

**Google News stories, from saved real data** (publishers contacted, Google not contacted)

- 6 of 17 reached full text: 5 in the run, plus Page Six, which reached 485 words after an extraction fix found by this test.
- 6 had no matching headline in the publisher's own feeds. 4 were refused with HTTP 403. 1 returned HTTP 402 and was recorded as paywalled.
- Every failure was recorded with its reason, and none reached the AI step.

This is one small sample, and results vary by publisher and day. Publisher feeds were clearly the more dependable source.

**OpenRouter, with a made-up key**

- The real 401 reply was understood: clear message, AI paused, key absent from what was stored.
- The public model list contains `openai/gpt-6-luna`, at $0.0000001 per input token and $0.0000005 per output token at the time of the check.

## Defects found by testing and fixed

| Defect | Found by |
|---|---|
| Pages in a non-UTF-8 encoding came out garbled | Automated test |
| A deferred slot never caught up unless another empty slot was also open | Automated test |
| Article text was deleted when its container's class name contained a word like "share" | Real-internet test |
| A teaser in a `main` element could win over the real article body | Real-internet test |
| Dashboard falsely said "Stalled" just after Start | Browser test |
| The Dashboard kept showing Starting until the page was reloaded, although the worker was running | Owner's live test |
| A publisher feed that disallows automated access was in the starting list | Real-internet test |
| A call only available from PHP 8.3 would have crashed on PHP 8.1 and 8.2 | Code review |
| A database query ran on every visitor page view | Code review |
| Starting mid-day counted earlier slots as missed work to catch up | Code review |
| With many categories, a second ready article per category went stale before its turn, wasting AI spend. The default is now one | Design review for the 21-category list |
| A category added while automation ran could be chosen before it had a source or WordPress category | Design review for the 21-category list |

## Acceptance criteria: technical specification

"Mock" means proven against the local mock. Real OpenRouter was not exercised.

| ID | Criterion | Result |
|---|---|---|
| AC-01 | Configure, edit, add and disable categories (now the owner's 21, in place of the six placeholders) | Pass, also in browser |
| AC-02 | Prompts stored exactly | Pass. Byte-for-byte, also through a real browser form. SHA-256 equals the supplied text |
| AC-03 | Start validates and starts persistent processing | Pass, also in browser |
| AC-04 | Continues after browser closure and logout | Pass. Observed after a real logout |
| AC-05 | Recency filters applied, limits disclosed | Pass. Boundary tests. Real feed filtering observed on publisher feeds only |
| AC-06 | Duplicates not added | Pass |
| AC-07 | Blocked or thin sources held, not invented | Pass. Mock, plus real 402, 403 and robots refusals |
| AC-08 | Configured model and exact prompt version used | Pass on mock. Request contents were captured and compared |
| AC-09 | Title, body and 20 tags in the right fields | Pass. Inspected in the WordPress editor |
| AC-10 | Draft mode and publish mode | Pass on mock. Draft also on a real clock |
| AC-11 | Three-post cap never exceeded | Pass, including across local midnight |
| AC-12 | Six-hour schedule in the configured timezone | Pass, including daylight saving |
| AC-13 | Retries do not duplicate posts | Pass. Simulated crash after post creation |
| AC-14 | Stop and pause independent and persistent | Pass, also in browser |
| AC-15 | Provider usage and cost, or clearly unavailable | Pass on mock. **Real figures not yet compared** |
| AC-16 | API key not exposed | Pass. Database, logs, pages, scripts and CSV checked |
| AC-17 | No images | Pass. Source scan and post inspection |
| AC-18 | Errors and state changes logged | Pass |

## Acceptance tests: master prompt

| # | Test | Result |
|---|---|---|
| 1 | Installs and activates with no fatal errors | Pass, real ZIP |
| 2 | Admin menu has the exact name | Pass |
| 3 | Starting categories present and editable (the owner's 21) | Pass |
| 4 | Topic URLs and search rules can change | Pass |
| 5 | Recency options available, limits disclosed | Pass |
| 6, 7 | Prompts preserved exactly | Pass |
| 8 | OpenRouter connection can be tested | Pass on mock. Real failure path confirmed. **Real success not yet seen** |
| 9 | Model verified, not substituted | Pass. Real model list confirmed |
| 10 | Source failure holds, nothing fabricated | Pass |
| 11 | Duplicates detected | Pass |
| 12 | Title and body separated | Pass on mock, HTML and Markdown |
| 13 | 20 usable tags validated or flagged | Pass on mock |
| 14 | Fields populated correctly | Pass |
| 15 | Draft mode creates a draft | Pass |
| 16 | Automatic mode publishes | Pass on mock |
| 17 | Daily cap in the configured timezone | Pass |
| 18 | Six-hour schedule works | Pass |
| 19 | Rotation avoids repeating a niche | Pass |
| 20 | Continues after closing the browser and logging out | Pass |
| 21 | Controls work independently | Pass |
| 22 | Retries do not duplicate | Pass |
| 23 | Usage and reported cost recorded | Pass on mock. **Real figures not yet compared** |
| 24 | Key not in the browser or logs | Pass |
| 25 | No images | Pass |
| 26 | Logs show each stage | Pass |
| 27 | Worker health and stale jobs visible | Pass |
| 28 | Deactivation stops work and keeps data | Pass |

## Performance

Dashboard on the local development server with a small data set: about 0.4 seconds server time and 0.8 seconds to load. This meets the 2-second target here, but your hosting will differ. Processing runs in bounded background runs and never in a browser request, except for the two test buttons you press yourself. Visitor pages gain no database queries from this plugin apart from one autoloaded option value.

## Decisions set as defaults

These can all be changed in settings.

| Decision | Default |
|---|---|
| Publishing mode | Automatic Publish, as the owner chose. Failed articles are still held |
| WordPress categories | Created automatically when none matches, as the owner chose |
| What counts toward the daily maximum | Drafts and published posts, shown separately |
| Empty slot | Try again at the next slot time |
| Timezone | Fixed +10:30. **Check this**: a location with daylight saving needs its name instead |
| Source attribution | Stored with the post, not shown to readers |
| Forbidden "—" in output | Replaced with a comma and noted in diagnostics |
| More than 20 tags | Keep the first 20 |
| Fewer than 20 tags | Retry, then hold |
| Article generations per day | 12, as a spending guard |
| Minimum source text | 250 words |
| Articles to keep ready per category | 1 |
| Category rotation | Round robin, so at 3 posts a day each of 21 categories comes up about once a week |
| Discovery | Every 60 minutes |
| Retention | Source text 30 days, logs 90 days |
