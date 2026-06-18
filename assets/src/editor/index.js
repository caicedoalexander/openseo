import { registerPlugin } from '@wordpress/plugins';
// WP 7.0: PluginDocumentSettingPanel lives in @wordpress/editor
// (it was removed from @wordpress/edit-post).
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useEntityProp } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import {
	TextControl,
	TextareaControl,
	ToggleControl,
	TabPanel,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { buildSnippetPreview } from './preview';

function useMeta( key ) {
	const postType = useSelect(
		( select ) => select( editorStore ).getCurrentPostType(),
		[]
	);
	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );

	const value = meta?.[ key ] ?? '';
	const update = ( next ) => setMeta( { ...meta, [ key ]: next } );

	return [ value, update ];
}

function GeneralTab() {
	const [ title, setTitle ] = useMeta( '_openseo_title' );
	const [ description, setDescription ] = useMeta( '_openseo_description' );

	const siteName = useSelect(
		( select ) => select( 'core' ).getSite?.()?.title ?? '',
		[]
	);

	const preview = buildSnippetPreview( {
		title,
		description,
		separator: '-',
		siteName,
	} );

	return (
		<>
			<TextControl
				label={ __( 'SEO title', 'openseo' ) }
				value={ title }
				onChange={ setTitle }
				help={ `${ title.length } / 60` }
			/>
			<TextareaControl
				label={ __( 'Meta description', 'openseo' ) }
				value={ description }
				onChange={ setDescription }
				help={ `${ description.length } / 160` }
			/>
			{ ( preview.title || preview.description ) && (
				<div
					style={ {
						marginTop: '8px',
						padding: '8px',
						background: '#f6f7f7',
						borderLeft: '3px solid #007cba',
						fontSize: '12px',
					} }
				>
					<strong style={ { display: 'block', color: '#1a0dab' } }>
						{ preview.title }
					</strong>
					<span style={ { color: '#545454' } }>
						{ preview.description }
					</span>
				</div>
			) }
		</>
	);
}

function SocialTab() {
	const [ ogTitle, setOgTitle ] = useMeta( '_openseo_og_title' );
	const [ ogDescription, setOgDescription ] = useMeta(
		'_openseo_og_description'
	);
	const [ ogImage, setOgImage ] = useMeta( '_openseo_og_image' );

	return (
		<>
			<TextControl
				label={ __( 'Social title', 'openseo' ) }
				value={ ogTitle }
				onChange={ setOgTitle }
			/>
			<TextareaControl
				label={ __( 'Social description', 'openseo' ) }
				value={ ogDescription }
				onChange={ setOgDescription }
			/>
			<TextControl
				label={ __( 'Social image URL', 'openseo' ) }
				value={ ogImage }
				onChange={ setOgImage }
			/>
		</>
	);
}

function AdvancedTab() {
	const [ noindex, setNoindex ] = useMeta( '_openseo_robots_noindex' );
	const [ nofollow, setNofollow ] = useMeta( '_openseo_robots_nofollow' );
	const [ canonical, setCanonical ] = useMeta( '_openseo_canonical' );

	return (
		<>
			<ToggleControl
				label={ __( 'No index', 'openseo' ) }
				checked={ noindex === '1' }
				onChange={ ( on ) => setNoindex( on ? '1' : '' ) }
			/>
			<ToggleControl
				label={ __( 'No follow', 'openseo' ) }
				checked={ nofollow === '1' }
				onChange={ ( on ) => setNofollow( on ? '1' : '' ) }
			/>
			<TextControl
				label={ __( 'Canonical URL', 'openseo' ) }
				value={ canonical }
				onChange={ setCanonical }
			/>
		</>
	);
}

const TABS = [
	{ name: 'general', title: __( 'General', 'openseo' ) },
	{ name: 'social', title: __( 'Social', 'openseo' ) },
	{ name: 'advanced', title: __( 'Advanced', 'openseo' ) },
];

function OpenSeoPanel() {
	return (
		<PluginDocumentSettingPanel
			name="openseo-panel"
			title={ __( 'OpenSEO', 'openseo' ) }
		>
			<TabPanel tabs={ TABS }>
				{ ( tab ) => {
					if ( tab.name === 'social' ) {
						return <SocialTab />;
					}
					if ( tab.name === 'advanced' ) {
						return <AdvancedTab />;
					}
					return <GeneralTab />;
				} }
			</TabPanel>
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'openseo-editor', { render: OpenSeoPanel } );
