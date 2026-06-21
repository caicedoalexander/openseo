/**
 * Insert a token into a string at the caret/selection, returning the new value
 * and caret position. Pure — no DOM access.
 *
 * @param {string} value Current field value.
 * @param {string} token Token to insert.
 * @param {number} start Selection start (defaults to end of value).
 * @param {number} end   Selection end (defaults to start).
 * @return {{ value: string, cursor: number }} New value and caret position.
 */
export function insertAtCursor( value, token, start, end ) {
	const len = value.length;
	const s = Math.max( 0, Math.min( start ?? len, len ) );
	const e = Math.max( s, Math.min( end ?? s, len ) );
	const next = value.slice( 0, s ) + token + value.slice( e );
	return { value: next, cursor: s + token.length };
}
