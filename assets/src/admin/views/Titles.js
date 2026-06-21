import { TextControl, TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { SettingsPanel } from '../components/SettingsPanel';
import { TemplateGroup } from '../components/TemplateGroup';

const contentTypes = window.openseoAdmin?.contentTypes ?? {
	postTypes: [],
	taxonomies: [],
};

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
						label={ __( 'Homepage title', 'openseo' ) }
						value={ values.home_title }
						onChange={ ( v ) => change( 'home_title', v ) }
					/>
					<TextareaControl
						label={ __( 'Homepage description', 'openseo' ) }
						value={ values.home_description }
						onChange={ ( v ) => change( 'home_description', v ) }
					/>

					<h2>{ __( 'Content types', 'openseo' ) }</h2>
					<TemplateGroup
						types={ contentTypes.postTypes }
						mapKey="post_types"
						values={ values }
						change={ change }
					/>

					<h2>{ __( 'Taxonomies', 'openseo' ) }</h2>
					<TemplateGroup
						types={ contentTypes.taxonomies }
						mapKey="taxonomies"
						values={ values }
						change={ change }
					/>
				</>
			) }
		</SettingsPanel>
	);
}
