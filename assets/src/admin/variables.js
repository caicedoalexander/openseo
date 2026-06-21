/**
 * Pure helpers for the admin variable inserter.
 */

/**
 * Variables applicable to a scope: every 'global' plus those of the given scope.
 *
 * @param {Array}  catalog Variable catalog ({ token, label, description, scope }).
 * @param {string} scope   'global' | 'singular' | 'taxonomy'.
 * @return {Array} Filtered list, in catalog order.
 */
export function variablesForScope( catalog, scope ) {
	return ( catalog ?? [] ).filter(
		( v ) => v.scope === 'global' || v.scope === scope
	);
}

/**
 * Case-insensitive search over label/token/description. Empty query → all.
 *
 * @param {Array}  list  Variables to filter.
 * @param {string} query Search text.
 * @return {Array} Matching variables.
 */
export function filterVariables( list, query ) {
	const q = String( query ?? '' ).trim().toLowerCase();
	if ( ! q ) {
		return list;
	}
	return list.filter(
		( v ) =>
			v.label.toLowerCase().includes( q ) ||
			v.token.toLowerCase().includes( q ) ||
			v.description.toLowerCase().includes( q )
	);
}
