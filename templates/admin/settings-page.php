<?php
/**
 * Settings page template.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$openseo_tabs = array(
	'general'   => __( 'General', 'openseo' ),
	'titles'    => __( 'Titles & Meta', 'openseo' ),
	'social'    => __( 'Social', 'openseo' ),
	'sitemaps'  => __( 'Sitemaps', 'openseo' ),
	'redirects' => __( 'Redirects', 'openseo' ),
	'schema'    => __( 'Schema', 'openseo' ),
	'ai'        => __( 'AI', 'openseo' ),
);

// Read-only tab selector; the form posts to options.php and saves all sections.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab is not a state change; sanitize_key is sufficient.
$openseo_active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
if ( ! isset( $openseo_tabs[ $openseo_active ] ) ) {
	$openseo_active = 'general';
}
?>
<div class="wrap openseo-settings">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<nav class="nav-tab-wrapper">
		<?php foreach ( $openseo_tabs as $openseo_slug => $openseo_label ) : ?>
			<?php
			$openseo_tab_url   = add_query_arg(
				array(
					'page' => 'openseo',
					'tab'  => $openseo_slug,
				),
				admin_url( 'options-general.php' )
			);
			$openseo_tab_class = 'nav-tab' . ( $openseo_active === $openseo_slug ? ' nav-tab-active' : '' );
			?>
			<a href="<?php echo esc_url( $openseo_tab_url ); ?>" class="<?php echo esc_attr( $openseo_tab_class ); ?>">
				<?php echo esc_html( $openseo_label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<form action="options.php" method="post">
		<?php
		settings_fields( \OpenSEO\Settings\Options::OPTION_GROUP );
		do_settings_sections( 'openseo_' . $openseo_active );
		submit_button();
		?>
	</form>
</div>
