# InkRush: WordPress News Automation Plugin

**Discover. Create. Publish.**

InkRush is an AI-powered WordPress news automation plugin. It finds news stories from Google News RSS and publisher feeds, reads the publisher's text when the page allows it, rewrites the story into an article through your own [OpenRouter](https://openrouter.ai/) account, adds a featured image from Wikimedia Commons, and publishes WordPress posts on a schedule you set. It runs in the background after you press Start.

- **Version:** 1.8.0 (a test release)
- **Requires:** WordPress 6.5 or newer, PHP 8.1 or newer
- **License:** GPL-2.0-or-later (see [LICENSE](LICENSE))
- **Developed by:** Sagor Das
- **Developer contact:** das.sagorwork@gmail.com

> **Technical name.** The plugin shows as **InkRush** in WordPress. Its technical name stays `zzz-copy-das-sobur` (the folder inside the ZIP, the slug and the text domain), and its web user agent stays `ZZZCopyDasSobur`, so existing sites, settings, data and publishers' robots.txt rules keep working.

## Contents

- [What InkRush does](#what-inkrush-does)
- [Features](#features)
- [How the workflow runs](#how-the-workflow-runs)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [AI setup with OpenRouter](#ai-setup-with-openrouter)
- [News sources](#news-sources)
- [Featured images](#featured-images)
- [Publishing and authors](#publishing-and-authors)
- [Scheduling and background work](#scheduling-and-background-work)
- [Troubleshooting](#troubleshooting)
- [FAQ](#faq)
- [Privacy](#privacy)
- [Copyright and editorial responsibility](#copyright-and-editorial-responsibility)
- [Security](#security)
- [Contributing](#contributing)
- [Developer and contact](#developer-and-contact)
- [License](#license)

## What InkRush does

InkRush is a content automation plugin for one WordPress site. You set up news categories, an OpenRouter API key, a schedule and an author. The plugin then:

1. Looks for new stories in each category.
2. Filters them by age and keywords, and drops duplicates.
3. Reads the original publisher page when it is accessible. It does not get past paywalls, logins or robots.txt rules.
4. Sends the source text and your saved prompt to the OpenRouter model you chose, and asks for tags with your saved tag prompt.
5. Checks the result. An article that fails a check is held for you to review and is never published.
6. Finds a featured image on Wikimedia Commons, using fixed rules about license, size and labels.
7. Creates a WordPress post at the planned time, as a draft or published, credited to an author you authorised.

The only outside services it contacts are your news sources, OpenRouter and Wikimedia Commons. It loads no third-party libraries, fonts, CDNs or tracking.

## Features

**Finding news**

- Categories, each with its own Google News topic addresses, Google News search queries and publisher RSS or Atom feeds. 21 starting categories are included, and you can edit, disable, reorder, delete or add them.
- A recency filter (past hour, past 24 hours, past 7 days or a custom range), required and excluded keywords, language, region, active days and a weight for each category.
- Duplicate checks on headlines and article text over a set number of days.
- A **Test search** button that shows what a category finds right now, without saving anything.

**Writing articles**

- Articles written through OpenRouter, with the model you choose. The default model identifier is `openai/gpt-6-luna`, and the plugin never switches to another model by itself.
- Your article prompt and tag prompt are used exactly as saved.
- Checks for cut-off text, missing parts the prompt asks for, the number of tags and the minimum word count. Failed articles are held with the reason.
- **Test generation** and **Create test draft** tools on the AI Settings page.
- A daily limit on article generations, and a Usage page with the tokens and costs that OpenRouter reported.

**Featured images**

- Every post needs a featured image from Wikimedia Commons. Without a usable one, the article is held and not published.
- Images are chosen by rules, with no AI: accepted licenses only, minimum size, preferred shape, relevance score from file labels, and no image used twice.
- A credit line under the image, with the author, license and file name.

**Scheduling and publishing**

- Posts per day, hours between posts, first post time and timezone.
- **Past-Hour Posting**, on by default: each post uses a story no more than 2 hours old when it goes out (adjustable from 1 to 3 hours), or the post is skipped.
- Category rotation, a daily maximum, and a choice of what happens when a post time has no article.
- Draft or Automatic Publish, for all categories or some.
- WordPress categories created automatically, or mapped to your own.
- Posts credited only to users you authorised on the **Authors** page.
- A visible source line under each post, if you turn it on.

**Running and monitoring**

- Start, Stop, Pause Research, Pause Publishing and Resume controls.
- An optional automatic stop after a number of days or published posts, whichever comes first, counted from each Start.
- A background worker driven by a server cron job (recommended) or by WP-Cron.
- A Dashboard with the state, today's counts, worker health and recent errors.
- Articles, Usage and Logs pages. Logs can be filtered and exported to CSV.
- The API key is stored encrypted, and keys are removed from logs before they are saved.

## How the workflow runs

```
News sources ──> Filters and duplicate checks ──> Publisher page read
       (Google News RSS,            (age, keywords,           (only when allowed;
        publisher feeds)             similarity)               otherwise skipped)
                                                                     │
                                                                     v
WordPress post <── Featured image <── Validation <── Article and tags written
 (draft or published,  (Wikimedia Commons,  (failures are      through OpenRouter
  at the planned time)   rules only)          held)             (your prompts)
```

For full details, see [docs/AUTOMATION.md](docs/AUTOMATION.md).

## Requirements

- WordPress 6.5 or newer, on a single site. Network-wide activation on multisite is not supported.
- PHP 8.1 or newer, with the `dom`, `libxml`, `mbstring` and `curl` extensions.
- MySQL or MariaDB with `utf8mb4`.
- An OpenRouter account with an API key and credits.
- Recommended: permission to add a server cron job.

These are the declared minimums. Which versions were actually tested is recorded in [TEST-RESULTS.md](zzz-copy-das-sobur/docs/TEST-RESULTS.md).

## Installation

1. Get the plugin ZIP, `inkrush-1.8.0.zip`.
2. In WordPress, go to **Plugins > Add New > Upload Plugin**, choose the ZIP, install it and activate it.
3. A new menu item, **InkRush**, appears.
4. Work through the setup in [docs/INSTALLATION.md](docs/INSTALLATION.md): AI Settings, News Sources, Schedule, Authors, Publishing, then **Start Automation** on the Dashboard.

## Configuration

Every setting is on the plugin's admin pages. There is nothing to edit in files, apart from the optional settings in `wp-config.php` described in the docs. [docs/CONFIGURATION.md](docs/CONFIGURATION.md) lists each setting with its default and allowed range.

## AI setup with OpenRouter

InkRush connects to AI models only through OpenRouter. It does not connect to OpenAI or other AI providers directly. Models from those providers can be used through OpenRouter.

1. Create an API key in your OpenRouter account and add credits.
2. On **AI Settings**, paste the key and press **Save key**. It is stored encrypted and never shown again.
3. Press **Test API Connection**.
4. Check the model identifier, and review the article and tag prompts.

AI is used only to write the article and its tags. Images are chosen without AI. See [docs/API-INTEGRATIONS.md](docs/API-INTEGRATIONS.md).

## News sources

- **Google News RSS:** topic addresses and search queries. Google offers no official news API and can limit or change these feeds at any time. Google's `/rss/` path is not on the allow list in its robots.txt, so the plugin has an off switch for Google News on the **News Sources** page. Decide for yourself whether to use it.
- **Publisher RSS or Atom feeds:** these link straight to the publisher's page, and usually work best.

The plugin reads a publisher page only when that site's robots.txt allows it, and it identifies itself with a user agent that includes your site address. Paywalled, blocked or too-short pages are recorded and skipped.

## Featured images

Images come from Wikimedia Commons through its official API. The plugin accepts CC0, public domain and CC BY files, and CC BY-SA files unless you turn them off. It refuses non-commercial, no-derivatives, unknown and conflicting licenses. It reads file labels, not the pictures themselves, so some images will not suit their story. Check them.

Full details are in [IMAGES.md](zzz-copy-das-sobur/docs/IMAGES.md).

## Publishing and authors

- **Automatic Publish** is the default: articles that pass the checks go live with no approval. Switch to **Draft** to review every post first.
- Posts are credited only to WordPress users you granted access on the **Authors** page. Nobody has access through their WordPress role, administrators included, and the plugin never changes roles.
- The source address and publisher are always stored with the post. A visible source line is optional.

## Scheduling and background work

The default schedule is 3 posts a day, 6 hours apart, from 10:00, in the fixed timezone UTC+10:30. Change the timezone to match your site. A named timezone such as `Australia/Adelaide` follows daylight saving, and a fixed offset does not.

To run for a set time only, use **Automatic stop** on the Schedule page: automation then stops by itself after a number of days, or a number of published posts, whichever comes first. Both count from the last Start.

A server cron job that runs every minute makes timing reliable. Without one, WordPress's own scheduler runs only when someone visits the site. See [SERVER-CRON.md](zzz-copy-das-sobur/docs/SERVER-CRON.md).

## Troubleshooting

Start with the **Logs** page: every stage records its result and reason. Common problems and fixes are in [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md).

## FAQ

**Does InkRush publish without my approval?**
In Automatic Publish mode, yes, for articles that pass the checks. Choose Draft mode to approve each post yourself.

**Does it guarantee a number of posts a day?**
No. Each post needs a suitable, readable story and a usable image. When there is none, fewer posts appear and the reasons are in Logs.

**Does it work without OpenRouter?**
No. Articles are written only through OpenRouter, with your own key. You pay OpenRouter for what you use.

**Can it read paywalled articles?**
No. It does not bypass paywalls, logins or robots.txt rules.

**Does it guarantee search rankings, word counts or readability scores?**
No. Goals like these in a prompt are instructions to the model, not guarantees.

**Is it on WordPress.org?**
No. It is not listed in the WordPress.org plugin directory.

**Does it send data about my visitors anywhere?**
No. See [PRIVACY.md](PRIVACY.md).

## Privacy

InkRush adds no tracking and collects no visitor data. It does send your site address, your search terms, publisher text and your prompts to the services it uses. [PRIVACY.md](PRIVACY.md) explains what goes where and how long it is kept.

## Copyright and editorial responsibility

InkRush is a tool. **You are responsible for what it publishes on your site.**

- A news story or image being publicly accessible does not mean it is free to republish. The plugin does not check copyright, facts, defamation, trademarks or other legal matters for you.
- Articles are written by an AI model from publisher text. Review them for accuracy and fairness, especially in Automatic Publish mode. Turning on the visible source line is recommended.
- Follow each publisher's terms and each image's license. CC BY and CC BY-SA images need credit, which the plugin adds, and CC BY-SA images must not be edited unless the edited version is shared under the same license.
- Follow the terms of Google, OpenRouter, your chosen AI model provider and Wikimedia.
- If you are unsure whether your use is allowed, ask a qualified lawyer.

## Security

Please report security problems privately. Do not open a public issue. See [SECURITY.md](SECURITY.md).

## Contributing

Bug reports and pull requests are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) first. The automated tests are described in [tests/README.md](tests/README.md). Maintainer rules and design decisions are in [docs/MAINTAINER-NOTES.md](docs/MAINTAINER-NOTES.md).

Changes are listed in [CHANGELOG.md](CHANGELOG.md).

## Developer and contact

- **Developed by:** Sagor Das
- **Developer contact:** das.sagorwork@gmail.com

## License

InkRush is licensed under the **GNU General Public License, version 2 or (at your option) any later version** (GPL-2.0-or-later), as declared in the plugin header. The full text of version 2 is in [LICENSE](LICENSE).
