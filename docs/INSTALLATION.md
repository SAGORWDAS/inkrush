# Installing InkRush

This guide takes you from the ZIP file to the first automatic post. The plugin's own, more detailed guide is [INSTALL.md](../zzz-copy-das-sobur/docs/INSTALL.md).

## Before you start

You need:

- WordPress 6.5 or newer, on a single site. Network-wide activation on multisite is not supported.
- PHP 8.1 or newer, with the `dom`, `libxml`, `mbstring` and `curl` extensions.
- MySQL or MariaDB with `utf8mb4`.
- An OpenRouter account with an API key and credits.
- Recommended: permission to add a server cron job.

Try it on a staging or test site first. In its default mode, the plugin publishes posts without asking.

## 1. Install the plugin

1. In WordPress, go to **Plugins > Add New > Upload Plugin**.
2. Choose `inkrush-1.9.0.zip`, then install and activate it.
3. A new menu item, **InkRush**, appears.

Activation creates the plugin's database tables and its 21 starting categories. Nothing runs until you press **Start Automation**.

## 2. AI Settings

1. Paste your OpenRouter API key and press **Save key**. It is encrypted and never shown again.
2. Press **Test API Connection**.
3. Check the model identifier. The default is `openai/gpt-6-luna`.
4. Review the article prompt and tag prompt.
5. Optional: try **Test generation** on sample text, and **Create test draft** to see it as a post. These are real calls and count toward your OpenRouter costs.

For stronger key protection, add this line to `wp-config.php` **before** saving the key, with your own long random value:

```php
define( 'ZZZCOPY_SECRET_KEY', 'a-long-random-string-only-you-know' );
```

If you add or change it later, enter the API key again.

## 3. News Sources

Edit the starting categories, or add your own with **Add category**. For each category, set the sources (Google News, publisher feeds or both), the addresses, searches and feeds, and the filters. A category is used once its Status says Ready.

Press **Test search** on a category to see what it finds right now. Nothing is saved by a test.

## 4. Schedule

1. **Check the timezone.** The default is a fixed `+10:30`. Use a named timezone, such as `Europe/London`, if your location has daylight saving.
2. Set the posts per day, hours between posts and first post time.
3. Decide whether to keep **Past-Hour Posting** on. With it on, each post uses a story no more than 2 hours old, or is skipped.
4. Optional: under **Automatic stop**, set a number of days or published posts after which automation stops by itself. 0 means no limit.
5. Set up the server cron job shown on this page. See [SERVER-CRON.md](../zzz-copy-das-sobur/docs/SERVER-CRON.md).

## 5. Authors

On the **Authors** page, find the user who should be credited with posts and press **Grant access**. Nobody has access until you grant it, administrators included. The user's WordPress role is not changed, so check what that role allows.

## 6. Publishing

1. Choose that user as **Post author**.
2. Choose **Automatic Publish** (the default) or **Draft**.
3. Choose how WordPress categories, tags and the source line behave.

## 7. Images

The defaults on the **Images** page work for most sites. Press **Test Wikimedia connection** to check access.

## 8. Start

On the **Dashboard**, press **Start Automation**. Anything still missing is listed there. The state shows Starting until the first worker run, then Running.

## Updating

Upload the new ZIP the same way. WordPress offers to replace the installed version. The plugin upgrades its own database on the next page load. Back up your database before updating. See [OPERATIONS.md](../zzz-copy-das-sobur/docs/OPERATIONS.md) for backup, upgrade and rollback.

## Removing

- Deactivating keeps all plugin data.
- Deleting removes plugin data only if "Delete all plugin data when the plugin is deleted" is turned on, on the Publishing page.
- WordPress posts are never deleted.
