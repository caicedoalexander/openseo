import {
	CheckboxControl,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { SettingsPanel } from '../components/SettingsPanel';
import { VerticalTabs } from '../components/VerticalTabs';
import { TemplateField } from '../components/TemplateField';
import { MediaField } from '../components/MediaField';
import { SeparatorField } from '../components/SeparatorField';
import { AdvancedRobotsField } from '../components/AdvancedRobotsField';
import { setTemplateField } from '../templateFields';
import { ROBOTS_DIRECTIVES } from '../robots';
import { RobotsFields, ROBOTS_LABELS } from '../components/RobotsFields';

const bootstrap = window.openseoAdmin ?? {};
const contentTypes = bootstrap.contentTypes ?? {
	postTypes: [],
	taxonomies: [],
};
const catalog = bootstrap.variables ?? [];

const TWITTER_CARD_OPTIONS = [
	{
		label: __( 'Summary card with large image', 'openseo' ),
		value: 'summary_large_image',
	},
	{ label: __( 'Summary card', 'openseo' ), value: 'summary' },
];

const GROUPS = [
	{
		tabs: [
			{ name: 'meta-global', title: __( 'Meta Global', 'openseo' ) },
			{ name: 'homepage', title: __( 'Homepage', 'openseo' ) },
		],
	},
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

function MetaGlobalPanel( { values, change } ) {
	const robots = values.robots ?? {};

	return (
		<>
			<SeparatorField
				value={ values.title_separator ?? '' }
				onChange={ ( v ) => change( 'title_separator', v ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Capitalize titles', 'openseo' ) }
				help={ __(
					'Automatically capitalize the first letter of each word in titles.',
					'openseo'
				) }
				checked={ values.capitalize_titles === '1' }
				onChange={ ( on ) =>
					change( 'capitalize_titles', on ? '1' : '' )
				}
			/>
			<h3>{ __( 'Default robots', 'openseo' ) }</h3>
			{ ROBOTS_DIRECTIVES.map( ( directive ) => (
				<CheckboxControl
					key={ directive }
					__nextHasNoMarginBottom
					label={ ROBOTS_LABELS[ directive ] }
					checked={ robots[ directive ] === '1' }
					onChange={ ( on ) =>
						change( 'robots', {
							...robots,
							[ directive ]: on ? '1' : '',
						} )
					}
				/>
			) ) }
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Noindex empty term archives', 'openseo' ) }
				checked={ robots.noindex_empty_terms === '1' }
				onChange={ ( on ) =>
					change( 'robots', {
						...robots,
						noindex_empty_terms: on ? '1' : '',
					} )
				}
			/>
			<AdvancedRobotsField
				value={ values.advanced_robots ?? {} }
				onChange={ ( v ) => change( 'advanced_robots', v ) }
			/>
			<h3>{ __( 'OpenGraph thumbnail', 'openseo' ) }</h3>
			<MediaField
				label={ __(
					'Default image used when a post has no featured or social image.',
					'openseo'
				) }
				value={ values.og_default_image ?? '' }
				onChange={ ( url ) => change( 'og_default_image', url ) }
			/>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Twitter card type', 'openseo' ) }
				value={ values.twitter_card_type ?? 'summary_large_image' }
				options={ TWITTER_CARD_OPTIONS }
				onChange={ ( v ) => change( 'twitter_card_type', v ) }
			/>
		</>
	);
}

function HomepagePanel( { values, change } ) {
	return (
		<>
			<TemplateField
				label={ __( 'Homepage title', 'openseo' ) }
				value={ values.home_title ?? '' }
				scope="global"
				catalog={ catalog }
				onChange={ ( v ) => change( 'home_title', v ) }
			/>
			<TemplateField
				label={ __( 'Homepage description', 'openseo' ) }
				value={ values.home_description ?? '' }
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
			<h3>{ __( 'Robots', 'openseo' ) }</h3>
			<RobotsFields
				robots={ entry.robots }
				onChange={ ( nextRobots ) =>
					change( mapKey, {
						...map,
						[ type.slug ]: { ...entry, robots: nextRobots },
					} )
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
	if ( tab === 'homepage' ) {
		return <HomepagePanel values={ values } change={ change } />;
	}
	return <MetaGlobalPanel values={ values } change={ change } />;
}

export function Titles() {
	const [ active, setActive ] = useState( 'meta-global' );
	const current = TAB_NAMES.includes( active ) ? active : 'meta-global';

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
