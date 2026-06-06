const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

// Get all default config settings
const config = {
    ...defaultConfig,
    entry: {
        'admin/index': './src/admin/index.js'
    },
    output: {
        path: path.resolve(process.cwd(), 'build'),
        filename: '[name].js'
    },
    resolve: {
        ...defaultConfig.resolve,
        alias: {
            ...defaultConfig.resolve.alias,
            '@nativecustomfields': path.resolve(__dirname, 'src')
        }
    },
    watchOptions: {
        ignored: /node_modules/,
        aggregateTimeout: 300,
        poll: 1000,
    }
};

module.exports = config;