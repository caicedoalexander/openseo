import { registerPlugin } from '@wordpress/plugins';
// WP 7.0: PluginDocumentSettingPanel lives in @wordpress/editor
// (it was removed from @wordpress/edit-post).
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useEntityProp } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import {
	Button,
	Notice,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
	TabPanel,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { buildSnippetPreview } from './preview';
import { aiErrorMessage } from './ai';

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

function GenerateButton( { abilityName, field, onResult } ) {
	const postId = useSelect(
		( select ) => select( editorStore ).getCurrentPostId(),
		[]
	);
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const aiAvailable = window.openseoEditor?.aiAvailable ?? false;
	const connectorsUrl = window.openseoEditor?.connectorsUrl ?? '';

	if ( ! aiAvailable ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'Connect an AI provider to generate suggestions.',
					'openseo'
				) }{ ' ' }
				<a href={ connectorsUrl }>
					{ __( 'Settings → Connectors', 'openseo' ) }
				</a>
			</Notice>
		);
	}

	const onClick = async () => {
		setBusy( true );
		setError( '' );

		try {
			const result = await apiFetch( {
				path: `/wp-abilities/v1/abilities/${ abilityName }/run`,
				method: 'POST',
				data: { input: { post_id: postId } },
			} );
			onResult( result?.[ field ] ?? '' );
		} catch ( e ) {
			setError( aiErrorMessage( e ) );
		} finally {
			setBusy( false );
		}
	};

	return (
		<>
			<Button
				variant="secondary"
				onClick={ onClick }
				isBusy={ busy }
				disabled={ busy }
			>
				{ busy
					? __( 'Generating…', 'openseo' )
					: __( 'Generate with AI', 'openseo' ) }
			</Button>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
		</>
	);
}

const SCHEMA_OPTIONS = [
	{ label: __( 'Default (automatic)', 'openseo' ), value: '' },
	{ label: 'Article', value: 'Article' },
	{ label: 'BlogPosting', value: 'BlogPosting' },
	{ label: 'NewsArticle', value: 'NewsArticle' },
	{ label: 'WebPage', value: 'WebPage' },
	{ label: __( 'None', 'openseo' ), value: 'none' },
];

const APPLICABLE_TYPES = [ 'Article', 'BlogPosting', 'NewsArticle', 'WebPage' ];

function SchemaField() {
	const [ type, setType ] = useMeta( '_openseo_schema_type' );
	const postId = useSelect(
		( select ) => select( editorStore ).getCurrentPostId(),
		[]
	);
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ suggestion, setSuggestion ] = useState( null );

	const aiAvailable = window.openseoEditor?.aiAvailable ?? false;

	const onRecommend = async () => {
		setBusy( true );
		setError( '' );
		setSuggestion( null );

		try {
			const result = await apiFetch( {
				path: '/wp-abilities/v1/abilities/openseo/suggest-schema-type/run',
				method: 'POST',
				data: { input: { post_id: postId } },
			} );
			setSuggestion( {
				type: result?.type ?? '',
				reason: result?.reason ?? '',
			} );
		} catch ( e ) {
			setError( aiErrorMessage( e ) );
		} finally {
			setBusy( false );
		}
	};

	return (
		<>
			<SelectControl
				label={ __( 'Schema type', 'openseo' ) }
				value={ type }
				options={ SCHEMA_OPTIONS }
				onChange={ setType }
			/>
			{ aiAvailable && (
				<Button
					variant="secondary"
					onClick={ onRecommend }
					isBusy={ busy }
					disabled={ busy }
				>
					{ busy
						? __( 'Analyzing…', 'openseo' )
						: __( 'Recommend with AI', 'openseo' ) }
				</Button>
			) }
			{ suggestion && (
				<Notice status="info" isDismissible={ false }>
					{ __( 'Recommended:', 'openseo' ) }{ ' ' }
					<strong>{ suggestion.type }</strong>
					{ suggestion.reason ? ` — ${ suggestion.reason }` : '' }
					{ APPLICABLE_TYPES.includes( suggestion.type ) && (
						<>
							{ ' ' }
							<Button
								variant="link"
								onClick={ () => setType( suggestion.type ) }
							>
								{ __( 'Apply', 'openseo' ) }
							</Button>
						</>
					) }
				</Notice>
			) }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
		</>
	);
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
			<GenerateButton
				abilityName="openseo/generate-title"
				field="title"
				onResult={ setTitle }
			/>
			<TextareaControl
				label={ __( 'Meta description', 'openseo' ) }
				value={ description }
				onChange={ setDescription }
				help={ `${ description.length } / 160` }
			/>
			<GenerateButton
				abilityName="openseo/generate-meta-description"
				field="meta_description"
				onResult={ setDescription }
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
			<SchemaField />
		</>
	);
}

function OpenSeoPanel() {
	const TABS = [
		{ name: 'general', title: __( 'General', 'openseo' ) },
		{ name: 'social', title: __( 'Social', 'openseo' ) },
		{ name: 'advanced', title: __( 'Advanced', 'openseo' ) },
	];

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
