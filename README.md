# CPH FileBirb

Clear pH's media-library folder plugin: a from-scratch, data-compatible replacement for FileBird Pro. It uses FileBird's own tables (`{prefix}fbv`, `{prefix}fbv_attachment_folder`), so a site swaps plugins with no migration.

The repository folder is named `cph-filebirb`; everything inside uses `cph-filebirb`. `PLAN.md` is the spec and `CLAUDE.md` holds the hard rules and decisions.

## Architecture

PHP 8.1+, WordPress 6.5+. Namespace `CPH\FileBirb`, PSR-4 from `includes/`, one singleton class per file (`get_instance()`).

| Path | Role |
| --- | --- |
| `cph-filebirb.php` | Bootstrap. Version guard (parses on PHP 7), autoloader check, activation hook. |
| `includes/Plugin.php` | Boots every component unless FileBird is active (then shows a notice and stops). |
| `includes/Install.php` | Creates the two tables with FileBird's exact DDL, seeds `cphfb_settings` once. |
| `includes/Model/` | `Folder`, `Assignment`, `Settings`, `UserSettings`. |
| `includes/Query.php` | `fbv` query var for `WP_Query`, the media modal and `/wp/v2/media`. |
| `includes/Upload.php` | Upload routing and assignment housekeeping. |
| `includes/Hooks.php` | Fires every `cphfb_*` hook, then its FileBird twin. |
| `includes/Rest/` | REST controllers (`cph-filebirb/v1`). |
| `includes/Cli/` | `wp cphfb` commands. |
| `includes/Admin/` | Settings page, list-table column and bulk action, attachment field, assets. |
| `includes/Updater.php` | GitHub release updates via Plugin Update Checker. |
| `uninstall.php` | Removes `cphfb_*` data only; keeps folder tables unless `CPHFB_REMOVE_ALL_DATA`. |
| `assets/src` | React frontend source, built to `assets/build`. |

## Hooks

Every mutation fires the `cphfb_*` hook first, then the FileBird hook with the same arguments.

| CPH FileBirb | FileBird | Args |
| --- | --- | --- |
| `cphfb_folder_created` | `fbv_after_folder_created` | `int $folder_id, array $node` |
| `cphfb_folder_renamed` | `fbv_after_folder_renamed` | `int $folder_id, string $new_name` |
| `cphfb_folder_deleted` | `fbv_after_folder_deleted` | `int $folder_id` |
| `cphfb_delete_all` | `fbv_after_delete_all` | none |
| `cphfb_folder_parent_updated` | `fbv_folder_parent_updated` | `int $folder_id, int $new_parent` |
| `cphfb_parent_updated` | `fbv_after_parent_updated` | `int $folder_id, int $new_parent` |
| `cphfb_before_setting_folder` | `fbv_before_setting_folder` | `int $attachment_id, int $folder_id` |
| `cphfb_after_set_folder` | `fbv_after_set_folder` | `int $attachment_id, int $folder_id` |
| `cphfb_after_assign_folder` | `fbv_after_assign_folder` | `int $folder_id, int[] $attachment_ids` |

Filters (value first):

| CPH FileBirb | FileBird | Value, extra args |
| --- | --- | --- |
| `cphfb_folder_created_by` | `fbv_folder_created_by` | `int $created_by = 0` |
| `cphfb_will_check_author` | `fbv_will_check_author` | `bool` (reserved, the tree is shared) |
| `cphfb_ids_assigned_to_folder` | `fbv_ids_assigned_to_folder` | `int[] $attachment_ids` |
| `cphfb_all_folders_and_count` | `fbv_all_folders_and_count` | `string $sql, ?string $lang` |
| `cphfb_user_default_folder` | `fbv_user_default_folder` | `int $folder_id, int $user_id` |
| `cphfb_query_include_subfolders` | `fbv_query_include_subfolders` | `bool, int $folder_id` |
| `cphfb_can_delete_folder` | `fbv_can_delete_folder` | `bool, int $folder_id` |
| `cphfb_auto_create_folders` | `fbv_auto_create_folders` | `bool = true` |
| `cphfb_counter_type` | `fbv_counter_type` | `string $type` |
| `cphfb_speedup_get_count_query` | `fbv_speedup_get_count_query` | `bool = false` |
| `cphfb_download_filename` | `fbv_download_filename` | `string $zip_name, object $folder` |
| `cphfb_use_zipstream` | `fbv_use_zipstream` | `bool = true` |
| `cphfb_post_types` | `filebird_post_types` | `array $post_types` |

