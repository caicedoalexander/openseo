<?php
/**
 * Admin asset loading for the OpenSEO menu screens.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Admin;

use OpenSEO\Ai\Connector;
use OpenSEO\Contracts\Hookable;
use OpenSEO\Meta\TemplateDefaults;
use OpenSEO\NotFound\LogRepository;
use OpenSEO\Redirects\Repository;
use OpenSEO\Settings\ContentTypes;
use OpenSEO\Meta\VariableCatalog;
use OpenSEO\Settings\Options;

/**
 * Enqueues the CSS + React app + window.openseoAdmin bootstrap on every
 * OpenSEO admin screen. Screen targeting uses the hook suffixes Menu
 * captured at registration; all screens are React.
 */
final class Assets implements Hookable {

	private const STYLE_HANDLE  = 'openseo-admin-settings';
	private const SCRIPT_HANDLE = 'openseo-admin-app';

	/**
	 * Constructor.
	 *
	 * @param Menu             $menu          Source of OpenSEO screen hook suffixes.
	 * @param Options          $options       Settings accessor (initial bootstrap state).
	 * @param Repository       $redirects     Redirect repository (dashboard count).
	 * @param LogRepository    $not_found_log 404 log (dashboard count).
	 * @param ContentTypes     $content_types Registered post types and taxonomies.
	 * @param TemplateDefaults $defaults      Per-surface default title/description templates.
	 * @param VariableCatalog  $variable_catalog Template variables catalog (inserter).
	 */
	public function __construct(
		private readonly Menu $menu,
		private readonly Options $options,
		private readonly Repository $redirects,
		private readonly LogRepository $not_found_log,
		private readonly ContentTypes $content_types,
		private readonly TemplateDefaults $defaults,
		private readonly VariableCatalog $variable_catalog,
	) {}

	/**
	 * Register the enqueue hook.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue CSS + JS + bootstrap on all OpenSEO screens (all screens are React).
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->menu->screen_hooks(), true ) ) {
			return;
		}

		$asset_path = OPENSEO_PLUGIN_DIR . 'assets/build/admin-settings.asset.php';

		if ( ! is_readable( $asset_path ) ) {
			return;
		}

		$asset   = require $asset_path;
		$version = $asset['version'] ?? OPENSEO_VERSION;

		$style_path = OPENSEO_PLUGIN_DIR . 'assets/build/style-admin-settings.css';
		if ( is_readable( $style_path ) ) {
			wp_enqueue_style(
				self::STYLE_HANDLE,
				OPENSEO_PLUGIN_URL . 'assets/build/style-admin-settings.css',
				array(),
				$version
			);
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			OPENSEO_PLUGIN_URL . 'assets/build/admin-settings.js',
			$asset['dependencies'] ?? array(),
			$version,
			true
		);

		wp_enqueue_media();

		if ( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) ) {
			wp_add_inline_script(
				self::SCRIPT_HANDLE,
				'window.openseoAdmin = ' . wp_json_encode( $this->bootstrap( $hook_suffix ), JSON_HEX_TAG ) . ';',
				'before'
			);
		}

		wp_set_script_translations( self::SCRIPT_HANDLE, 'openseo' );
	}

	/**
	 * Decorate slug/label entries with the per-surface default templates.
	 *
	 * @param array<int, array{slug:string, label:string}> $types               Slug/label pairs.
	 * @param string                                       $default_title       Default title template.
	 * @param string                                       $default_description Default description template.
	 * @return array<int, array{slug:string, label:string, defaultTitle:string, defaultDescription:string}>
	 */
	private function content_type_entries( array $types, string $default_title, string $default_description ): array {
		return array_map(
			static fn( array $type ): array => array(
				'slug'               => $type['slug'],
				'label'              => $type['label'],
				'defaultTitle'       => $default_title,
				'defaultDescription' => $default_description,
			),
			$types
		);
	}

	/**
	 * Build the bootstrap payload. Dashboard counts only on the dashboard screen.
	 *
	 * @param string $hook_suffix Current screen hook.
	 * @return array<string, mixed>
	 */
	private function bootstrap( string $hook_suffix ): array {
		$data = array(
			'settings'     => $this->options->all(),
			'connector'    => array(
				'available' => Connector::is_text_generation_available(),
				'url'       => Connector::settings_url(),
			),
			'contentTypes' => array(
				'postTypes'  => $this->content_type_entries(
					$this->content_types->post_types(),
					$this->defaults->singular_title(),
					$this->defaults->singular_description()
				),
				'taxonomies' => $this->content_type_entries(
					$this->content_types->taxonomies(),
					$this->defaults->taxonomy_title(),
					$this->defaults->taxonomy_description()
				),
			),
			'variables'    => $this->variable_catalog->all(),
		);

		if ( $hook_suffix === $this->menu->dashboard_hook() ) {
			$data['dashboard'] = array(
				'redirects' => $this->redirects->count_active(),
				'notfound'  => $this->not_found_log->count_all(),
			);
		}

		return $data;
	}
}
