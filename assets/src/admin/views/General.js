import { SelectControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { SettingsPanel } from '../components/SettingsPanel';

export function General() {
	return (
		<SettingsPanel>
			{ ( { values, change } ) => (
				<>
					<SelectControl
						label={ __( 'Site represents', 'openseo' ) }
						value={ values.schema_site_type }
						options={ [
							{
								label: __( 'Organization', 'openseo' ),
								value: 'Organization',
							},
							{
								label: __( 'Person', 'openseo' ),
								value: 'Person',
							},
						] }
						onChange={ ( v ) => change( 'schema_site_type', v ) }
					/>
					<TextControl
						label={ __(
							'Name (defaults to site name)',
							'openseo'
						) }
						value={ values.schema_site_name }
						onChange={ ( v ) => change( 'schema_site_name', v ) }
					/>
					<TextControl
						label={ __( 'Logo / image URL', 'openseo' ) }
						value={ values.schema_logo }
						onChange={ ( v ) => change( 'schema_logo', v ) }
					/>
				</>
			) }
		</SettingsPanel>
	);
}
