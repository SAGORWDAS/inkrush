# Changelog

All notable changes to InkRush are listed here. Its technical name and folder are `zzz-copy-das-sobur`.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Entries come from the plugin's `readme.txt` changelog. Dates come from the project's git history. The public repository starts with 1.8.0, so earlier commits are not in it. Versions before 1.3.0 were made before this repository existed, so their dates are not recorded.

Versions from 1.3.0 onward are test releases.

## [Unreleased]

Nothing yet.

## [1.10.2] - 2026-11-08

Findings from an audit of every path that ends in a skipped post time. The 128 social unit tests and the 37 image tests still pass.

### Fixed

- **A stalled worker was not reported while only social distribution was running.** `Health::status()` judged staleness by `Automation::is_active()`, but since 1.10.0 the worker also runs for social distribution alone, so a dead worker in that state reported nothing and the Dashboard claimed it was not running. Staleness now follows `Plugin::worker_wanted()`, and the Dashboard distinguishes stopped, social-only, stalled and healthy.

### Added

- **A warning when the daily generation limit cannot cover the day.** Every generation attempt counts against `daily_ai_limit`, failures included, and `past_hour_max_articles` allows up to three articles per post time. The Schedule page now warns when the limit is below post times × articles per post, and names the figure to raise it to. Without it, a day with a few OpenRouter failures exhausted the limit and the last post times were skipped for want of generations.
## [1.10.1] - 2026-11-08

Fixes three faults found on the owner's site. Image tests built from the real logs: 37 passed. The 128 social unit tests still pass.

### Fixed

- **An article was illustrated with a photograph of someone it was not about.** The 10:00 post on 2026-11-07, about Dana White being offered the press secretary job, used `Boy feeling Barack Obama's hair.jpg`, which scored 81. A surname used alone, "White", was credited to the highest-scoring name containing the word, which was "White House" and not "Dana White", so the building became the subject. A surname now joins the name it ends, and an ambiguous short form is left alone rather than guessed at.
- **One bad search could decide the image.** Searching stopped as soon as a file scored 75, which on that article happened on the first and only query. At least two searches now always run.
- **Nothing checked whether the picture showed the wrong person.** A file whose own title names a person the article never mentions is now refused outright, whatever it scores, and the reason names that person.
- **A photograph of a child passed the protection meant to prevent it.** The waiver for the child, victim and identifiable-people rules was satisfied by any subject, including a place, so an article mentioning the White House switched it off for a photograph filed under the White House. Only a person's name waives it now.
- **"and" joined two unrelated subjects into one name.** "Ghana and China" was searched as a phrase and found nothing usable. The two countries are now separate subjects. The genuine name particles, as in "Bank of the West", are unchanged.
- **A row of chart labels became an article's subject.** "Measure Achievers Challengers Aspirants Share" was searched and returned no files at all. A run of four or more capitalised words is now only used when the title, the headline or a tag confirms it.
- **A short run of capitals before a full stop did not end a name**, so "the UFC. White has run" was read as a name called "UFC. White". "U.S." and "St." are unaffected.
- **Releasing an image did not release it.** Releasing a reservation on the Images page left the article pointing at that image, so it published with it anyway. The article now searches again, unless the image is already on a published post, which is left alone.
- **A post was skipped although two good articles had been written for it.** Both were lost at the image step, which used up a fixed limit of two.

### Added

- **Articles to write before giving up on a post**, on the Schedule page, from 1 to 5 and now 3 by default. Maximum article generations per day still caps the day's spending.
- `tests/unit/run-images.php`: 37 checks built from the real articles, file titles and searches in the logs, including the pictures that were chosen correctly and must keep being chosen, and the four fixes from 1.9.1.

## [1.10.0] - 2026-11-08

Social media distribution. Not yet run against any real platform: see the 1.10.0 section of `zzz-copy-das-sobur/docs/TEST-RESULTS.md` for exactly what was and was not tested.

### Added

