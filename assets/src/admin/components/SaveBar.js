import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export function SaveBar( { dirty, saving, error, onSave } ) {
	return (
		<div className="openseo-savebar">
			{ error ? (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) : null }
			<Button
				variant="primary"
				isBusy={ saving }
				disabled={ ! dirty || saving }
				onClick={ onSave }
			>
				{ saving
					? __( 'Saving…', 'openseo' )
					: __( 'Save changes', 'openseo' ) }
			</Button>
		</div>
	);
}
