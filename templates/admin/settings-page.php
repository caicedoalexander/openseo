<?php
/**
 * Settings page template.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap openseo-settings">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<form action="options.php" method="post">
		<?php
		settings_fields( \OpenSEO\Settings\Options::OPTION_GROUP );
		do_settings_sections( 'openseo' );
		submit_button();
		?>
	</form>
</div>
