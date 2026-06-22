import {
	Button,
	SelectControl,
	TextControl,
	Flex,
	FlexItem,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { addRow, removeRow, updateCell } from '../repeatable';

/**
 * Generic repeatable-row editor (opening hours, phone numbers, additional info).
 * Row index is the React key on purpose: controls are fully controlled by value,
 * no per-row local state, and the whole group re-renders on each change.
 *
 * @param {Object}   props
 * @param {string}   props.label    Group legend.
 * @param {Array}    props.value    Rows.
 * @param {Array}    props.columns  Column descriptors.
 * @param {Object}   props.emptyRow Shape of a new row.
 * @param {Function} props.onChange Receives the new rows array.
 * @param {string}   props.addLabel Label for the Add button.
 */
export function RepeatableGroup( {
	label,
	value,
	columns,
	emptyRow,
	onChange,
	addLabel,
} ) {
	const rows = value ?? [];

	return (
		<fieldset className="openseo-repeatable">
			<legend>{ label }</legend>
			{ rows.map( ( row, index ) => (
				<Flex
					key={ index }
					className="openseo-repeatable__row"
					align="flex-end"
					gap={ 2 }
				>
					{ columns.map( ( col ) => (
						<FlexItem key={ col.key } isBlock>
							{ col.control === 'select' ? (
								<SelectControl
									__nextHasNoMarginBottom
									label={ col.label }
									value={ row[ col.key ] ?? '' }
									options={ col.options }
									onChange={ ( v ) => {
										onChange(
											updateCell(
												rows,
												index,
												col.key,
												v
											)
										);
									} }
								/>
							) : (
								<TextControl
									__nextHasNoMarginBottom
									type={
										col.control === 'time' ? 'time' : 'text'
									}
									label={ col.label }
									value={ row[ col.key ] ?? '' }
									onChange={ ( v ) => {
										onChange(
											updateCell(
												rows,
												index,
												col.key,
												v
											)
										);
									} }
								/>
							) }
						</FlexItem>
					) ) }
					<FlexItem>
						<Button
							variant="tertiary"
							isDestructive
							onClick={ () => {
								onChange( removeRow( rows, index ) );
							} }
						>
							{ __( 'Remove', 'openseo' ) }
						</Button>
					</FlexItem>
				</Flex>
			) ) }
			<Button
				variant="secondary"
				onClick={ () => {
					onChange( addRow( rows, emptyRow ) );
				} }
			>
				{ addLabel }
			</Button>
		</fieldset>
	);
}
