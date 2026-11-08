# Manual QA checklist

Use this on a staging copy of your site if you have one. Automatic Publish is on by default, so on a live site, posts appear as soon as a slot arrives after Start.

## Setup

- [ ] Plugin installs from the ZIP and activates with no errors
- [ ] The Plugins page shows "InkRush" by Sagor Das, and the menu shows "InkRush"
- [ ] Each plugin page shows "InkRush" with "Discover. Create. Publish." at the top, and "Developed by: Sagor Das" with the contact address at the bottom
- [ ] All 21 categories appear, and can be edited, disabled, reordered, duplicated and deleted
- [ ] Add category works, and the new category shows Ready once it has a source and a WordPress category
- [ ] Article and tag prompts match what you supplied
- [ ] API key saves, shows only its last four characters, and is not in the page source
- [ ] Test API Connection succeeds, and fails clearly with a wrong key
- [ ] Schedule page shows 10:00, 16:00 and 22:00 in your timezone, with the UTC times

## Sources

- [ ] Test search finds stories for each category
- [ ] The filter shown matches the recency you chose
- [ ] An excluded keyword filters out a matching story

## One article

- [ ] Test generation gives a title, body with sections and table, and 20 tags
- [ ] Create test draft makes a WordPress draft with those fields, and no image (test drafts do not use the image step). Publishing it by hand keeps it a draft until a featured image is set

## Automation

- [ ] Start refuses while something is missing, and says what
- [ ] After Start, the state goes from Starting to Running
- [ ] Close the browser and log out. On return, the heartbeat has moved and work continued
- [ ] Articles reach the queue, and blocked sources show a reason with no article written
- [ ] At a slot time, a post goes live with the right title, body, category and 20 tags
- [ ] Source address and publisher are in the post's data (`_zzzc_source_url`, `_zzzc_publisher`)
- [ ] Read several posts against their sources for accuracy

## Controls

- [ ] Pause Research: no new discovery, and queued articles still publish
- [ ] Research starts about 4 hours before a slot, not the day before
- [ ] Deactivate the plugin: a post's image credit still shows, at the end of the article. Reactivate: it shows once, below the image
- [ ] Pause Publishing: no new posts, no unfinished post is finished, and research continues
- [ ] Stop asks for confirmation, then cancels future work and keeps the queue
- [ ] Automatic stop: set 1 published post on the Schedule page, press Start, and after the first post the Dashboard says "Automation stopped by itself", with the reason. The next queued article is kept
- [ ] Automatic stop: Start again, and the Dashboard counts from 0 again. Set both limits to 0, and automation runs until Stop
- [ ] Start again creates no duplicates
- [ ] Retry and Skip work on a failed article

## Featured images

- [ ] Images page: Test Wikimedia connection succeeds
- [ ] Images page: Preview image search shows files with score, license, creator and result
- [ ] A published post has a featured image in your real theme or page builder
- [ ] The credit line shows once, below the image or at the top of the text, and all three links open the right pages
- [ ] Look at each picture yourself: does it suit the story? The plugin reads labels and cannot see pictures
- [ ] Articles shows the image status, creator, license and source
- [ ] No image repeats across several days of posts
- [ ] Every published post from the plugin has a featured image. An article with no usable image shows in Failed or held, and the Dashboard counts it
- [ ] Publishing one of the plugin's drafts by hand, after removing its featured image, leaves it a draft
- [ ] Delete a test post and its image: the Images page still lists that image as used

## Authors

- [ ] After the upgrade, the Authors page shows only the user already credited as Active, and Logs (stage `config`) names them
- [ ] Search, the role filter and the access filter each narrow the list. Emails show only on this page
- [ ] Grant access, Pause, Resume and Revoke work on one row and in bulk. Revoke asks to confirm. Each change appears in Logs (stage `access`) with your name
- [ ] The WordPress role of a user you grant access to is unchanged on the Users screen
- [ ] The Publishing page lists only active authors as Post author
- [ ] Pause the Post author: the Dashboard says posts are not being published, the next article is held in Failed or held with the reason, and Retry publishes it once they are active again
- [ ] A post's author on the site is the Post author, and Logs > Person shows their history
- [ ] Publishing a plugin draft by hand is recorded in Logs with your name
- [ ] An editor cannot open the Authors page

## Multiple users mode

