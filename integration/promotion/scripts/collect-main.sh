#!/usr/bin/env sh
set -eu
. /promotion/scripts/common.sh
wait_for_wordpress_files
wait_for_database
$WP eval-file /promotion/scripts/collect-state.php main > /artifacts/collection-main.json
