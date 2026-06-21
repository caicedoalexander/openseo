import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export function PreviewDevices( { device, onChange } ) {
	return (
		<div className="openseo-serp-devices">
			<Button
				variant={ device === 'desktop' ? 'primary' : 'secondary' }
				onClick={ () => onChange( 'desktop' ) }
			>
				{ __( 'Desktop', 'openseo' ) }
			</Button>
			<Button
				variant={ device === 'mobile' ? 'primary' : 'secondary' }
				onClick={ () => onChange( 'mobile' ) }
			>
				{ __( 'Mobile', 'openseo' ) }
			</Button>
		</div>
	);
}
