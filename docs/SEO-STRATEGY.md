# Discoverability plan for the InkRush repository

This document covers how people can find the InkRush repository on GitHub and in search engines. Every phrase here describes something the plugin actually does. It is about the repository, not about the posts the plugin writes. The plugin makes no promise about search rankings.

Nothing here has been applied. Repository settings (description, topics, Discussions, social preview) are for the owner to set by hand on GitHub.

## Positioning

- **Name:** InkRush
- **Tagline:** Discover. Create. Publish.
- **Description:** AI-Powered WordPress News Automation Plugin.
- **Category:** WordPress Plugin / Content Automation
- **Primary keyword:** WordPress News Automation Plugin

## Suggested GitHub settings

**Repository name.** The current name, `zzz-copy-das-sobur-1.3.0-with-images-search`, contains an old version number and no brand. A name such as `inkrush` or `inkrush-wordpress-news-automation` is easier to find and remember. GitHub redirects the old address after a rename, but local clones should update their remote. The plugin folder and slug inside the repository stay `zzz-copy-das-sobur` either way, for compatibility with existing sites.

**About description** (under 350 characters):

> InkRush: an AI-powered WordPress news automation plugin. Finds stories from Google News RSS and publisher feeds, rewrites them through OpenRouter, adds Wikimedia Commons featured images, and publishes on a schedule.

**Topics** (GitHub allows up to 20):

`wordpress`, `wordpress-plugin`, `news-automation`, `content-automation`, `automated-publishing`, `ai-writing`, `openrouter`, `rss`, `google-news`, `wikimedia-commons`, `featured-images`, `php`, `wp-cron`, `news-aggregator`, `ai-content-generation`

Avoid `openai` and `chatgpt` as topics. The plugin does not connect to OpenAI directly, and those topics would suggest that it does.

**Website field:** leave empty until a real project website exists.

**Social preview image:** none exists yet. If one is made, use the name, tagline and a plain screenshot of the real Dashboard.

## Page title and meta description

For a future project page, or for reference when sharing links:

- **Title** (about 60 characters): `InkRush: AI-Powered WordPress News Automation Plugin`
- **Meta description** (about 155 characters): `InkRush finds news, rewrites it through your OpenRouter model, adds Wikimedia Commons images and publishes WordPress posts on your schedule.`

## Keywords

Use these where they fit naturally. Do not repeat them for the sake of it.

| Keyword | Where it fits |
|---|---|
| WordPress news automation plugin | README title and first paragraph |
| AI news writer for WordPress | Features, AI setup |
| automated news publishing | Overview, scheduling |
| WordPress content automation | Category, overview |
| RSS to WordPress posts | News sources |
| Google News RSS | News sources |
| OpenRouter WordPress plugin | AI setup, integrations |
| Wikimedia Commons featured images | Images |
| scheduled WordPress posts | Scheduling |

Do not use phrases the code does not support, such as "SEO-optimized articles", "plagiarism-free", "guaranteed traffic", "unlimited posts", "OpenAI integration" or "free to republish".

## GitHub Discussions

If the owner turns on Discussions, these categories fit the project:

| Category | Format | For |
|---|---|---|
| Announcements | Announcement (maintainer only) | New releases and important notices |
| Q&A | Question and answer | Setup and usage questions |
| Ideas | Open discussion | Feature ideas, before a feature request issue |
| Show and tell | Open discussion | Sites and setups using InkRush |
| Sources and prompts | Open discussion | Sharing publisher feeds and prompt tips |

Bug reports and security problems do not belong in Discussions. Bugs go in issues, and security problems are reported privately (see [SECURITY.md](../SECURITY.md)).

## Releases

Publishing each version as a GitHub Release, with its ZIP attached and the matching [CHANGELOG.md](../CHANGELOG.md) section as the notes, helps people find and trust the latest version. Releases are created by the owner.
