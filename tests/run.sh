#!/usr/bin/env bash
# Runs the test suite. Arguments go straight to PHPUnit, e.g.:
#   tests/run.sh                          # everything
#   tests/run.sh --testsuite Unit         # no database needed
#   tests/run.sh --filter PlaceQueue      # one area
set -euo pipefail

cd "$(dirname "$0")"
TESTS_DIR="$(pwd)"

export BADEVENT_CONFIG_DIR="$TESTS_DIR/.envConfig"
mkdir -p tmp/mail tmp/sessions

if [ ! -x vendor/bin/phpunit ]; then
    composer install --no-interaction --no-progress
fi

# sendmail_path can't be changed at runtime (ini_set), so it is passed here: every mail() call —
# login links, notifications — is stored in tests/tmp/mail/ instead of being sent.
exec php -d sendmail_path="$TESTS_DIR/bin/fake-sendmail" -d error_reporting=E_ALL vendor/bin/phpunit "$@"