- **Social media distribution** to Facebook Pages, Instagram, X, LinkedIn, Threads and Bluesky, each through that platform's own official API. Off on upgrade; publishes nothing until an account is connected and a platform switched on.
- **InkRush → Social Media**: six connection cards, each with its status, its accounts, a connection test, its own settings, and that platform's requirements, approvals, costs and limits written out in full. Plus the callback address every platform needs, and the global distribution settings.
- **InkRush → Social Publishing**: the figures, the approval queue with editable post text, and the full publishing history with filters by platform, result and date, and buttons to view, retry, edit, cancel and read the error.
- **Social controls on the Articles page**: which platforms to publish a published article to, whether to read the text first, and the state of each platform for that article.
- **Per-platform post text** written through the existing OpenRouter key and model from the published article only, in each platform's own tone and length. A new `social` prompt type, seeded from `defaults/social-prompt.txt`, with version history like the others. Captions can be edited by hand or rewritten one platform at a time.
- Four job types on the existing queue: `social_generate`, `social_publish`, `social_refresh_token` and `social_reconcile`. One publishing job per platform, each independent.
- Database version 12: two new tables, `social_accounts` and `social_posts`. No existing table, option, article or post is changed.
- `docs/SOCIAL.md`, covering each platform's setup, requirements, limits and what is sent where.
- 128 unit tests in `tests/unit/`, runnable without a WordPress install, and 44 integration tests in `tests/test-11-social.php` against a local stand-in for all six platforms.

### Changed

- The background worker now runs when **either** news automation or social distribution has work. Previously `Runner::tick()` returned before acquiring its lock whenever automation was inactive, so no job of any type could run. News job types are still allowed only while automation is active and not paused; the social types are allowed regardless, so a social post already queued still goes out after Stop or Pause.
- `Automation::stop()` and the automatic stop no longer clear the worker's schedule outright. They match it to whether the worker is wanted, so stopping news automation does not strand queued social posts. With distribution off as well, the schedule is cleared exactly as before.
- Log redaction now also covers Meta access tokens, JSON web tokens, app passwords, authorisation codes and any field named like a token or secret, and an adapter registers each credential it uses so it is stripped even if a platform echoes it back.
- The shared admin layout helpers in `Admin\Pages` became public so the new screens could use them rather than duplicating the layout. No existing screen changed.

### Security

- Platform credentials and developer application secrets are encrypted with the plugin's existing scheme before they are stored. The ordinary read path strips the credential columns entirely, so a token cannot reach a page, a script or an AJAX response through an oversight. An application secret is shown only as its last four characters.
- Every social form checks the administrator's capability and its nonce. The authorisation callback cannot carry a nonce, because a platform does not have one, so it checks the capability and a one-time state value: random, held for ten minutes, tied to the platform and the administrator who started it, used once, and with the platform read from what the site recorded rather than from the query.
- Requests to the platforms are pinned to each platform's own hosts, are checked against private network addresses with the same test the rest of the plugin uses, and do not follow redirects, because they carry a credential.

## [1.9.1] - 2026-11-07

