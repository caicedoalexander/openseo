import {
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { RepeatableGroup } from './RepeatableGroup';

const bootstrap = window.openseoAdmin ?? {};
const choices = bootstrap.localChoices ?? {
	businessTypes: [],
	phoneTypes: [],
	additionalInfoTypes: [],
	days: [],
};

const BUSINESS_TYPE_OPTIONS = [
	{ label: __( '— None', 'openseo' ), value: '' },
	...choices.businessTypes,
];

const PHONE_TYPE_OPTIONS = [
	{ label: __( '— Type', 'openseo' ), value: '' },
	...choices.phoneTypes,
];

const FIRST_DAY = choices.days[ 0 ]?.value ?? 'Monday';
const FIRST_INFO = choices.additionalInfoTypes[ 0 ]?.value ?? 'legalName';

const ADDRESS_FIELDS = [
	{ key: 'street', label: __( 'Street address', 'openseo' ) },
	{ key: 'locality', label: __( 'City / locality', 'openseo' ) },
	{ key: 'region', label: __( 'Region / state', 'openseo' ) },
	{ key: 'postal_code', label: __( 'Postal code', 'openseo' ) },
];

export function LocalBusinessFields( { values, change } ) {
	const address = values.local_address ?? {};
	const setAddress = ( key, v ) =>
		change( 'local_address', { ...address, [ key ]: v } );

	return (
		<>
			<h3>{ __( 'Local business', 'openseo' ) }</h3>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Business type', 'openseo' ) }
				value={ values.local_business_type ?? '' }
				options={ BUSINESS_TYPE_OPTIONS }
				onChange={ ( v ) => change( 'local_business_type', v ) }
			/>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Description', 'openseo' ) }
				value={ values.local_description ?? '' }
				onChange={ ( v ) => change( 'local_description', v ) }
			/>
			<h3>{ __( 'Address', 'openseo' ) }</h3>
			{ ADDRESS_FIELDS.map( ( field ) => (
				<TextControl
					key={ field.key }
					__nextHasNoMarginBottom
					label={ field.label }
					value={ address[ field.key ] ?? '' }
					onChange={ ( v ) => setAddress( field.key, v ) }
				/>
			) ) }
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Country', 'openseo' ) }
				help={ __(
					'ISO 3166–1 alpha-2 code, e.g. US, ES.',
					'openseo'
				) }
				value={ address.country ?? '' }
				onChange={ ( v ) => setAddress( 'country', v ) }
			/>
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Price range', 'openseo' ) }
				value={ values.local_price_range ?? '' }
				onChange={ ( v ) => change( 'local_price_range', v ) }
			/>
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Geo coordinates', 'openseo' ) }
				help={ __(
					'Latitude,longitude — e.g. 40.7128,-74.006.',
					'openseo'
				) }
				value={ values.local_geo ?? '' }
				onChange={ ( v ) => change( 'local_geo', v ) }
			/>
			<RepeatableGroup
				label={ __( 'Opening hours', 'openseo' ) }
				value={ values.local_opening_hours }
				columns={ [
					{
						key: 'day',
						label: __( 'Day', 'openseo' ),
						control: 'select',
						options: choices.days,
					},
					{
						key: 'opens',
						label: __( 'Opens', 'openseo' ),
						control: 'time',
					},
					{
						key: 'closes',
						label: __( 'Closes', 'openseo' ),
						control: 'time',
					},
				] }
				emptyRow={ { day: FIRST_DAY, opens: '', closes: '' } }
				onChange={ ( rows ) => change( 'local_opening_hours', rows ) }
				addLabel={ __( 'Add hours', 'openseo' ) }
			/>
			<RepeatableGroup
				label={ __( 'Phone numbers', 'openseo' ) }
				value={ values.local_phone_numbers }
				columns={ [
					{
						key: 'type',
						label: __( 'Type', 'openseo' ),
						control: 'select',
						options: PHONE_TYPE_OPTIONS,
					},
					{
						key: 'number',
						label: __( 'Number', 'openseo' ),
						control: 'text',
					},
				] }
				emptyRow={ { type: '', number: '' } }
				onChange={ ( rows ) => change( 'local_phone_numbers', rows ) }
				addLabel={ __( 'Add phone', 'openseo' ) }
			/>
			<RepeatableGroup
				label={ __( 'Additional info', 'openseo' ) }
				value={ values.local_additional_info }
				columns={ [
					{
						key: 'type',
						label: __( 'Type', 'openseo' ),
						control: 'select',
						options: choices.additionalInfoTypes,
					},
					{
						key: 'value',
						label: __( 'Value', 'openseo' ),
						control: 'text',
					},
				] }
				emptyRow={ { type: FIRST_INFO, value: '' } }
				onChange={ ( rows ) => change( 'local_additional_info', rows ) }
				addLabel={ __( 'Add info', 'openseo' ) }
			/>
		</>
	);
}
