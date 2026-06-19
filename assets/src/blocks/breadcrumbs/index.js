import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import Edit from './edit';

// Attributes mirror the PHP registration (Block::register_block); the server
// owns rendering, so save() returns null.
registerBlockType( 'openseo/breadcrumbs', {
	apiVersion: 3,
	title: __( 'OpenSEO Breadcrumbs', 'openseo' ),
	category: 'theme',
	icon: 'networking',
	attributes: {
		showHome: { type: 'boolean', default: true },
		textAlign: { type: 'string', default: '' },
	},
	edit: Edit,
	save: () => null,
} );
