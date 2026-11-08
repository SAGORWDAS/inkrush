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
| Facebook and Instagram (`graph.facebook.com`) | Only when connected and switched on: the generated post text, your article's web address, the featured image's web address, the image's alt text, the hashtags, and the access credential for that account | To publish the article to a Page or an Instagram professional account |
| X (`api.x.com`) | Only when connected and switched on: the generated post text, your article's web address, the featured image's bytes, its alt text, the hashtags, and the access credential | To publish the article |
| LinkedIn (`api.linkedin.com`, `www.linkedin.com`) | Only when connected and switched on: the generated post text, your article's web address, title and description, the featured image's bytes, its alt text, and the access credential | To publish the article as you or as an organisation you administer |
| Threads (`graph.threads.net`) | Only when connected and switched on: the generated post text, your article's web address, the featured image's web address, its alt text, one hashtag, and the access credential | To publish the article |
| Bluesky (`bsky.social`) | Only when connected and switched on: the generated post text, your article's web address, the featured image's bytes, its alt text, the hashtags, and the app password | To publish the article |

**Social media distribution is off until you set it up.** With it off, none of those six services is contacted at all. Each one is contacted only for an account you connected and a platform you switched on, and only for articles that are already publicly published on your site.

None of them receives your OpenRouter key, your prompts, the original publisher's text, your WordPress login, your visitors' data, or anything about any other article. A credential is sent only to the platform it belongs to.

What each platform keeps, and for how long, is set by its own terms and privacy policy. Those are listed in `readme.txt` under External services. Publishing an article to a platform makes it visible to that platform's users under that platform's rules, and removing it later is done on the platform, not here.

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
- **Connected social media accounts**: the platform, the account's own identifier, its display name and handle, and its **encrypted** access and refresh credentials. A credential is never returned to the browser, never shown on a page, and is removed from anything the plugin writes to its log. A developer application secret is stored encrypted the same way and is shown only as its last four characters.
- **Social publishing records**: for each article and platform, the post text that was sent, the image used, the result, any error, and the identifier and address of the published post. Failed, cancelled and skipped records are kept for 365 days by default, which you can change on the Social Media page. Records of posts that were published are kept, because they are the record of what went out.

You can change the retention periods on the **Publishing** page. Log exports to CSV are files you download yourself, and they stay with you.

## Removing data

- **Deactivating** the plugin keeps all its data.
- **Deleting** the plugin removes its tables, options and temporary data only if you turned on "Delete all plugin data when the plugin is deleted" on the Publishing page. It is off by default.
- WordPress posts, and images in the Media Library, are never deleted by the plugin. Remove them yourself if you need to.
- **Disconnecting a social media account** deletes its stored credentials from your database at once, and cancels anything not yet published through it.
- **Posts already published to a social media platform are never removed by this plugin**, whether you disconnect the account, delete the WordPress article, or delete the plugin. They exist on that platform under its rules. Remove them there.

## Your responsibilities

- Tell your visitors, in your own privacy notice, that your posts are written with AI and use outside sources, if your law or your readers expect it.
- Make sure your use of Google News, publisher content, OpenRouter, the model provider and Wikimedia Commons follows their terms.
- If you connect a social media platform, make sure your use of it follows that platform's terms and developer terms, and that you are entitled to publish the article and its image there. Publishing an image under a share-alike licence to a platform is still your decision and your responsibility.
- Do not put personal data into prompts, categories or search queries unless you are allowed to send it to those services.

## Questions

Contact the developer, Sagor Das, at das.sagorwork@gmail.com.
