import {
	Notice,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { SettingsPanel } from '../components/SettingsPanel';
import { VerticalTabs } from '../components/VerticalTabs';
import { TemplateField } from '../components/TemplateField';
import { MediaField } from '../components/MediaField';
import { SeparatorField } from '../components/SeparatorField';
import { AdvancedRobotsField } from '../components/AdvancedRobotsField';
import { LocalBusinessFields } from '../components/LocalBusinessFields';
import { setTemplateField } from '../templateFields';
import { RobotsFields } from '../components/RobotsFields';
import { RobotsCheckboxes } from '../components/RobotsCheckboxes';

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

// Order kept consistent with the editor's SCHEMA_OPTIONS (Task 10).
const SCHEMA_TYPE_OPTIONS = [
	{ label: 'Article', value: 'Article' },
	{ label: 'BlogPosting', value: 'BlogPosting' },
	{ label: 'NewsArticle', value: 'NewsArticle' },
	{ label: 'WebPage', value: 'WebPage' },
	{ label: __( 'None', 'openseo' ), value: 'none' },
];

const GROUPS = [
	{
		tabs: [
			{ name: 'meta-global', title: __( 'Meta Global', 'openseo' ) },
			{ name: 'seo-local', title: __( 'SEO Local', 'openseo' ) },
			{ name: 'homepage', title: __( 'Homepage', 'openseo' ) },
			{ name: 'authors', title: __( 'Authors', 'openseo' ) },
			{ name: 'other-pages', title: __( 'Other pages', 'openseo' ) },
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
			<RobotsCheckboxes
				map={ robots }
				onChange={ ( next ) => change( 'robots', next ) }
			/>
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

function SeoLocalPanel( { values, change } ) {
	return (
		<>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Site represents', 'openseo' ) }
				value={ values.schema_site_type ?? 'Organization' }
				options={ [
					{
						label: __( 'Organization', 'openseo' ),
						value: 'Organization',
					},
					{ label: __( 'Person', 'openseo' ), value: 'Person' },
				] }
				onChange={ ( v ) => change( 'schema_site_type', v ) }
			/>
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Website name', 'openseo' ) }
				help={ __(
					'Name of the WebSite node (defaults to site name).',
					'openseo'
				) }
				value={ values.local_website_name ?? '' }
				onChange={ ( v ) => change( 'local_website_name', v ) }
			/>
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Alternate website name', 'openseo' ) }
				value={ values.local_website_alternate_name ?? '' }
				onChange={ ( v ) =>
					change( 'local_website_alternate_name', v )
				}
			/>
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Person or Organization name', 'openseo' ) }
				help={ __(
					'Name of the Organization/Person entity (defaults to site name).',
					'openseo'
				) }
				value={ values.schema_site_name ?? '' }
				onChange={ ( v ) => change( 'schema_site_name', v ) }
			/>
			<h3>{ __( 'Logo', 'openseo' ) }</h3>
			<MediaField
				label={ __( 'Minimum size 112×112px.', 'openseo' ) }
				value={ values.schema_logo ?? '' }
				onChange={ ( url ) => change( 'schema_logo', url ) }
			/>
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'URL', 'openseo' ) }
				help={ __( 'Defaults to the site URL.', 'openseo' ) }
				value={ values.local_url ?? '' }
				onChange={ ( v ) => change( 'local_url', v ) }
			/>
			<TextControl
				__nextHasNoMarginBottom
				type="email"
				label={ __( 'Email', 'openseo' ) }
				value={ values.local_email ?? '' }
				onChange={ ( v ) => change( 'local_email', v ) }
			/>
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Phone', 'openseo' ) }
				value={ values.local_phone ?? '' }
				onChange={ ( v ) => change( 'local_phone', v ) }
			/>
			{ ( values.schema_site_type ?? 'Organization' ) ===
				'Organization' && (
				<LocalBusinessFields values={ values } change={ change } />
			) }
		</>
	);
}

function HomepagePanel( { values, change } ) {
	const homeRobots = values.home_robots ?? {};

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
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Custom homepage robots', 'openseo' ) }
				help={ __(
					'Override the global robots meta for the homepage.',
					'openseo'
				) }
				checked={ values.home_robots_custom === '1' }
				onChange={ ( on ) =>
					change( 'home_robots_custom', on ? '1' : '' )
				}
			/>
			{ values.home_robots_custom === '1' && (
				<RobotsCheckboxes
					map={ homeRobots }
					onChange={ ( next ) => change( 'home_robots', next ) }
				/>
			) }
			<h3>{ __( 'Homepage social (OpenGraph)', 'openseo' ) }</h3>
			<TemplateField
				label={ __( 'Homepage title for Facebook', 'openseo' ) }
				value={ values.home_og_title ?? '' }
				scope="global"
				catalog={ catalog }
				onChange={ ( v ) => change( 'home_og_title', v ) }
			/>
			<TemplateField
				label={ __( 'Homepage description for Facebook', 'openseo' ) }
				value={ values.home_og_description ?? '' }
				multiline
				scope="global"
				catalog={ catalog }
				onChange={ ( v ) => change( 'home_og_description', v ) }
			/>
			<MediaField
				label={ __(
					'Homepage thumbnail for Facebook (min. 1200×630px).',
					'openseo'
				) }
				value={ values.home_og_image ?? '' }
				onChange={ ( url ) => change( 'home_og_image', url ) }
			/>
		</>
	);
}

function AuthorsPanel( { values, change } ) {
	const authorRobots = values.author_robots ?? {};

	return (
		<>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Author archives', 'openseo' ) }
				help={ __(
					'When off, author archives redirect to the homepage.',
					'openseo'
				) }
				checked={ values.author_archives === '1' }
				onChange={ ( on ) =>
					change( 'author_archives', on ? '1' : '' )
				}
			/>
			<TemplateField
				label={ __( 'Author archive title', 'openseo' ) }
				value={ values.author_title ?? '' }
				scope="author"
				catalog={ catalog }
				onChange={ ( v ) => change( 'author_title', v ) }
			/>
			<TemplateField
				label={ __( 'Author archive description', 'openseo' ) }
				value={ values.author_description ?? '' }
				multiline
				scope="author"
				catalog={ catalog }
				onChange={ ( v ) => change( 'author_description', v ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Custom author robots', 'openseo' ) }
				checked={ values.author_robots_custom === '1' }
				onChange={ ( on ) =>
					change( 'author_robots_custom', on ? '1' : '' )
				}
			/>
			{ values.author_robots_custom === '1' && (
				<RobotsCheckboxes
					map={ authorRobots }
					onChange={ ( next ) => change( 'author_robots', next ) }
				/>
			) }
		</>
	);
}

function OtherPagesPanel( { values, change } ) {
	return (
		<>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Date archives', 'openseo' ) }
				help={ __(
					'When off, date archives redirect to the homepage.',
					'openseo'
				) }
				checked={ values.date_archives === '1' }
				onChange={ ( on ) => change( 'date_archives', on ? '1' : '' ) }
			/>
			<TemplateField
				label={ __( '404 title', 'openseo' ) }
				value={ values.title_404 ?? '' }
				scope="global"
				catalog={ catalog }
				onChange={ ( v ) => change( 'title_404', v ) }
			/>
			<TemplateField
				label={ __( 'Search results title', 'openseo' ) }
				value={ values.search_title ?? '' }
				scope="search"
				catalog={ catalog }
				onChange={ ( v ) => change( 'search_title', v ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Noindex search results', 'openseo' ) }
				checked={ values.noindex_search === '1' }
				onChange={ ( on ) => change( 'noindex_search', on ? '1' : '' ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Noindex paginated pages', 'openseo' ) }
				checked={ values.noindex_paginated === '1' }
				onChange={ ( on ) =>
					change( 'noindex_paginated', on ? '1' : '' )
				}
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Noindex paginated single pages', 'openseo' ) }
				checked={ values.noindex_paginated_singular === '1' }
				onChange={ ( on ) =>
					change( 'noindex_paginated_singular', on ? '1' : '' )
				}
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Noindex password-protected pages', 'openseo' ) }
				checked={ values.noindex_password_protected === '1' }
				onChange={ ( on ) =>
					change( 'noindex_password_protected', on ? '1' : '' )
				}
			/>
		</>
	);
}

function TypePanel( { type, mapKey, scope, values, change } ) {
	const map = values[ mapKey ] ?? {};
	const entry = map[ type.slug ] ?? {};
	const isPostType = mapKey === 'post_types';

	const setField = ( field, value ) =>
		change( mapKey, setTemplateField( map, type.slug, field, value ) );

	return (
		<>
			<TemplateField
				label={ __( 'Title', 'openseo' ) }
				value={ entry.title ?? '' }
				placeholder={ type.defaultTitle }
				scope={ scope }
				catalog={ catalog }
				onChange={ ( v ) => setField( 'title', v ) }
			/>
			<TemplateField
				label={ __( 'Description', 'openseo' ) }
				value={ entry.description ?? '' }
				placeholder={ type.defaultDescription }
				multiline
				scope={ scope }
				catalog={ catalog }
				onChange={ ( v ) => setField( 'description', v ) }
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
			{ isPostType && (
				<>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Default schema type', 'openseo' ) }
						value={ entry.schema_type ?? '' }
						options={ [
							{
								label: sprintf(
									/* translators: %s: automatic schema type for this content type. */
									__( 'Automatic (%s)', 'openseo' ),
									type.defaultSchemaType ?? ''
								),
								value: '',
							},
							...SCHEMA_TYPE_OPTIONS,
						] }
						onChange={ ( v ) => setField( 'schema_type', v ) }
					/>
					<MediaField
						label={ __(
							'Default social image for this content type.',
							'openseo'
						) }
						value={ entry.og_image ?? '' }
						onChange={ ( url ) => setField( 'og_image', url ) }
					/>
				</>
			) }
		</>
	);
}

function AttachmentsPanel( { type, values, change } ) {
	const redirect = values.attachment_redirect === '1';

	return (
		<>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Redirect attachment pages to the parent post',
					'openseo'
				) }
				help={ __(
					'Recommended: attachment pages are thin content. When on, their SEO templates below are disabled.',
					'openseo'
				) }
				checked={ redirect }
				onChange={ ( on ) =>
					change( 'attachment_redirect', on ? '1' : '' )
				}
			/>
			{ redirect ? (
				<>
					<TextControl
						__nextHasNoMarginBottom
						type="url"
						label={ __(
							'Fallback URL for attachments with no parent',
							'openseo'
						) }
						help={ __(
							'Used when an attachment has no parent post. Defaults to the homepage.',
							'openseo'
						) }
						value={ values.attachment_redirect_orphan ?? '' }
						onChange={ ( v ) =>
							change( 'attachment_redirect_orphan', v )
						}
					/>
					<Notice status="info" isDismissible={ false }>
						{ __(
							'Attachment SEO templates are disabled while redirection is on.',
							'openseo'
						) }
					</Notice>
				</>
			) : (
				<TypePanel
					type={ type }
					mapKey="post_types"
					scope="singular"
					values={ values }
					change={ change }
				/>
			) }
		</>
	);
}

function renderPanel( tab, values, change ) {
	if ( tab.startsWith( 'pt:' ) ) {
		const type = contentTypes.postTypes.find(
			( t ) => t.slug === tab.slice( 3 )
		);
		if ( ! type ) {
			return null;
		}
		if ( type.slug === 'attachment' ) {
			return (
				<AttachmentsPanel
					type={ type }
					values={ values }
					change={ change }
				/>
			);
		}
		return (
			<TypePanel
				type={ type }
				mapKey="post_types"
				scope="singular"
				values={ values }
				change={ change }
			/>
		);
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
	if ( tab === 'authors' ) {
		return <AuthorsPanel values={ values } change={ change } />;
	}
	if ( tab === 'other-pages' ) {
		return <OtherPagesPanel values={ values } change={ change } />;
	}
	if ( tab === 'homepage' ) {
		return <HomepagePanel values={ values } change={ change } />;
	}
	if ( tab === 'seo-local' ) {
		return <SeoLocalPanel values={ values } change={ change } />;
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
