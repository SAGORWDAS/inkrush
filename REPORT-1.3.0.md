# Developer report: version 1.3.0, Wikimedia Commons featured images

A test release. Built to the Master Development Prompt, with one owner change: **no AI for images**. Selection uses rules only. This also means there is no AI vision step, so the plugin cannot see pictures.

## 1. Summary

When an article reaches its slot, the plugin searches Wikimedia Commons, applies license and label rules, scores the files, and imports the best one. It sets that file as the featured image and shows a credit below it. An image is never used twice. If nothing qualifies, the article publishes with no image, as the owner chose. A setting can hold it instead.

## 2. Workflow

Slot due, duplicate and cap checks, analyze the article, 3 to 5 searches, license check, history check, label rules, score, reserve, download, file checks, same-picture check, Media Library, create draft, tags, set and confirm image and credit, publish.

## 3. Integration point

`Publisher::run_publish` calls `ImagePipeline::prepare` after the duplicate and cap checks and before `create_post`. `Publisher::finish_post` calls `ImagePipeline::assign` before the post changes from draft. Prompts, model, discovery, generation, rotation, schedule and cap were not changed.

## 4. Files created

`includes/Images/`: `Commons`, `LicenseChecker`, `ArticleAnalyzer`, `CandidateScorer`, `ImageHistory`, `MediaImporter`, `ImagePipeline`, `Attribution`. Also `docs/IMAGES.md`. Tests: `tests/test-5-images.php`, `tests/mock-commons.php`, `tests/reserve-worker.php`.

## 5. Files modified

**Code:** `zzz-copy-das-sobur.php`, `uninstall.php`, `includes/Http.php`, `Schema.php`, `Settings.php`, `Events.php`, `Publisher.php`, `Runner.php`, `Plugin.php`, `Articles.php`, `Admin/Menu.php`, `Admin/Pages.php`, `Admin/Actions.php`, `Admin/Ajax.php`, `assets/admin.js`, `assets/admin.css`.

**Docs and tests:** `readme.txt`, `README.md`, `docs/ARCHITECTURE.md`, `INSTALL.md`, `OPERATIONS.md`, `QA-CHECKLIST.md`, `TEST-RESULTS.md`, `tests/lib.php`, `run.php`, `mock-server.php`, `build-zip.php`, `test-4-publishing.php`, `tests/README.md`.

Publishing locks were lengthened (queue 900 s, publish 600 s, runner +420 s) because image work runs inside the publishing step.

## 6. Database changes

`Schema::DB_VERSION` is now 4. Two new tables are added automatically on upgrade:

- `image_history`: permanent record, with unique keys on Commons page id, file name and file address hash.
- `image_runs`: one row per article, with progress, searches, and files checked with their results.

Thirteen new settings. Credit data is stored in the post meta `_zzzc_image_credit` and the attachment meta `_zzzc_image_source`.

## 7. Wikimedia Commons API

The official `commons.wikimedia.org/w/api.php`, using `generator=search` in the file namespace with `prop=imageinfo` and `extmetadata`. The plugin sends its own identifying user agent. There is a 3-second gap between requests, results are cached for 6 hours, and HTTP 429 and Retry-After are honored. Files are downloaded only from `upload.wikimedia.org`, and every redirect is checked. No API key is needed.

## 8. License handling

`LicenseChecker` accepts CC0, public domain and CC BY with a named creator. It refuses share-alike, non-commercial, no-derivatives, unknown, conflicting, restricted and not-free files. It checks the license name, code and address against each other. **This does not guarantee legal compliance,** because the Commons fields can be wrong and other rights can apply.

## 9. Attribution

`Attribution` adds one credit through `post_thumbnail_html`, directly below the image, with links to the creator, the file page and the license. If a theme or builder skips that filter, it adds the credit at the top of the article text. It shows a modification note only when one is recorded. The plugin makes no edits itself.

## 10. Featured image

