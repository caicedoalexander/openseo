import { CheckboxControl } from '@wordpress/components';
import { ROBOTS_DIRECTIVES } from '../robots';
import { ROBOTS_LABELS } from './RobotsFields';

/**
 * Five robots directive checkboxes over a flat `directive => '1'` map.
 * Absolute (no tri-state): used by the global, homepage and author panels.
 *
 * @param {Object}   props
 * @param {Object}   props.map      Current `directive => '1'` map.
 * @param {Function} props.onChange Receives the next full map.
 * @return {JSX.Element} The checkbox group.
 */
export function RobotsCheckboxes( { map, onChange } ) {
	const value = map ?? {};

	return (
		<>
			{ ROBOTS_DIRECTIVES.map( ( directive ) => (
				<CheckboxControl
					key={ directive }
					__nextHasNoMarginBottom
					label={ ROBOTS_LABELS[ directive ] }
					checked={ value[ directive ] === '1' }
					onChange={ ( on ) =>
						onChange( { ...value, [ directive ]: on ? '1' : '' } )
					}
				/>
			) ) }
		</>
	);
}
