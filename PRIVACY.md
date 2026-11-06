# Privacy

This page describes what InkRush does with data, based on its code. It is not legal advice. It does not make your site compliant with any privacy law. You, as the site owner, are responsible for your own privacy notice and legal obligations.

## No tracking

The plugin has no analytics, telemetry or tracking. It does not collect information about your site's visitors, set cookies, or send anything to the developer.

## What is sent to outside services

The plugin contacts only these services, and only as part of its work.

| Service | What it sends | Why |
|---|---|---|
| Google News RSS (`news.google.com`) | Your category topic addresses and search queries, language and region | To find stories |
| Publisher websites | Requests for their feeds, robots.txt and article pages | To find stories and read their text |
| OpenRouter (`openrouter.ai`) | Your API key, the publisher text, your article and tag prompts, the model identifier, your site address, and the plugin name | To write the article and its tags, check the key and model, and report usage |
| Wikimedia Commons (`commons.wikimedia.org`) | Search terms made from the article's title, text and tags | To find a featured image |

Requests to Google News, publishers and Wikimedia Commons carry a user agent that names the plugin by its technical name, `ZZZCopyDasSobur`, and includes your site address, so they can see which site is asking. Requests to OpenRouter send your site address in the `HTTP-Referer` header, and the app name InkRush.

OpenRouter passes your requests to the provider of the model you chose. What OpenRouter and that provider keep, and for how long, is set by their own terms and privacy policies. Read them before you use the plugin.

## What is stored on your site

- **Settings, categories, prompts and state**, in WordPress options and the plugin's own database tables.
- **The OpenRouter API key**, encrypted.
- **Publisher text and story details** used to write articles. Kept for 30 days by default ("Keep publisher text and rejected stories").
- **Logs**, including the WordPress user who made a change and the author credited with a post. Kept for 90 days by default.
- **Raw diagnostics of failed articles.** Kept for 30 days by default.
- **AI usage**: tokens and the cost OpenRouter reported.
- **Image history**: which Wikimedia files were used, so none is used twice.
- **Downloaded images**, in the WordPress Media Library.
- **Authors**: which WordPress user IDs may be credited with posts, and who granted or changed access. The Authors page does not show users' email addresses.

You can change the retention periods on the **Publishing** page. Log exports to CSV are files you download yourself, and they stay with you.

## Removing data

- **Deactivating** the plugin keeps all its data.
- **Deleting** the plugin removes its tables, options and temporary data only if you turned on "Delete all plugin data when the plugin is deleted" on the Publishing page. It is off by default.
- WordPress posts, and images in the Media Library, are never deleted by the plugin. Remove them yourself if you need to.

## Your responsibilities

- Tell your visitors, in your own privacy notice, that your posts are written with AI and use outside sources, if your law or your readers expect it.
- Make sure your use of Google News, publisher content, OpenRouter, the model provider and Wikimedia Commons follows their terms.
- Do not put personal data into prompts, categories or search queries unless you are allowed to send it to those services.

## Questions

Contact the developer, Sagor Das, at das.sagorwork@gmail.com.
