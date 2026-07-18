#!/usr/bin/env sh
set -eu
. /promotion/scripts/common.sh

wait_for_wordpress_files
wait_for_database
install_wordpress_core
install_woocommerce
configure_store
install_current_plugin
configure_plugin
$WP eval-file /promotion/seed.php
flush_runtime
prove_provider_readiness
$WP eval-file /promotion/scripts/readiness-hardening.php > /artifacts/clean-readiness-hardening.json
$WP eval-file /promotion/scripts/assert-current-install.php clean > /artifacts/clean-install.json
$WP eval-file /promotion/scripts/boot-probe.php > /artifacts/clean-boot.json
printf '%s  %s\n' "$PLUGIN_SHA256" 'yassin-ai-assistant.zip' > /artifacts/installed-package.sha256
printf '%s\n' 'Packaged plugin clean installation and activation passed.'
