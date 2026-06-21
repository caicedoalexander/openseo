import {
	useReducer,
	useState,
	useEffect,
	useCallback,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { listReducer, INITIAL_LIST } from './listReducer';
import { getNotfound, deleteNotfound, clearNotfound } from '../api';

export function useNotfound() {
	const [ state, dispatch ] = useReducer( listReducer, INITIAL_LIST );
	const [ query, setQuery ] = useState( { page: 1 } );

	const load = useCallback( async ( q ) => {
		dispatch( { type: 'LOADING' } );
		try {
			const { items, total } = await getNotfound( { page: q.page } );
			dispatch( { type: 'LOADED', items, total } );
		} catch ( e ) {
			dispatch( {
				type: 'ERROR',
				error: e?.message || __( 'Could not load 404s.', 'openseo' ),
			} );
		}
	}, [] );

	useEffect( () => {
		load( query );
	}, [ query, load ] );

	const refresh = useCallback( () => load( query ), [ load, query ] );

	const remove = useCallback(
		async ( id ) => {
			await deleteNotfound( id );
			await load( query );
		},
		[ load, query ]
	);

	const clear = useCallback( async () => {
		await clearNotfound();
		await load( query );
	}, [ load, query ] );

	return { ...state, query, setQuery, refresh, remove, clear };
}
