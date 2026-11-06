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

## Operations

- [ ] Server cron is running: the Dashboard shows Active
- [ ] Logs filter correctly and export to CSV
- [ ] Deactivating stops work and keeps posts and history

## Before pressing Start

- [ ] You have read a test draft and trust the output
- [ ] You have decided about the visible source line
- [ ] You have read the legal note in `OPERATIONS.md`