Plugin-only filters: `cphfb_update_repo_url` (update repository URL), `cphfb_csv_import_max_bytes` (default 10 MB).

## Constants

| Constant | Effect |
| --- | --- |
| `CPHFB_DISABLE_UPDATES` | `true` turns off the GitHub update checker. |
| `CPHFB_GITHUB_TOKEN` | Token for a private repository. Not needed once the repo is public. |
| `CPHFB_REMOVE_ALL_DATA` | `true` makes uninstall also drop the `fbv` tables and `fbv_*` options. |

## REST API

Namespace `cph-filebirb/v1`. Cookie auth needs an `X-WP-Nonce`. Reads and folder or file changes need `upload_files` (assignment also drops IDs the user cannot `edit_post`); global settings and CSV import need `manage_options`.

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/folders` | Folder tree |
| POST | `/folders` | Create a folder |
| PATCH | `/folders/{id}` | Rename, move, recolour |
| DELETE | `/folders/{id}` | Delete a folder and its subfolders |
| POST | `/folders/{id}/duplicate` | Duplicate a folder |
| POST | `/folders/order` | Save sibling order and parents |
| POST | `/assign` | Move attachments into a folder (0 = uncategorized) |
| GET | `/attachments/{id}/folder` | Folder of one attachment |
| GET | `/counts` | All, uncategorized and per-folder counts |
| GET, POST | `/settings` | Global settings (POST: `manage_options`) |
| GET, POST | `/user-settings` | Current user's settings |
| GET | `/export.csv` | CSV export |
| POST | `/import.csv` | CSV import (`file` upload or `csv` string) |

## WP-CLI

```
wp cphfb folder list|create|rename|delete|move|duplicate
wp cphfb assign <folder> <ids>...
wp cphfb counts
wp cphfb export-csv / import-csv
wp cphfb import-filebird
wp cphfb verify [--snapshot=<file>] [--compare=<file>]
wp cphfb cleanup
```

Run `wp help cphfb <command>` for options.

## Development

```
composer install
npm install
npx wp-env start
npm run start        # watch the frontend
```

Tests:

```
bin/test.sh                     # PHPUnit inside wp-env (serialised with a lock)
bin/test.sh --filter QueryTest
npm run test:e2e                # Playwright
composer phpcs                  # WordPress-Extra + PHPCompatibilityWP
composer phpcbf
```

Never run PHPUnit outside `bin/test.sh`: the WP test suite reinstalls its tables on boot, so parallel runs corrupt each other.

## Releases

Updates ship from GitHub releases through Plugin Update Checker. Every release needs an asset named exactly `cph-filebirb.zip`; the updater ignores releases without it (the tag's source zip has no `vendor/` or built assets).

1. Bump the version in `cph-filebirb.php` (header `Version:` and `CPHFB_VERSION`) and in `readme.txt` (`Stable tag:`). Add a changelog entry to `readme.txt`.
2. Commit and push.
3. Tag: `git tag vX.Y.Z && git push origin vX.Y.Z`.
4. Build: `bin/build-zip.sh` (runs `npm run build`, stages runtime files, installs a production `vendor/` in a temp copy, checks that nothing from `reference/` or FileBird's bundles is included, lints every PHP file and loads every class from the zip). Output: `dist/cph-filebirb.zip`.
5. Release: `gh release create vX.Y.Z dist/cph-filebirb.zip --title "vX.Y.Z" --notes "..."`.

Sites pick the update up on their next update check (Dashboard > Updates > Check again forces it).

## Cutover

`bin/cutover.sh` switches one site from FileBird to CPH FileBirb:

```
bin/cutover.sh --wp "wp --ssh=user@host/path" --zip https://github.com/dbreck/cph-filebirb/releases/download/vX.Y.Z/cph-filebirb.zip --dry-run
bin/cutover.sh --wp "wplocal mysite"
bin/cutover.sh --wp "npx wp-env run cli wp" --dry-run
```

It checks the tables, records folder and assignment counts from SQL, exports both tables to a local backup (never on the remote host), deactivates FileBird, installs and activates CPH FileBirb, runs `wp cphfb verify`, and compares counts. Any failure rolls back to FileBird and exits non-zero. FileBird's plugin files are never deleted. `--zip` is read on the site's host, so use a release URL over SSH. If the site's `cph-filebirb` folder is a symlink or dev checkout, the install step is skipped.
