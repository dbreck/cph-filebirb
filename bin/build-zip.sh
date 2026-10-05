#!/usr/bin/env bash
# Build the release zip: dist/cph-filebirb.zip with a top-level cph-filebirb/ folder
# holding only runtime files and a production vendor/.
#
# Usage: bin/build-zip.sh [--skip-build]
#   --skip-build  Use the existing assets/build/ instead of running `npm run build`.
#
# The working tree's vendor/ (dev dependencies) is never touched: composer runs in a
# temporary staging copy. Fails if anything from reference/ or FileBird's compiled
# bundles could end up in the zip, then verifies the zip (php -l + class loading).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="cph-filebirb"
DIST="$ROOT/dist"
ZIP="$DIST/$SLUG.zip"
SKIP_BUILD=0

for arg in "$@"; do
	case "$arg" in
		--skip-build) SKIP_BUILD=1 ;;
		-h|--help) sed -n '2,10p' "$0"; exit 0 ;;
		*) echo "Unknown option: $arg" >&2; exit 2 ;;
	esac
done

fail() { echo "BUILD FAILED: $*" >&2; exit 1; }

command -v composer >/dev/null || fail "composer not found"
command -v zip >/dev/null || fail "zip not found"
command -v php >/dev/null || fail "php not found"

cd "$ROOT"

if [ "$SKIP_BUILD" -eq 0 ]; then
	echo "==> npm run build"
	npm run build
fi
[ -d assets/build ] && [ -n "$(ls -A assets/build 2>/dev/null)" ] || fail "assets/build is missing or empty (run npm run build)"

WORK="$(mktemp -d "${TMPDIR:-/tmp}/cphfb-build.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT
STAGE="$WORK/$SLUG"
mkdir -p "$STAGE"

echo "==> Staging runtime files"
cp cph-filebirb.php uninstall.php readme.txt composer.json composer.lock "$STAGE/"
# Copy with rsync so dotfiles and OS junk stay out.
RSYNC_EXCLUDES=(--exclude '.*' --exclude '*.map' --exclude 'Thumbs.db')
rsync -a "${RSYNC_EXCLUDES[@]}" includes/ "$STAGE/includes/"
mkdir -p "$STAGE/assets"
rsync -a "${RSYNC_EXCLUDES[@]}" assets/build/ "$STAGE/assets/build/"
if [ -d languages ]; then
	rsync -a "${RSYNC_EXCLUDES[@]}" languages/ "$STAGE/languages/"
fi

echo "==> composer install --no-dev (staging copy)"
composer install --working-dir="$STAGE" --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --quiet
# composer.json/lock are build inputs, not runtime files.
rm -f "$STAGE/composer.json" "$STAGE/composer.lock"
# Drop dotfiles and docs/tests shipped inside dependencies.
find "$STAGE/vendor" -name '.*' -not -name '.' -prune -exec rm -rf {} +

echo "==> Licence and leakage checks"
# Nothing from reference/ or FileBird's Envato-licensed compiled assets may ship.
if find "$STAGE" -path '*filebird-pro*' -o -path '*reference*' | grep -q .; then
	find "$STAGE" -path '*filebird-pro*' -o -path '*reference*' >&2
	fail "staged tree contains filebird-pro/ or reference/ paths"
fi
if grep -rIl -e 'njt-fbv' -e 'njt_fbv' -e 'fbv_data' -e 'NinjaTeam' -e 'ninjateam' "$STAGE" >"$WORK/markers.txt" 2>/dev/null; then
	cat "$WORK/markers.txt" >&2
	fail "staged tree contains FileBird bundle markers"
fi
if [ -d reference/filebird-pro/assets/dist ]; then
	# Byte-identical copies of any FileBird compiled file, wherever they ended up.
	while IFS= read -r ref; do
		sum="$(shasum -a 256 "$ref" | cut -d' ' -f1)"
		if find "$STAGE" -type f -exec shasum -a 256 {} + | grep -q "^$sum "; then
			fail "staged tree contains a copy of $ref"
		fi
	done < <(find reference/filebird-pro/assets/dist -type f)
fi
for banned in tests node_modules assets/src reference bin PLAN.md CLAUDE.md package.json webpack.config.js playwright.config.js phpunit.xml.dist phpcs.xml.dist; do
	[ ! -e "$STAGE/$banned" ] || fail "staged tree contains $banned"
done
[ ! -d "$STAGE/vendor/phpunit" ] || fail "dev dependencies leaked into vendor/"

echo "==> Zipping"
mkdir -p "$DIST"
rm -f "$ZIP"
(cd "$WORK" && zip -rqX "$ZIP" "$SLUG")

echo "==> Verifying zip"
VERIFY="$WORK/verify"
mkdir -p "$VERIFY"
unzip -q "$ZIP" -d "$VERIFY"
[ -d "$VERIFY/$SLUG" ] || fail "zip has no top-level $SLUG/ folder"
[ "$(ls -A "$VERIFY")" = "$SLUG" ] || fail "zip has more than one top-level entry"

LINT_ERRORS=0
while IFS= read -r -d '' file; do
	if ! out="$(php -l "$file" 2>&1)"; then
		echo "$out" >&2
		LINT_ERRORS=$((LINT_ERRORS + 1))
	fi
done < <(find "$VERIFY/$SLUG" -name '*.php' -not -path '*/vendor/*' -print0)
[ "$LINT_ERRORS" -eq 0 ] || fail "$LINT_ERRORS PHP files failed php -l"

# Every class under includes/ must load from the zip's own autoloader.
cat >"$WORK/classes.php" <<'PHP'
<?php
// Minimal stand-ins so files guarded by `defined( 'ABSPATH' ) || exit;` load.
define( 'ABSPATH', __DIR__ . '/' );
$root = $argv[1];
require $root . '/vendor/autoload.php';
$missing = array();
$count   = 0;
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	if ( 'php' !== $file->getExtension() ) {
		continue;
	}
	$relative = substr( $file->getPathname(), strlen( $root . '/includes/' ), -4 );
	$class    = 'CPH\\FileBirb\\' . str_replace( '/', '\\', $relative );
	++$count;
	if ( ! class_exists( $class, true ) ) {
		$missing[] = $class;
	}
}
if ( ! class_exists( 'YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
	$missing[] = 'YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';
}
if ( $missing ) {
	fwrite( STDERR, "Missing classes:\n  " . implode( "\n  ", $missing ) . "\n" );
	exit( 1 );
}
echo "All {$count} includes/ classes and PucFactory load from the zip.\n";
PHP
php "$WORK/classes.php" "$VERIFY/$SLUG" || fail "class loading check failed"

echo
echo "==> Contents"
unzip -l "$ZIP" | awk 'NR>3 {print $4}' | grep -v '^$' | grep -v '/vendor/' || true
echo "    (+ $(unzip -l "$ZIP" | grep -c '/vendor/') vendor/ entries)"
echo
echo "Built $ZIP ($(du -h "$ZIP" | cut -f1), $(wc -c <"$ZIP" | tr -d ' ') bytes)"
