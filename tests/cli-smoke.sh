#!/usr/bin/env bash
# Smoke test for `wp cphfb …` against the wp-env development site.
# Builds a small tree under a uniquely named root, exercises every command,
# then deletes everything it created. Never restarts or destroys wp-env.
# Usage: tests/cli-smoke.sh
set -euo pipefail
cd "$(dirname "$0")/.."

wp() { npx wp-env run cli wp "$@" 2>/dev/null; }
sh_in() { npx wp-env run cli bash -c "$1" 2>/dev/null; }
pass() { echo "ok - $*"; }
fail() { echo "FAIL - $*" >&2; exit 1; }
expect() { # expect <haystack> <needle> <label>
	grep -qF -- "$2" <<<"$1" || { echo "$1" >&2; fail "$3 (missing: $2)"; }
	pass "$3"
}

ROOT_NAME="cphfb-smoke-$$"
TMP="/tmp/cphfb-smoke-$$"
ATT=()
ROOT=""

cleanup() {
	set +e
	for id in $(wp cphfb folder list --format=json | python3 -c "import json,sys; print(' '.join(str(f['id']) for f in json.load(sys.stdin) if f['name'].startswith('cphfb-smoke-') or f['name'].startswith('Smoke Import')))"); do
		wp cphfb folder delete "$id" --yes >/dev/null
	done
	[ ${#ATT[@]} -gt 0 ] && wp post delete "${ATT[@]}" --force >/dev/null
	return 0
}
trap 'cleanup; sh_in "rm -rf $TMP" >/dev/null' EXIT

wp plugin is-active cph-filebirb || wp plugin activate cph-filebirb >/dev/null
sh_in "mkdir -p $TMP"

# Baseline snapshot, compared again at the very end (after cleanup of our data).
wp cphfb verify --snapshot="$TMP/base.json" >/dev/null
pass "verify --snapshot (baseline)"

# Attachments.
for n in 1 2 3; do
	ATT+=( "$(wp post create --post_type=attachment --post_status=inherit --post_mime_type=image/png --post_title="cphfb smoke $n" --porcelain | tr -dc '0-9')" )
done
[ ${#ATT[@]} -eq 3 ] || fail "create attachments"
pass "created attachments ${ATT[*]}"

# folder create.
ROOT=$(wp cphfb folder create "$ROOT_NAME" --porcelain | tr -dc '0-9')
[ -n "$ROOT" ] || fail "folder create --porcelain"
CHILD=$(wp cphfb folder create Logos --parent="$ROOT" --porcelain | tr -dc '0-9')
OTHER=$(wp cphfb folder create Hero --parent="$ROOT" --porcelain | tr -dc '0-9')
out=$(wp cphfb folder create Logos --parent="$ROOT")
expect "$out" 'Logos (1)' "folder create dedupes sibling names"
DUPE=$(grep -oE 'folder [0-9]+' <<<"$out" | tr -dc '0-9')

# folder list.
out=$(wp cphfb folder list --tree)
expect "$out" "  Logos" "folder list --tree indents"
out=$(wp cphfb folder list --format=ids)
expect "$out" "$CHILD" "folder list --format=ids"
out=$(wp cphfb folder list --format=csv)
expect "$out" "id,name,parent,ord,count" "folder list --format=csv header"

# folder rename / move / duplicate / delete.
expect "$(wp cphfb folder rename "$DUPE" Icons)" "Success" "folder rename"
expect "$(wp cphfb folder move "$DUPE" "$CHILD" --ord=0)" "Success" "folder move"
out=$(wp cphfb folder list --format=json)
expect "$out" "\"id\":$DUPE,\"name\":\"Icons\",\"parent\":$CHILD" "move/rename persisted"
COPY=$(wp cphfb folder duplicate "$CHILD" --porcelain | tr -dc '0-9')
expect "$(wp cphfb folder list --format=json)" "\"id\":$COPY,\"name\":\"Logos (Copy)\"" "folder duplicate"
expect "$(wp cphfb folder delete "$COPY" --yes)" "Success" "folder delete --yes (subtree)"
if wp cphfb folder rename 999999 x >/dev/null; then fail "rename of missing folder should error"; fi
pass "model WP_Error becomes non-zero exit"

# assign.
expect "$(wp cphfb assign "$CHILD" "${ATT[0]}" "${ATT[1]}")" "Assigned 2" "assign by id"
expect "$(wp cphfb assign "$ROOT_NAME/Hero" "${ATT[2]}")" "folder $OTHER" "assign by path"
if wp cphfb assign "$ROOT_NAME/Nope" "${ATT[2]}" >/dev/null; then fail "missing path should error"; fi
pass "assign to missing path errors without --create"
out=$(wp cphfb assign "$ROOT_NAME/Made/Here" "${ATT[2]}" --create)
expect "$out" "Assigned 1" "assign --create makes path"
expect "$(wp cphfb folder list --tree)" "    Here" "created path is nested"
expect "$(wp cphfb assign 0 "${ATT[2]}")" "Unassigned 1" "assign 0 unassigns"

# counts.
out=$(wp cphfb counts --format=json)
expect "$out" "{\"id\":$CHILD,\"name\":\"Logos\",\"count\":2}" "counts per folder"
expect "$out" '"name":"Uncategorized"' "counts uncategorized row"

# children-up delete keeps the grandchild.
expect "$(wp cphfb folder delete "$CHILD" --mode=children-up --yes)" "Success" "folder delete --mode=children-up"
expect "$(wp cphfb folder list --format=json)" "\"id\":$DUPE,\"name\":\"Icons\",\"parent\":$ROOT" "children-up reparented"

# export / import CSV.
out=$(wp cphfb export-csv)
expect "$out" "id,name,parent,type,ord,created_by,attachment_ids" "export-csv to stdout"
expect "$(wp cphfb export-csv "$TMP/out.csv")" "Exported" "export-csv to file"
sh_in "printf 'id,name,parent,type,ord,created_by,attachment_ids\n901,Smoke Import,0,0,0,0,${ATT[0]}\n902,Child,901,0,0,0,${ATT[1]}\n' > $TMP/in.csv"
expect "$(wp cphfb import-csv "$TMP/in.csv")" "Imported 2 folder(s) and 2 assignment(s)" "import-csv"

# import-filebird.
expect "$(wp cphfb import-filebird)" "Nothing to import" "import-filebird no-op"

# verify, orphan detection, cleanup.
out=$(wp cphfb verify --format=json)
expect "$out" '"schema": "ok"' "verify --format=json"
wp db query "INSERT INTO wp_fbv_attachment_folder (folder_id, attachment_id) VALUES ($ROOT, 99999999)" >/dev/null
out=$(npx wp-env run cli wp cphfb verify 2>&1)
expect "$out" "orphan" "verify warns about orphan rows (exit 0)"
if wp cphfb verify --compare="$TMP/base.json" >/dev/null; then fail "compare should fail after changes"; fi
pass "verify --compare exits non-zero on mismatch"
expect "$(wp cphfb cleanup)" "Removed 1 orphaned" "cleanup"

# Tear down our data, then the counts must match the baseline again.
cleanup
ATT=()
set -e
expect "$(wp cphfb verify --compare="$TMP/base.json")" "Counts match the snapshot" "verify --compare matches baseline after cleanup"

echo "All CLI smoke checks passed."
