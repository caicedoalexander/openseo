/**
 * Pure helpers for robots directive maps (no i18n — labels live in components).
 */

export const ROBOTS_DIRECTIVES = [
	'noindex',
	'nofollow',
	'noarchive',
	'nosnippet',
	'noimageindex',
];

/**
 * Immutably set one tri-state directive. '' (inherit) removes it from the map.
 *
 * @param {Object} robots    Current directive map.
 * @param {string} directive Directive key.
 * @param {string} value     '' | 'on' | 'off'.
 * @return {Object} A new map; the input is not mutated.
 */
export function setRobotsField( robots, directive, value ) {
	const next = { ...( robots ?? {} ) };
	if ( value === '' ) {
		delete next[ directive ];
	} else {
		next[ directive ] = value;
	}
	return next;
}
