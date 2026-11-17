=== InkRush ===
Tags: news, automation, ai, rss, publishing
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.11.9
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered WordPress news automation. Finds stories, rewrites them through OpenRouter, adds Wikimedia Commons images and publishes on a schedule.

== Description ==

**InkRush: Discover. Create. Publish.**

InkRush is an AI-powered WordPress news automation plugin for one site. Its technical name stays `zzz-copy-das-sobur` (the folder inside the ZIP, the slug and the text domain), and its web user agent stays `ZZZCopyDasSobur`, so existing sites, settings, data and publishers' robots.txt rules keep working.

After setup, press Start Automation. The plugin keeps working in the background until you stop or pause it. Closing the browser or logging out does not stop it.

1. Finds stories from Google News RSS and publisher feeds.
2. Applies recency and keyword filters and checks for duplicates. With Past-Hour Posting, on by default, each post uses one story that is no more than 12 hours old when it goes out, or is skipped. The age limit can be set from 1 to 12 hours.
3. Reads the original publisher page when it is accessible. Paywalled or blocked pages are recorded and skipped.
4. Sends the source and your exact saved prompt to your OpenRouter model.
5. Generates 20 tags with your exact saved tag prompt.
6. Validates the result and holds anything that fails.
7. At each publishing slot, finds a featured image on Wikimedia Commons with rules only, then creates a draft or published WordPress post.
8. Credits each post only to a user you authorised on the Authors page. Nobody gets access through their WordPress role. In Multiple users mode, each author posts on their own schedule and is credited with those posts.
9. Optionally stops by itself after a number of days or published posts, whichever comes first.

10. Optionally distributes each published article to Facebook Pages, Instagram, X, LinkedIn, Threads and Bluesky, through those platforms' own official APIs. This is switched off until you connect an account and turn a platform on.

The outside services are your news sources, OpenRouter, Wikimedia Commons, and any social media platform you choose to connect. There are no third-party plugins, libraries, fonts, CDNs or telemetry.

Developed by: Sagor Das
Developer Contact: das.sagorwork@gmail.com

= Your responsibility for what is published =

InkRush is a tool, and you are responsible for what it publishes. A story or image being publicly accessible does not make it free to republish. The plugin does not check copyright, facts or other legal matters for you. Review AI-written articles for accuracy, especially in Automatic Publish mode, and follow each publisher's terms and each image's license.

== External services ==

This plugin connects to these outside services. It sends nothing to the developer.