A test release: [InkRush 1.9.1 on GitHub](https://github.com/SAGORWDAS/inkrush/releases/tag/v1.9.1). It passed 251 automated tests in a local setup. The owner installed it on their website on 2026-11-07; how it does there is not yet checked.

### Fixed

- Image searches no longer use the news outlet as the subject. On 2026-11-07 the 16:00 post was skipped after two articles searched Wikimedia Commons for "The Tokyo Reporter" and "Nippon News Network" instead of their stories, and found nothing that passed. The publisher, and an outlet the article quotes, are now left out unless the title or headline names them.
- Words that only begin a sentence, such as "Is" in "Is Poundland" or "Man" in "Man arrested…", a sentence word at the end of a name, such as "Apple What", and plain numbers, such as "000" from "10,000", are no longer searched.
- At least one of the searches no longer uses the two main names, for example a place named in the article, so a wrong subject cannot use up every search.

### Changed

- When no image passes, the log now gives the minimum score and the best score reached, instead of "Relevance score N is below the minimum of N".

## [1.9.0] - 2026-11-07

A test release: [InkRush 1.9.0 on GitHub](https://github.com/SAGORWDAS/inkrush/releases/tag/v1.9.0). It passed 247 automated tests in a local setup, and has not yet run on a live website.

### Added

- Multiple users mode, chosen in a new Posting mode card on the Authors page. Single user mode, with one Post author, stays the default and works exactly as before.
- A schedule for each active author, from a Schedule button in their row: timezone, days of the week, From and Until dates, dates to skip, up to 24 post times at least 15 minutes apart, 1 to 5 posts at each time, their own categories, limits per day, week (Monday to Sunday) and month, and whether a missed time is published at their next time or skipped. The form shows how much of each limit is used.
- A conflict check when saving: a time within 15 minutes of another active author's time is refused, checked over the next 14 days in every timezone, so daylight-saving changes are covered. A warning is shown when the authors' times together go above Maximum posts per day.
- Each post is credited to the author whose time it is. Right before each post the author must be active and under their limits. Otherwise that time is closed with the reason in Logs, and the article waits for another author's time.
- The Schedule page lists each author's times for today, the Dashboard's Next publish slot says who it is by, and the Publishing page says when the Post author is not used.
- Logs record mode switches and schedule saves with who made them, times not used and why, and each post with its author.
- A new feature section, F8 Multiple users mode, in the user guide. Later features are numbered one higher.

### Changed

- No research is started for an author who is paused, revoked, deleted or at a limit.
- In Multiple users mode, Start Automation needs an active author with a schedule instead of a Post author.
- Past-Hour Posting works in both modes. In Multiple users mode it searches each author's own categories, and the story age is not shortened by the Interval setting, which that mode does not use.
- Maximum posts per day, Maximum article generations per day and Automatic stop still apply to the whole site, above every author.
- Database version 11 adds the schedule columns and an author column for slots. Existing data is unchanged, and the mode stays Single user mode until you switch.

## [1.8.0] - 2026-11-07

The first public release: [InkRush 1.8.0 on GitHub](https://github.com/SAGORWDAS/inkrush/releases/tag/v1.8.0). It passed 231 automated tests in a local setup, and has not yet run on a live website.

### Added

- Automatic stop on the Schedule page: automation can stop by itself after a number of days, or a number of published posts, whichever comes first. Both count from the last press of Start Automation and are off by default.
- The Dashboard shows the limits and progress, and says when and why automation stopped by itself.
- Public repository documentation under the InkRush name: README, contributing guide, security and privacy policies, user guides in `docs/`, and GitHub issue and pull request templates.

### Changed

- The plugin is named InkRush in WordPress: the Plugins page, the admin menu, page titles with the tagline "Discover. Create. Publish.", and messages. Each admin page ends with "Developed by: Sagor Das" and the contact address. The plugin author is Sagor Das. OpenRouter sees the app name InkRush.
- The release ZIP is named `inkrush-{version}.zip`, and a log export downloads as `inkrush-logs-{date}.csv`.
- A full user guide, `dist/InkRush-User-Guide.pdf`, replaces the one-page workflow chart.
- Unchanged, so existing sites keep working: the folder inside the ZIP, slug, text domain, settings, database tables, hooks, page addresses and the `ZZZCopyDasSobur` user agent that publishers' robots.txt rules match.
- Nothing about Start Automation or the Stop button. Reaching a limit stops automation in exactly the same way as Stop.

## [1.7.0] - not released separately

Version 1.7.0 was built and tested in a local setup, but never released on its own. Its changes are included in 1.8.0.

### Added

- Authors page: grant, pause or revoke which WordPress users can be credited with posts, one at a time or in bulk, with search and filters by role and access.
- Logs show names and the credited author, and can be filtered by person. They record who changed access and who published a plugin draft by hand.

### Changed

- Nobody is authorised automatically, administrators included, and WordPress roles are never changed.
- Right before each post, the plugin checks that its author is active. If not, the article is held, and no new articles are written until an author is active.
- The Post author list shows only active authors.
- Upgrading authorises the user already credited, and nobody else.

### Removed

- The "First administrator" choice for Post author.

## [1.6.0] - 2026-11-06

### Changed

- Past-Hour Posting uses stories up to 2 hours old, and preparation starts 2 hours before each post.
- New "Maximum story age" setting on the Schedule page, from 60 to 180 minutes. The preparation time goes from 15 to 180 minutes and is never more than the story age. Neither is ever longer than the interval between posts.
- Other categories are tried in the second half of the window, 60 minutes before a post. Google News is asked for the same window.
- The upgrade moves a preparation time still at 60 minutes to 120, and keeps one you changed.

## [1.5.0] - 2026-11-06

### Changed

- Every post needs a featured image. An article with no usable image is held and never published, and its image search runs once more 6 hours later. With Past-Hour Posting on, another story is chosen instead.
- Images are found as soon as an article is ready.
- A post from this plugin cannot go public, by hand or on a schedule, unless its featured image is set and its file opens on the server.

### Removed

- The switches that allowed a post without an image.

### Fixed

- When a strong match ends the searching but its file cannot be used, the other searches now run.

## [1.4.2] - 2026-11-06

### Changed

- Faster, more accurate image search. A 1920-pixel copy is downloaded instead of the original.
- Commons is asked only for files at least as large as the minimum image size.
- Searching stops once a file scores 75 or more.
- AI tags confirm names, and a single word is searched alone only when it is also a tag.

## [1.4.1] - 2026-11-06

### Fixed

- Names ending in 's no longer cause image searches for the wrong word, and a surname used on its own counts toward the full name.
- Dashboard: a dropped story no longer shows as the chosen one, and Next discovery says when preparation is already under way.

## [1.4.0] - 2026-11-05

### Added

- Past-Hour Posting, on by default, with an off switch on the Schedule page. Each post uses a story that will be no more than 60 minutes old at the post time, or the post is skipped and never caught up.
- A Google News search for the Technology, Entertainment, Business, Science, Sports, and Health & Wellness starting categories, unless their topic address or search was changed.

## [1.3.4] - 2026-11-05

### Added

- CC BY-SA images are accepted, with the same checks and credit as CC BY, and an off switch on the Images page.

## [1.3.3] - 2026-11-05

### Fixed

- A long wait asked for by Wikimedia is respected, and the article waits without using up an image attempt.
- A rate limit partway through the image searches no longer ends the image attempt.

## [1.3.2] - 2026-11-05

### Changed

- The image credit names the file, and a copy is saved at the end of the post text, so the credit stays if the plugin is removed. Existing posts get the copy on upgrade.
- Research for a category starts only when its next slot is close, 4 hours by default.

## [1.3.1] - 2026-11-05

### Fixed

- A brief network problem no longer blocks a story or an image for good.
- Title Case headlines give real names for image searches.
- A common word can no longer make a photo of people count as being about the article.
- Pause Publishing also stops the finishing of a post after a crash.

### Security

- Private addresses written in IPv6 form, such as `::ffff:127.0.0.1`, are always refused.

## [1.3.0] - 2026-11-05

### Added

- Automatic featured images from Wikimedia Commons, chosen by rules, with no AI. CC0, public domain and CC BY are accepted. A credit line shows below the image, and an image is never used twice.
- Images page, and image details on the Dashboard, Articles, Usage and Logs pages.

## [1.2.1]

### Fixed

- The state label on the Dashboard updates in the background, so Starting changes to Running without a reload.

## [1.2.0]

### Changed

- Automatic Publish and automatic WordPress category creation are the defaults. Articles that fail checks are still held.

## [1.1.0]

### Changed

- The starting list is the owner's 21 categories. Existing installs gain the missing ones, and existing categories are not changed.
- A category added later waits until it has a source and a WordPress category.
- One ready article per category by default.

## [1.0.0]

### Added

- First release.
