# Changelog

All notable changes to InkRush are listed here. Its technical name and folder are `zzz-copy-das-sobur`.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Entries come from the plugin's `readme.txt` changelog. Dates come from the git history. Versions before 1.3.0 were made before this repository existed, so their dates are not recorded.

Versions from 1.3.0 onward are test releases.

## [Unreleased]

### Added

- Public repository documentation under the InkRush name: README, contributing guide, security and privacy policies, user guides in `docs/`, and GitHub issue and pull request templates. No plugin code was changed.

## [1.8.0] - not yet released

Version 1.8.0 is built and tested in a local setup, but has not been committed or released.

### Added

- Automatic stop on the Schedule page: automation can stop by itself after a number of days, or a number of published posts, whichever comes first. Both count from the last press of Start Automation and are off by default.
- The Dashboard shows the limits and progress, and says when and why automation stopped by itself.

### Changed

- The plugin is named InkRush in WordPress: the Plugins page, the admin menu, page titles with the tagline "Discover. Create. Publish.", and messages. Each admin page ends with "Developed by: Sagor Das" and the contact address. The plugin author is Sagor Das. OpenRouter sees the app name InkRush.
- The release ZIP is named `inkrush-{version}.zip`, and a log export downloads as `inkrush-logs-{date}.csv`.
- A full user guide, `dist/InkRush-User-Guide.pdf`, replaces the one-page workflow chart.
- Unchanged, so existing sites keep working: the folder inside the ZIP, slug, text domain, settings, database tables, hooks, page addresses and the `ZZZCopyDasSobur` user agent that publishers' robots.txt rules match.
- Nothing about Start Automation or the Stop button. Reaching a limit stops automation in exactly the same way as Stop.

## [1.7.0] - not yet released

Version 1.7.0 is built and tested in a local setup, but has not been committed or released.

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
