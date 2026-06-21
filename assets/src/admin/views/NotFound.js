import {
	Button,
	ToggleControl,
	TextControl,
	Notice,
	Flex,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { DataTable } from '../components/DataTable';
import { useNotfound } from '../hooks/useNotfound';
import { useSettings } from '../hooks/useSettings';

export function NotFound() {
	const { items, total, loading, error, query, setQuery, remove, clear } =
		useNotfound();
	const settings = useSettings( window.openseoAdmin?.settings ?? {} );

	const columns = [
		{ id: 'url', label: __( 'URL', 'openseo' ) },
		{ id: 'hits', label: __( 'Hits', 'openseo' ) },
		{ id: 'last_seen', label: __( 'Last seen', 'openseo' ) },
	];

	const rowActions = ( r ) => (
		<Flex gap={ 1 } justify="flex-start">
			<Button
				variant="link"
				href={ `admin.php?page=openseo-redirects&source=${ encodeURIComponent(
					r.url
				) }` }
			>
				{ __( 'Create redirect', 'openseo' ) }
			</Button>
			<Button
				variant="link"
				isDestructive
				onClick={ () => remove( Number( r.id ) ) }
			>
				{ __( 'Delete', 'openseo' ) }
			</Button>
		</Flex>
	);

	return (
		<div className="openseo-panel">
			<h2>{ __( 'Settings', 'openseo' ) }</h2>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Enable 404 monitor', 'openseo' ) }
				checked={ settings.values.notfound_monitor_enabled === '1' }
				onChange={ ( on ) =>
					settings.change( 'notfound_monitor_enabled', on ? '1' : '' )
				}
			/>
			<TextControl
				__nextHasNoMarginBottom
				label={ __( '404 retention (days)', 'openseo' ) }
				value={ settings.values.notfound_retention_days }
				onChange={ ( v ) =>
					settings.change( 'notfound_retention_days', v )
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
				<h2>{ __( 'Logged 404s', 'openseo' ) }</h2>
				<Button
					variant="secondary"
					isDestructive
					disabled={ total === 0 }
					onClick={ () => {
						if (
							// eslint-disable-next-line no-alert
							window.confirm(
								__( 'Clear the entire 404 log?', 'openseo' )
							)
						) {
							clear();
						}
					} }
				>
					{ __( 'Clear log', 'openseo' ) }
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
				onPageChange={ ( p ) => setQuery( { page: p } ) }
				rowActions={ rowActions }
				emptyLabel={ __( 'No 404s logged.', 'openseo' ) }
			/>
		</div>
	);
}
