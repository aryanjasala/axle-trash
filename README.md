<p align="center"><img src=".wordpress-org/icon-256x256.png" alt="" width="88"></p>

<h1 align="center">Axle Trash</h1>

<p align="center">
  <a href="https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/aryanjasala/axle-trash/main/blueprint.json"><img src="https://img.shields.io/badge/Try%20in-WordPress%20Playground-3858e9?logo=wordpress&logoColor=white" alt="Try in WordPress Playground"></a>
  <a href="https://github.com/aryanjasala/axle-trash/releases/latest"><img src="https://img.shields.io/github/v/release/aryanjasala/axle-trash?label=release&color=2271b1" alt="Latest release"></a>
  <img src="https://img.shields.io/badge/WordPress-6.6%2B-3858e9?logo=wordpress&logoColor=white" alt="WordPress 6.6+">
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php&logoColor=white" alt="PHP 7.4+">
  <a href="LICENSE.md"><img src="https://img.shields.io/badge/license-GPL--2.0--or--later-blue" alt="License: GPL-2.0-or-later"></a>
  <br>
  <a href="https://github.com/aryanjasala/axle-trash/actions/workflows/phpcs.yml"><img src="https://github.com/aryanjasala/axle-trash/actions/workflows/phpcs.yml/badge.svg" alt="PHPCS"></a>
  <a href="https://github.com/aryanjasala/axle-trash/actions/workflows/phpstan.yml"><img src="https://github.com/aryanjasala/axle-trash/actions/workflows/phpstan.yml/badge.svg" alt="PHPStan"></a>
  <a href="https://github.com/aryanjasala/axle-trash/actions/workflows/unit-tests.yml"><img src="https://github.com/aryanjasala/axle-trash/actions/workflows/unit-tests.yml/badge.svg" alt="Unit tests"></a>
  <a href="https://github.com/aryanjasala/axle-trash/actions/workflows/e2e-tests.yml"><img src="https://github.com/aryanjasala/axle-trash/actions/workflows/e2e-tests.yml/badge.svg" alt="E2E tests"></a>
  <a href="https://github.com/aryanjasala/axle-trash/actions/workflows/plugin-check.yml"><img src="https://github.com/aryanjasala/axle-trash/actions/workflows/plugin-check.yml/badge.svg" alt="Plugin Check"></a>
</p>

<p align="center">Templates, patterns, menus, fonts and deleted users wait in a trash, and come back with the same ID.</p>

<p align="center">
  <img src=".wordpress-org/screenshot-1.png" alt="The Trash screen: what was deleted, who deleted it, when, and what still uses it" width="880">
</p>

---

WordPress has no trash for most of what the Site Editor deletes. Delete a template, a template part, a synced pattern, a navigation menu or a font, and it is gone, with its font files. Delete a user with "Delete all content" and every custom post and every upload they made goes with them. Rival plugins can undo some of it, but they bring it back under a new ID, so every page that pointed at it still breaks. Axle Trash catches these deletes, parks the item with its ID, and shows the page it left behind: what used it, who deleted it, and when. Restore puts it back exactly as it was. Nothing leaves your site, and a deleted user's password is never kept.

