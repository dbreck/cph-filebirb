#!/usr/bin/env bash
# Per-site cutover from FileBird (Pro or free) to CPH FileBirb.
#
# Usage:
#   bin/cutover.sh --wp "<wp-cli prefix>" [--zip <path|url>] [--backup-dir <dir>] [--dry-run]
#
# --wp          How to run WP-CLI against the site. Examples:
#                 --wp "wp --ssh=user@host/path"      (Flywheel / any SSH host)
#                 --wp "wplocal mysite"               (Local WP; the zsh wplocal function)
#                 --wp "npx wp-env run cli wp"        (wp-env dev site)
#                 --wp "wp --path=/var/www/html"      (plain)
# --zip         Plugin zip to install (default dist/cph-filebird.zip). WP-CLI reads this
#               path on the *site's* host, so for --ssh pass a URL the server can download.
#               If the site's cph-filebird folder is a symlink or a dev checkout, the
#               install is skipped and the existing copy is activated instead.
# --backup-dir  Local directory for the table backup (default ./cutover-backups).
#               Backups are always written on this machine, never on the remote host
#               (Flywheel home directories are wiped without warning).
# --dry-run     Read-only: run the checks and counts, print every write it would do.
#
# Steps: pre-check tables, record folder/assignment counts from SQL, export both tables
# locally, deactivate filebird-pro/filebird, install (--force) and activate cph-filebird,
# run `wp cphfb verify`, compare counts. Any failure after the swap rolls back
# (deactivate cph-filebird, reactivate FileBird) and exits non-zero.
# FileBird's plugin files are never deleted. Sites without FileBird are supported: the
# deactivate step is skipped and the counts and verify still run.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WP_PREFIX=""
ZIP="$ROOT/dist/cph-filebird.zip"
BACKUP_DIR="$PWD/cutover-backups"
DRY_RUN=0
SLUG="cph-filebird"

while [ $# -gt 0 ]; do
	case "$1" in
		--wp) WP_PREFIX="${2:-}"; shift 2 ;;
		--wp=*) WP_PREFIX="${1#*=}"; shift ;;
		--zip) ZIP="${2:-}"; shift 2 ;;
		--zip=*) ZIP="${1#*=}"; shift ;;
		--backup-dir) BACKUP_DIR="${2:-}"; shift 2 ;;
		--backup-dir=*) BACKUP_DIR="${1#*=}"; shift ;;
		--dry-run) DRY_RUN=1; shift ;;
		-h|--help) sed -n '2,27p' "$0"; exit 0 ;;
		*) echo "Unknown option: $1" >&2; exit 2 ;;
	esac
done

[ -n "$WP_PREFIX" ] || { echo "Missing --wp \"<wp-cli prefix>\". See --help." >&2; exit 2; }

log()  { printf '==> %s\n' "$*"; }
warn() { printf 'WARNING: %s\n' "$*" >&2; }
die()  { printf 'CUTOVER FAILED: %s\n' "$*" >&2; exit 1; }

# Run WP-CLI through the prefix. `wplocal` is a zsh function, so it goes through zsh.
read -r -a WP_WORDS <<<"$WP_PREFIX"
wp_run() {
	if [ "${WP_WORDS[0]}" = "wplocal" ] && ! command -v wplocal >/dev/null 2>&1; then
		zsh -ic 'wplocal "$@"' wplocal "${WP_WORDS[@]:1}" "$@"
	else
		"${WP_WORDS[@]}" "$@"
	fi
}

# Writes: printed in dry-run, executed otherwise.
wp_write() {
	if [ "$DRY_RUN" -eq 1 ]; then
		printf '    [dry-run] would run: %s %s\n' "$WP_PREFIX" "$*"
		return 0
	fi
	wp_run "$@"
}

# First integer on the last non-empty line of a query result.
sql_int() {
	wp_run db query "$1" --skip-column-names 2>/dev/null | tr -d '\r' | grep -E '^[0-9]+$' | tail -n 1
}

log "Checking WordPress via: $WP_PREFIX"
wp_run core is-installed >/dev/null 2>&1 || die "WP-CLI could not reach an installed WordPress with that prefix."

PREFIX="$(wp_run db prefix 2>/dev/null | tr -d '\r' | grep -v '^$' | tail -n 1)"
[ -n "$PREFIX" ] || die "Could not read the table prefix."
T_FOLDERS="${PREFIX}fbv"
T_ASSIGN="${PREFIX}fbv_attachment_folder"

table_exists() {
	wp_run db query "SHOW TABLES LIKE '$1'" --skip-column-names 2>/dev/null | tr -d '\r' | grep -qx "$1"
}

HAVE_TABLES=1
for t in "$T_FOLDERS" "$T_ASSIGN"; do
	if ! table_exists "$t"; then
		HAVE_TABLES=0
		warn "Table $t does not exist."
	fi
done

# Which FileBird is active (site-wide or network-wide)?
ACTIVE="$(wp_run plugin list --status=active,active-network --field=name 2>/dev/null | tr -d '\r')"
FILEBIRD=""
for candidate in filebird-pro filebird; do
	if printf '%s\n' "$ACTIVE" | grep -qx "$candidate"; then
		FILEBIRD="$candidate"
		break
	fi
done
NETWORK_FLAG=()
if [ -n "$FILEBIRD" ] && wp_run plugin list --status=active-network --field=name 2>/dev/null | tr -d '\r' | grep -qx "$FILEBIRD"; then
	NETWORK_FLAG=(--network)
fi

