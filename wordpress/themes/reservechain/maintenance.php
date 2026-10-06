<?php
/**
 * Maintenance mode holding page (served with HTTP 503 by the Core plugin).
 *
 * @package ReserveChain\Theme
 */

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html lang="<?php echo esc_attr( rct_lang() ); ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>ReserveChain — <?php esc_html_e( 'Scheduled maintenance', 'reservechain' ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( RCT_URI . '/assets/css/main.css?v=' . RCT_VERSION ); ?>"></head>
<body class="rc-maint">
<main class="rc-wrap rc-maint__box">
	<?php echo rct_logo(); // phpcs:ignore ?>
	<h1><?php esc_html_e( 'Scheduled maintenance', 'reservechain' ); ?></h1>
	<p><?php esc_html_e( 'ReserveChain is being updated. Please check back shortly.', 'reservechain' ); ?></p>
	<p class="rc-maint__notice"><?php echo esc_html( rct_disclosure() ); ?></p>
</main>
</body>
</html>
