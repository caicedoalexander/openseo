/**
 * Pure immutable helpers for repeatable-row fields.
 */

/**
 * @param {Array}  rows     Current rows.
 * @param {Object} emptyRow Shape of a new blank row.
 * @return {Array} New array with a copy of emptyRow appended.
 */
export function addRow( rows, emptyRow ) {
	return [ ...( rows ?? [] ), { ...emptyRow } ];
}

/**
 * @param {Array}  rows  Current rows.
 * @param {number} index Row to remove.
 * @return {Array} New array without that row.
 */
export function removeRow( rows, index ) {
	return ( rows ?? [] ).filter( ( _row, i ) => i !== index );
}

/**
 * @param {Array}  rows  Current rows.
 * @param {number} index Row to update.
 * @param {string} key   Cell key.
 * @param {string} value New value.
 * @return {Array} New array with one cell of one row changed.
 */
export function updateCell( rows, index, key, value ) {
	return ( rows ?? [] ).map( ( row, i ) =>
		i === index ? { ...row, [ key ]: value } : row
	);
}
