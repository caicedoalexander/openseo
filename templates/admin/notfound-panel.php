<?php
/**
 * 404 monitor panel (sub-tab of the redirects manager).
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// The 404 panel self-constructs its (stateless) collaborators on purpose: this
// keeps the Part A RedirectsPage constructor free of the Part B LogRepository,
// so redirects work even if the 404 monitor is never built.
$openseo_logs  = new \OpenSEO\NotFound\LogRepository();
$openseo_table = new \OpenSEO\NotFound\Admin\NotFoundListTable( $openseo_logs );
$openseo_table->prepare_items();
?>
<h2><?php echo esc_html__( '404 Monitor', 'openseo' ); ?></h2>
<?php if ( '1' !== (string) ( new \OpenSEO\Settings\Options() )->get( 'notfound_monitor_enabled' ) ) : ?>
	<p>
		<?php echo esc_html__( 'The 404 monitor is off.', 'openseo' ); ?>
		<a href="<?php echo esc_url( admin_url( 'options-general.php?page=openseo&tab=redirects' ) ); ?>"><?php echo esc_html__( 'Enable it in Settings → OpenSEO → Redirects.', 'openseo' ); ?></a>
	</p>
<?php endif; ?>
<form method="get">
	<input type="hidden" name="page" value="openseo-redirects" />
	<input type="hidden" name="tab" value="notfound" />
	<?php $openseo_table->display(); ?>
</form>
