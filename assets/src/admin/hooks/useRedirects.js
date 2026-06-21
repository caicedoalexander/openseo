import {
	useReducer,
	useState,
	useEffect,
	useCallback,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { listReducer, INITIAL_LIST } from './listReducer';
import {
	getRedirects,
	createRedirect,
	updateRedirect,
	deleteRedirect,
	bulkRedirects,
} from '../api';

export function useRedirects() {
	const [ state, dispatch ] = useReducer( listReducer, INITIAL_LIST );
	const [ query, setQuery ] = useState( { page: 1, search: '' } );

	const load = useCallback( async ( q ) => {
		dispatch( { type: 'LOADING' } );
		try {
			const { items, total } = await getRedirects( {
				page: q.page,
				search: q.search,
			} );
			dispatch( { type: 'LOADED', items, total } );
		} catch ( e ) {
			dispatch( {
				type: 'ERROR',
				error:
					e?.message || __( 'Could not load redirects.', 'openseo' ),
			} );
		}
	}, [] );

	useEffect( () => {
		load( query );
	}, [ query, load ] );

	const refresh = useCallback( () => load( query ), [ load, query ] );

	const save = useCallback(
		async ( data, id = 0 ) => {
			const saved = id
				? await updateRedirect( id, data )
				: await createRedirect( data );
			await load( query );
			return saved;
		},
		[ load, query ]
	);

	const remove = useCallback(
		async ( id ) => {
			await deleteRedirect( id );
			await load( query );
		},
		[ load, query ]
	);

	const bulk = useCallback(
		async ( action, ids ) => {
			await bulkRedirects( action, ids );
			await load( query );
		},
		[ load, query ]
	);

	return { ...state, query, setQuery, refresh, save, remove, bulk };
}
