import { __ } from '@wordpress/i18n';

/**
 * Turn an ability error into a user-facing message.
 *
 * @param {{ code?: string, message?: string }} error
 * @return {string} User-facing error message.
 */
export function aiErrorMessage( error ) {
	if ( error?.code === 'openseo_no_connector' ) {
		return __(
			'No AI connector is configured. Add one under Settings → Connectors.',
			'openseo'
		);
	}

	return (
		error?.message ||
		__( 'AI generation failed. Please try again.', 'openseo' )
	);
}
