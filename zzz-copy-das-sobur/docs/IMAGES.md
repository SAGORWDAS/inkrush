# Featured images

Version 1.3.0 adds automatic featured images from Wikimedia Commons. **This is a test release.** The choice is made by rules that read file labels. No AI is used for images, and the plugin cannot see the pictures.

## What happens, in order

**Every post needs a featured image (from 1.5.0).** No usable image means no post. This cannot be turned off.

1. An article is written and joins the queue. On the next worker run its image is searched for, one article at a time, newest first. No search runs once the daily maximum of posts is reached.
2. Its publishing slot arrives. Duplicate and daily cap checks run as before. An article whose image is already found is taken first. If none has one yet, the image is searched for now.
3. `ArticleAnalyzer` reads the title, source headline, body and tags, and builds 3 to 5 searches. The AI tags confirm names: a tag such as "Russell Brand" replaces a longer or shorter form of the same name, and a single word is searched alone only when it is also a tag. The publisher, and an outlet the article quotes such as "Nippon News Network", are never the subject unless the title or headline names them. Words that only begin a sentence, such as "Is" or "Man", and plain numbers are not searched. At least one search does not use the two main names, so a wrong subject cannot use every search. When no file passes, the log gives the minimum score and the best score reached.
4. `Commons` runs the searches through the official API, at most one request every 3 seconds, and keeps results for 6 hours. Each search asks Commons only for files at least as large as the minimum image size. After each search the files found so far are scored, and once one passes every rule with a score of 75 or more, the remaining searches are skipped. **At least two searches always run (from 1.10.1)**, because the first search is built from whichever subject the rules thought most likely, and when that subject is wrong the first search is exactly the one that finds a confident but irrelevant picture.
5. Each file goes through `LicenseChecker`, then `ImageHistory`, then the rules in `CandidateScorer`, then the minimum score. A high score never overrides a rule. **A file whose own title names a person the article does not mention is refused outright (from 1.10.1)**, however well its other labels match, and the reason names that person.
6. Starting from the highest score, up to 5 files are tried. Each is reserved in `image_history`, downloaded and checked by `MediaImporter`, then compared with images already used. The download is a copy 1920 pixels wide that Commons makes, often a tenth of the original's size. A file no wider than that is downloaded as it is.
7. The file goes into the Media Library. The post is created as a draft, the image and credit are set and confirmed, and then a final check runs: the post has a featured image, it is a picture in the Media Library, its file is on the server and opens, and WordPress has prepared it. Only then does the post get its final status. If any part fails, the post stays a draft.

If the first search finds a strong match but its file cannot be used, the searches that were skipped run after all, before the article is held.

## When no usable image is found

- The article is **held** in Failed or held, with the reason, and is never published. The Dashboard shows how many are held.
- **6 hours later, its image search runs once more**, when the saved Commons results have expired and new files may be found. If it is still held after that, only **Retry** on the Articles page tries again.
- An article older than "Skip queued articles older than" (48 hours by default) is skipped as before.
- A post time with no ready article is handled like any slot with no article, by the catch-up setting on the Schedule page.

## Posts published by hand or on a schedule

A post made by this plugin, including a test draft, cannot go public without a featured image that passes the final check. This applies when a person presses Publish and when WordPress publishes a scheduled post. The post is set back to draft, and Logs says why. Posts that were already public are not changed when edited, and posts not made by this plugin are never affected.

Posts published before 1.5.0 with no image are listed on the Images page, with a **Retry image** button.

The prepare step sits in `Publisher::run_publish`, and the assign step in `Publisher::finish_post`.

**With Past-Hour Posting on,** the search runs as soon as the article is written, during preparation, so a slow search cannot age the story. Publishing reuses the result. A story with no usable image is set aside, and another story is chosen while there is still time. If a temporary problem is still there at the post time, the post waits only while the story stays within the story age and the post time is within its grace period, then the post is skipped. It never goes out without an image. Only the timing changes: licenses, scoring, credits and history work the same. An image found for an article that is then dropped is removed and can be used by another post.

A story under 2 hours old often has no matching photo on Commons yet, so expect more skipped posts than in the earlier workflow.

## Licenses

| Accepted | Refused |
|---|---|
| CC0, public domain, CC BY and CC BY-SA (any version, with a named creator). CC BY-SA can be turned off on the Images page | CC BY-NC, CC BY-ND, GFDL, fair use, unknown licenses, fields that disagree, and files marked with restrictions or as not free |

**Do not edit CC BY-SA images.** Adding text or a logo, or cropping one in another program, makes an edited version, and that version must be shared under the same license. The plugin does not edit images. Turning CC BY-SA off does not change images already used.

Wikimedia's license fields are written by its users and can be wrong. **These checks do not guarantee legal compliance.** Not every Commons file is free of other rights, such as trademarks or a person's right to their image. You are responsible for what you publish.

## Credit line

The credit shows once on the public article page:

`File photo: "File title", Creator / Wikimedia Commons. License: CC BY 4.0.`

