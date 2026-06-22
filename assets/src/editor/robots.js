/**
 * Pure robots-value helpers for the editor (no i18n, no @wordpress deps).
 */

// Legacy '1' (old binary toggle) reads as 'on'.

/**
 * Map a stored robots value to its tri-state select value.
 *
 * @param {string} v Stored value ('' | 'on' | 'off' | legacy '1').
 * @return {string} '' | 'on' | 'off' (legacy '1' becomes 'on').
 */
export const triValue = ( v ) => ( v === '1' ? 'on' : v );

/**
 * Whether a stored robots value means "noindex".
 *
 * @param {string} v Stored value ('' | 'on' | 'off' | legacy '1').
 * @return {boolean} True for 'on' or legacy '1'.
 */
export const isNoindexValue = ( v ) => v === 'on' || v === '1';