- [ ] On a fresh install and after the upgrade, Posting mode shows Single user mode, and posting is as before
- [ ] Multiple users mode cannot be chosen until an active author has a schedule that is on, with a post time
- [ ] Open Schedule next to an active author, set a timezone, days, two times and a weekly limit of 2, and save. The row shows the times, and Logs (stage `access`) shows who saved it
- [ ] A second author with a time 10 minutes from the first author's time is refused, and the message names the first author. 15 minutes apart saves
- [ ] After switching to Multiple users mode, Schedule > Today's slots lists each author's times with their names, and Dashboard > Next publish slot says who it is by
- [ ] A post made at an author's time is credited to that author on the Posts screen and in Logs
- [ ] Pause an author: their next time is closed with the reason in Logs, and the article is posted at another author's time
- [ ] An author with categories ticked gets only articles from those categories
- [ ] After an author reaches their limit, their further times that day or week are closed with the reason
- [ ] Switch back to Single user mode: posts go to the Post author at the Schedule page's times again
## Past-Hour Posting

- [ ] The Schedule page shows it ticked, with a maximum story age of 120 minutes and preparation 120 minutes before, and the Dashboard shows On
- [ ] Saving a preparation time longer than the story age is refused, with the field marked
- [ ] Preparation starts at the right time before a post, in your timezone, and Logs (stage `past_hour`) show each search with its sources and window
- [ ] The Dashboard shows the chosen headline, source, publication time, ages, category and status
- [ ] The publication time shown matches the time on the original article page
- [ ] The post goes out at its time, with a story under 120 minutes old, and with its image if one was found
- [ ] When nothing suitable exists, the post is skipped with a clear reason, no article appears in Usage, and nothing goes out later
- [ ] Fallback: a post can use another category from 60 minutes before its time, and is filed under that category
- [ ] Unticked and saved, the earlier workflow returns: the buffer fills ahead of time, and older stories are used

## Limits

- [ ] The daily maximum is not exceeded across a full day
- [ ] The count resets at local midnight, not UTC midnight
- [ ] Usage shows tokens and reported cost that agree with your OpenRouter account

## Social media distribution

Skip this whole section if you are not using it. Checks marked **live** need a real connected
account and will publish a real post, so do them on a test Page or account first.

### Before connecting

- [ ] Social Media page opens with distribution off and no account connected
- [ ] The callback address is shown and can be selected in one click
- [ ] A warning appears if the site is not served over https
- [ ] Each platform's card states its approval requirement, its cost and its limits
- [ ] Switching a platform on without an account is refused with a clear message

### Connecting

- [ ] Saving an application identifier and secret succeeds, and the secret is then shown only as its last four characters
- [ ] **live** Connect account opens the platform, and returning connects the account
- [ ] Returning to the callback with a changed or missing state value is refused and nothing is saved
- [ ] Bluesky refuses a value that is not shaped like an app password, without sending it anywhere
- [ ] Bluesky connects with a real handle and app password
- [ ] Test connection reports the account and does not print a credential
- [ ] Reconnect replaces the credentials of an account already connected rather than adding a second row
- [ ] Disconnect deletes the credentials, cancels what is pending, and keeps the published history

### Publishing

- [ ] A draft article queues nothing
- [ ] **live** A published article queues one record per platform that is on, and publishes it
- [ ] The post on the platform carries the right text, the article link and the featured image
- [ ] Instagram's caption has no web address and ends with the call to action
- [ ] X's post is within 280 characters including the link
- [ ] Bluesky's link and hashtags are clickable, not plain text
- [ ] LinkedIn's post is not cut short where a bracket or a dash appears, and its hashtags are links
- [ ] Threads carries one hashtag only
- [ ] The history row shows the platform, the account, the result, the date and a working link to the post

### The rules that matter most

- [ ] Publishing a draft by hand distributes nothing while that setting is off, and does while it is on
- [ ] A scheduled post is distributed when it goes public, not when it was scheduled
- [ ] Editing a distributed article does not post it again
- [ ] Deleting the article leaves the published social post in place on the platform
- [ ] Pressing Stop on the Dashboard, then waiting, still publishes a queued social post
- [ ] With one platform's credentials deliberately broken, the others still publish
- [ ] Retry on a post that was published is refused, with the reason
- [ ] Manual approval holds every post with its text ready, and sends nothing until approved
- [ ] Editing one platform's text leaves the others unchanged
- [ ] Writing one platform's text again leaves the others unchanged
- [ ] A scheduled social post can be cancelled before it goes out
- [ ] An article with no usable picture skips Instagram with the reason, and still posts elsewhere

### Security

- [ ] View the page source of the Social Media page: no access token, refresh token or application secret appears
- [ ] Logs under the `social` stage name the platform and the article, and contain no credential
- [ ] Every social form is refused when its nonce is missing or stale
- [ ] A non-administrator cannot reach either social page or any social action

## Operations

- [ ] Server cron is running: the Dashboard shows Active
- [ ] Logs filter correctly and export to CSV
- [ ] Deactivating stops work and keeps posts and history
- [ ] Deactivating stops social publishing too, and reactivating resumes what was queued

## Before pressing Start

- [ ] You have read a test draft and trust the output
- [ ] You have decided about the visible source line
- [ ] You have read the legal note in `OPERATIONS.md`
