<?php
/**
 * Redirects manager page.
 *
 * @package OpenSEO
 *
 * @var \OpenSEO\Redirects\Repository             $openseo_repo     Injected by the page controller.
 * @var string                                    $prefill          Pre-filled source path.
 * @var \OpenSEO\Settings\BehaviorSettings        $openseo_behavior Renders the toggle form.
 * @var \OpenSEO\Redirects\Admin\RedirectsListTable $openseo_table  Prepared list table.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap openseo-admin">
	<?php require OPENSEO_PLUGIN_DIR . 'templates/admin/header.php'; ?>
	<h1><?php echo esc_html__( 'Redirects', 'openseo' ); ?></h1>

	<?php settings_errors(); ?>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only status flag.
	$openseo_msg = isset( $_GET['openseo_msg'] ) ? sanitize_key( wp_unslash( $_GET['openseo_msg'] ) ) : '';

	$openseo_notices = array(
		'saved'         => array( 'success', __( 'Redirect saved.', 'openseo' ) ),
		'invalid'       => array( 'error', __( 'Could not save: check the source, target, and type.', 'openseo' ) ),
		'invalid_regex' => array( 'error', __( 'Could not save: the regex pattern is invalid.', 'openseo' ) ),
		'cycle'         => array( 'error', __( 'Could not save: this would create a redirect loop with an existing rule.', 'openseo' ) ),
		'delete'        => array( 'success', __( 'Redirect deleted.', 'openseo' ) ),
		'enable'        => array( 'success', __( 'Redirect enabled.', 'openseo' ) ),
		'disable'       => array( 'success', __( 'Redirect disabled.', 'openseo' ) ),
	);

	if ( isset( $openseo_notices[ $openseo_msg ] ) ) {
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $openseo_notices[ $openseo_msg ][0] ),
			esc_html( $openseo_notices[ $openseo_msg ][1] )
		);
	}
	?>

	<h2><?php echo esc_html__( 'Settings', 'openseo' ); ?></h2>
	<?php $openseo_behavior->render_redirects_form(); ?>

	<h2><?php echo esc_html__( 'Add redirect', 'openseo' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="openseo_save_redirect" />
		<?php wp_nonce_field( 'openseo_save_redirect' ); ?>
		<table class="form-table">
			<tr>
				<th><label for="openseo_source"><?php echo esc_html__( 'Source path', 'openseo' ); ?></label></th>
				<td><input type="text" id="openseo_source" name="source_path" class="regular-text" value="<?php echo esc_attr( $prefill ); ?>" required /></td>
			</tr>
			<tr>
				<th><label for="openseo_target"><?php echo esc_html__( 'Target', 'openseo' ); ?></label></th>
				<td><input type="text" id="openseo_target" name="target" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="openseo_status"><?php echo esc_html__( 'Type', 'openseo' ); ?></label></th>
				<td>
					<select id="openseo_status" name="status_code">
						<option value="301">301</option>
						<option value="302">302</option>
						<option value="307">307</option>
						<option value="410">410</option>
					</select>
					<label><input type="checkbox" name="is_regex" value="1" /> <?php echo esc_html__( 'Regex', 'openseo' ); ?></label>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Save redirect', 'openseo' ) ); ?>
	</form>

	<form method="get">
		<input type="hidden" name="page" value="openseo-redirects" />
		<?php
		$openseo_table->search_box( __( 'Search', 'openseo' ), 'openseo-redirect' );
		$openseo_table->display();
		?>
	</form>
</div>
