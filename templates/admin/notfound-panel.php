<?php
/**
 * 404 monitor panel (sub-tab of the redirects manager).
 *
 * @package OpenSEO
 *
 * @var \OpenSEO\NotFound\Admin\NotFoundListTable $openseo_notfound_table Injected by the page controller.
 * @var \OpenSEO\Settings\Options                 $openseo_options        Injected by the page controller.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<h2><?php echo esc_html__( '404 Monitor', 'openseo' ); ?></h2>
<?php if ( '1' !== (string) $openseo_options->get( 'notfound_monitor_enabled' ) ) : ?>
	<p>
		<?php echo esc_html__( 'The 404 monitor is off.', 'openseo' ); ?>
		<a href="<?php echo esc_url( admin_url( 'options-general.php?page=openseo&tab=redirects' ) ); ?>"><?php echo esc_html__( 'Enable it in Settings → OpenSEO → Redirects.', 'openseo' ); ?></a>
	</p>
<?php endif; ?>
<form method="get">
	<input type="hidden" name="page" value="openseo-redirects" />
	<input type="hidden" name="tab" value="notfound" />
	<?php $openseo_notfound_table->display(); ?>
</form>
