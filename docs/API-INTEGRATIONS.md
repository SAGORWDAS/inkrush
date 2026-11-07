# API integrations

InkRush uses four kinds of outside service. It uses no others, and loads no third-party libraries, fonts or CDNs. What each one receives is listed in [PRIVACY.md](../PRIVACY.md).

| Service | Used for | Account needed |
|---|---|---|
| OpenRouter | Writing articles and tags | Yes, with credits |
| Wikimedia Commons | Featured images | No |
| Google News RSS | Finding stories | No |
| Publisher websites | Finding stories and reading their text | No |

## OpenRouter

InkRush reaches AI models only through [OpenRouter](https://openrouter.ai/). It has no direct connection to OpenAI, Anthropic, Google or any other model provider. Models from those providers can be used if OpenRouter offers them.

**Endpoints used** (base address `https://openrouter.ai/api/v1`):

| Endpoint | When |
|---|---|
| `POST /chat/completions` | Writing an article, writing tags, and the Test generation tool. Requests carry the app name InkRush |
| `GET /key` | Test API Connection, to check the key |
| `GET /models` | Checking that the chosen model is available |
| `GET /generation` | Reading the reported usage and cost of a past request |

**Setup**

1. Create an API key in your OpenRouter account and add credits.
2. Save it on the **AI Settings** page. It is stored encrypted and is never shown again or sent anywhere else.
3. Press **Test API Connection**.
4. Enter the OpenRouter model identifier you want, in the form `provider/model`. The default is `openai/gpt-6-luna`. The plugin never swaps it for another model, so if the model stops being available, AI jobs pause and the Dashboard tells you.

**Costs.** You pay OpenRouter for every request, including test requests and retries. The plugin asks OpenRouter to report usage with each response, and the Usage page shows the tokens and cost that came back. "Unavailable" means OpenRouter did not report a cost. The plugin never estimates one. Keep costs under control with "Maximum article generations per day" on the Schedule page.

**When OpenRouter refuses.** A rejected key, too few credits or an unavailable model puts AI jobs on hold, and the state shows Error. Research and the publishing of articles already written continue. Fix the cause, then press **Resume**.

**Changing the address.** Developers can point the plugin at another OpenRouter-compatible address with the `zzzc_openrouter_base_url` filter. This is meant for testing.

## Wikimedia Commons

Images are found through the official [MediaWiki API](https://commons.wikimedia.org/w/api.php) for Wikimedia Commons. No account or key is needed.

- The plugin searches with terms built from the article's title, text and tags, and reads each file's size, type and license details.
- It sends at most one request every 3 seconds by default, and keeps results for 6 hours. It respects the waits Wikimedia asks for.
- Images are downloaded only from Wikimedia's media hosts, as a copy 1920 pixels wide when the original is larger.
- The choice is made by rules, not AI. See [IMAGES.md](../zzz-copy-das-sobur/docs/IMAGES.md) for the licenses accepted, scoring and credits.

Press **Test Wikimedia connection** on the Images page to check access. Follow the [Wikimedia Foundation's terms of use](https://foundation.wikimedia.org/wiki/Policy:Terms_of_Use) and each file's license.

## Google News RSS

Google offers no official news API. InkRush reads Google News RSS feeds for the topic addresses and search queries you set, with your language and region.

- Requests to Google are spaced 30 seconds apart by default.
- A Google News story links to Google, not the publisher. The plugin finds the publisher's own page by matching the headline in that publisher's feeds or news sitemap. When no match is found, the story is skipped.
- Google's robots.txt does not list the `/rss/` path as allowed. Decide for yourself whether to use it. Turn it off with "Use Google News RSS" on the News Sources page, and the plugin uses publisher feeds only.
- Google can limit, change or stop these feeds at any time.

## Publisher websites

- RSS and Atom feeds you add are read directly.
- Before reading a publisher page, the plugin reads that site's robots.txt and follows it. If robots.txt cannot be read, nothing is fetched, and it tries again later.
- It does not bypass paywalls, logins, refusals or rate limits. It waits 5 seconds between requests to one site by default.
- It identifies itself honestly, with a user agent of the form `ZZZCopyDasSobur/1.9.1 (+https://your-site/)`. `ZZZCopyDasSobur` is InkRush's original technical name. It is kept so that robots.txt rules publishers wrote for it keep applying.
- Outbound requests go only to public `http` and `https` addresses on ports 80 and 443. Private and local network addresses are refused.

Reading a page does not give you the right to republish it. See [Copyright and editorial responsibility](../README.md#copyright-and-editorial-responsibility).
