import { MediaUpload } from '@wordpress/media-utils';
import { Button, Flex, FlexItem } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Image picker backed by the WordPress media library. Stores only the URL.
 * Uses MediaUpload from @wordpress/media-utils (NOT MediaUploadCheck, which
 * lives in @wordpress/block-editor and needs the block-editor store). Capability
 * is already gated by the admin page (manage_options) + the user's upload_files.
 *
 * @param {Object}   props
 * @param {string}   props.label    Field label.
 * @param {string}   props.value    Current image URL.
 * @param {Function} props.onChange Receives the selected URL (or '' when removed).
 */
export function MediaField( { label, value, onChange } ) {
	return (
		<div className="openseo-media-field">
			{ label && <p className="openseo-media-field__label">{ label }</p> }
			{ value && (
				<img
					src={ value }
					alt=""
					className="openseo-media-field__preview"
					style={ {
						maxWidth: '160px',
						height: 'auto',
						display: 'block',
					} }
				/>
			) }
			<Flex justify="flex-start" gap={ 2 }>
				<FlexItem>
					<MediaUpload
						onSelect={ ( media ) => onChange( media.url ) }
						allowedTypes={ [ 'image' ] }
						render={ ( { open } ) => (
							<Button variant="secondary" onClick={ open }>
								{ value
									? __( 'Replace image', 'openseo' )
									: __( 'Select image', 'openseo' ) }
							</Button>
						) }
					/>
				</FlexItem>
				{ value && (
					<FlexItem>
						<Button
							variant="link"
							isDestructive
							onClick={ () => onChange( '' ) }
						>
							{ __( 'Remove', 'openseo' ) }
						</Button>
					</FlexItem>
				) }
			</Flex>
		</div>
	);
}
