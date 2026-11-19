=== Axle Trash ===
Contributors: aryanjasala
Tags: trash, undo, restore, site editor, templates
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Everything WordPress deletes for good goes to a trash first, and comes back with its original ID.

== Description ==

Delete a template, a template part, a synced pattern, a navigation menu or a font in the Site Editor and it is gone for good, font files included. Delete a user with "Delete all content" and every custom post and every upload they made goes with them. WordPress has no trash for any of it.

Axle Trash catches these deletes and parks the item instead. The post is hidden from the site and from every query, but its row stays, so it keeps its ID. Restore it and every page that pointed at it works again.

Part of Axle by AryanX, a family of plugins for running WordPress sites safely.

**What it keeps:**

* Templates, template parts, patterns (synced or not) and navigation menus deleted in the Site Editor.
* Font families, with their faces and their files on disk.
* Users deleted with "Delete all content": the account, its meta, and every post, custom post and attachment that WordPress would have deleted for good. Upload files stay where they are.

**The Trash screen (Tools > Trash):**

* Views for Site Editor, Fonts and Users, with counts.
* Who deleted each item and when, and what still uses it: "Used in 34 posts" for a pattern, "Used by 12 pages" for a template.
* Restore, Delete permanently (with a confirm step), bulk actions and Empty the trash.
* If a newer template took the same name, Restore offers to move the newer one to the trash.
* In the editor, a snackbar says "Moved to the Axle trash" with Undo. After Users > Delete, the notice says "Moved to Tools > Trash" with Undo.

**Only what you could have deleted:**

Each kind checks its own capability, item by item. Templates, parts, menus and fonts need `edit_theme_options`. Patterns follow the usual delete rule. Users need `delete_users`. Nobody sees, counts or restores an item they could not have deleted.

**Retention:**

Items stay for 30 days, then a daily job deletes them for good (font files and uploads included). You can change the days and switch kinds off. If the daily job stops running, Site Health says so.

**Good to know:**

* A restored user comes back with the same ID, login and email. If the login or email now belongs to someone else, the restore is refused and says why.
* "Attribute all content to" is not touched: the account is kept, the content stays where it went.
* Network-level user deletion and site deletion on multisite are not covered. The trash works per site.
* Deactivating the plugin leaves everything parked. Deleting the plugin finishes the parked deletes and removes its data.
* A deleted account is kept for the retention period. The plugin adds a privacy policy paragraph, an exporter and an eraser.

Free and open source under the GPL. No pro version, no upsells, no ads in wp-admin, no tracking. And it stays that way.

= WP-CLI =

* `wp axle-trash list [--kind=<kind>] [--format=<format>]`
* `wp axle-trash restore <id>... [--replace]`
* `wp axle-trash delete <id>... [--yes]`
* `wp axle-trash empty [--older-than=<days>] [--yes]`

= For developers =

Every default, limit, list and message has a filter, and every step fires an action: `axle_trash_parked`, `axle_trash_restored`, `axle_trash_deleted` and more. There are abilities for agents (WordPress 6.9+) and a REST route for Undo. The full list is in [the reference](https://github.com/aryanjasala/axle-trash/blob/main/docs/REFERENCE.md).

== Installation ==

1. Install from Plugins > Add New, or upload the zip.
2. Activate it. Open Tools > Trash to see what was deleted.

== Frequently Asked Questions ==

= Does it change how WordPress's own trash works? =

No. Posts and pages still go to WordPress's trash. Axle Trash only catches the deletes that have no trash: the Site Editor's, fonts, and what a deleted user takes with them.

= Why does a restored pattern still work in my posts? =

The post never leaves the database. Only its status changes, so the ID in every `wp:block` reference stays valid.

= Can I turn it off for one kind of item? =

Yes. Untick it on the Trash screen. WordPress then deletes that kind for good, as before.

= What happens to an account that cannot be restored? =

If the username or email now belongs to someone else, or someone took the user ID, Restore says so and nothing changes. The snapshot stays until the retention runs out.

== Screenshots ==

1. The Trash screen: what was deleted, who deleted it, when, and what still uses it.
2. Deleting for good asks first and says it cannot be undone.
3. The Users view: a deleted account with the content that was deleted with it.
4. A newer template took the same name: restore it and move the newer one to the trash, or cancel.
5. The settings card: which kinds are kept and for how many days.

== Changelog ==

= 1.0.0 =
* First version.
