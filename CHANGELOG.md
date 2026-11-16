# Changelog

All notable changes to InkRush are listed here. Its technical name is `zzz-copy-das-sobur`, and its source is in the `inkrush/` folder.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Entries come from the plugin's `readme.txt` changelog. Dates come from the project's git history. The public repository starts with 1.8.0, so earlier commits are not in it. Versions before 1.3.0 were made before this repository existed, so their dates are not recorded.

Versions from 1.3.0 onward are test releases.

## [Unreleased]

Nothing yet.

## [1.12.2] - 2026-11-16

Owner's request: humanize every word of the article chosen for each post, headings included. `DB_VERSION` 19.

### Changed

- **Whole article to Walter.** `HumanizeText::blocks()` now splits `<p>`, `<h1>`–`<h6>` and `<li>` blocks (losslessly, with each block's opening tag kept). Sent: the title and every block not inside `blockquote`/`table`/`figure`/`aside`/`details`/`pre`/`code`, whose only markup is `a`/`strong`/`em`/`b`/`i` (`inline()`), without a web address in its text and not a source/credit line. Quotes and links are sent. `units()` lists them; `items()` joins them into Markdown pieces (`#` title, `##` headings, `-` items) of 55 to 700 words, cut at a heading after 350. Pieces are chosen in order within the post's word share, then batched as before.
- **Fact repair by the site's AI model.** After Walter, one OpenRouter request (`Settings::model_id`, `max_output_tokens`, purpose `humanize` in `generation_usage`) with the original parts as JSON and Walter's text (`repair_messages()`): keep Walter's wording, correct numbers, dates, times, names, titles, attribution, hedges and negations, remove anything added, keep quotes word for word and the `keep` phrases (link and emphasis text), one text per part id. `parse_repair()` reads the JSON even with fences around it. Walter's answer is kept in transient `zzzc_walter_answer_<job>` (two hours) so a retry after a failed repair does not spend Walter's words again.
- **Per-part check** (`check_part()` → `fact_check( ..., $with_quotes = true, $names )`): quotes compared word for word after normalising curly quotes (none lost or added); length 50 to 200 percent for parts of 12 words or fewer; `keep` phrases present; a title or heading must not become sentences; in title case only names the article uses in its sentences (`prose_names()`) must stay, and the invented-name check is skipped. `render_inner()` escapes the text and puts each link or emphasis element back around its first match; `rebuild()` writes each accepted block in its own tag.
- `Humanizer::humanize()` updates `title` too, and the validation comparison uses the new title. Unchanged parts count as "came back the same". `set_time_limit` adds `ai_timeout`.
- Removed: `sections()`, `pieces()`, `piece_text()`, `piece_words()`, `split_answer()`, `group_text()`, `clean()`, `fit_paragraphs()` and the "## Part" markers.
- **`Schema::upgrade_to_19()`**: `article_content.original_title` (through `create_tables()`).
- AI Settings help text, readme, PRIVACY.md (OpenRouter now receives Walter's rewrite) and docs/API-INTEGRATIONS.md updated.

### Tested

- `run-humanizer` 67 (blocks with headings, quotes, links, list items; units, items cut at headings; repair request and JSON parsing; quotes word for word; reworded headings and title-case names; link words; render and rebuild), `run-unit` 128, `run-images` 81, `run-validator` 17, all 0 failures. `php -l` clean.
- Local WordPress 7.1.4 (SQLite) with Walter and OpenRouter faked through `pre_http_request`: upgrade 18 → 19 adds `original_title`; a stand-in article sends one Walter piece and one repair request; the new title, a reworded heading and a paragraph with its link put back are kept, while a part with "46 residents" changed and one with "now" dropped keep the AI's wording; `original_title`/`original_body` saved, usage row `humanize`, a second run does nothing. With the first repair request failing (503), the article goes to `retry` and the last try reuses Walter's answer: Walter called once, the AI twice.
- **Not verified:** not run against the real Walter or a real AI model, so how much of each article passes is unknown, and the repair model's accuracy is untested. The guard, token renewal and a full posting cycle have not run live.

## [1.12.1] - 2026-11-16

Fixes from running 1.12.0 on a local WordPress 7.1.4 site (SQLite) connected to the owner's Walter account, plus the owner's "strict, save words" decision. No schema change, `DB_VERSION` stays 18.

### Changed

- **`HumanizeText::groups()` replaced by `sections()` / `pieces()`.** A section is a run of touching eligible paragraphs. Pieces join sections across headings (55 to 350 words) with a `## Part N` marker line between them, which Walter keeps; `split_answer()` splits the answer back at heading lines and returns null if the count differs, which refuses the whole piece. Each section is fact-checked against its own original. Before, a typical article (one 35 to 55 word paragraph per section) sent 1 of 11 paragraphs.
- Requests use `output_format: markdown` so the markers survive.
- `fact_check()`: digit words (zero to ninety) count as figures, so "2 million" and "two million" match; hedges are compared by kind (about/around/approximately/roughly, may/might/could...), not by word. `clean()` strips a leading `–`, `-` or `•`.
- `Humanizer::run()` re-reads the balance with `walter_account_info` after each article: batch answers carry no `X-Walter-Words-Remaining`.
- Connect help text: after Allow, Walter shows a "Walter Connected!" page with a Back to InkRush button instead of redirecting.

### Added

- **Waste guard** (owner's decision, 2026-11-16): option `zzzc_walter_guard` (`misses`, `paused_on`). An article that spent words with no section accepted adds a miss; one accepted section resets it. At `Humanizer::MISSES_TO_PAUSE` (3) misses, sending is paused for the rest of the local day (`skipped`, logged). The next day one article is tried, and another miss pauses that day again. Pure helpers `paused()` / `next_guard()`. Temporary Walter errors do not count. The AI Settings card shows the pause. `uninstall.php` deletes the option.

### Tested

- `run-humanizer` 58 (sections, pieces, `split_answer`, digit words, hedge kinds, the guard), `run-unit` 128, `run-images` 81, `run-validator` 17, all 0 failures. `php -l` clean.
- On the local WordPress site with 1.12.0 code plus these fixes: upgrade 17 → 18, the Walter card, Connect → Walter sign-in → Allow → Back to InkRush → "Connected · Pro", and `Humanizer::run()` on a test article (2 to 5 s, logged, second run does nothing). Walter's answers changed facts in every section ("46 residents" → "over 40 citizens", invented details), and the fact check refused all of them, so the body stayed the AI's. 961 words were spent in testing.
- **Not verified:** the guard has only unit tests, not a live run. Token renewal after an hour and the two call sites in a real posting cycle have not run. Walter's accept rate on real articles is expected to be low.

## [1.12.0] - 2026-11-16

Walter Writes humanizer, at the owner's request: use the owner's existing Walter subscription (no Walter API plan), run entirely on the WordPress site (nothing on a PC or VPS), and humanize only the article chosen for each post. `DB_VERSION` 18.

### Why Walter's MCP server

- The web app (`app.walterwrites.ai`) calls `POST https://api.walterwrites.ai/api/feature/humanizer/` with a NextAuth-issued one-hour bearer token. From a non-browser client both that endpoint and `/api/auth/session` return a Cloudflare 403 page before authentication. Getting past it would mean imitating a browser to get around bot protection, so it was not used.
- Walter's own MCP server, `https://mcp-server.walterwrites.ai/mcp`, is built for programs. It publishes OAuth metadata (`/.well-known/oauth-authorization-server`): dynamic client registration, `authorization_code` + `refresh_token`, public clients (`token_endpoint_auth_method: none`), PKCE S256, scopes `tools:read tools:write`, one-hour access tokens. It answers server requests normally. Its tools are `walter_humanize`, `walter_batch_humanize` (25 items, 50 to 5,000 words each), `walter_detect` and `walter_account_info`. Through it, `walter_account_info` reported the owner's Pro plan, 53,711 words left and 1,500 words per request, the same balance the web app showed, so it draws on the subscription. Registration accepts `admin-post.php?action=...` as a redirect URI. Answers come as `text/event-stream` and no `Mcp-Session-Id` is needed.

### Added

- **`Walter\WalterClient`**: registers the site once per callback address (`zzzc_walter` option), sends the owner to `/oauth/authorize` with a 40-character state (transient, ten minutes, tied to the user, used once) and a PKCE verifier stored encrypted, exchanges the code, and stores the access and refresh tokens with `Secrets::encrypt_value()`. Renews the access token five minutes before it expires, and once more on a 401. `invalid_grant`/`invalid_client`, an unreadable token or a second 401 set `needs_reconnect`, log a `walter_reconnect` error and email `admin_email` at most once a day. `call()` sends JSON-RPC `tools/call` and reads JSON or SSE (`parse_rpc()`). 429, 5xx and network errors are temporary.
- **`Walter\HumanizeText`** (pure): `blocks()` splits the body into top-level `<p>` blocks and everything else, losslessly. A paragraph is sent only if it is not inside `blockquote`/`li`/`table`/`figure`/`aside`/`details`, has no inner tags, no double quotes, no URL, is not a label or source line, and has 15+ words. `groups()` joins touching paragraphs into pieces of at least 60 words (target 130, at most 300). `clean()` removes the "Beginning of Text" prefix Walter added, HTML and Markdown. `fact_check()` refuses a piece on: any quote mark (only quote-free paragraphs are sent), a length outside 75 to 140 percent, any figure changed, lost or added (thousands separators normalised), any time or number word lost or added, a changed count of negations (including n't), fewer attribution words (said, told, according, announced...), a lost hedge (about, may, expected, will, nearly...), a lost name or one not in the article, a URL, the em dash, or text about the rewriting. `rebuild()` puts accepted pieces back in place, escaped, and `fit_paragraphs()` splits a merged answer by sentences.
- **`Walter\Humanizer::run( $job_id, $last_chance )`**: runs once per article (status in `article_content.humanize_status`), under a `humanize_<id>` lock. Shares the words left over the posts until the plan renews (`allowance_for()`: words left / (days to `walter_renewal_day` × `daily_target`), capped at `walter_max_words`, balance re-read after 30 minutes), sends the first groups that fit in `walter_batch_humanize` calls of up to 25 items and 90 percent of the per-request limit (`mode`, `reading_level`, tone "neutral, factual news reporting", entities per item, numbers and URLs preserved, plain output, em dash disallowed). Accepted pieces replace the body, the AI's version goes to `original_body`, and the whole body must not gain a `Validator` error the original lacks. Statuses: `done`, `partial`, `unchanged` (fact check or validation), `skipped` (not connected, no words), `retry` (temporary error before the last chance), `failed`. Every outcome is logged on the new `humanizer` stage.
- Called from `PastHour::prepare_image()` right after the slot becomes `READY`, and from `Publisher::run_publish()` just before `create_post()` (last chance, also the only call outside Past-Hour), which then re-reads the content.
- AI Settings: a "Walter Writes humanizer" card with Connect / Connect again / Check now / Disconnect and the settings `walter_enabled` (1), `walter_mode` (`safe`), `walter_reading_level` (`general`), `walter_renewal_day` (29), `walter_max_words` (1500), `walter_timeout` (120). Callback `admin_post_zzzc_walter_callback`, guarded by capability and the state value. The article detail shows the humanizer result.
- **`Schema::upgrade_to_18()`**: `article_content` gains `original_body`, `humanize_status`, `humanize_note`, `humanized_words`, `humanized_at` (through `create_tables()`). `REPLACE INTO` on regeneration resets them.
- `uninstall.php` deletes `zzzc_walter`. PRIVACY.md, readme External services and docs/API-INTEGRATIONS.md describe what is sent.

### Tested

- New `tests/unit/run-humanizer.php`, 41 checks, built on the real Walter answers of 2026-11-16: the web app's answer ("Monday" became "yesterday", "$12 million" became words), the MCP batch answer that dropped the Riverton paragraph, and an invented mayor's quote are all refused. A faithful rewrite and reordered sentences pass. Also block splitting, grouping, rebuilding, cleaning, the renewal-day arithmetic (16 to 29 November is 13 days, February clamps to the 28th) and SSE parsing. `run-unit` 128, `run-images` 81, `run-validator` 17, all 0 failures. `php -l` clean on every changed file.
- Live, against Walter on 2026-11-16 with a temporary client and the owner's consent: registration, PKCE sign-in, token exchange, `tools/list`, `walter_account_info`, `walter_humanize` and `walter_batch_humanize` (which refuses items under 50 words), then token revocation.
- **Not verified:** the plugin code was not run in WordPress (no install available), so the Connect button, the callback, token renewal after an hour, the `article_content` columns and both call sites have not run on a real site. How many pieces pass the fact check on real articles is unknown. In the live tests every Walter answer changed a fact, so at first many posts may go out partly humanized or unchanged. Watch the log's `humanizer` entries and the Articles page. The words Walter counts against the plan for refused pieces are spent all the same.

## [1.11.8] - 2026-11-15

Follow-up to 1.11.7, at the owner's request. No schema change, `DB_VERSION` stays 17.

### Changed

- **`ArticleAnalyzer::analyze()` searched a one-word subject on its own.** Live Commons counts on 2026-11-15 (`list=search`, namespace 6): "Halo" 352,599 hits (solar halos, haloed saints), "Halo Xbox" 135 (the game), "Halo game" 4,593, "Halo Campaign Evolved" 0, "Firefight" 39,881 (firefighting). A query that is just the main or second subject and one word long is now replaced by the new `with_context()`: the word plus the first tag that is one capitalized word, not a subject, not an outlet, not a stop word, and used in the article text ("Halo Xbox"). Failing that, a longer capitalized tag that contains the word ("Halo Studios"). Failing both, the query is left alone. The bare word is appended to the end of the list, so it only runs when `image_query_count` leaves room.
- Query de-duplication keys on the sorted lower-case words, since Commons matches every word in any order, so "Halo Xbox" and "Xbox Halo" are one search.

### Tested

- `run-images` 81 (4 new: the Halo article searches "Halo Xbox" and neither "Halo" nor "Firefight" alone; a one-word subject with no context tag, "Nvidia", is still searched), `run-unit` 128, `run-validator` 17, all with 0 failures. The Ghana case now searches "Ghana Accra" first, and its assertion was changed to check only that the two countries are not demanded together.
- **Not verified:** the Halo tags in the test are stand-ins, since the real post's tags are not in the log. A context tag can also narrow a search too far ("Firefight Xbox" had 1 hit); the 1.11.7 two-word rule and later searches cover that, but the effect on `no_image` rejections has not been measured on a site.

## [1.11.7] - 2026-11-15

The 22:00 post of 2026-11-15, "Halo: Campaign Evolved Adds Firefight in a Free Winter Update" (Entertainment), was published with `File:Halo, sun dogs and sun pillar.jpg`, a photograph of a solar halo, at score 68. The article text matched the source; only the image was wrong. No schema change, `DB_VERSION` stays 17.

### Fixed

- **`CandidateScorer::label_text()` read administrative Commons categories as subject labels.** The file was filed under `Uploaded via Campaign:wsc-pl`, which matched the headline keyword "campaign". Its score was subject 23 (halo, campaign), entity 10 (Halo), concept 10 (halo, campaign), quality 10, shape 10, context 5. The new `subject_categories()` drops categories matching `ADMIN_CATEGORY` (upload campaigns, self-published work, Wiki Loves / Wiki Science Competition, licence, `Files by/from`, `Media needing`, `Photographs by`, `Taken with`, Flickr, Exif and similar) before labels are scored or checked.
- **One shared word was enough for a file to pass.** The same word counted as a keyword, an entity and a concept, so a single-word subject that is also an ordinary word ("Halo") carried a wrong-sense file. `mandatory_failure()` now requires at least two distinct article words in the labels, from the new `matched_words()`: tokens of the keywords, entities, concepts (body words and tags) and main subject, each counted once.

### Tested

- `run-images` 77 (4 new: the real Halo file's metadata from the Commons API is refused, its upload-campaign category is gone from the labels, and a Halo game photograph that also says Xbox is accepted), `run-unit` 128 and `run-validator` 17 pass with 0 failures. Earlier accepted cases (Taylor Swift, Oak Street Health, Jensen Huang-style titles) still pass.
- **Not verified:** not run against live Commons searches or a WordPress site, so how many more files the two-word rule refuses in practice is not measured. It may raise `no_image` rejections for single-word subjects; the last-chance minimum (50) is unchanged. Watch the log for "Only one article word".

## [1.11.6] - 2026-11-15

Zero-token fixes from the owner's review of 2026-11-15, chosen to cut skipped posts without more OpenRouter spending. No schema change, `DB_VERSION` stays 17.

### Fixed

- **`Generation::handle_validate()` threw away a paid Past-Hour article on a tag-only failure.** `tag_only_failure` sent it to `HELD`, which is not in `Lifecycle::PIPELINE`, so `PastHour::watch_article()` dropped it and the post started a new story and a new generation. When `PastHour::applies()`, the article now goes on to `VALIDATED` with the tags it has, and a `validation` warning with `error_code` `tag_count` is logged. `handle_tags()` has already retried the count up to `ai_max_retries` by then. Outside Past-Hour, `HELD` is unchanged.
- **`Retrieval::handle()` retried the same page on `network_error`, HTTP 429 and HTTP 5xx in Past-Hour mode.** Each now calls `move_on()`, as `resolver_unavailable` and `robots_unavailable` did since 1.11.1. On 429 the host cooldown is still set.
- **`uninstall.php` left the social data behind.** It now drops `social_accounts` and `social_posts` and deletes `zzzc_social_platforms` and `zzzc_social_apps`. The social transients were already removed by the `_transient_zzzc_` pattern.

### Changed

- `PastHour::PUBLISHER_FAILURES` gains `network_error`, `rate_limited`, `http_500`, `http_502`, `http_503` and `http_504`, so for the current post only, that publisher's other stories are passed over. `LASTING_FAILURES` (24 hours) is unchanged.
- `PastHour::search_scope()` also opens the other categories when the new `planned_is_empty()` is true: the planned category is in `categories_searched`, no `ph:<slot>:` fetch is pending or running, and `eligible_items()` finds no untried story in it. `category_fallback` still applies.
- `PastHour::search()` fills `fallback_queue` with the other categories as soon as they open, when none of them has been searched for this post, instead of waiting for the next `RETRY_EVERY` (15-minute) round. This also speeds up the `FALLBACK_AFTER_TRIES` and `fallback_within()` triggers.

### Tested

- `php -l` is clean on every changed file. Unit suites: `run-unit` 128, `run-images` 73 and `run-validator` 17 pass with 0 failures.
- **Not verified:** none of the changed paths has a unit test. The WordPress suite was not run, since no WordPress install was available, so the tag-count path, the new move-on cases, the earlier fallback and uninstall have not run on a real site. Check the log after the next few posts for "Kept for its post with the tags it has", "Another story is tried for this post instead of waiting" and "Category fallback".

## [1.11.5] - 2026-11-15

Feeds for Insurance, Crime, Myths & Legends and Fitness, which gained none in 1.11.3. Owner's request of 2026-11-15.

### Added

- **`Categories::FEEDS_1_11_5`**: Insurance (Artemis, Carrier Management, Reinsurance News), Crime (New York Post crime tag, The Guardian US Crime, The Trace), Myths & Legends (TheCollector, La Brújula Verde), Fitness (The Guardian Fitness, mindbodygreen). Checked as in 1.11.3. The 33 refused feeds and the reason for each are listed in the code. NBC News and New York Post Metro passed but are general news, so they were not added to Crime.
- **`Schema::upgrade_to_17()`**, `DB_VERSION` 17: adds `FEEDS_1_11_5`, then removes `REMOVED_FEEDS_1_11_5`, logging each change. `seed_row()` includes `FEEDS_1_11_5` for new installs.

### Removed

- Live Science from Myths & Legends (every article flagged as paywalled, so each story failed as `paywalled`) and Defense News from War & Conflict (newest items were video pages of 19 and 30 words), from the seeds and, through `REMOVED_FEEDS_1_11_5`, from existing sites.

### Not changed

- Insurance Journal stays in Insurance. Its certificate chain failed to verify from the testing machine, so it could be neither confirmed nor ruled out.

### Tested

- 43 candidate feeds checked with the plugin's FeedParser, Robots rules and Extractor. A run of connection failures caused by a brief DNS outage on the testing machine was checked again once it cleared.
- `upgrade_to_17()`'s add-then-remove order run against a stand-in database: Myths & Legends ended with TheCollector and La Brújula Verde, War & Conflict kept War on the Rocks and Military Times, existing Insurance and Fitness feeds were kept, and a second run added nothing. Every seed built with `seed_row()` has no duplicate and none of the removed feeds.
- Unit suites pass with 0 failures. The WordPress suite was not run.

## [1.11.4] - 2026-11-15

### Removed

- **iHeartPublix from Coupon Blogs.** It was in the starting list, and its newest posts had 111, 121 and 126 words on 2026-11-15, under `min_source_words` (250), so every story from it failed. It is gone from the seed, and `Schema::upgrade_to_16()` (`DB_VERSION` 16) removes it from existing Coupon Blogs categories through the new `Categories::remove_feeds()` and `REMOVED_FEEDS`, logging the removal. Owner's request of 2026-11-15.

### Tested

- `remove_feeds()` against a stand-in database: it removed the feed when written in different case without a trailing slash, kept Hip2Save and Money Saving Mom, left the same URL in another category alone, and removed nothing on a second run. A new install's Coupon Blogs gets Hip2Save and Money Saving Mom only.
- Unit suites pass with 0 failures. The WordPress suite was not run.

## [1.11.3] - 2026-11-15

More chances for every post, a warning before one is skipped, and more feeds for 15 categories. Owner's request of 2026-11-15. A backup AI model was considered and left out at the owner's choice.

### Changed

- Defaults: `image_query_count` 5 (was 4), `image_candidates_per_query` 40 (was 20), `past_hour_max_articles` 4 (was 3), `daily_ai_limit` 24 (was 18). `PastHour::MAX_AI_ARTICLES` is 4.
- `Schema::DB_VERSION` is 15. `upgrade_to_15()` raises each of those four stored settings to the new default only when it is lower, logs which were raised, and adds `Categories::FEEDS_1_11_3`.
- The Stop button's confirmation names every post time from the new `PastHour::preparing_now()`.
- `Categories::seed_row()` adds `ADDED_FEEDS` and `FEEDS_1_11_3` to a new install's seeds through the new `merge_feeds()`, which `add_feeds()` now uses too.

### Added

- **`PastHour::warn_if_at_risk()`**, run on every preparation tick. Once per post, when it is 60 minutes away and not `READY`, it logs `post_at_risk` with the stories tried and the current reason, and emails the site's `admin_email` unless the new `post_risk_email` setting (default on, Schedule page) is off. It stays quiet when the daily post cap is reached, publishing is paused, or preparation began less than 15 minutes earlier.
- **`Categories::FEEDS_1_11_3`**: 35 feeds across 15 categories. A feed passed when robots.txt allows the feed and its articles, and at least two of its newest three articles were read with 250 words or more and none showed a paywall. The refused feeds are listed in the code. No feed passed for Insurance, Crime, Myths & Legends or Fitness.

### Found, not changed

- Existing seed feeds that failed the same check: Insurance Journal (robots.txt could not be read), Defense News (video pages), iHeartPublix (posts of about 120 words, under `min_source_words`), Live Science (paywall flag). They are left in place for the owner to decide.

### Tested

- `merge_feeds()`/`add_feeds()` against a stand-in database: existing feeds kept, a duplicate in different case with a trailing slash skipped, nothing added on a second run. Every seed was built with `seed_row()`: no duplicates, and every `FEEDS_1_11_3` key is a seeded category name.
- Unit suites pass with 0 failures, and `php -l` is clean on every changed file.
- The WordPress suite was **not run**: `upgrade_to_15()`, the warning email and the Stop confirmation have not run on a real site.

## [1.11.2] - 2026-11-15

More publisher feeds for Business and Technology, after the 10:00 post of 2026-11-15 was skipped with both Business feeds reporting "New 0".

### Added

- **`Categories::ADDED_FEEDS`**: 4 Business feeds (The Guardian Business, ABC News Australia Business, The Conversation AU Business, CBS MoneyWatch) and 10 Technology feeds (Engadget, TechRadar, The Guardian Technology, CNBC Technology, Gizmodo, 9to5Mac, Wired, ZDNet, MIT Technology Review, iTnews). They are part of the Business and Technology seeds for new installs.
- **`Categories::add_feeds()`** appends feeds to every live category with that exact name. A feed already listed, compared without case or a trailing slash, is not added again.
- **`Schema::upgrade_to_14()`**, `DB_VERSION` 14: adds `ADDED_FEEDS` to the existing Business and Technology categories and logs how many were added to each. Nothing is removed, and no other category is changed.

### Tested

- 28 candidate feeds were checked from a desktop with the plugin's own `FeedParser`, `Robots::parse()`/`path_allowed()` and `Extractor`. A feed passed when its robots.txt allows the feed and its articles, and its newest three articles were read with 250 words or more and no paywall. 14 new ones are added. Refused: Fortune, Business Insider and The Verge (paywall), Forbes and Sky News (HTTP 403), Ars Technica (HTTP 405), The Register and Fox Business (robots.txt), NPR (connection reset), The Independent (no stories read). This was run from a desktop, not the site's server, on a Sunday morning.
- `add_feeds()` was run against a stand-in database: it kept the existing feeds, skipped one already listed in different case with a trailing slash, and added nothing on a second run.
- Unit suites pass with 0 failures. The WordPress suite was **not run**, so `upgrade_to_14()` has not run on a real database.

## [1.11.1] - 2026-11-15

Fixes the skipped 10:00 post of 2026-11-15 (Business). `stories_tried` reached 8 at 07:40 local with 2 h 20 min and 13 eligible stories left: one live blog older than the page said, five Google News stories whose publishers could not be read (Bloomberg 403, WSJ and Reuters unmatched, Politico and Yahoo Finance with no feed), one Washington Post story held 35 minutes by two resolver retries, and one CNBC article whose best image scored 58. Category fallback never opened, because it waited for the last 90 minutes.

### Changed

- `PastHour::MAX_STORIES` is 30, not 8. AI spending is still capped by `past_hour_max_articles` and `daily_ai_limit`.
- `PastHour::search_scope()` opens the other categories once `stories_tried` reaches `FALLBACK_AFTER_TRIES` (3), as well as in the last `fallback_within()`.
- `PastHour::failed_publishers()` also returns publishers that failed with a `LASTING_FAILURES` code (`publisher_url_unresolved`, `http_401`, `http_403`, `http_451`, `paywalled`) for any post in the last 24 hours. `resolver_unavailable` and `robots_unavailable` join `PUBLISHER_FAILURES`, for the current post only.
- `Retrieval::handle()` does not retry `resolver_unavailable` or `robots_unavailable` for a Past-Hour article. The new `move_on()` ends it as `RETRIEVAL_FAILED` so the post tries another story at once.

### Added

- `PastHour::last_chance_min_score()`. When `why_not_selecting()` would refuse another article, or the post is within 45 minutes, `ImagePipeline::prepare()` is given a minimum of 50 (`LAST_CHANCE_MIN_SCORE`) instead of `image_min_score`, and the log says so. A site minimum of 50 or less is kept. `prepare()` and `review()` take an optional minimum; every other rule is unchanged. Owner's decision of 2026-11-15.

### Tested

- Unit suites: 128 social, images and validator suites pass with 0 failures. `php -l` on every changed file.
- The WordPress suite (`tests/test-6-past-hour.php`) was **not run**: no WordPress install was available. The new behaviour has no test of its own yet.

## [1.11.0] - 2026-11-14

Fewer skipped posts. The owner's four decisions of 2026-11-14, taken after the site's log for 2026-11-06 to 14 showed 9 posts published and 17 skipped. Includes the 1.10.5 fix, which was built the same day and not released on its own.

### Changed

- **Stories up to 12 hours old.** `past_hour_max_age_minutes` now ranges from 60 to 720 and defaults to 720. `Schema::DB_VERSION` is 13, and `upgrade_to_13()` sets the stored value to 720, keeps the preparation time and logs the change. Posts still go out at their set times. `PastHour::max_age_minutes()` is unchanged, so Single user mode still caps the age at the interval between posts.
- `PastHour::fallback_within()` treats an age limit over 180 minutes as 180, so other categories are tried from 90 minutes before the post and not from the start of preparation. For an age of 180 minutes or less it returns what it did before.
- **`LicenseChecker::check()` accepts a file whose only restriction is `personality`.** Any other value in `Restrictions`, alone or beside it, still refuses the file.
- **`Validator::looks_truncated()` takes the finish reason.** When it is `stop`, `end_turn`, `stop_sequence`, `eos` or `complete`, a last line without closing punctuation counts as cut off only when it ends on a comma, a dash, an open bracket or quote, or one of the words in `UNFINISHED_ENDINGS`. With any other finish reason, or none, it is as strict as before. `…`, `»` and `*` now close a line. The log showed 15 articles regenerated for this.
- **`Validator::ends_in_structure()`** replaces the test on the last element's tag, so a closing list or table wrapped in a `div` or `section` is recognised.
- **`ArticleAnalyzer::split_run()` drops a Title Case run that nothing confirms.** When the article has running text, a run of capitalized headline words is a name only if the text or a tag confirms part of it. Runs of one or two words are no longer kept unchecked. Tags now confirm names as the title or headline writes them. A headline with no text behind it is read as before.
- **`CandidateScorer::mandatory_failure()` accepts a square picture.** A portrait picture is refused with "A portrait image, taller than it is wide.", which replaces "Not a landscape image."

### Added

- **`PastHour::failed_publishers()`** and the `PUBLISHER_FAILURES` list. `eligible_items()` leaves out every publisher that already failed for this post with `publisher_url_unresolved`, `http_401`, `http_403`, `http_451` or `paywalled`. `watch_article()` logs it once, when the failure happens.
- `tests/unit/run-images.php`: 18 further checks for the license, shape and Title Case rules. 73 pass, 0 fail. `tests/unit/stubs.php` gains `esc_url_raw()`.
- `tests/unit/run-validator.php`, a new unit suite for the cut-off check. 17 pass, 0 fail, and 8 of them fail on the 1.10.4 code.
- `tests/test-6-past-hour.php`: PH-40 for the upgrade step, PH-41 for passing over a failed publisher, and PH-42 for the 12-hour window. All three pass. PH-28 now expects `when:6h`, the 12-hour default capped by the 6-hour interval in Single user mode.

### Tested

- The full suite on a local site (WordPress 7.1.4, PHP 8.3.36, MariaDB 11.4.5): 258 pass, 47 fail. The untouched 1.10.4 commit gives 254 pass, 48 fail on the same machine, and no test fails on 1.11.0 that passes on 1.10.4. The 47 failures are listed in `docs/TEST-RESULTS.md` and have not been looked into.
- The upgrade from 1.10.4 was also watched in a browser on that site.

## [1.10.5] - 2026-11-14

Fixes image searches built from words that are not names, found in the owner site's logs of 2026-11-09 to 14. Licenses, rules, the minimum score and the image source are unchanged.

### Fixed

- **`ArticleAnalyzer::names()` read sentence-opening words as part of a name.** "Check Nvidia", "Use Kuaishou", "Which Baltic", "No. Nvidia" and "Through Nov" were each sent to Commons as a search. `NOT_NAMES` gains the question words, prepositions and short verbs seen in the logs, and the short months that are nobody's name. A new `not_a_name()` ignores a trailing full stop, so "No." inside a run is recognised, and leaves an initial such as "A." alone.
- **`ArticleAnalyzer::analyze()` joined names across block boundaries.** Paragraphs, list items, table cells and line breaks now end a run of capitalized words, as headings already did.

### Added

- `tests/unit/run-images.php`: 9 further checks. 55 pass, 0 fail. The headlines are the real ones from the logs. The titles and bodies are stand-ins, because the log does not keep the article text.

## [1.10.4] - 2026-11-08

Image searching now starts from the headline, following the owner's guidelines of 2026-11-08. The workflow is unchanged: rules only, no AI, Wikimedia Commons only.

### Added

- **`ArticleAnalyzer::headline_pair()`**, searched before every other query. When the title or headline names two separate subjects, the first search is the two of them together: `Donald Trump Dana White` for the press-secretary story. Tags supply full names where news writing uses surnames, and a pair needs both halves vouched for by the main subject or a tag, so a capitalised body phrase such as "Finds AI" cannot be paired. A lower-case tag is a subject, not a name, and is never half of a pair. Two names sharing a word are never paired, which keeps "Dana White" away from "White House". A pair needs at least one full name, so "Ghana" and "China" are still searched separately.
- **`ArticleAnalyzer::strip_honorifics()`**, applied to every name used in a search. Commons matches all the words of a query, so "President Donald Trump" found fewer pictures of the man than "Donald Trump" does. The second-name queries no longer carry an office.
- **`CandidateScorer::subjects_matched()`**, counting how many of the article's subjects a file's labels name, with longer forms of the same name counted once.
- `tests/unit/run-images.php`: 9 further checks, including the published title of the article the owner linked. 46 pass, 0 fail.

### Changed

- `ImagePipeline::review()` orders files by score plus 4 points when the labels name more than one subject, so a photograph of both people in a headline beats a photograph of one of them. Ordering only: no threshold, and no reported score, is affected.

## [1.10.3] - 2026-11-08

Turns the 1.10.2 warning into a guarantee. The daily generation allowance is now shared across the day's post times instead of being first come, first served.

### Fixed

- **One post time could spend the day's whole generation allowance.** `Usage::ai_articles_today()` counts every attempt, failures included, and `past_hour_max_articles` allows up to three articles per post time, so the first post of the day could exhaust `daily_ai_limit` on retries and leave the later ones unable to write anything. `PastHour::why_not_selecting()` now holds one generation back for each post time still to come that day, counted by the new `PastHour::later_posts()`. A post may use everything above that reservation and no more. The limit itself is unchanged and still absolute.
- **The automation guide still described the old fixed limit of two articles per post**, which 1.10.1 replaced with the `past_hour_max_articles` setting.

### Changed

- `daily_ai_limit` now defaults to 18 rather than 12, which covers three post times at three articles each with room for failures. Sites that have saved their own value keep it.
- The Schedule page's note now distinguishes a limit too low for one article per post time from one that allows an article each but no retries, and names the figure to raise it to.

### Added

- `tests/test-6-past-hour.php`: PH-38 and PH-39 cover the reservation, the untouched later post times, and that a fully spent limit still names the limit rather than the reservation. **Both are unrun** — they need a WordPress install.
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

Social media distribution. Not yet run against any real platform: see the 1.10.0 section of `inkrush/docs/TEST-RESULTS.md` for exactly what was and was not tested.

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
