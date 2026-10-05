#!/usr/bin/env bash
# Run PHPUnit inside wp-env. Serialised with a lock dir because the WP test
# suite reinstalls its tables on boot, so two runs at once corrupt each other.
# Usage: bin/test.sh [phpunit args], e.g. bin/test.sh --filter QueryTest
cd "$(dirname "$0")/.." || exit 1
LOCK=.phpunit.lock
while ! mkdir "$LOCK" 2>/dev/null; do sleep 2; done
trap 'rmdir "$LOCK"' EXIT
npx wp-env run tests-cli --env-cwd=wp-content/plugins/cph-filebird vendor/bin/phpunit "$@"
