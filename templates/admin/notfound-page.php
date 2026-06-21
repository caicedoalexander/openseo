<?php
/**
 * 404s page wrapper.
 *
 * @package OpenSEO
 *
 * @var \OpenSEO\NotFound\Admin\NotFoundListTable $openseo_notfound_table Injected by the page controller.
 * @var \OpenSEO\Settings\Options                 $openseo_options        Injected by the page controller.
 * @var \OpenSEO\Settings\BehaviorSettings        $openseo_behavior       Renders the toggle form.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap openseo-admin">
	<?php require OPENSEO_PLUGIN_DIR . 'templates/admin/header.php'; ?>
	<h1><?php echo esc_html__( '404s', 'openseo' ); ?></h1>

	<?php settings_errors(); ?>

	<h2><?php echo esc_html__( 'Settings', 'openseo' ); ?></h2>
	<?php $openseo_behavior->render_notfound_form(); ?>

	<?php require OPENSEO_PLUGIN_DIR . 'templates/admin/notfound-panel.php'; ?>
</div>
