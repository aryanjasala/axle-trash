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
* Users deleted with "Delete all content": the account's profile and meta (not its password), and every post, custom post and attachment that WordPress would have deleted for good. Upload files stay where they are.

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
* The password is not kept. A restored user gets a new random password, and you see a notice with a "Send a password reset link" button. Nothing is emailed until you press it.
* You can only restore a user whose role you are allowed to give.
* "Attribute all content to" is not touched: the account is kept, the content stays where it went.
* Multisite: the trash works per site. Deleting a user from the Network Admin, and deleting a whole site, are not covered. Nothing shows in Network Admin.
* Deactivating the plugin leaves everything parked. Deleting the plugin finishes the parked deletes and removes its data.
* A deleted account's profile is kept for the retention period. See Privacy below.

= Privacy =

Axle Trash stores no data about visitors and makes no requests to other sites. It does keep one kind of personal data: when you delete a user, a copy of the account's profile stays in your own database until you restore it, you delete it for good, or the retention runs out (30 days by default).

* What is kept: the username, email address, display name, URL, registration date and the user's meta (profile fields, role, preferences). Posts and uploads deleted with the account wait next to it.
* What is not kept: the password hash, the password reset key, signed-in sessions and hashed application passwords. Copies saved by an early build are blanked on the first load after the update.
* A restored account gets a new random password. Nobody can sign in as that account until a reset link is sent, and it is only sent when the person who restored it presses "Send a password reset link".
* The plugin adds a suggested paragraph to Settings > Privacy, a personal data exporter and an eraser. Both look up the copy by email address. The eraser deletes it together with the content parked for it. A user removed through WordPress's own eraser tool is never parked.
* Deleting the plugin deletes every parked item for good and removes the copies.

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

= What happens when I deactivate or delete the plugin? =

Deactivating leaves everything parked, still hidden from the site, until you activate it again. Deleting the plugin from the Plugins screen deletes every parked item for good, the way WordPress would have, and removes its settings and copies of users.

= Can I turn it off for one kind of item? =

Yes. Untick it on the Trash screen. WordPress then deletes that kind for good, as before.

= Does it work on multisite? =

Only per site. Activate it on a site (or network activate it) and each site has its own trash on Tools > Trash. Deleting a user from Network Admin (Users > Delete in the network screen) and deleting a whole site are not caught, and Network Admin has no trash screen. When a site admin removes a user from one site, the account stays on the network, and a restore adds it back to that site with its role.

= Does it keep the password of a deleted user? =

No. The password hash is not saved. A restored user gets a new random password and nobody can sign in until a reset link is sent. After a restore you see a notice with a "Send a password reset link" button. The plugin never sends the link by itself.

= Who can see the trash? =

Only people who could have deleted the item. Templates, template parts, navigation menus and fonts need the right to edit the theme. A pattern follows the usual rule for deleting that pattern (including its published or private state). Users need the right to delete users. Nothing about other items is shown, counted or returned, in the list, the counts, REST, WP-CLI or the abilities.

= Does it keep my deleted posts and pages? =

WordPress's own trash already does, and Axle Trash leaves it alone. When a user is deleted with their content, their posts and pages stay in WordPress's trash, linked to the account, and come back with it.

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