if [ -n "$FILEBIRD" ]; then
	log "FileBird plugin active: $FILEBIRD ${NETWORK_FLAG[*]:-}"
	[ "$HAVE_TABLES" -eq 1 ] || die "FileBird is active but its tables are missing; refusing to continue."
else
	log "FileBird is not active on this site; skipping deactivation."
fi

if [ "$HAVE_TABLES" -eq 1 ]; then
	PRE_FOLDERS="$(sql_int "SELECT COUNT(*) FROM \`$T_FOLDERS\`")"
	PRE_ASSIGN="$(sql_int "SELECT COUNT(*) FROM \`$T_ASSIGN\`")"
else
	PRE_FOLDERS=0
	PRE_ASSIGN=0
fi
[ -n "$PRE_FOLDERS" ] && [ -n "$PRE_ASSIGN" ] || die "Could not count rows in the FileBird tables."
log "Pre-swap counts: folders=$PRE_FOLDERS assignments=$PRE_ASSIGN"

# Backup (local file, streamed from the remote over stdout).
STAMP="$(date +%Y%m%d-%H%M%S)"
SITE_TAG="$(printf '%s' "$WP_PREFIX" | tr -c 'A-Za-z0-9._-' '_' | cut -c1-60)"
BACKUP="$BACKUP_DIR/fbv-${SITE_TAG}-${STAMP}.sql"
if [ "$HAVE_TABLES" -eq 1 ]; then
	if [ "$DRY_RUN" -eq 1 ]; then
		printf '    [dry-run] would export %s,%s to %s\n' "$T_FOLDERS" "$T_ASSIGN" "$BACKUP"
	else
		mkdir -p "$BACKUP_DIR" || die "Cannot create $BACKUP_DIR"
		wp_run db export - --tables="$T_FOLDERS,$T_ASSIGN" >"$BACKUP" 2>/dev/null || die "Table export failed."
		grep -q "CREATE TABLE \`$T_ASSIGN\`" "$BACKUP" || die "Backup $BACKUP looks incomplete."
		log "Backup written to $BACKUP ($(wc -c <"$BACKUP" | tr -d ' ') bytes)"
	fi
else
	log "No tables to back up."
fi

# From here on, failures roll back.
rollback() {
	warn "Rolling back: $1"
	if [ "$DRY_RUN" -eq 0 ]; then
		wp_run plugin deactivate "$SLUG" ${NETWORK_FLAG[@]+"${NETWORK_FLAG[@]}"} >/dev/null 2>&1 || true
		if [ -n "$FILEBIRD" ]; then
			wp_run plugin activate "$FILEBIRD" ${NETWORK_FLAG[@]+"${NETWORK_FLAG[@]}"} || warn "Could not reactivate $FILEBIRD, do it by hand."
		fi
	fi
	die "$1"
}

if [ -n "$FILEBIRD" ]; then
	log "Deactivating $FILEBIRD"
	wp_write plugin deactivate "$FILEBIRD" ${NETWORK_FLAG[@]+"${NETWORK_FLAG[@]}"} || rollback "Could not deactivate $FILEBIRD."
fi

# Never overwrite a symlinked or dev-checkout copy (Local sites link to the dev source;
# wp-env mounts the repo).
DEV_COPY="$(wp_run eval '$d = WP_PLUGIN_DIR . "/cph-filebird"; echo ( is_link( $d ) || file_exists( $d . "/composer.json" ) ) ? "yes" : "no";' --skip-plugins --skip-themes 2>/dev/null | tr -d '\r' | grep -v '^$' | tail -n 1)"
if [ "$DEV_COPY" = "yes" ]; then
	log "cph-filebird is a symlink or dev checkout on this site; skipping install, activating it."
else
	case "$ZIP" in
		http://*|https://*) ;;
		*) [ "$DRY_RUN" -eq 1 ] || [ -f "$ZIP" ] || rollback "Zip not found: $ZIP" ;;
	esac
	log "Installing $ZIP"
	wp_write plugin install "$ZIP" --force || rollback "Plugin install failed."
fi

log "Activating $SLUG"
wp_write plugin activate "$SLUG" ${NETWORK_FLAG[@]+"${NETWORK_FLAG[@]}"} || rollback "Activation failed."

if [ "$DRY_RUN" -eq 1 ]; then
	if wp_run plugin is-active "$SLUG" >/dev/null 2>&1; then
		log "cph-filebird is already active; running verify read-only."
		wp_run cphfb verify || warn "verify reported a problem."
	else
		printf '    [dry-run] would run: %s cphfb verify\n' "$WP_PREFIX"
	fi
	log "Dry run complete. Nothing was changed."
	exit 0
fi

log "Running wp cphfb verify"
wp_run cphfb verify || rollback "wp cphfb verify failed."

POST_FOLDERS="$(sql_int "SELECT COUNT(*) FROM \`$T_FOLDERS\`")"
POST_ASSIGN="$(sql_int "SELECT COUNT(*) FROM \`$T_ASSIGN\`")"
log "Post-swap counts: folders=$POST_FOLDERS assignments=$POST_ASSIGN"
if [ "$POST_FOLDERS" != "$PRE_FOLDERS" ] || [ "$POST_ASSIGN" != "$PRE_ASSIGN" ]; then
	rollback "Counts changed (folders $PRE_FOLDERS -> $POST_FOLDERS, assignments $PRE_ASSIGN -> $POST_ASSIGN)."
fi

log "Cutover complete. FileBird's plugin files were left in place; delete them once you're happy."
[ "$HAVE_TABLES" -eq 1 ] && log "Backup: $BACKUP"
exit 0
