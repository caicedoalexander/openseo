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

/**
 * Pure helpers for the editor SERP preview. expandTokens mirrors
 * Variables::replace (PHP): substitute tokens, collapse whitespace, then strip
 * a dangling separator treated as a whole (regex-escaped) string.
 */

/**
 * @param {string} template Template containing %tokens%.
 * @param {Object} tokens   Map of token -> replacement (includes %sep%).
 * @return {string} The expanded, cleaned string.
 */
export function expandTokens( template, tokens ) {
	let out = template;
	Object.keys( tokens ).forEach( ( token ) => {
		out = out.split( token ).join( tokens[ token ] ?? '' );
	} );

	out = out.replace( /\s+/g, ' ' ).trim();

	const sep = String( tokens[ '%sep%' ] ?? '' ).trim();
	if ( sep ) {
		const esc = sep.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
		out = out
			.replace( new RegExp( `^(?:${ esc }\\s*)+`, '' ), '' )
			.replace( new RegExp( `(?:\\s*${ esc })+$`, '' ), '' );
	}

	return out.trim();
}

/**
 * @param {Object} input
 * @param {string} input.override Per-entry override (wins verbatim if non-empty).
 * @param {string} input.template Type template to expand when no override.
 * @param {Object} input.tokens   Token map.
 * @return {string} Resolved field value.
 */
export function resolveSnippet( { override, template, tokens } ) {
	return override ? override : expandTokens( template, tokens );
}

/**
 * @param {string} text
 * @param {number} max
 * @return {string} text truncated with an ellipsis for display only.
 */
export function truncate( text, max ) {
	return text.length > max ? `${ text.slice( 0, max ) }…` : text;
}

/**
 * Best-effort excerpt from serialized block content (NOT parity with
 * get_the_excerpt): strip block comments and tags, collapse whitespace.
 *
 * @param {string} content
 * @return {string}
 */
export function deriveExcerpt( content ) {
	return content
		.replace( /<!--[\s\S]*?-->/g, '' )
		.replace( /<[^>]*>/g, '' )
		.replace( /\s+/g, ' ' )
		.trim();
}

/**
 * Format a URL as a Google-style breadcrumb (host › segment › segment).
 *
 * @param {string} url
 * @return {string}
 */
export function formatBreadcrumb( url ) {
	const noProtocol = String( url ).replace( /^[a-z]+:\/\//i, '' ).replace( /\/+$/, '' );
	const parts = noProtocol.split( '/' ).filter( Boolean );
	return parts.join( ' › ' );
}
