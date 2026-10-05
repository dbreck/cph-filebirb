/**
 * @wordpress/scripts defaults, with `react/jsx-runtime` pointed at a small shim
 * over `React.createElement` instead of the `react-jsx-runtime` handle (that
 * handle needs WP 6.6+; the plugin supports 6.5).
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

module.exports = {
	...defaultConfig,
	resolve: {
		...defaultConfig.resolve,
		alias: {
			...( defaultConfig.resolve?.alias || {} ),
			'react/jsx-runtime$': path.resolve( __dirname, 'assets/src/jsx-runtime.js' ),
			'react/jsx-dev-runtime$': path.resolve( __dirname, 'assets/src/jsx-runtime.js' ),
		},
	},
	plugins: [
		...defaultConfig.plugins.filter(
			( plugin ) => plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
		new DependencyExtractionWebpackPlugin( {
			requestToExternal( request ) {
				if ( request === 'react/jsx-runtime' || request === 'react/jsx-dev-runtime' ) {
					return false;
				}
			},
		} ),
	],
};
