import { TextControl, TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { SettingsPanel } from '../components/SettingsPanel';

export function Titles() {
	return (
		<SettingsPanel>
			{ ( { values, change } ) => (
				<>
					<TextControl
						label={ __( 'Title separator', 'openseo' ) }
						value={ values.title_separator }
						onChange={ ( v ) => change( 'title_separator', v ) }
					/>
					<TextControl
						label={ __( 'Default title template', 'openseo' ) }
						value={ values.title_template }
						onChange={ ( v ) => change( 'title_template', v ) }
					/>
					<TextareaControl
						label={ __(
							'Default description template',
							'openseo'
						) }
						value={ values.description_template }
						onChange={ ( v ) =>
							change( 'description_template', v )
						}
					/>
					<TextControl
						label={ __( 'Homepage title', 'openseo' ) }
						value={ values.home_title }
						onChange={ ( v ) => change( 'home_title', v ) }
					/>
					<TextareaControl
						label={ __( 'Homepage description', 'openseo' ) }
						value={ values.home_description }
						onChange={ ( v ) => change( 'home_description', v ) }
					/>
				</>
			) }
		</SettingsPanel>
	);
}
