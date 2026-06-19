import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * @param {{ attributes: { showHome: boolean, textAlign: string }, setAttributes: Function }} props
 */
export default function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Breadcrumbs', 'openseo' ) }>
					<ToggleControl
						label={ __( 'Show home link', 'openseo' ) }
						checked={ attributes.showHome }
						onChange={ ( showHome ) =>
							setAttributes( { showHome } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<ServerSideRender
				block="openseo/breadcrumbs"
				attributes={ attributes }
			/>
		</div>
	);
}
