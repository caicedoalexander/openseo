import { TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { SettingsPanel } from '../components/SettingsPanel';
import { VerticalTabs } from '../components/VerticalTabs';
import { TemplateField } from '../components/TemplateField';
import { setTemplateField } from '../templateFields';

const bootstrap = window.openseoAdmin ?? {};
const contentTypes = bootstrap.contentTypes ?? {
	postTypes: [],
	taxonomies: [],
};
const catalog = bootstrap.variables ?? [];

const GROUPS = [
	{ tabs: [ { name: 'general', title: __( 'General', 'openseo' ) } ] },
	...( contentTypes.postTypes.length
		? [
				{
					label: __( 'Content types', 'openseo' ),
					tabs: contentTypes.postTypes.map( ( t ) => ( {
						name: `pt:${ t.slug }`,
						title: t.label,
					} ) ),
				},
		  ]
		: [] ),
	...( contentTypes.taxonomies.length
		? [
				{
					label: __( 'Taxonomies', 'openseo' ),
					tabs: contentTypes.taxonomies.map( ( t ) => ( {
						name: `tax:${ t.slug }`,
						title: t.label,
					} ) ),
				},
		  ]
		: [] ),
];

const TAB_NAMES = GROUPS.flatMap( ( g ) => g.tabs.map( ( t ) => t.name ) );

function GeneralPanel( { values, change } ) {
	return (
		<>
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Title separator', 'openseo' ) }
				value={ values.title_separator }
				onChange={ ( v ) => change( 'title_separator', v ) }
			/>
			<TemplateField
				label={ __( 'Homepage title', 'openseo' ) }
				value={ values.home_title }
				scope="global"
				catalog={ catalog }
				onChange={ ( v ) => change( 'home_title', v ) }
			/>
			<TemplateField
				label={ __( 'Homepage description', 'openseo' ) }
				value={ values.home_description }
				multiline
				scope="global"
				catalog={ catalog }
				onChange={ ( v ) => change( 'home_description', v ) }
			/>
		</>
	);
}

function TypePanel( { type, mapKey, scope, values, change } ) {
	const map = values[ mapKey ] ?? {};
	const entry = map[ type.slug ] ?? {};
	return (
		<>
			<TemplateField
				label={ __( 'Title', 'openseo' ) }
				value={ entry.title ?? '' }
				placeholder={ type.defaultTitle }
				scope={ scope }
				catalog={ catalog }
				onChange={ ( v ) =>
					change(
						mapKey,
						setTemplateField( map, type.slug, 'title', v )
					)
				}
			/>
			<TemplateField
				label={ __( 'Description', 'openseo' ) }
				value={ entry.description ?? '' }
				placeholder={ type.defaultDescription }
				multiline
				scope={ scope }
				catalog={ catalog }
				onChange={ ( v ) =>
					change(
						mapKey,
						setTemplateField( map, type.slug, 'description', v )
					)
				}
			/>
		</>
	);
}

function renderPanel( tab, values, change ) {
	if ( tab.startsWith( 'pt:' ) ) {
		const type = contentTypes.postTypes.find(
			( t ) => t.slug === tab.slice( 3 )
		);
		return type ? (
			<TypePanel
				type={ type }
				mapKey="post_types"
				scope="singular"
				values={ values }
				change={ change }
			/>
		) : null;
	}
	if ( tab.startsWith( 'tax:' ) ) {
		const type = contentTypes.taxonomies.find(
			( t ) => t.slug === tab.slice( 4 )
		);
		return type ? (
			<TypePanel
				type={ type }
				mapKey="taxonomies"
				scope="taxonomy"
				values={ values }
				change={ change }
			/>
		) : null;
	}
	return <GeneralPanel values={ values } change={ change } />;
}

export function Titles() {
	const [ active, setActive ] = useState( 'general' );
	const current = TAB_NAMES.includes( active ) ? active : 'general';

	return (
		<SettingsPanel>
			{ ( { values, change } ) => (
				<VerticalTabs
					groups={ GROUPS }
					active={ current }
					onSelect={ setActive }
				>
					{ ( tab ) => renderPanel( tab, values, change ) }
				</VerticalTabs>
			) }
		</SettingsPanel>
	);
}
