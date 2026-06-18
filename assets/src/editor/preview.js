const MAX_DESCRIPTION = 160;

/**
 * @param {Object} input
 * @param {string} input.title
 * @param {string} input.description
 * @param {string} input.separator
 * @param {string} input.siteName
 * @return {{ title: string, description: string }} Formatted snippet preview with full title and truncated description.
 */
export function buildSnippetPreview( {
	title,
	description,
	separator,
	siteName,
} ) {
	const fullTitle = title
		? `${ title } ${ separator } ${ siteName }`
		: siteName;

	const trimmed =
		description.length > MAX_DESCRIPTION
			? `${ description.slice( 0, MAX_DESCRIPTION ) }…`
			: description;

	return { title: fullTitle, description: trimmed };
}