`media_handle_sideload`, then `set_post_thumbnail`, confirmed with `get_post_thumbnail_id`. The post stays a draft until this is done.

## 11. Duplicate prevention

Database unique keys, a permanent record that survives post and attachment deletion, SHA-256, and a perceptual hash. Only an administrator's Release can free an image, and it asks for confirmation and is logged.

## 12. Security

Capability and nonce checks on every action. Input is sanitized and output is escaped. Only approved hosts are accepted, with SSRF protection and redirect checks. File size, real image type, completeness and memory use are checked before import.

## 13. Dashboard, Articles, Usage and Logs

- **New Images page:** settings, test tools, preview with score, license, creator and reason, history with Release, totals and log.
- **Dashboard:** images added, posts with no image, latest image activity.
- **Articles:** thumbnail, status, creator, license, source, Retry image, and files checked.
- **Usage:** separate image totals, with no cost shown because there is none.
- **Logs:** a new `image` stage.

## 14. Test results

**133 automated tests: 133 passed, 0 failed** (95 earlier, 38 for images), on WordPress 7.1.4, PHP 8.3.36 and MariaDB 11.4.5. These use a local stand-in for Commons.

| Prompt's test | Covered by | Result |
|---|---|---|
| CC BY, public domain, CC0 assigned | IMG-1, 2, 3 | Pass |
| SA, NC, ND, unknown rejected | IMG-4, 5, 6, 7 | Pass |
| Low resolution rejected | IMG-8 | Pass |
| Previously used rejected; deleted post stays reserved | IMG-9, 10 | Pass |
| Two simultaneous jobs | IMG-11, four real PHP processes | Pass |
| Corrupted rejected | IMG-12, 12b, 34 | Pass |
| Failed download does not regenerate | IMG-13, 13b | Pass |
| Failed assign logged and recoverable | IMG-14, 14b | Pass |
| Credit below image, on the single page, not on listings | IMG-15, 15b, 19, plus a real page | Pass |
| Links work | Real page; all three addresses returned HTTP 200 | Pass |
| Modification info | IMG-18 | Pass |
| Draft and Automatic | IMG-20, 21 | Pass |
| 3 a day, controls, no duplicate posts | IMG-23, 24, 25 | Pass |
| Rotation and all existing features | 95 earlier tests, with images off; images do not touch rotation code | Pass |

**Tested for real, outside the automated tests:** 23 sample stories against real Commons, 4 real files imported, the credit on a real page in Twenty Twenty-Five, the admin screens in a browser, and the ZIP installed through WordPress's own installer.

**Four bugs were found only by real data and fixed:** no credit on block themes, broken credit layout, a good photo refused as incomplete, and missing preview pictures. This shows the mock tests alone were not enough.

**Not tested:** real Elementor, classic themes, a full run with real AI and real Commons together, the owner's test website, several days of use, PHP 8.1 and 8.2.

## 15. Known limitations

- 20 of 23 samples found an image. Judged by file names only: 12 fit well, 5 are loose, 3 are wrong, such as a painter named Chase for a Chase bank story.
- The plugin cannot see pictures, so nudity, watermarks, people and blur are caught only when labels mention them.
- Old or misleading photos can pass.
- Elementor is expected to show the credit at the top of the text instead of below the image.
- The image step adds about 5 to 10 seconds to publishing, and each image adds several files to the Media Library.

Details are in `docs/IMAGES.md`.

## 16. Install and upgrade

Press Stop, upload `dist/zzz-copy-das-sobur-1.3.0 WITH IMAGES SEARCH.zip`, and choose to replace the current version. The tables are added automatically. Images are on by default, so turn them off on the Images page if you want the old behavior.

## 17. Rollback

Turn off **Find images automatically** on the Images page. That is enough in most cases. For a full rollback, follow `docs/OPERATIONS.md`, and note that credits stop showing on 1.2.1, while CC BY images need them.
