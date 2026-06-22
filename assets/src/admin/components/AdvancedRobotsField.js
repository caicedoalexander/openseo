import {
	CheckboxControl,
	TextControl,
	SelectControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { setAdvancedRobots, MAX_IMAGE_PREVIEW_VALUES } from '../advancedRobots';

const IMAGE_PREVIEW_LABELS = {
	large: __( 'Large', 'openseo' ),
	standard: __( 'Standard', 'openseo' ),
	none: __( 'None', 'openseo' ),
};

const IMAGE_PREVIEW_OPTIONS = MAX_IMAGE_PREVIEW_VALUES.map( ( value ) => ( {
	value,
	label: IMAGE_PREVIEW_LABELS[ value ],
} ) );

/**
 * Three advanced robots rows (max-snippet / max-video-preview / max-image-preview),
 * each a checkbox plus a value control shown when enabled.
 *
 * @param {Object}   props
 * @param {Object}   props.value    advanced_robots map.
 * @param {Function} props.onChange Receives the new map.
 */
export function AdvancedRobotsField( { value, onChange } ) {
	const map = value ?? {};
	const block = ( key ) => map[ key ] ?? {};
	const set = ( key, field, v ) =>
		onChange( setAdvancedRobots( map, key, field, v ) );

	return (
		<fieldset className="openseo-advanced-robots">
			<legend>{ __( 'Advanced robots meta', 'openseo' ) }</legend>

			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __( 'Snippet (max-snippet)', 'openseo' ) }
				checked={ block( 'max_snippet' ).enabled === '1' }
				onChange={ ( on ) =>
					set( 'max_snippet', 'enabled', on ? '1' : '' )
				}
			/>
			{ block( 'max_snippet' ).enabled === '1' && (
				<TextControl
					__nextHasNoMarginBottom
					type="number"
					label={ __( 'Max snippet length', 'openseo' ) }
					value={ block( 'max_snippet' ).length ?? '-1' }
					onChange={ ( v ) => set( 'max_snippet', 'length', v ) }
				/>
			) }

			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __( 'Video preview (max-video-preview)', 'openseo' ) }
				checked={ block( 'max_video_preview' ).enabled === '1' }
				onChange={ ( on ) =>
					set( 'max_video_preview', 'enabled', on ? '1' : '' )
				}
			/>
			{ block( 'max_video_preview' ).enabled === '1' && (
				<TextControl
					__nextHasNoMarginBottom
					type="number"
					label={ __( 'Max video preview (seconds)', 'openseo' ) }
					value={ block( 'max_video_preview' ).length ?? '-1' }
					onChange={ ( v ) =>
						set( 'max_video_preview', 'length', v )
					}
				/>
			) }

			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __( 'Image preview (max-image-preview)', 'openseo' ) }
				checked={ block( 'max_image_preview' ).enabled === '1' }
				onChange={ ( on ) =>
					set( 'max_image_preview', 'enabled', on ? '1' : '' )
				}
			/>
			{ block( 'max_image_preview' ).enabled === '1' && (
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Max image preview size', 'openseo' ) }
					value={ block( 'max_image_preview' ).value ?? 'large' }
					options={ IMAGE_PREVIEW_OPTIONS }
					onChange={ ( v ) => set( 'max_image_preview', 'value', v ) }
				/>
			) }
		</fieldset>
	);
}
