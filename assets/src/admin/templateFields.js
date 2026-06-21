/**
 * Immutably set one field (title|description) of one slug in a template map.
 *
 * @param {Object} map   Current map keyed by slug → { title, description }.
 * @param {string} slug  Content type or taxonomy slug.
 * @param {string} field 'title' or 'description'.
 * @param {string} value New field value.
 * @return {Object} A new map; the input is not mutated.
 */
export function setTemplateField( map, slug, field, value ) {
	return {
		...map,
		[ slug ]: { ...( map?.[ slug ] ?? {} ), [ field ]: value },
	};
}
