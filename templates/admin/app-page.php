<?php
/**
 * React mount page for OpenSEO settings views.
 *
 * @package OpenSEO
 *
 * @var string $openseo_view Server-set view id (closed list).
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap openseo-admin">
	<?php require OPENSEO_PLUGIN_DIR . 'templates/admin/header.php'; ?>
	<h1 class="screen-reader-text"><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<div id="openseo-app" data-view="<?php echo esc_attr( $openseo_view ); ?>"></div>
</div>
