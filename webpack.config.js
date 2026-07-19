/**
 * Custom webpack config.
 *
 * Extends the @wordpress/scripts default config so the existing block builds
 * (src/first-block via block.json) keep working, while adding the admin-chat
 * widget entry. The default `entry` may be either a function or an object, so
 * both are supported here.
 */
const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const DependencyExtractionWebpackPlugin = require('@wordpress/dependency-extraction-webpack-plugin');
const path = require('path');

const baseEntry =
	typeof defaultConfig.entry === 'function'
		? defaultConfig.entry()
		: defaultConfig.entry;

// @wordpress/abilities and @wordpress/core-abilities are published on npm for
// self-bundling, but WP core only exposes them as Script Modules — there is no
// window.wp.abilities / window.wp.coreAbilities classic-script global. The
// default dependency-extraction rule externalizes every "@wordpress/*" import
// to such a global, so these two must be excluded and bundled directly.
const SELF_BUNDLED_WP_PACKAGES = ['@wordpress/abilities', '@wordpress/core-abilities'];

const plugins = defaultConfig.plugins.map((plugin) =>
	plugin instanceof DependencyExtractionWebpackPlugin
		? new DependencyExtractionWebpackPlugin({
				requestToExternal(request) {
					if (SELF_BUNDLED_WP_PACKAGES.includes(request)) {
						return false;
					}
				},
		  })
		: plugin
);

module.exports = {
	...defaultConfig,
	plugins,
	entry: {
		...baseEntry,
		'admin-chat/index': path.resolve(
			process.cwd(),
			'src/admin-chat',
			'index.js'
		),
		'dashboard/index': path.resolve(
			process.cwd(),
			'src/dashboard',
			'index.js'
		),
		'ability-list/index': path.resolve(
			process.cwd(),
			'src/ability-list',
			'index.js'
		),
		'settings-editor/index': path.resolve(
			process.cwd(),
			'src/settings-editor',
			'index.js'
		),
	},
};
