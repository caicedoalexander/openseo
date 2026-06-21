/**
 * Pure reducer shared by useRedirects/useNotfound for async list state.
 *
 * @param {Object} state  Current list state.
 * @param {Object} action Dispatched action with a `type` key.
 */
export function listReducer( state, action ) {
	switch ( action.type ) {
		case 'LOADING':
			return { ...state, loading: true, error: '' };
		case 'LOADED':
			return {
				items: action.items,
				total: action.total,
				loading: false,
				error: '',
			};
		case 'ERROR':
			return { ...state, loading: false, error: action.error };
		default:
			return state;
	}
}

export const INITIAL_LIST = { items: [], total: 0, loading: true, error: '' };
