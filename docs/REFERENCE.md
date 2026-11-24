# Reference

## What gets parked

WordPress deletes these for good, with no trash. Axle Trash catches the delete (`pre_delete_post`, and `pre_delete_attachment` for a deleted user's uploads), moves the post to the internal status `axle_trash` and tells core it worked. The row stays, so its ID, terms, meta, comments and children stay too, and every `ref` that points at it works again after a restore.

| Kind | Post types | Who may see and restore it |
| --- | --- | --- |
| Template | `wp_template` | `edit_theme_options` |
| Template part | `wp_template_part` | `edit_theme_options` |
| Pattern | `wp_block` | `delete_post` on that pattern |
| Navigation menu | `wp_navigation` | `edit_theme_options` |
| Font | `wp_font_family`, `wp_font_face` (a family's faces travel with it) | `edit_theme_options` |
| User | an account snapshot, plus everything deleted with it | `delete_users` |

A person only ever sees, counts, restores or deletes items they could have deleted. Everything else looks as if it does not exist.

Deleting a user with "Delete all content" parks the account (the `wp_users` row without its password hash and activation key, and all user meta except `session_tokens` and `_application_passwords`) and every post, page, custom post and attachment that core would have deleted for good. Attachment files stay on disk. Posts and pages that core sends to its own trash stay there, linked to the snapshot. Restore puts the user back with the same ID, login and email (refused with the reason if either now belongs to someone else), their meta, and the content. The old password was never kept, so the account comes back with a new random password and the person who restored it sees a notice with a "Send a password reset link" button. Nothing is emailed until they press it. Restoring a user whose role you could not give to someone is refused. "Attribute all content to" is not touched: the account is kept, the content stays where it went.

Not handled: network-level user deletion and site deletion on multisite. On multisite the trash works per site.

## The Trash screen

Tools > Trash. Views: All, Site Editor, Fonts, Users, each with a count of what you may see. Each row shows what it is, who deleted it, when, and what still points at it ("Used in 34 posts" counts `wp:block` references in posts you can edit). Restore, Delete permanently (with a confirm step), bulk actions and Empty the trash. When a newer template or part took the same name, Restore offers "Restore and move the current one to the trash".

In the Site Editor and the block editor, a successful delete shows "Moved to the Axle trash" with Undo. After Users > Delete, the notice says "Moved to Tools > Trash" with an Undo link.

## Retention and settings

Items stay for 30 days. A daily job (`axle_trash_purge`) deletes older ones for good, font files and uploads included. Settings on the Trash screen: which kinds are kept (all by default) and the number of days. Deactivating the plugin leaves everything parked. Deleting the plugin finishes the deletes, so nothing stays hidden in the database.

## Hooks

| Hook | Type | Arguments | Use |
| --- | --- | --- | --- |
| `axle_trash_kinds` | filter | `string[] $kinds` | Kinds that are kept: `template`, `template_part`, `pattern`, `navigation`, `font`, `user` |
| `axle_trash_retention_days` | filter | `int $days` | Days before the daily job deletes an item. Clamped to 1 to 3650 |
| `axle_trash_capability` | filter | `string $cap, string $kind` | Capability for a kind. `delete_post` is checked against the item |
| `axle_trash_can` | filter | `bool $allowed, WP_Post $post, string $kind, int $user_id` | Final say on whether a person may see and act on an item |
| `axle_trash_park` | filter | `bool $park, WP_Post $post, string $kind` | Return false to let core delete one item for good |
| `axle_trash_park_user` | filter | `bool $park, int $user_id` | Return false to let one user be deleted as before |
| `axle_trash_user_meta_skip` | filter | `string[] $keys` | User meta left out of snapshots. Default `session_tokens` |
| `axle_trash_settings_capability` | filter | `string $cap` | Who changes the settings. Default `manage_options` |
| `axle_trash_messages` | filter | `array $messages` | Texts for missing item, conflict and font parent |
| `axle_trash_notice` | filter | `string $text, string $done` | The result notice on the screen |
| `axle_trash_editor_strings` | filter | `array $strings` | The snackbar words |
| `axle_trash_parked` | action | `int $id, string $kind, WP_Post $post, int $batch` | An item was parked. `$kind` is `user_content` for what a deleted user owned |
| `axle_trash_restored` | action | `int $id, string $kind, ?WP_Post $post, bool $replaced` | An item came back. For a user, `$id` is the user's ID and `$post` is null |
| `axle_trash_deleted` | action | `int $id, string $kind, WP_Post $post` | An item was deleted for good |
| `axle_trash_user_parked` | action | `int $snapshot, WP_User $user, bool $content` | A user account was saved just before core deleted it |
| `axle_trash_purged` | action | `int $deleted` | The daily job finished a batch |

Editor JS: filter `axleTrash.undoNotice` (`{ message, label }`, item info), actions `axleTrash.parked` and `axleTrash.restored`.

```php
// Keep deleted items for two weeks, and never park patterns.
add_filter( 'axle_trash_retention_days', fn() => 14 );
add_filter( 'axle_trash_park', fn( $park, $post ) => 'wp_block' === $post->post_type ? false : $park, 10, 2 );
```

## WP-CLI

```sh
wp axle-trash list [--kind=<kind>] [--format=<format>]
wp axle-trash restore <id>... [--replace]
wp axle-trash delete <id>... [--yes]
wp axle-trash empty [--older-than=<days>] [--yes]
```

WP-CLI runs with full rights, so it lists every item.

## REST API

`POST /wp-json/axle-trash/v1/items/{id}/restore` with optional `replace` (boolean). The permission is the item's own capability, and the request needs a valid REST nonce or an application password. Restoring a deleted user adds `user_id` and `password_reset_required: true` to the response, and sends nothing. A successful `DELETE` on `/wp/v2/blocks`, `/templates`, `/template-parts`, `/navigation` and `/font-families` carries `axle_trash: { id, kind, restore }` in its response when the item was parked.

## Abilities (WordPress 6.9+)

| Ability | Annotations | Does |
| --- | --- | --- |
| `axle-trash/list-items` | readonly | Lists items you could have deleted, optionally by kind |
| `axle-trash/restore-item` | not destructive | Restores one item by ID |
| `axle-trash/delete-item` | destructive, idempotent | Deletes one item for good |

## Privacy

Deleted accounts are kept for the retention period, so the plugin adds a suggested privacy policy paragraph, a personal data exporter and an eraser keyed by email. The eraser deletes the snapshot and the content parked with it. A user removed through core's personal data eraser is never parked.

The snapshot holds the account's profile row (username, email, display name, URL, registration date) and its user meta, in the same database the account was in. It does **not** hold the password hash, the password reset key or hashed application passwords. When an account is restored it gets a new random password, and the restorer can send a reset link from the notice on Tools > Trash (core's `retrieve_password()`), or with `wp user reset-password <id>`. The plugin never sends one by itself. Snapshots saved by an earlier build have those fields blanked once, on the first load after the update.

## Site Health

"The Axle trash is emptied on time": recommended when items are older than the retention plus a day, which means WP-Cron is not running.

## Data

Parked posts keep their rows with status `axle_trash` and these meta keys: `_axle_trash_status`, `_axle_trash_by`, `_axle_trash_at`, `_axle_trash_theme`, `_axle_trash_batch`. A deleted user is a record of the type `axle_trash_user` with `_axle_trash_user` (JSON) and `_axle_trash_email`. Options: `axle_trash_settings`, `axle_trash_data_version`. Cron: `axle_trash_purge`, `axle_trash_purge_more`. Uninstall removes all of it.
