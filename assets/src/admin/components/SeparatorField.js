import { Button, TextControl, Flex, FlexItem } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { SEPARATOR_PRESETS } from '../advancedRobots';

/**
 * Title separator picker: six preset characters (stored as literal UTF-8) plus
 * a free-text "custom" field that preserves arbitrary values.
 *
 * @param {Object}   props
 * @param {string}   props.value    Current separator character.
 * @param {Function} props.onChange Receives the chosen character.
 */
export function SeparatorField( { value, onChange } ) {
	return (
		<fieldset className="openseo-separator-field">
			<legend className="openseo-separator-field__label">
				{ __( 'Title separator', 'openseo' ) }
			</legend>
			<Flex justify="flex-start" gap={ 1 } wrap>
				{ SEPARATOR_PRESETS.map( ( preset ) => (
					<FlexItem key={ preset }>
						<Button
							variant="secondary"
							isPressed={ value === preset }
							onClick={ () => onChange( preset ) }
						>
							{ preset }
						</Button>
					</FlexItem>
				) ) }
			</Flex>
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Custom separator', 'openseo' ) }
				value={ value ?? '' }
				onChange={ onChange }
			/>
		</fieldset>
	);
}
