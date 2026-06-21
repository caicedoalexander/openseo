import { useState, useEffect } from '@wordpress/element';
import {
	Button,
	Modal,
	TextControl,
	SelectControl,
	ToggleControl,
	Notice,
	Flex,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getQueryArg } from '@wordpress/url';
import { DataTable } from '../components/DataTable';
import { useRedirects } from '../hooks/useRedirects';
import { useSettings } from '../hooks/useSettings';

const STATUSES = [
	{ label: '301', value: '301' },
	{ label: '302', value: '302' },
	{ label: '307', value: '307' },
	{ label: '410', value: '410' },
];

const EMPTY_RULE = {
	id: 0,
	source_path: '',
	target: '',
	status_code: '301',
	is_regex: false,
	enabled: true,
};

// Strip a scheme-bearing, protocol-relative, or marked-up prefill before
// showing it (defense; the server validates again on save).
function safeSource( raw ) {
	const value = String( raw || '' );
	if (
		value.includes( ':' ) ||
		value.includes( '<' ) ||
		value.startsWith( '//' )
	) {
		return '';
	}
	return value;
}

export function Redirects() {
	const {
		items,
		total,
		loading,
		error,
		query,
		setQuery,
		save,
		remove,
		bulk,
	} = useRedirects();
	const settings = useSettings( window.openseoAdmin?.settings ?? {} );
	const [ editing, setEditing ] = useState( null ); // null = modal closed
	const [ formError, setFormError ] = useState( '' );
	const [ selected, setSelected ] = useState( [] );

	const openEditor = ( rule ) => {
		setFormError( '' );
		setEditing( rule );
	};

	// Open the create modal pre-filled when arriving from a 404 row.
	useEffect( () => {
		const prefill = safeSource(
			getQueryArg( window.location.href, 'source' )
		);
		if ( prefill ) {
			openEditor( { ...EMPTY_RULE, source_path: prefill } );
		}
	}, [] );

	const onSave = async () => {
		setFormError( '' );
		try {
			await save(
				{
					source_path: editing.source_path,
					target: editing.target,
					status_code: parseInt( editing.status_code, 10 ),
					is_regex: editing.is_regex,
					enabled: editing.enabled,
				},
				editing.id
			);
			setEditing( null );
		} catch ( e ) {
			setFormError(
				e?.message || __( 'Could not save the redirect.', 'openseo' )
			);
		}
	};

	const columns = [
		{
			id: 'source_path',
			label: __( 'Source', 'openseo' ),
			render: ( r ) => (
				<>
					{ r.source_path }{ ' ' }
					{ Number( r.is_regex ) === 1 ? (
						<em>{ __( 'regex', 'openseo' ) }</em>
					) : null }
				</>
			),
		},
		{ id: 'target', label: __( 'Target', 'openseo' ) },
		{ id: 'status_code', label: __( 'Type', 'openseo' ) },
		{
			id: 'enabled',
			label: __( 'Status', 'openseo' ),
			render: ( r ) =>
				Number( r.enabled ) === 1
					? __( 'Enabled', 'openseo' )
					: __( 'Disabled', 'openseo' ),
		},
		{ id: 'hits', label: __( 'Hits', 'openseo' ) },
	];

	const rowActions = ( r ) => (
		<Flex gap={ 1 } justify="flex-start">
			<Button
				variant="link"
				onClick={ () =>
					openEditor( {
						id: Number( r.id ),
						source_path: r.source_path,
						target: r.target,
						status_code: String( r.status_code ),
						is_regex: Number( r.is_regex ) === 1,
						enabled: Number( r.enabled ) === 1,
					} )
				}
			>
				{ __( 'Edit', 'openseo' ) }
			</Button>
			<Button
				variant="link"
				onClick={ () =>
					bulk( Number( r.enabled ) === 1 ? 'disable' : 'enable', [
						Number( r.id ),
					] )
				}
			>
				{ Number( r.enabled ) === 1
					? __( 'Disable', 'openseo' )
					: __( 'Enable', 'openseo' ) }
			</Button>
			<Button
				variant="link"
				isDestructive
				onClick={ () => {
					if (
						// eslint-disable-next-line no-alert
						window.confirm(
							__( 'Delete this redirect?', 'openseo' )
						)
					) {
						remove( Number( r.id ) );
					}
				} }
			>
				{ __( 'Delete', 'openseo' ) }
			</Button>
		</Flex>
	);

	const bulkActions = [
		{
			action: 'enable',
			label: __( 'Enable', 'openseo' ),
			onClick: ( ids ) => {
				bulk( 'enable', ids );
				setSelected( [] );
			},
		},
		{
			action: 'disable',
			label: __( 'Disable', 'openseo' ),
			onClick: ( ids ) => {
				bulk( 'disable', ids );
				setSelected( [] );
			},
		},
		{
			action: 'delete',
			label: __( 'Delete', 'openseo' ),
			destructive: true,
			onClick: ( ids ) => {
				if (
					// eslint-disable-next-line no-alert
					window.confirm(
						__( 'Delete the selected redirects?', 'openseo' )
					)
				) {
					bulk( 'delete', ids );
					setSelected( [] );
				}
			},
		},
	];

	return (
		<div className="openseo-panel">
			<h2>{ __( 'Settings', 'openseo' ) }</h2>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Auto-redirect on slug change', 'openseo' ) }
				checked={ settings.values.redirects_auto_slug === '1' }
				onChange={ ( on ) =>
					settings.change( 'redirects_auto_slug', on ? '1' : '' )
				}
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Track redirect hits', 'openseo' ) }
				checked={ settings.values.redirects_track_hits === '1' }
				onChange={ ( on ) =>
					settings.change( 'redirects_track_hits', on ? '1' : '' )
				}
			/>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Default redirect type', 'openseo' ) }
				value={ settings.values.redirects_default_status }
				options={ [
					{ label: '301', value: '301' },
					{ label: '302', value: '302' },
					{ label: '307', value: '307' },
				] }
				onChange={ ( v ) =>
					settings.change( 'redirects_default_status', v )
				}
			/>
			<Button
				variant="primary"
				isBusy={ settings.saving }
				disabled={ ! settings.dirty || settings.saving }
				onClick={ settings.save }
			>
				{ settings.saving
					? __( 'Saving…', 'openseo' )
					: __( 'Save settings', 'openseo' ) }
			</Button>

			<Flex justify="space-between" style={ { marginTop: '24px' } }>
				<h2>{ __( 'Redirects', 'openseo' ) }</h2>
				<Button
					variant="primary"
					onClick={ () => openEditor( { ...EMPTY_RULE } ) }
				>
					{ __( 'Add redirect', 'openseo' ) }
				</Button>
			</Flex>

			{ error ? (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) : null }

			<DataTable
				columns={ columns }
				items={ items }
				total={ total }
				page={ query.page }
				loading={ loading }
				searchable
				search={ query.search }
				onSearch={ ( s ) => {
					setQuery( { page: 1, search: s } );
					setSelected( [] );
				} }
				onPageChange={ ( p ) => {
					setQuery( { ...query, page: p } );
					setSelected( [] );
				} }
				selectable
				selected={ selected }
				onSelectionChange={ setSelected }
				rowActions={ rowActions }
				bulkActions={ bulkActions }
				emptyLabel={ __( 'No redirects yet.', 'openseo' ) }
			/>

			{ editing !== null && (
				<Modal
					title={
						editing.id
							? __( 'Edit redirect', 'openseo' )
							: __( 'Add redirect', 'openseo' )
					}
					onRequestClose={ () => setEditing( null ) }
				>
					{ formError ? (
						<Notice status="error" isDismissible={ false }>
							{ formError }
						</Notice>
					) : null }
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Source path', 'openseo' ) }
						value={ editing.source_path }
						onChange={ ( v ) =>
							setEditing( { ...editing, source_path: v } )
						}
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Type', 'openseo' ) }
						value={ editing.status_code }
						options={ STATUSES }
						onChange={ ( v ) =>
							setEditing( { ...editing, status_code: v } )
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Target', 'openseo' ) }
						value={ editing.target }
						disabled={ editing.status_code === '410' }
						onChange={ ( v ) =>
							setEditing( { ...editing, target: v } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Regex source', 'openseo' ) }
						checked={ editing.is_regex }
						onChange={ ( on ) =>
							setEditing( { ...editing, is_regex: on } )
						}
					/>
					<Flex
						justify="flex-end"
						gap={ 2 }
						style={ { marginTop: '16px' } }
					>
						<Button
							variant="tertiary"
							onClick={ () => setEditing( null ) }
						>
							{ __( 'Cancel', 'openseo' ) }
						</Button>
						<Button variant="primary" onClick={ onSave }>
							{ __( 'Save redirect', 'openseo' ) }
						</Button>
					</Flex>
				</Modal>
			) }
		</div>
	);
}
