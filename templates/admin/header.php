<?php
/**
 * Shared branded header for OpenSEO admin pages.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="openseo-header">
	<span class="dashicons dashicons-search openseo-header__logo" aria-hidden="true"></span>
	<span class="openseo-header__title">OpenSEO</span>
	<span class="openseo-header__version"><?php echo esc_html( OPENSEO_VERSION ); ?></span>
</div>
