/**
 * Status + bar fill for a length indicator.
 *
 * @param {number} len            Current text length (excludes display ellipsis).
 * @param {Object} bounds
 * @param {number} bounds.min     Below this → warn.
 * @param {number} bounds.max     Above this → warn (up to hardMax).
 * @param {number} bounds.hardMax 0 or above this → over.
 * @return {{ count: number, status: 'ok'|'warn'|'over', percent: number }} Computed length state.
 */
export function lengthState( len, { min, max, hardMax } ) {
	let status = 'ok';
	if ( len === 0 || len > hardMax ) {
		status = 'over';
	} else if ( len < min || len > max ) {
		status = 'warn';
	}

	const percent = Math.min( 100, Math.round( ( len / max ) * 100 ) );

	return { count: len, status, percent };
}
