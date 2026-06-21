import { TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { SettingsPanel } from '../components/SettingsPanel';

export function Social() {
	return (
		<SettingsPanel>
			{ ( { values, change } ) => (
				<TextControl
					label={ __( 'Default social image URL', 'openseo' ) }
					value={ values.og_default_image }
					onChange={ ( v ) => change( 'og_default_image', v ) }
				/>
			) }
		</SettingsPanel>
	);
}
