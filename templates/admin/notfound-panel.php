<?php
/**
 * 404 monitor list (body of the 404s page).
 *
 * @package OpenSEO
 *
 * @var \OpenSEO\NotFound\Admin\NotFoundListTable $openseo_notfound_table Injected by the page controller.
 * @var \OpenSEO\Settings\Options                 $openseo_options        Injected by the page controller.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<h2><?php echo esc_html__( 'Logged 404s', 'openseo' ); ?></h2>
<?php if ( '1' !== (string) $openseo_options->get( 'notfound_monitor_enabled' ) ) : ?>
	<p><?php echo esc_html__( 'The 404 monitor is off. Enable it in the settings above.', 'openseo' ); ?></p>
<?php endif; ?>
<form method="get">
	<input type="hidden" name="page" value="openseo-404s" />
	<?php $openseo_notfound_table->display(); ?>
</form>
