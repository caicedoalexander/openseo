/**
 * Pure helpers + constants for the Meta Global panel (no i18n — labels live in
 * the components).
 */

export const SEPARATOR_PRESETS = [ '-', '–', '—', '»', '|', '•' ];

export const MAX_IMAGE_PREVIEW_VALUES = [ 'large', 'standard', 'none' ];

/**
 * Immutably set one field inside one advanced_robots block.
 *
 * @param {Object} map   Current advanced_robots map.
 * @param {string} block Block key (max_snippet | max_video_preview | max_image_preview).
 * @param {string} field Field key (enabled | length | value).
 * @param {string} value New value.
 * @return {Object} A new map; the input is not mutated.
 */
export function setAdvancedRobots( map, block, field, value ) {
	const current = map ?? {};
	return {
		...current,
		[ block ]: {
			...( current[ block ] ?? {} ),
			[ field ]: value,
		},
	};
}
