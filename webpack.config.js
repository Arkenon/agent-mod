/**
 * Custom webpack config.
 *
 * Extends the @wordpress/scripts default config so the existing block builds
 * (src/first-block via block.json) keep working, while adding the admin-chat
 * widget entry. The default `entry` may be either a function or an object, so
 * both are supported here.
 */
const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

const baseEntry =
	typeof defaultConfig.entry === 'function'
		? defaultConfig.entry()
		: defaultConfig.entry;

module.exports = {
	...defaultConfig,
	entry: {
		...baseEntry, // first-block + other block.json entries are preserved.
		'admin-chat/index': path.resolve(
			process.cwd(),
			'src/admin-chat',
			'index.js'
		),
	},
};
