/**
 * Pure helpers for the editor live-preview token map.
 */

import { dateI18n, getSettings } from '@wordpress/date';

/**
 * Read the display name from a core-data record (user or term).
 *
 * @param {Object} record Entity record, or undefined while resolving.
 * @return {string} The record name, or '' when missing.
 */
export function recordName( record ) {
	return record?.name ?? '';
}

/**
 * Read the rendered title from a postType core-data record.
 *
 * @param {Object} record postType entity record, or undefined.
 * @return {string} The rendered title, or '' when missing.
 */
export function recordTitle( record ) {
	return record?.title?.rendered ?? '';
}

/**
 * Format an ISO date with the site's date_format (parity with get_the_date).
 *
 * @param {string} iso ISO date string from the editor, or empty.
 * @return {string} Localized date, or '' when there is no date.
 */
export function formatTokenDate( iso ) {
	if ( ! iso ) {
		return '';
	}
	return dateI18n( getSettings().formats.date, iso );
}