> [!NOTE]
> Free and open source under the GPL. No pro version, no upsells, no ads in wp-admin, no tracking. And it stays that way.
>
> Part of [Axle](https://axlewp.com/) by AryanX, a family of free plugins for running WordPress sites safely.

## Features

- **Site Editor items are parked, not deleted.** Templates, template parts, patterns (synced or not), navigation menus and font families with their faces. The editor shows "Moved to the Axle trash" with Undo
- **The original ID comes back.** Every `ref` in your posts and templates works again after a restore, because the row never went away. A parked item is gone from the front end and from every query
- **Users deleted with their content.** The account and everything core would have deleted for good (custom posts, attachments with their files) wait together. Restore brings the user back with the same ID, login, email and meta, and their content with them
- **Tools > Trash** with views for Site Editor, Fonts and Users, who deleted what and when, and what still uses it ("Used in 34 posts"). Restore, Delete permanently with a confirm step, bulk actions, Empty the trash
- **Name clashes are handled.** If a newer template took the same name, Restore offers to move the newer one to the trash
- **Only what you could have deleted.** Each kind checks its own capability, per item. Nobody sees, counts or restores an item they could not have deleted
- **30 days by default**, then a daily job deletes for good, font files included. Retention and kinds are settings
- **WP-CLI, REST, abilities, Site Health, privacy tools and hooks** for every part. Deleting the plugin finishes the parked deletes, so nothing stays hidden

## Install

1. Download `axle-trash.zip` from the [latest release](https://github.com/aryanjasala/axle-trash/releases/latest).
2. In WordPress, go to **Plugins > Add New Plugin > Upload Plugin**, choose the zip and activate it.

Or with WP-CLI:

```sh
wp plugin install https://github.com/aryanjasala/axle-trash/releases/latest/download/axle-trash.zip --activate
```

Needs WordPress 6.6+ and PHP 7.4+. To try it first, the **Try in WordPress Playground** badge opens a throwaway WordPress in your browser with the plugin and some demo content.

## Quick look

Delete a synced pattern in the Site Editor. It disappears from the posts that used it. Open Tools > Trash and press Restore, or from a terminal:

```sh
wp axle-trash list
# id   kind     title      deleted_by  deleted_at           used
# 412  pattern  Offer box  Aryan       2026-11-20 09:12:03  Used in 3 posts

wp axle-trash restore 412
# Success: Restored.
```

## Screenshots

<table>
  <tr>
    <td width="50%" valign="top"><img src=".wordpress-org/screenshot-2.png" alt="Deleting for good asks first and says it cannot be undone"><br><sub>Deleting for good asks first and says it cannot be undone</sub></td>
    <td width="50%" valign="top"><img src=".wordpress-org/screenshot-3.png" alt="The Users view: a deleted account with the content that was deleted with it"><br><sub>The Users view: a deleted account with the content that was deleted with it</sub></td>
  </tr>
  <tr>
    <td width="50%" valign="top"><img src=".wordpress-org/screenshot-4.png" alt="A newer template took the same name: restore it and move the newer one to the trash, or cancel"><br><sub>A newer template took the same name: restore it and move the newer one to the trash, or cancel</sub></td>
    <td width="50%" valign="top"><img src=".wordpress-org/screenshot-5.png" alt="The settings card: which kinds are kept and for how many days"><br><sub>The settings card: which kinds are kept and for how many days</sub></td>
  </tr>
</table>

## Documentation

| Doc | What it covers |
| --- | --- |
| [Reference](docs/REFERENCE.md) | What gets parked, the Trash screen, retention, hooks, WP-CLI, REST, abilities, privacy and data |
| [Development](docs/DEVELOPMENT.md) | Local setup, commands and tests |
| [Contributing](docs/CONTRIBUTING.md) | Pull requests, required checks and PR titles |
| [Releasing](docs/RELEASING.md) | How merged pull requests become releases |
| [Security](docs/SECURITY.md) | Reporting a vulnerability |
| [readme.txt](readme.txt) | What WordPress.org shows, with the FAQ and changelog |

## Project structure

```
axle-trash.php           plugin header and PSR-4 autoloader
uninstall.php            finishes parked deletes, removes options and cron
assets/undo.js           the editor snackbar with Undo
src/
├── Plugin.php           creates the services
├── Status.php           the axle_trash status, the snapshot type, meta keys
├── Upgrade.php          one-time data changes (blanks old password hashes)
├── Kinds.php            kinds, views, per-kind capabilities
├── Settings.php         kinds kept, retention
├── Interceptor.php      pre_delete_post, pre_delete_attachment, delete_user
├── Trash.php            park, restore, conflicts, purge
├── Users.php            account snapshot and restore
├── Items.php            what is parked, cut down to what the person may see
├── Usage.php            "Used in 34 posts"
├── Retention.php        the daily purge
├── Editor.php           loads the Undo script
├── Privacy.php          policy text, exporter, eraser
├── Abilities.php        list, restore, delete
├── Admin/Page.php       Tools > Trash
├── Admin/ItemsTable.php the list
├── Admin/Notices.php    Undo after Users > Delete
├── Cli/Command.php      wp axle-trash
├── Health/SiteHealth.php
└── Rest/Controller.php  POST items/{id}/restore
```

## More free plugins

Small plugins that each fill one gap in WordPress, all free with no pro version. Try them all together at [demo.axlewp.com](https://demo.axlewp.com/).

| | Plugin | What it does |
| --- | --- | --- |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-page-template-usage/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Page Template Usage](https://github.com/aryanjasala/axle-page-template-usage) | See which template every page uses, and fix the pages on a missing template |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-staging-guard/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Staging Guard](https://github.com/aryanjasala/axle-staging-guard) | Stops staging copies from emailing real people, calling live APIs or getting indexed |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-category-tag-trash/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Category & Tag Trash](https://github.com/aryanjasala/axle-category-tag-trash) | A trash for categories, tags and custom terms, with undo and full restore |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-read-only-mode/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Read-Only Mode](https://github.com/aryanjasala/axle-read-only-mode) | Read-only mode for WordPress during migrations and deploys |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-block-restore/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Block Restore](https://github.com/aryanjasala/axle-block-restore) | Restore a single block from an old revision, not the whole post |
| <img src="https://raw.githubusercontent.com/aryanjasala/activity-log-with-undo/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Activity Log with Undo](https://github.com/aryanjasala/activity-log-with-undo) | An activity log where every change you can reverse has an Undo button |
| <img src="https://raw.githubusercontent.com/aryanjasala/agent-governance/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Agent Governance](https://github.com/aryanjasala/agent-governance) | Hold risky AI agent calls for approval, preview what they change, and roll back a session |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-ai-guard/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle AI Guard](https://github.com/aryanjasala/axle-ai-guard) | See and control every AI call your plugins make: cost, data sent, budgets and a kill switch |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-config-sync/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Config Sync](https://github.com/aryanjasala/axle-config-sync) | Keep settings in git, diff them against the live site, apply with a preview and undo |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-leftovers/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Leftovers](https://github.com/aryanjasala/axle-leftovers) | Find what deleted plugins and themes left behind, see who owns it and why, and clean it up with undo |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-license-tracker/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle License Tracker](https://github.com/aryanjasala/axle-license-tracker) | See which premium plugins are licensed, which lapsed and stopped getting updates, and what renews when |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-review/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Review](https://github.com/aryanjasala/axle-review) | Share drafts through private links, collect outside feedback as block Notes, and know when an approval is out of date |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-runtime-doctor/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Runtime Doctor](https://github.com/aryanjasala/axle-runtime-doctor) | Check OPcache coverage, the object cache, PHP support and limits, cron and more, with the fix for your host |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-sessions/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Sessions](https://github.com/aryanjasala/axle-sessions) | See every device signed in, sign out one, and limit sessions per role |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-smoke-test/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Smoke Test](https://github.com/aryanjasala/axle-smoke-test) | Check key pages after every update or deploy, see what broke and roll it back |
| <img src="https://raw.githubusercontent.com/aryanjasala/block-doctor/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Block Doctor](https://github.com/aryanjasala/block-doctor) | Scan the site for broken blocks and fix them in bulk, with undo. See which posts use each block before you switch a plugin off |
| <img src="https://raw.githubusercontent.com/aryanjasala/block-theme-ops/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Block Theme Ops](https://github.com/aryanjasala/block-theme-ops) | Find Site Editor changes that override your theme files, merge a theme update into them, reset them, send them to Git, move them to another site or lock them |
| <img src="https://raw.githubusercontent.com/aryanjasala/role-right-sizer/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Role Right-Sizer](https://github.com/aryanjasala/role-right-sizer) | Recommends the smallest role each user needs, from what they actually do |
| <img src="https://raw.githubusercontent.com/aryanjasala/update-rehearsal/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Update Rehearsal](https://github.com/aryanjasala/update-rehearsal) | Try plugin, theme, core and PHP updates, a new plugin, or life without one, on a disposable copy of your site in WordPress Playground first |

## License

Copyright Aryan Jasala. Licensed under [GPL-2.0-or-later](LICENSE.md).
