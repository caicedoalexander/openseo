import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export function Social() {
	return (
		<Notice status="info" isDismissible={ false }>
			{ __(
				'The default social image is now managed under OpenSEO → Titles & Meta → Meta Global.',
				'openseo'
			) }
		</Notice>
	);
}
