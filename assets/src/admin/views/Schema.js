import { TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { SettingsPanel } from '../components/SettingsPanel';

export function Schema() {
	return (
		<SettingsPanel>
			{ ( { values, change } ) => (
				<TextControl
					label={ __( 'Breadcrumb separator', 'openseo' ) }
					value={ values.breadcrumb_separator }
					onChange={ ( v ) => change( 'breadcrumb_separator', v ) }
				/>
			) }
		</SettingsPanel>
	);
}
