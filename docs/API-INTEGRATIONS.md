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

## Walter Writes humanizer

Optional, from 1.12.0. The article chosen for each post is rewritten by your own [Walter Writes](https://walterwrites.ai/) plan after the AI writes it and before it is published. It uses your subscription words, not a separate API plan.

**Connecting.** On the AI Settings page, click **Connect Walter**, sign in to Walter Writes and click **Allow**. The plugin talks to Walter's official MCP server (`https://mcp-server.walterwrites.ai/mcp`) with standard OAuth: it registers itself as a client, signs you in with a PKCE challenge, and stores the tokens encrypted. The access token lasts an hour and is renewed automatically. If Walter stops accepting the sign-in, the log and an email (at most one a day) ask you to connect again. Walter's web app itself cannot be used from a server, because Cloudflare refuses requests that do not come from a browser.

**What is sent.** Since 1.12.2, the whole article: the title, every heading, list item and paragraph, and (since 1.12.3) every table cell with words in it, including paragraphs with quotes and links (the link text is sent, not the address). Block quotes (someone's own words), source and credit lines, cells or blocks that are only a figure, and blocks with other markup stay as written. The article is sent as Markdown in pieces of 55 to 700 words cut at headings, because Walter refuses anything under 50 words. Names in each piece are sent as entities to keep, and numbers and links are marked to keep too.

**The fact repair.** In tests on 16 November 2026, Walter changed facts in every section, even when asked to keep them: "46 residents" became "over 40 citizens", details and a quote were made up. So Walter's text, with the original parts, goes to your AI model on OpenRouter (the model set on AI Settings, one extra request per post, logged under the purpose `humanize`). It is asked to keep Walter's wording and style and change only what is needed to make every fact match the original, returning one text per part. If that request fails, the post's last try uses the same Walter text again, so Walter's words are not spent twice.

**The fact check.** Each repaired part is then used only if all of these hold:

- Its length is within 75 to 140 percent of the original (50 to 200 percent for titles, headings and other parts of 12 words or fewer).
- Every quote is word for word the same, and none is added.
- Every figure is the same, and none is added.
- Every time word (Monday, next, March, spring) and number word stays, and none is added.
- No "not" or "no" is added or lost.
- It has at least as many "said"-type words, and every hedge ("about", "may", "expected") stays.
- Every name stays, and none is invented (in a title or heading, the names the article uses in its sentences).
- The words of each link and bold or italic phrase are still there, so the link goes back around them.
- A title or heading did not become sentences, and there is no web address or forbidden dash.

A part that fails keeps the AI's wording. Then the whole article, with its new title, must pass the same validation as before. The AI's title and body are kept in `original_title` and `original_body`.

**Words.** Since 1.12.3 every article is sent in full, every time (owner's decision): there is no monthly sharing and no daily pause. Walter's Pro plan has 70,000 words a month, and three 1,500-word articles a day need about 135,000, so on Pro the plan runs out part way through the month. From then on, only the pieces that fit in the words left are sent, and once none fit the post goes out with the AI's wording and the log says to add words. Words left are shown on the AI Settings page, and the words used for each article are in the log.

**When.** In Past-Hour Posting, as soon as the post's article has its image, while the post waits for its time. Otherwise, or if Walter was busy then, just before the post is created. A post is never held back for Walter. If Walter is not connected, out of words or down, the post goes out on time with the AI's wording.

## Wikimedia Commons

Images are found through the official [MediaWiki API](https://commons.wikimedia.org/w/api.php) for Wikimedia Commons. No account or key is needed.

- The plugin searches with terms built from the article's title, text and tags, and reads each file's size, type and license details.
- It sends at most one request every 3 seconds by default, and keeps results for 6 hours. It respects the waits Wikimedia asks for.
- Images are downloaded only from Wikimedia's media hosts, as a copy 1920 pixels wide when the original is larger.
- The choice is made by rules, not AI. See [IMAGES.md](../inkrush/docs/IMAGES.md) for the licenses accepted, scoring and credits.

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
