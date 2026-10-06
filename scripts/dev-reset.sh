#!/usr/bin/env bash
# Rebuild the local DEV database from scratch: install WordPress, activate ReserveChain, seed demo data.
set -e
cd "$(dirname "$0")/.."
W="docker compose run --rm wpcli wp"
$W eval 'global $wpdb; $wpdb->query("SET FOREIGN_KEY_CHECKS=0"); foreach ($wpdb->get_col("SHOW TABLES") as $t) { $wpdb->query("DROP TABLE IF EXISTS `$t`"); } echo "tables dropped
";'
$W core install --url=http://localhost:8088 --title=ReserveChain --admin_user=rcadmin --admin_password='RC-Admin-Dev-2026!' --admin_email=dev@reservechain.invalid --skip-email
$W rewrite structure '/%postname%/' --hard
$W theme activate reservechain || true
$W plugin activate reservechain-core
$W rc seed
$W rc tamper-test
