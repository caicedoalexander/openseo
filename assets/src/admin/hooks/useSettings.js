/**
 * Settings state: local values, dirty tracking, and save via REST.
 */
import { useReducer, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { saveSettings } from '../api';

export function settingsReducer( state, action ) {
	switch ( action.type ) {
		case 'CHANGE':
			return {
				...state,
				values: { ...state.values, [ action.key ]: action.value },
				dirty: true,
				error: '',
			};
		case 'SAVING':
			return { ...state, saving: true, error: '' };
		case 'SAVED':
			return {
				values: action.values,
				dirty: false,
				saving: false,
				error: '',
			};
		case 'ERROR':
			return { ...state, saving: false, error: action.error };
		default:
			return state;
	}
}

export function useSettings( initial ) {
	const [ state, dispatch ] = useReducer( settingsReducer, {
		values: initial,
		dirty: false,
		saving: false,
		error: '',
	} );

	const change = useCallback(
		( key, value ) => dispatch( { type: 'CHANGE', key, value } ),
		[]
	);

	const save = useCallback( async () => {
		dispatch( { type: 'SAVING' } );
		try {
			const values = await saveSettings( state.values );
			dispatch( { type: 'SAVED', values } );
		} catch ( e ) {
			dispatch( {
				type: 'ERROR',
				error:
					e?.message || __( 'Could not save settings.', 'openseo' ),
			} );
		}
	}, [ state.values ] );

	return {
		values: state.values,
		dirty: state.dirty,
		saving: state.saving,
		error: state.error,
		change,
		save,
	};
}
