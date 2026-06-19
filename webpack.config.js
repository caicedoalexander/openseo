/**
 * Extends the default @wordpress/scripts webpack config so that JS/CSS sources
 * live in assets/src/ and compile to assets/build/ (keeping src/ free for the
 * PSR-4 PHP namespace).
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		'admin-settings': path.resolve(
			process.cwd(),
			'assets/src/admin/index.js'
		),
		editor: path.resolve( process.cwd(), 'assets/src/editor/index.js' ),
		breadcrumbs: path.resolve(
			process.cwd(),
			'assets/src/blocks/breadcrumbs/index.js'
		),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( process.cwd(), 'assets/build' ),
	},
};