* **OpenRouter** (https://openrouter.ai/), to write articles and tags. It receives your API key, the publisher text, your prompts, the model identifier and your site address, each time an article or its tags are written, and when you test the connection. Terms: https://openrouter.ai/terms. Privacy: https://openrouter.ai/privacy.
* **Wikimedia Commons** (https://commons.wikimedia.org/), to find and download featured images. It receives search terms made from each article. Terms: https://foundation.wikimedia.org/wiki/Policy:Terms_of_Use. Privacy: https://foundation.wikimedia.org/wiki/Policy:Privacy_policy.
* **Google News RSS** (https://news.google.com/), to find stories, when "Use Google News RSS" is on. It receives your topic addresses, search queries, language and region. Terms: https://policies.google.com/terms. Privacy: https://policies.google.com/privacy.
* **Publisher websites** you add or that Google News links to, to read feeds, robots.txt and article pages.

Requests to Google News, publishers and Wikimedia Commons carry a user agent that names the plugin and your site address.

= Social media platforms =

These are contacted only for a platform you have connected and switched on. With Social media distribution off, which is how it installs, none of them is contacted at all.

Each one receives, for each article it publishes: the post text InkRush generated from your published article, your article's web address, the featured image (either its address, so the platform can fetch it, or its bytes, uploaded directly), the image's alt text, and the hashtags. It also receives the access credential you authorised. None of them receives your OpenRouter key, your prompts, the original publisher's text, your WordPress login, your visitors' data or anything about any other article.

* **Facebook Pages**, through the Meta Graph API (https://graph.facebook.com/). The featured image is fetched by Meta from your site's public address. Terms: https://www.facebook.com/terms.php. Privacy: https://www.facebook.com/privacy/policy. Platform terms: https://developers.facebook.com/terms/.
* **Instagram**, through the Instagram Content Publishing API (https://graph.facebook.com/). The featured image is fetched by Instagram from your site's public address. Your article's web address is not placed in the caption, because Instagram does not make one clickable. Privacy: https://privacycenter.instagram.com/policy.
* **X**, through the X API v2 (https://api.x.com/). The featured image is uploaded directly as bytes. Terms: https://x.com/en/tos. Privacy: https://x.com/en/privacy. Developer terms: https://developer.x.com/en/developer-terms.
* **LinkedIn**, through the LinkedIn Posts API (https://api.linkedin.com/). The featured image is uploaded directly as bytes. Terms: https://www.linkedin.com/legal/user-agreement. Privacy: https://www.linkedin.com/legal/privacy-policy. API terms: https://www.linkedin.com/legal/l/api-terms-of-use.
* **Threads**, through the Threads API (https://graph.threads.net/). The featured image is fetched by Threads from your site's public address. Terms: https://help.instagram.com/769983657850450.
* **Bluesky**, through the AT Protocol (https://bsky.social/). The featured image is uploaded directly as bytes. Terms: https://bsky.social/about/support/tos. Privacy: https://bsky.social/about/support/privacy-policy.

Access credentials for these platforms are stored encrypted in your own database and are never sent to the developer, to OpenRouter, or to any platform other than the one they belong to. They are removed from everything the plugin writes to its log.

Three of these platforms need the developer application to be approved before it can publish to an account other than your own test account, and one of them charges for the access level needed to publish at a useful rate. Each platform's card on the Social Media page states its own requirements, approvals, costs and limits before you try to connect it.

== Installation ==

1. Upload the plugin ZIP in Plugins > Add New > Upload Plugin, and activate it.
2. On AI Settings, save your OpenRouter API key and press Test API Connection.
3. Review the categories on News Sources, and the schedule and timezone on Schedule.
4. On Authors, grant access to the user to be credited with posts, then choose them as Post author on Publishing.
5. Press Start Automation on the Dashboard.

To publish to social media as well, which is optional and off until you set it up, see docs/SOCIAL.md. In short: on the Social Media page copy the callback address shown there into each platform's developer settings, save that platform's application identifier and secret, press Connect account, then switch the platform on. Bluesky needs no developer application: it takes a handle and an app password.

See docs/INSTALL.md for details. A server cron job is strongly recommended: see docs/SERVER-CRON.md.

== Frequently Asked Questions ==

= Does it publish without my approval? =

In Automatic Publish mode, the default, yes, for articles that pass the checks. Choose Draft mode to approve each post yourself.

= Do I need an OpenRouter account? =

Yes. Articles are written only through OpenRouter, with your own API key, and you pay OpenRouter for what you use.

= Can it read paywalled articles? =

No. It does not bypass paywalls, logins or robots.txt rules.

= Does it guarantee a number of posts a day? =

No. Each post needs a suitable, readable story and a usable image. When there is none, fewer posts appear, and the reasons are in Logs.

= Does it collect data about my visitors? =

No. It has no tracking or telemetry.

== Documentation ==

* docs/INSTALL.md: installing, configuring, starting, pausing, stopping and monitoring
* docs/SERVER-CRON.md: reliable background timing
* docs/TROUBLESHOOTING.md: common problems and what to do
* docs/OPERATIONS.md: backup, upgrade, rollback, deactivation and uninstall
* docs/IMAGES.md: how featured images are chosen, credited and kept from repeating, and their limits
* docs/ARCHITECTURE.md: files, data model, article states and the Google News access method
* docs/QA-CHECKLIST.md: manual checks for a staging site
* docs/TEST-RESULTS.md: what was tested, what was not, and the first run on a real site

== Changelog ==

= 1.11.9 =
Built on 1.11.8, with no humanizer. Better image searches, and no more pictures that only share stray words with the story.

**Why.** The Doha Film Festival post of 17 November got a close-up of purple mushrooms. "November" had been taken as the story's subject, and the mushroom photo shared two words with the article: "November" and "Focus", from Deadline's "Festival In Focus" headline and the photo's "focus stacking".

**Dates are not subjects.** Months and weekdays are never searched for or counted as a shared word. A name such as April Ryan still counts.

**Better searches.** A name of two or more words is searched as an exact phrase ("Doha Film Festival"), so Commons stops matching the words one by one. Only real names are paired into one search, so "Yemeni film" is no longer joined with another subject. A year in a tag is left out.

**Stricter picture check.** How a photo was taken ("focus stacking", "close-up", "taken on…") no longer counts as a label. One of the shared words must come from what the story is about: its subjects or its tags. A photo of mushrooms, flowers, birds, insects and the like is refused when the article never mentions them.

**Searching no longer stops on a stray match.** The searches stop early only for a file that names one of the story's subjects.

**A view of the place before a skip.** When no picture of the subject can be used, the plugin looks for a skyline or cityscape of the place the story happens in, such as Doha. A file that matches only the story's words is tried after that, so a post is still not skipped.

= 1.11.8 =
Picture searches that find the right thing.

**A one-word subject is searched with a word of context.** Many one-word names are also ordinary words. A search for "Halo" on Wikimedia Commons finds over 350,000 files, mostly of light around the sun, while "Halo Xbox" finds the game. "Firefight" alone finds firefighting. A one-word subject is now searched together with another name from the article's tags, such as "Xbox". The word on its own is only searched last, if there is room. When the tags offer no such word, the search is the same as before.

= 1.11.7 =
Better matching between a story and its picture.

**A picture needs two words in common with the story, not one.** The 22:00 post on 15 November, about the Halo video game, was published with a photo of a halo around the sun. The only thing they shared was the word "halo". A picture's labels now have to share at least two words with the story (for example "Halo" and "Xbox"), so a word that means something else in the picture is no longer enough.

**Wikimedia's upload labels no longer count.** Commons files are filed under labels such as "Uploaded via Campaign" or "Self-published work". These say how a file was uploaded, not what it shows, and the sun photo earned points for the word "campaign" from one of them. They are now ignored.

= 1.11.6 =
Fewer skipped posts and fewer wasted articles. No extra AI spending.

**A finished article is no longer thrown away over its tags.** If the AI kept returning the wrong number of tags, a scheduled post's finished article was held for review. The post then paid for a new article from another story, or was skipped. Now the article goes out with the tags it has, and the log notes it. Articles outside Past-Hour Posting are still held as before.

**A story whose page won't load is dropped at once.** Past-Hour Posting already moved on when a publisher couldn't be looked up. It now does the same when the page times out, the site asks it to slow down (HTTP 429), or the site has a server error (HTTP 5xx), instead of waiting and trying the same page again. That publisher is passed over for the rest of that post.

**Other categories are tried sooner when the planned one is empty.** Once the planned category has been searched and has no untried stories left, the other categories are searched straight away, instead of after three failed stories or in the last 90 minutes. They are also searched within the same minute, not at the next 15-minute search round. The "Category fallback" setting still decides whether this happens at all.

**Uninstall removes social media data too.** With "delete all plugin data" ticked, the social accounts, social post history and social app settings are now deleted along with everything else.

= 1.11.5 =
Feeds for the four categories that gained none in 1.11.3, and two feeds removed that could never be used.

**Insurance gains 3 feeds:** Artemis, Carrier Management and Reinsurance News.

**Crime gains 3 feeds:** New York Post Crime, The Guardian US Crime and The Trace.

**Myths & Legends gains 2 feeds:** TheCollector and La Brújula Verde. The category's required words (myth, legend, ancient, folklore) still decide which of their stories are used.

**Fitness gains 2 feeds:** The Guardian Fitness and mindbodygreen.

**Removed:** Live Science from Myths & Legends, because its articles are all marked as behind a paywall, and Defense News from War & Conflict, because its newest items were videos with almost no text. Feeds are added before any are removed, so no category is left without one.

Each new feed was checked on 15 November: its robots.txt allows this plugin, and at least two of its newest three articles could be read in full, with no paywall. Updating adds them after the feeds you already have, and your other feeds are kept.

= 1.11.4 =
**iHeartPublix is removed from Coupon Blogs.** It was in the category's starting list, but its posts are about 120 words, under the 250-word minimum, so no story from it could ever be used. Updating removes it from your Coupon Blogs category and logs that it did. Coupon Blogs keeps Hip2Save and Money Saving Mom, added in 1.11.3. Your other feeds and categories are not changed.

= 1.11.3 =
More chances for every post, a warning before one is skipped, and more feeds for 15 categories.

**More image files are checked, and each post can write one more article.** The new defaults are 5 image searches per article instead of 4, 40 files checked per search instead of 20, 4 articles before giving up on a post instead of 3, and 24 article generations per day instead of 18. Updating raises your settings to these only where yours are lower. A higher value you chose is kept.

**You are warned when a post is at risk.** When a post is 60 minutes away and has no article ready with its image, the log says so, and the site's administration email address gets one message for that post, saying what was tried and what can help. You can turn the email off on the Schedule page.

**Stopping the automation warns you about a post being prepared.** The Stop button's confirmation now names any post whose preparation has started, because stopping then skips it.

**More publisher feeds for 15 categories:** World, Entertainment, Celebrity, Politics, Science, Sports, Medical, War & Conflict, Celebrity News, Coupon Blogs, Referral Programs & Blogs, Gaming, Health & Wellness, Finance & Retirement and Technology & Consumer News. Each was checked on 15 November: its robots.txt allows this plugin, and at least two of its newest three articles could be read in full, with no paywall. Updating adds them after the feeds you already have. Nothing is removed, and a feed you already list is not added twice. No feed passed for Insurance, Crime, Myths & Legends or Fitness, so those are unchanged.

= 1.11.2 =
More publisher feeds for Business and Technology. Publisher feeds give the story's own link, so they avoid the Google News lookups that failed for five of the eight stories tried for the 10:00 post on 15 November. Both Business feeds had nothing new that morning.

**Business gains 4 feeds:** The Guardian Business, ABC News (Australia) Business, The Conversation (Australia) Business and CBS MoneyWatch.

**Technology gains 10 feeds:** Engadget, TechRadar, The Guardian Technology, CNBC Technology, Gizmodo, 9to5Mac, Wired, ZDNet, MIT Technology Review and iTnews.

Each feed was checked on 15 November: its robots.txt allows this plugin, and its newest three articles could be read in full, with no paywall. Updating adds them to your Business and Technology categories, after the feeds you already have. Nothing is removed, a feed you already list is not added twice, and other categories are not changed. You can edit the lists on the Categories page.

= 1.11.1 =
Fixes the skipped 10:00 post of 15 November. Its search gave up at 07:40 after 8 stories, with more than two hours left and 13 stories still to try. Seven of the eight failed before any article was written, and the one article written found no picture scoring 65.

**A post keeps trying until its time.** Up to 30 stories can be tried for one post instead of 8. Most fail before any AI is used, and each comes from a different publisher. Articles to write before giving up on a post, and the daily generation limit, still cap the spending.

**Other categories are tried after 3 failed stories**, as well as in the last 90 minutes, so one bad category cannot use up the post.

**A publisher that cannot be read is passed over for a day.** A publisher with no readable feed, whose headlines do not match its feed, that refuses access or has a paywall, is passed over for every post for 24 hours, not only the post it failed for.

**A publisher that is briefly unreachable is not waited for.** The post moves on to another story at once, instead of waiting 16 minutes, up to three times, on one story.

**The last article a post can get may use a picture scoring 50 or more.** When dropping it would leave the post with nothing, or the post is less than 45 minutes away, the image minimum is 50 instead of 65. Licenses, the wrong-person, child and shape refusals, and every other rule still apply. All other articles keep the normal minimum.

= 1.11.0 =
Fewer skipped posts. The owner asked for these four changes on 14 November, after the site's log showed 17 of 26 posts skipped.

**Stories can be up to 12 hours old.** The maximum story age on the Schedule page now goes from 60 to 720 minutes, and upgrading sets it to 720. Posts still go out at their set times, and your preparation time is kept. In Single user mode the age is still never longer than the interval between posts.

**A publisher that could not be reached is passed over for the rest of that post.** When a story fails because its publisher's page cannot be found, the publisher refuses access, or the page is behind a paywall, other stories from that publisher are not tried for the same post. They can still be used for a later one. The log says when this happens.

**A picture with the Wikimedia Commons "personality" notice is accepted.** The notice says the picture shows an identifiable person. It is not a limit of the license. Files with any other restriction, such as "trademarked", are still refused.

**A square picture is accepted.** A portrait picture, taller than it is wide, is still refused.

**Fewer articles are written twice.** An article was sent back to be written again whenever its last line had no closing punctuation, which happened 15 times in the logs. When the AI provider reports that the model finished the article itself, a last line such as "Source: Variety" or a figure is now accepted. An article that plainly breaks off, on a comma or on a word such as "the", is still written again, and so is one that reached the output token limit.

**Words from a Title Case headline are searched for only when the article confirms them as a name.** "Sales Rose", "Remake Gets" and "Formal Cooperation" were each searched for as if they were names.

Nothing else changed: the schedule, the licenses, the minimum image score, the credits and the limits on articles work as before.

= 1.10.5 =
Stops image searches that were built from words which are not names.

The logs of 9 to 14 November showed searches such as "Check Nvidia", "Use Kuaishou", "Which Baltic" and "No. Nvidia". Wikimedia Commons matches every word of a search, so each of these found nothing useful, and the article written for the post was set aside for having no image.

**A word that only opens a sentence or a heading is no longer part of a name.** "Which", "Check", "Use", "No", "Through" and similar words are dropped from the start and end of a name, and so are short months such as "Nov". "Jan", "Mar" and "Jun" are kept, because they are also first names.

**A name no longer runs from one paragraph, list item or table cell into the next.** A table cell that says "Check" beside a cell that begins "Nvidia" was read as one name.

Nothing else about images changed: the licenses, the rules, the minimum score and the source are the same.

= 1.10.4 =
Searches for images by the headline first, as the owner asked.

**The people in the headline are now searched for together, before anything else.** A story headlined "Trump says he offered UFC president Dana White the White House press secretary job" is searched for as "Donald Trump Dana White". A story about two people is best illustrated by a picture of the two of them, and that is what a reader expects to see.

A tag supplies a name in full where news writing does not, so a headline that says only "Trump" is searched as "Donald Trump". Describing words are left out, because Wikimedia Commons matches every word of a search: "President Donald Trump" asks for pictures labelled with the office as well as the man, and finds fewer of him than "Donald Trump" does.

Three things keep the pairing honest. Only the main subject or a name among the tags may go into a pair, so a capitalised phrase from the body such as "Finds AI" is never paired with anything. A tag written in lower case is a subject and not somebody's name, so "customer service" is still searched on its own terms. And two names that share a word are never paired, because "Dana White" and "White House" are one subject and a building that repeats its word, not two subjects. Two bare one-word subjects, such as "Ghana" and "China", are searched one at a time, since asking for both at once would find nothing.

**Between two pictures that score alike, the one that names more of the article's subjects is now chosen.** A photograph of both people in a headline is preferred to a photograph of one of them. It is worth a few points of ordering only, so it settles near-ties and never overrides a picture that is plainly the better match, and the score you see in the log and on the Images page is the real one.

Nothing else about the workflow has changed. Images are still chosen by rules with no AI, still from Wikimedia Commons only, and every rule that refuses a picture still refuses it however well it scores.

= 1.10.3 =
Makes the daily generation allowance safe for the whole day, so no post time can be left with nothing by an earlier one.

**One post could spend the day's article generations.** Every generation attempt counts against **Maximum article generations per day**, the failed ones included, and each post time may have up to three articles written for it. One post having a bad run of unusable stories could therefore spend the whole day's allowance, and the later post times were skipped for want of generations rather than for want of news. One generation is now held back for each post time still to come that day. A post may use everything above that reservation and no more, so every post time of the day can always write at least one article, and the limit itself is still absolute.

The Schedule page says where the limit stands against the day: too low to give every post one article, or enough for one each but not enough for them all to try again. The default is now 18 rather than 12, which covers three post times at three articles each with room for failures. Your own setting is unchanged, and the page will tell you if it is tight.

Nothing about how articles, images or social posts are chosen has changed in this version.
= 1.10.2 =
Two findings from an audit of everything that can cause a post time to be skipped.

**A stalled worker went unreported when only social distribution was running.** Worker health was judged by whether news automation was running. Since 1.10.0 the worker also runs on its own for social distribution, so with automation stopped and social distribution on, a worker that had died reported no fault at all and the Dashboard said it was not running when it was. Worker health now follows whichever of the two is wanted, and the Dashboard says which of them the worker is running for.

**The daily generation limit could quietly empty the last post of the day.** Every generation attempt counts against **Maximum article generations per day**, including the failed ones, and each post time may have up to three articles written for it. Three post times can therefore need nine generations before a single failure, against a limit of twelve. A day with a few failures at OpenRouter reached the limit, and the last post time was then skipped for want of generations rather than for want of news. The Schedule page now says so whenever the limit is lower than the day's post times could need, and names the number to raise it to.

Nothing about how articles, images or social posts are chosen has changed in this version.
= 1.10.1 =
Fixes three faults found on the owner's site on 2026-11-07 and 2026-11-08.

**An article was published with a photograph of someone it was not about.** The 10:00 post, about Dana White being offered the press secretary job, was illustrated with a photograph of Barack Obama and a child, which scored 81. Three things combined. A surname used on its own, "White", was credited to the highest-scoring name containing that word, which was "White House" rather than "Dana White", so the building became the subject and the man disappeared. Searching then stopped after the very first query because one file scored above 75, so no later query could correct it. And nothing checked whether the chosen picture was of a person the article never mentions.

Now a surname joins the name it ends, so "White" belongs to "Dana White" and not to "White House", and an ambiguous short form is left alone rather than guessed at. Searching never stops on the first query alone. A picture whose own title names a person the article does not mention is refused outright, whatever it scores, and the reason names that person.

**A photograph of a child passed the protection meant to stop exactly that.** The rule that waives the child, victim and identifiable-people checks when the article is about those people was satisfied by any subject, including a place. An article mentioning the White House therefore switched off the child protection for a photograph filed under the White House. Only a person's name waives it now.

**The 16:00 post was skipped although two good articles had been written for it.** Both failed to find an image because their subjects were wrong: "Ghana and China" was read as one name and searched as a phrase, and a row of chart labels, "Measure Achievers Challengers Aspirants Share", became another article's subject. "and" no longer joins two names, so Ghana and China are searched separately, and a run of four or more capitalised words is only used when the title, the headline or a tag confirms it. A short run of capitals before a full stop now ends a name, so "the UFC. White has run" is no longer read as a name called "UFC. White".

**Releasing an image did not release it.** Releasing a reservation on the Images page left the article's own image record pointing at that image, so the article published with it anyway. It now searches again, unless the image is already on a published post, which is left alone.

**Articles to write before giving up on a post** is a new setting on the Schedule page, from 1 to 5 and now 3 rather than a fixed 2, because two was too few when both were lost to the image step. Maximum article generations per day still caps the day's spending.

= 1.10.0 =
New Social Media Distribution. Once WordPress confirms an article is publicly published, InkRush can post it to Facebook Pages, Instagram, X, LinkedIn, Threads and Bluesky, each through that platform's own official API. It is switched off on upgrade and publishes nothing until you connect an account and turn a platform on.

Two new screens. Social Media holds six connection cards, each with its status, its accounts, a connection test, and that platform's own requirements, approvals, costs and limits written out in full. Social Publishing holds the figures, the approval queue and the full publishing history, with filters by platform, result and date, and buttons to retry, edit, cancel or read the error.

Each platform is published independently: one article becomes one job per platform, so a Facebook post stays published when Instagram fails, and a social media failure never changes the article, which is already on the website. Post text is written for each platform in its own tone and length through your existing OpenRouter key and model, from your published article only, using a new Social post prompt you can edit and whose versions are kept like the others. Captions can be edited by hand or rewritten one platform at a time, and Manual approval mode holds every post for you to read first.

Social publishing is deliberately independent of news automation. Posts already queued keep going out after Stop or Pause, because the article they belong to is already published. A post is never duplicated: every record carries an idempotency key that is unique in the database, and an attempt whose outcome could not be read is checked against the platform before anything else is sent, rather than being retried or called a failure.

Drafts are never distributed. A draft published by hand is distributed only if you switch that on. A scheduled post is distributed when it actually goes public. Deleting an article removes nothing from any platform. Credentials are stored encrypted, are never sent to the browser, and are stripped from the log. Database version 12 adds two tables and changes none of the existing ones.

= 1.9.1 =
For testing. Image searches no longer use the news outlet as the subject: the publisher, and an outlet the article quotes such as "Nippon News Network", are left out unless the title or headline names them. Words that only begin a sentence, such as "Is" or "Man", a sentence word at the end of a name, and plain numbers such as "000" are no longer searched. At least one search no longer uses the two main names, for example a place in the article, so a wrong subject cannot use up every search. When no image passes, the log gives the minimum score and the best score reached.

= 1.9.0 =
For testing. New Multiple users mode, chosen in the new Posting mode card on the Authors page. Each active author can have their own schedule: a timezone, days of the week, From and Until dates, dates to skip, up to 24 post times at least 15 minutes apart, 1 to 5 posts at each time, their own categories, limits per day, week (Monday to Sunday) and month, and whether a missed time is published at their next time or skipped. Each post is credited to the author whose time it is. A time within 15 minutes of another author's time is refused when saving, checked over the next 14 days in every timezone, and a warning is shown when the times together go above Maximum posts per day. Right before each post, the author must be active and under their limits; otherwise that time is closed and logged, and the article waits for another author. No research is started for an author who cannot post. Maximum posts per day, Maximum article generations per day and Automatic stop still apply to the whole site, and Past-Hour Posting works in both modes. The Schedule page lists each author's times for today, and the Dashboard shows who the next post is by. Single user mode, with one Post author, stays the default and works exactly as before.

= 1.8.0 =
For testing. New Automatic stop on the Schedule page: automation can stop by itself after a number of days, or a number of published posts, whichever comes first. Both are counted from the last press of Start Automation, count again from 0 at the next Start, and are off by default. Reaching a limit works exactly like the Stop button: future jobs are cancelled, a step already running finishes, and queued articles and history are kept. Drafts do not count as published. The Dashboard shows the limits and how far the current run has got, and says when and why automation stopped by itself. Start Automation and the Stop button work exactly as before.
The plugin is now named InkRush, with the tagline "Discover. Create. Publish.", on the Plugins page, the admin menu, page titles and messages. Each admin page ends with the developer attribution. The release ZIP is named inkrush-1.8.0.zip, and a log export downloads as inkrush-logs-(date).csv. The folder inside the ZIP, slug, text domain, settings, data, hooks, page addresses and user agent are unchanged, so uploading the ZIP still upgrades an existing copy.

= 1.7.0 =
For testing. New Authors page: grant, pause or revoke which WordPress users can be credited with posts, one at a time or in bulk, with search and filters by role and access. Nobody is authorised automatically, administrators included, and WordPress roles are never changed. Right before each post the plugin checks that its author is active. If not, the article is held, and no new articles are written until an author is active. The Post author list shows only active authors, and "First administrator" is gone. Logs show names, the credited author and a filter by person, and record who changed access and who published a plugin draft by hand. Upgrading authorises the user already credited, and nobody else.

= 1.6.0 =
For testing. Past-Hour Posting now uses stories up to 2 hours old, and preparation starts 2 hours before each post. The new "Maximum story age" setting on the Schedule page goes from 60 to 180 minutes, and the preparation time from 15 to 180, never more than the story age. Neither is ever longer than the interval between posts. Other categories are tried in the second half of the window, 60 minutes before a post. Google News is asked for the same window. The upgrade moves a preparation time still at 60 minutes to 120, and keeps one you changed. Posts stay 6 hours apart.

= 1.5.0 =
For testing. Every post now needs a featured image. An article with no usable image is held in Failed or held and never published, and its image search runs once more 6 hours later. With Past-Hour Posting on, another story is chosen instead. Images are found as soon as an article is ready, so a post time takes an article that already has one. A post from this plugin cannot go public, by hand or on a schedule, unless its featured image is set and its file opens on the server. The switches that allowed a post without an image are removed. When a strong match ends the searching but its file cannot be used, the other searches now run. Posts already published are not changed.

= 1.4.2 =
For testing. Faster, more accurate image search. A 1920-pixel copy is downloaded instead of the original, which is often 10 times larger. Commons is asked only for files at least as large as the minimum image size. Searching stops once a file scores 75 or more. The AI tags confirm names, and a single word such as "Channel" is searched alone only when it is also a tag.

= 1.4.1 =
For testing. Fixed: an article that wrote "Russell Brand's" and then "Brand" searched Wikimedia for the word "Brand", so no photo passed. Names now drop a final 's, and a surname used on its own counts toward the full name. Fixed on the Dashboard: a story that was dropped no longer shows as the chosen one, and Next discovery says when preparation is already under way.

= 1.4.0 =
For testing. New: Past-Hour Posting, on by default, with an off switch on the Schedule page. Preparation starts 60 minutes before each post (15 to 60). The plugin searches every 15 minutes for a story that will still be no more than 60 minutes old at the post time, writes one article for it, finds its image, checks the story's age again just before publishing, and publishes at the post time. From 30 minutes before, it also tries other categories, one at a time. With no such story the post is skipped, the reason is recorded, and it is never caught up. Turned off, the earlier workflow runs as before. Technology, Entertainment, Business, Science, Sports, and Health & Wellness also get a Google News search, unless you changed their topic address or search. You can edit or remove it on the News Sources page.

= 1.3.4 =
For testing. CC BY-SA images are now accepted, as the owner chose, with the same checks and credit as CC BY. They can be turned off on the Images page. Do not edit these images: an edited version must be shared under the same license.

= 1.3.3 =
For testing. A long wait asked for by Wikimedia is now respected: nothing is sent until it ends, and the article waits without using up an image attempt.
A rate limit partway through the image searches no longer ends the image attempt. The searches that were missed are tried again, and earlier results are kept.

= 1.3.2 =
For testing. The image credit now names the file, and a copy is saved at the end of the post text, so the credit stays if the plugin is deactivated or removed. Existing posts get the copy on upgrade.
Research for a category now starts only when its next slot is close, 4 hours by default, so news is not chosen today and published tomorrow. This can be changed on the Schedule page.

= 1.3.1 =
For testing. A brief network problem no longer blocks a story or an image for good: stories, feeds and Google News matches wait and try again.
Title Case headlines now give real names for image searches, not the whole headline.
A common word or part of a word can no longer make a photo of people count as being about the article.
Pause Publishing now also stops the finishing of a post after a crash. It finishes after Resume.
Security: private addresses written in IPv6 form, such as ::ffff:127.0.0.1, are now always refused. Older versions could accept them on some PHP versions.

= 1.3.0 =
For testing. Automatic featured images from Wikimedia Commons, chosen by rules, with no AI. Only CC0, public domain and CC BY are accepted. A credit line shows below the image, and an image is never used twice. New Images screen, and image details on Dashboard, Articles, Usage and Logs. If no image qualifies, the article publishes with none by default.

= 1.2.1 =
The state label on the Dashboard now updates in the background, so Starting changes to Running without a reload.

= 1.2.0 =
Automatic Publish and automatic WordPress category creation are now the defaults, as the owner chose. Articles that fail checks are still held.

= 1.1.0 =
Starting list is now the owner's 21 categories. Existing installs gain the missing ones, and existing categories are not changed.
A category added later waits until it has a source and a WordPress category, and the News Sources page shows its status.
One ready article per category by default, so spare articles do not go stale and waste AI spend.

= 1.0.0 =
First release.
