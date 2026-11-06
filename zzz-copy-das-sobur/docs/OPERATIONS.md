# Backup, upgrade, rollback, deactivation and uninstall

## Backup

Back up the WordPress database and files as usual. The plugin's data is in tables named `{prefix}zzzc_*` and options named `zzzc_*`.

The API key is encrypted with the WordPress salts, or with `ZZZCOPY_SECRET_KEY` if you set it. After restoring to a site with different salts, enter the API key again.

## Upgrade

1. Back up the database.
2. Press **Stop Automation**. Queue and history are kept.
3. Upload the new ZIP through Plugins > Add New > Upload Plugin and choose to replace the current version.
4. Database changes run by themselves on the next request. The schema version is stored in `zzzc_db_version`.
5. Check the Dashboard and Logs, then press **Start Automation**.

**Upgrading to 1.7.0 adds the Authors page.** The user already credited with posts, the chosen Post author or else the first administrator, becomes the one active author, and Logs records who. Nobody else is given access, administrators included. To credit someone else, grant them access on the Authors page and choose them on the Publishing page.

**Upgrading to 1.6.0 widens Past-Hour Posting to 2 hours.** Stories can be up to 120 minutes old, and a preparation time still at the old default of 60 minutes moves to 120. One you changed is kept. Logs records it. Both are on the Schedule page.

**Upgrading to 1.5.0 makes a featured image required on every post.** The upgrade removes the settings "Find images automatically", "Set the chosen image as the featured image" and "When no image can be used", and Logs records it. Posts already published are not changed. Ones with no image are listed on the Images page, with a Retry image button.

**Upgrading to 1.4.0 turns Past-Hour Posting on.** To keep the earlier workflow, untick it on the Schedule page before pressing Start. While it is on, articles already in the queue are not used. They wait, and are skipped by the "Skip queued articles older than" setting.

The upgrade also adds a Google News search to Technology, Entertainment, Business, Science, Sports, and Health & Wellness, and Logs lists the ones changed. A category is left alone if its topic address was changed or it already has a search. The search stays if Past-Hour Posting is turned off, so each of these categories then makes one more Google request per research round. Remove it on the News Sources page if you do not want that.

## Rollback

1. Press **Stop Automation** and deactivate the plugin.
2. Restore the database backup taken before the upgrade. An older plugin version may not understand a newer schema.
3. Install the previous ZIP and activate it.

Posts the newer version created after your backup will not be in the restored database.

**Going back from 1.7.0 to 1.6.0:** 1.6.0 credits posts to the Post author, or the first administrator, with no check of the Authors page. The new table and columns are ignored.

**Going back from 1.6.0 to 1.5.0:** 1.5.0 has a fixed 60-minute limit and allows a preparation time of at most 60 minutes. Set the preparation time to 60 or less before going back, or 1.5.0 keeps the longer saved value and searches before any usable story exists. The story age setting is ignored.

**Going back from 1.5.0 to 1.4.2:** 1.4.2 reads the removed image settings as their old defaults, so posts with no usable image are published again without one. The extra database column is ignored.

**Going back from 1.3.0 to 1.2.1:** from 1.5.0 on, images cannot be turned off. If you do roll back, 1.2.1 ignores the two new tables, and featured images already on posts stay, but their credit line stops showing. Keep the credit in mind before rolling back on a public site, because CC BY images need it. Restoring the backup also removes those posts, so it avoids this problem.

## Deactivation

Deactivating stops scheduled work. It keeps all posts, queue items, history, logs and settings. When reactivated, the plugin stays Stopped until you press Start.

## Uninstall

Deleting the plugin from the Plugins screen:

- **By default**, removes the plugin files and schedules and keeps its data, in case you reinstall.
- **With "Delete all plugin data when the plugin is deleted" turned on** (Publishing page), also removes its tables and options, including the API key.

WordPress posts, categories and tags the plugin created are never deleted. Their `_zzzc_*` post meta stays with them.

## Data retention

Set on the Publishing page. A daily job applies these:

| Data | Default | After that |
|---|---|---|
| Stored publisher text, and rejected stories | 30 days | Removed |
| Logs | 90 days | Removed |
| Raw model responses of failed articles | 30 days | Removed |
| Finished background job records | 14 days | Removed |
| Slot records | 180 days | Removed |

Article records, usage history and post links are kept so reports stay complete.

## Legal and content responsibility

You are the publisher. The plugin holds back failed content and does not bypass paywalls or robots rules on publisher sites. It cannot decide whether rewriting a given source is allowed, or whether a generated article is accurate. Automatic Publish, the default the owner chose, means no person reads an article before it goes live. Switch to Draft on the Publishing page at any time to review first.

Also be aware that the plugin uses Google News RSS even though the `/rss/` path is not on the allow list in Google's robots.txt. That was a deliberate owner decision. You can turn it off under News Sources.
