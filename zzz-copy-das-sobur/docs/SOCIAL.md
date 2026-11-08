# Social media distribution

InkRush can post every published article to six social media platforms. This document covers what
each platform requires, how to set it up, what is sent where, and the limits that cannot be worked
around.

Added in version 1.10.0. It installs switched off and publishes nothing until you connect an
account and switch a platform on.

## How it works

1. The existing workflow runs unchanged: a story is found, read, rewritten through OpenRouter,
   validated, given a Wikimedia Commons featured image, and published as a WordPress post.
2. The moment WordPress confirms the post is publicly published, one record is created per
   platform that is switched on and has a usable account.
3. One request to OpenRouter writes the post text for all of those platforms at once, each in its
   own tone and within its own length limit.
4. Each platform then gets its own publishing job on the plugin's existing queue.

A draft is never distributed. The website is always the first destination, and nothing about social
media can delay or prevent an article being published on it.

## The one rule that shapes everything else

**A post is never sent twice.** Every record carries an idempotency key, unique in the database,
made from the WordPress post, the platform and the account. A retry, a double button press, two
workers overlapping, or a worker dying halfway all land on the same record.

This is why an attempt whose outcome could not be read is treated as neither success nor failure.
If a request was sent and no answer came back, the post may exist on the platform. InkRush marks
it **Being checked** and asks the platform what actually happened before anything else is sent.
Five of the six platforms can be asked this; LinkedIn cannot, so a LinkedIn post in that state
waits for a person to look and then either retry it or cancel it.

## Independent of news automation

Social publishing does not stop when news discovery does.

Pressing **Stop** stops finding and writing news. Social posts already queued belong to articles
that are already published on the website, so they keep going out. The background worker therefore
stays scheduled while Social media distribution is on, and the worker allows only the social job
types once automation is stopped. Switching distribution off as well clears the schedule, exactly
as before this feature existed.

## Setting up a platform

On **InkRush → Social Media**:

1. Copy the callback address shown at the top. Every platform needs this exact address in its
   developer settings, including the `https`. An address that does not match is refused by the
   platform, which is the most common reason connecting fails.
2. Open the platform's card, save its application identifier and secret, and press **Connect
   account**. You are sent to the platform to authorise, and back again. You are never asked for a
   social media password.
3. Switch the platform on in its own settings, and choose which account to publish through if more
   than one is connected.

Bluesky is the exception: it needs no developer application, and takes a handle and an app
password instead.

### What each platform requires

| Platform | Application | Approval | Cost | Account |
|---|---|---|---|---|
| Facebook Pages | Meta app, Business type, Facebook Login product | App Review for `pages_*`, plus Business Verification | None | A Page you hold a content or management role on. Personal profiles cannot be published to |
| Instagram | The same Meta app, Instagram Graph API product | App Review for `instagram_content_publish`, plus Business Verification | None | A Business or Creator account linked to a Page you hold a role on |
| X | Project and app in the X Developer Portal, OAuth 2.0 confidential client | None for posting | **Paid.** The Free tier allows only a token number of posts a month. Daily use needs at least Basic | Any account that authorises the app |
| LinkedIn | App in the LinkedIn Developer Portal, linked to a Page | Self-serve for posting as yourself. The Community Management API, needed to post as an organisation, is reviewed case by case and can be refused | None | Posting as an organisation needs an administrator role on that Page |
| Threads | Meta app with the Threads API use case | App Review for `threads_content_publish` | None | A Threads profile |
| Bluesky | None | None | None | Any account, with an app password |

Until a Meta or Threads application is approved, it can publish only to accounts owned by the
developer account itself. That is Meta's rule, not a limitation of this plugin.

**The X cost is a real dependency, not a detail.** Check the current price in the X Developer
Portal before relying on that platform.

### Permissions requested

| Platform | Permissions |
|---|---|
| Facebook | `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`, `business_management` |
| Instagram | `instagram_basic`, `instagram_content_publish`, `pages_show_list`, `pages_read_engagement` |
| X | `tweet.read`, `tweet.write`, `users.read`, `media.write`, `offline.access` |
| LinkedIn | `openid`, `profile`, `w_member_social`, and `r_organization_social` with `w_organization_social` for an organisation |
| Threads | `threads_basic`, `threads_content_publish` |
| Bluesky | Not applicable. An app password carries the account's own permissions |

These are the least that each platform needs to identify the account and publish to it. Nothing is
requested for reading your audience, your messages or your statistics.

## What is sent, and what is not

Each platform receives, per article: the post text InkRush generated, your article's web address,
the featured image, the image's alt text, the hashtags, and the credential for that platform.

Facebook, Instagram and Threads **fetch the image from your site's public address**. They cannot
publish from a site that is not reachable from the internet, and InkRush says so plainly rather
than letting the attempt fail obscurely. X, LinkedIn and Bluesky are sent the image's bytes
directly, so they work on any site.

