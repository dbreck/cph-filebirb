# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

CPH FileBirb is Clear pH's media-library folder plugin, a drop-in replacement for FileBird. It reads and writes FileBird's own tables, so a site can swap one for the other with no migration.

This repo is public. Keep client names, site URLs, local filesystem paths and internal planning out of every tracked file. Machine- and client-specific notes go in `CLAUDE.local.md`, and the build plan lives in `PLAN.md`; both are gitignored.

## Identity

- Display name: **CPH FileBirb**
- Plugin slug, folder, main file, text domain: `cph-filebirb`
- PHP namespace: `CPH\FileBirb` (PSR-4 via Composer, root `includes/`)
- Prefix for options, hooks, user meta, handles: `cphfb_`
- REST namespace: `cph-filebirb/v1`
- WP-CLI root command: `wp cphfb`
- GitHub: `dbreck/cph-filebirb`; release asset `cph-filebirb.zip`

"FileBird" (with a d) always means the third-party plugin: its tables, its `fbv_*` hooks, the `filebird-pro` slug, the `import-filebird` command. Never rename those.

## Current State

- **Backend:** done. Models, query filtering, upload routing, REST, CLI, legacy hooks, settings page.
- **Media Library sidebar and `wp.media` modals:** done. Folder tree, uploader folder picker, drag attachments onto folders, "Move to folder…" picker.
- **Integrations:** verified on a real Salient + WPBakery site (grid, list, featured image, classic Add Media, WPBakery backend and frontend editors, Salient metaboxes, Redux theme options). Not yet verified: Nectar Slider and Home Slider image fields, ACF fields, Safari and Firefox drag and drop.
- **Distribution:** v0.1.0 is released. The updater, with no token, sees the release and its `cph-filebirb.zip` asset. Not yet exercised: a real old-to-new update on a site, installing from the zip on a non-symlinked site, and `bin/cutover.sh` over SSH.
- **Extra features** (auto-foldering, folder templates, smart folders, zip download and so on): none chosen, none built.

Keep this section current.

## Architecture

All classes are `final` singletons with `get_instance()`; the private constructor registers hooks. One class per file. PHP 8.1+, WP 6.5+. The main plugin file must stay parseable on PHP 7 so its version guard can run.

- `includes/Plugin.php`: bootstrap; refuses to boot while FileBird is active
- `includes/Install.php`: creates the two tables with FileBird's exact DDL, only when missing
- `includes/Hooks.php`: dual-fire helper and the single `cphfb_*` to `fbv_*` name map
- `includes/Model/`: `Folder`, `Assignment`, `Settings`, `UserSettings` (all SQL lives here)
- `includes/Query.php`, `includes/Upload.php`: media query filtering and upload routing
- `includes/Rest/`: controllers for `cph-filebirb/v1`; they validate, authorise and call the models
- `includes/Admin/`: `Assets`, `ListTable`, `AttachmentFields`, `SettingsPage`
- `includes/Cli/`: `wp cphfb` commands
- `includes/Csv.php`, `includes/Updater.php`
- `assets/src/`: React sidebar built with `@wordpress/scripts`; `assets/build/` is committed

## Hard Rules

**Data compatibility.** Tables `{prefix}fbv` and `{prefix}fbv_attachment_folder` must stay byte-for-byte compatible with FileBird's schema. If a column or key looks like it needs to change, stop and ask.

**Licensing.** FileBird's PHP is GPL and may be studied. Its compiled frontend bundles and stylesheet are under Envato's licence: never copy, bundle or deobfuscate them. The frontend here is a from-scratch rewrite, and `bin/build-zip.sh` fails if FileBird assets end up in the zip. Any local FileBird copy lives in the gitignored `reference/` folder.

**Hooks.** Every mutation fires the `cphfb_*` hook first, then its `fbv_*` twin with the same args, through `Hooks::action()` / `Hooks::filter()`.

**Coexistence.** If FileBird is active, show a notice and do nothing else. Never run both.

**Uninstall.** Never drop the `fbv` tables or `fbv_*` options unless `CPHFB_REMOVE_ALL_DATA` is true.

## Decisions

- **Query var is `fbv`**, same as FileBird. `-1` all, `0` uncategorized, `N` folder. JS attachment models carry `fbv` and `folder_id`.
- **Upload routing order:** `X-CPHFB-Folder` header, `cphfb_folder` request var, legacy `fbv` request var (numeric, or path form `12/Sub/Sub2` that auto-creates folders up to 10 levels, gated by `fbv_auto_create_folders`), then the user's default upload folder. The first source present wins, even if invalid.
- **Legacy `fbv` upload param is not restricted by request type.** It only works for users with `upload_files`, who can already do the same through REST.
- **One shared folder tree, no per-user mode.** New folders get `created_by = 0`; reads never filter by owner; an attachment lives in at most one folder.
- **Permissions:** `upload_files` for reads, folder writes and assignment (IDs also filtered by `edit_post`). `manage_options` for global settings and CSV import.
- **Options:** folder colors stay in FileBird's `fbv_folder_colors`. Global settings in `cphfb_settings`, seeded once from FileBird's. Per-user settings in user meta `cphfb_user_settings`.
- **Updates:** public GitHub releases through Plugin Update Checker, no token and no activation step. `CPHFB_GITHUB_TOKEN` is honoured if defined.

## Testing

Tests must pass before a step is called done.

- PHPUnit: `bin/test.sh [phpunit args]` (wp-env; serialised with a lock, so never call phpunit directly)
- Browser: `npm run test:e2e` (Playwright against the wp-env dev site at `http://localhost:8888`). One Playwright run at a time.
- CLI: `bash tests/cli-smoke.sh` (writes to the wp-env dev site and cleans up)
- Real site: `tests/e2e/salient-site.spec.js`, skipped unless `CPHFB_SITE_URL` and `CPHFB_SITE_WP` are set (see the file header)

## Releases

Bump `Version:` and `CPHFB_VERSION` in `cph-filebirb.php` and `Stable tag` plus changelog in `readme.txt`, commit, tag `vX.Y.Z`, run `bin/build-zip.sh`, then `gh release create vX.Y.Z dist/cph-filebirb.zip`. The updater ignores releases without an asset named exactly `cph-filebirb.zip`.

Commit per logical step with clear messages.
