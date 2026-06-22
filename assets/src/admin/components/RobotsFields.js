import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ROBOTS_DIRECTIVES, setRobotsField } from '../robots';

export const ROBOTS_LABELS = {
	noindex: __( 'No index', 'openseo' ),
	nofollow: __( 'No follow', 'openseo' ),
	noarchive: __( 'No archive', 'openseo' ),
	nosnippet: __( 'No snippet', 'openseo' ),
	noimageindex: __( 'No image index', 'openseo' ),
};

const TRISTATE_OPTIONS = [
	{ label: __( 'Default', 'openseo' ), value: '' },
	{ label: __( 'Yes', 'openseo' ), value: 'on' },
	{ label: __( 'No', 'openseo' ), value: 'off' },
];

export function RobotsFields( { robots, onChange } ) {
	const map = robots ?? {};

	return (
		<>
			{ ROBOTS_DIRECTIVES.map( ( directive ) => (
				<SelectControl
					key={ directive }
					__nextHasNoMarginBottom
					label={ ROBOTS_LABELS[ directive ] }
					value={ map[ directive ] ?? '' }
					options={ TRISTATE_OPTIONS }
					onChange={ ( value ) =>
						onChange( setRobotsField( map, directive, value ) )
					}
				/>
			) ) }
		</>
	);
}
