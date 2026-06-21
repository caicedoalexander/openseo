import { ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { SettingsPanel } from '../components/SettingsPanel';

export function Sitemaps() {
	return (
		<SettingsPanel>
			{ ( { values, change } ) => (
				<>
					<ToggleControl
						label={ __( 'Enable XML sitemap', 'openseo' ) }
						checked={ values.sitemap_enabled === '1' }
						onChange={ ( on ) =>
							change( 'sitemap_enabled', on ? '1' : '' )
						}
					/>
					<ToggleControl
						label={ __( 'Include author sitemap', 'openseo' ) }
						checked={ values.sitemap_include_authors === '1' }
						onChange={ ( on ) =>
							change( 'sitemap_include_authors', on ? '1' : '' )
						}
					/>
				</>
			) }
		</SettingsPanel>
	);
}
