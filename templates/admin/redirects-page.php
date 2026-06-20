<?php
/**
 * Redirects manager template.
 *
 * @package OpenSEO
 *
 * @var string                        $tab             Active sub-tab.
 * @var string                        $prefill         Pre-filled source path.
 * @var \OpenSEO\Redirects\Repository $openseo_repo    Injected by the page controller.
 * @var \OpenSEO\Settings\Options     $openseo_options Injected by the page controller.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php echo esc_html__( 'OpenSEO Redirects', 'openseo' ); ?></h1>

	<nav class="nav-tab-wrapper">
		<a href="<?php echo esc_url( admin_url( 'tools.php?page=openseo-redirects&tab=redirects' ) ); ?>" class="<?php echo esc_attr( 'nav-tab ' . ( 'redirects' === $tab ? 'nav-tab-active' : '' ) ); ?>"><?php echo esc_html__( 'Redirections', 'openseo' ); ?></a>
		<a href="<?php echo esc_url( admin_url( 'tools.php?page=openseo-redirects&tab=notfound' ) ); ?>" class="<?php echo esc_attr( 'nav-tab ' . ( 'notfound' === $tab ? 'nav-tab-active' : '' ) ); ?>"><?php echo esc_html__( '404 Monitor', 'openseo' ); ?></a>
	</nav>

	<?php if ( 'redirects' === $tab ) : ?>
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

		<?php
		$openseo_table = new \OpenSEO\Redirects\Admin\RedirectsListTable( $openseo_repo );
		$openseo_table->prepare_items();
		?>
		<form method="get">
			<input type="hidden" name="page" value="openseo-redirects" />
			<?php
			$openseo_table->search_box( __( 'Search', 'openseo' ), 'openseo-redirect' );
			$openseo_table->display();
			?>
		</form>
	<?php else : ?>
		<?php require OPENSEO_PLUGIN_DIR . 'templates/admin/notfound-panel.php'; ?>
	<?php endif; ?>
</div>