- The creator links to their page when Commons gives one. "Wikimedia Commons" links to the file page. The license links to its exact text.
- It is added through `post_thumbnail_html`, directly after the image. Block themes and most classic themes use this.
- A theme or page builder may print the image another way. **Elementor's featured image widget is expected to.** The credit then shows at the top of the article text. This was not tested with real Elementor.
- It does not show on blog listing pages, feeds or other posts' thumbnails.
- A copy is saved at the end of the post text, and you can see it in the post editor. While the plugin runs, that copy is hidden, so the credit shows once. If the plugin is deactivated or removed, the copy shows at the end of the article, so the credit stays with the image. Do not delete it unless you also remove the image.
- The plugin does not crop or edit images, so it shows no modification note. WordPress does make smaller copies of each image, and scales very large ones down to 2560 pixels.

## Never using an image twice

- `image_history` has unique keys on the Commons page id, file name and file address, so the database refuses a second reservation. Tested with four separate PHP processes racing for one image.
- The SHA-256 of each file and a perceptual hash are stored, so a copy under another name is refused.
- Rows stay after a post or Media Library file is deleted. Only the **Release** button on the Images page frees an image. It asks for confirmation and is logged.

## When something goes wrong

| Problem | What happens |
|---|---|
| Wikimedia is busy or cannot be reached, including a failed name lookup | The article waits, using Retry-After and growing delays, up to 3 attempts, then it is held. It is not rewritten, and this is not counted as a publish failure. A file being downloaded at the time is given back, not rejected |
| Wikimedia asks for a wait | Nothing is sent to it until the wait ends. The article waits, and this does not use up an attempt |
| A rate limit arrives partway through the searches | Any good file already found is used. If there is none, the article waits and the missed searches run again, up to the same 3 attempts. Earlier results are kept |
| A download is refused or smaller than Commons states | That file is rejected and is not tried again |
| No file passes | The article is held in Failed or held, and no post is created. Its search runs once more 6 hours later |
| The featured image cannot be set, or its file is missing at the final check | It is logged and the post stays a draft. Publishing is tried again, up to 3 times, with the same file. **Retry image** on the article reuses the same file too |
| The worker stops midway | Recovery finishes the same post with the same image. While publishing is paused, recovery waits until Resume |

## How often an image is found

Measured on 2026-11-05 with real Commons data and 23 hand-written sample stories, one per category, at the default settings. These are samples, so real results will differ.

- **20 of 23 got an image.** Coupon Blogs, Business and Entertainment found none. In the Business sample, for example, the good matches were share-alike.
- About 1,365 files were checked and 221 passed. Most refusals were for license.
- 92 search requests at the 3-second pace produced no rate-limit errors.

**Do the chosen images suit the stories?** Judged only by reading file names, since nobody looked at the pictures:

| Top choice | Count | Examples |
|---|---|---|
| Fits well | 12 | Nintendo Switch 2 launch, New York City Marathon |
| Loosely related | 5 | A 2017 army leaders' meeting for a Japan and South Korea trade story |
| Wrong | 3 | A painting by William Merritt Chase for a Chase bank story, and a Social Security Scotland photo for a US story |

So **some wrong or misleading images should be expected.** Use Draft mode, or check posts, until you trust the results.

## Known limits

- The plugin reads labels and cannot see the picture. Nudity, watermarks, people, blur and wrong subjects are caught only when labels mention them. Sharpness is an estimate.
- Word matching cannot tell names apart, such as Chase the bank and Chase the painter.
- A surname used on its own counts toward the full name it ends, so "White" counts toward "Dana White" and not toward "White House" (corrected in 1.10.1, after an article about Dana White was illustrated with a White House photograph). When the word sits in the same position in two names, it is left alone rather than guessed at, so neither name is inflated. A final 's is dropped, so "Russell Brand's" is the name "Russell Brand".
- Old photos can pass and mislead, because the labels do not say how old a file is.
- A person's photo can pass when the labels name the person in the story, even if the photo is unflattering or out of context.
- A picture is only refused for showing the wrong person when that person's name is in the file's own title. A photograph of someone named only in its description or its categories is not caught.
- The child, victim and identifiable-people protections are waived when the article is about the people shown. From 1.10.1 only a person's name waives them: an article naming a place or an organisation the picture is also filed under, such as "White House", no longer does.
- Publishing can take longer, usually 5 to 10 seconds, and more if Wikimedia is slow.
- Each image adds files to your Media Library: the downloaded copy, at most 1920 pixels wide, and several smaller copies WordPress makes.
- Requiring an image means fewer posts on days when Commons has nothing that passes. Held articles are counted on the Dashboard.
- The final check reads the file on the server. It does not load the image from your public website, because many hosts block a site from requesting its own address, so a problem only a visitor would see, such as a caching or CDN fault, is not caught.
- A post is checked when it goes public, not afterwards. If its image file is deleted later, the post stays public.
- A theme or plugin that changes a post's status directly in the database, without WordPress's own functions, is not caught.
- When the block editor publishes a post that is then kept as a draft, the editor may need a reload to show it. This was not checked in a browser.
- A file scoring 75 can end the searching even if a later search would have found one scoring higher, though never before two searches have run.
- Many space and museum files are over the 15 MB limit and are skipped. You can raise the limit on the Images page.
