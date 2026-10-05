=== CPH FileBird ===
Contributors: clearph
Tags: media library, folders, media folders, filebird
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Media library folders by Clear pH. A data-compatible replacement for FileBird.

== Description ==

CPH FileBird organises the WordPress media library into folders. It is built by Clear pH as a drop-in replacement for FileBird and FileBird Pro.

* Folder tree in the media library grid, list view and the media modal.
* Drag files into folders, bulk move from the list view, and pick a folder per file on the attachment edit screen.
* Choose the target folder when uploading, plus a per-user default upload folder.
* Folder colours, sorting and counts (optionally including subfolders).
* CSV export and import, compatible with FileBird's export format.
* REST API and WP-CLI commands for scripting.
* Updates come straight from Clear pH's GitHub releases. No licence key, no account, no tracking.

= FileBird compatibility =

CPH FileBird reads and writes FileBird's own database tables (`{prefix}fbv` and `{prefix}fbv_attachment_folder`) with the exact same schema, and keeps folder colours in FileBird's `fbv_folder_colors` option. A site can switch from FileBird to CPH FileBird, or back, with no migration step.

It also fires FileBird's `fbv_*` action and filter hooks alongside its own `cphfb_*` hooks, so theme and plugin code written for FileBird keeps working.

Never run both at once. If FileBird is active, CPH FileBird pauses itself and shows a notice until FileBird is deactivated.

== Installation ==

= New site =

1. Upload `cph-filebird.zip` from the latest GitHub release via Plugins > Add New > Upload Plugin.
2. Activate it. Folders appear in the media library.

= Switching from FileBird =

1. Take a backup of the database (at least the `fbv` and `fbv_attachment_folder` tables).
2. Deactivate FileBird or FileBird Pro. Do not delete it yet.
3. Install and activate CPH FileBird.
4. Open the media library and check your folders. `wp cphfb verify` reports schema, counts and any data oddities.
5. Once you are happy, you can delete FileBird's plugin files.

Clear pH sites use `bin/cutover.sh` from the plugin's repository, which does all of the above per site, compares folder and assignment counts before and after, and rolls back automatically on any mismatch.

== Frequently Asked Questions ==

= Will I lose my folders? =

No. CPH FileBird uses the same tables FileBird already created, so every folder and every file assignment is there the moment you activate it. Nothing is copied or converted.

= Can I go back to FileBird? =

Yes. Deactivate CPH FileBird and reactivate FileBird. Any folders you created or files you moved in the meantime are in the shared tables, so FileBird shows them too. Uninstalling CPH FileBird also leaves the folder tables and FileBird's options in place.

= What does uninstalling remove? =

Only CPH FileBird's own settings (`cphfb_settings`, `cphfb_db_version`, per-user settings, and cached counts). Folder data is kept so you can reinstall or switch back. To remove the folder tables too, add `define( 'CPHFB_REMOVE_ALL_DATA', true );` to `wp-config.php` before deleting the plugin.

= How do updates work? =

The plugin checks the GitHub releases of `dbreck/cph-filebird` and offers updates on the normal Plugins screen. Define `CPHFB_DISABLE_UPDATES` as `true` in `wp-config.php` to switch this off. If the repository is private, define `CPHFB_GITHUB_TOKEN` with a read-only token.

= Does every user get their own folder tree? =

No. There is one shared folder tree per site. FileBird's per-user folder mode is not supported; existing folders created in that mode are all shown.

== Changelog ==

= 0.1.0 =
* First release.
* Backend core: install, folder and assignment models, global and per-user settings, CSV import and export.
* Media library folder tree, drag and drop, bulk move, upload folder selection.
* REST API (`cph-filebird/v1`) and WP-CLI commands (`wp cphfb`), including `wp cphfb verify` for cutovers.
* Fires FileBird's `fbv_*` hooks for compatibility.
* Self-hosted updates from GitHub releases.