No platform receives your OpenRouter key, your prompts, the original publisher's text, your
WordPress login, anything about your visitors, or anything about any other article. Nothing is
sent to the developer.

## Length and format limits

| Platform | Post length | Hashtags | Links | Picture |
|---|---|---|---|---|
| Facebook | 5,000 characters used of a much larger limit | Up to 10 | In the text, clickable | Optional, up to 10 MB, JPEG, PNG or GIF |
| Instagram | 2,200 | Up to 30 | **Not clickable.** A call to action is used instead | **Required**, up to 8 MB, JPEG only, between 4:5 and 1.91:1 |
| X | 280, of which a link always counts as 23 however long it is | Up to 3 | In the text | Optional, up to 5 MB, JPEG, PNG or WebP |
| LinkedIn | 2,900 used of 3,000 | Up to 5 | Attached as a link preview | Optional, up to 10 MB, JPEG or PNG |
| Threads | 500 | **One only.** Threads uses no more | In the text | Optional, up to 8 MB, JPEG or PNG |
| Bluesky | 300 graphemes, so an emoji counts once | Up to 3 | In the text, with proper link markup | Optional, under 976.56 KB |

Instagram allows 50 API posts per account per 24 hours, and Threads allows 250 per profile. Both
are read from the platform itself before publishing rather than counted locally.

### Pictures are never edited

The featured image is the one the existing Wikimedia Commons pipeline chose. It is never resized,
cropped or re-encoded, because the owner's rule is that a CC BY-SA image must not be edited, and
resizing one would create a derivative work and bring the share-alike condition into play.

Instead, when the full-size image does not meet a platform's limits, InkRush looks through the
sizes WordPress itself generated when the image was imported and uses the largest one that does
fit. Those files already exist for every image on the site.

When no available size fits, the image is not sent. A platform that can publish without one
publishes text only, and a platform that requires one, which means Instagram, is skipped for that
article with the reason recorded. This is a real limitation: a PNG featured image cannot go to
Instagram at all, because Instagram accepts only JPEG and the plugin will not convert it.

## Post text

The text is written from **your published article**, never from the original publisher's page, so
a post can only repeat what your own site says.

The instruction is a prompt like the others, seeded from `defaults/social-prompt.txt`, editable,
and with its versions kept. It forbids adding any fact not in the article, forbids clickbait and
withheld facts, keeps the article's own hedging words, and bans invented hashtags.

With caption writing switched off, or when OpenRouter is unavailable, each post is the article's
own headline and nothing else. A fallback never adds anything.

Captions can be edited by hand or rewritten one platform at a time. Rewriting one platform's text
leaves the other five exactly as they are.

## Approval and timing

**Automatic** publishes as soon as the article is public. **Manual approval** holds every post on
the Social Publishing page with its text ready to read, change and release.

Timing is immediate, after a delay you set, or at a time you choose per article. Times use the
plugin's own timezone setting, the same one the posting schedule uses, not the WordPress one. A
scheduled post can be cancelled before it goes out and is never rescheduled after it has been
published.

## When something goes wrong

| What happened | What InkRush does |
|---|---|
| Rate limit | Waits as long as the platform asked, and uses no attempt |
| Temporary platform problem | Retries with a growing delay, up to the attempts you set |
| Connection refused, or a name that would not resolve | Retries. The request certainly never arrived |
| Request timed out, or no answer came back | **Checks the platform** before sending anything again |
| Credential refused | Fails the post and marks the account as needing reconnecting |
| Permission refused | Fails the post and says the application may not be approved for it |
| Required picture unavailable | Skips that platform for that article, with the reason |
| Platform returned no identifier | Does **not** call it published. Checks the platform |

Everything is recorded on the Logs page under the `social` stage, with the platform, the article
and the publishing job named on every line. Credentials never appear there.

## Disconnecting and deleting

Disconnecting an account deletes its stored credentials and cancels anything not yet published.
Posts already published stay in the history with their identifiers, and **nothing is removed from
the platform**.

Deleting a WordPress article is the same: anything not yet published is cancelled, and what is
already published on a platform is left exactly as it is. To remove a post from a platform, remove
it on that platform.

## Known limits

- An unconfirmed LinkedIn post cannot be checked automatically, because the Posts API cannot be
  searched by content. It waits for a person.
- A PNG featured image cannot be published to Instagram, which accepts JPEG only.
- An image larger than every platform limit at every generated size is not published, and
  Instagram is skipped for that article.
- Facebook publishes to Pages only. Personal profiles are not available through any official API.
- Reconciliation recognises the plugin's own post by comparing its text with the account's recent
  posts. An account posting very heavily from elsewhere at the same moment could in principle
  confuse that comparison.
- Only one image per post. Carousels and video are not supported.
- Meta and LinkedIn retire their API versions on a timetable. Each of those platforms has an API
  version box in its card so a version can be changed without waiting for a plugin update.
