const path = require('path')
const webpack = require('webpack')
const { VueLoaderPlugin } = require('vue-loader')
const { name: appName, version: appVersion } = require('./package.json')

module.exports = (env, argv) => {
	const isDev = argv.mode === 'development'
	return {
		mode: isDev ? 'development' : 'production',
		devtool: isDev ? 'cheap-source-map' : false,
		entry: {
			'files_viewers-main': path.join(__dirname, 'src', 'main.js'),
		},
		output: {
			path: path.join(__dirname, 'js'),
			filename: '[name].js',
			clean: false,
		},
		resolve: {
			extensions: ['.js', '.vue'],
			// notebookjs misdetects webpack bundles as Node and require()s jsdom
			// (`new JSDOM().window.document`). Shim it to the real browser window
			// instead of bundling jsdom + its node deps (canvas/http/net/tls).
			alias: { jsdom: path.resolve(__dirname, 'src', 'jsdom-shim.js') },
			// epubjs (added later) references node core modules it doesn't need in-browser
			fallback: { stream: false, path: false, fs: false, http: false, https: false, net: false, tls: false, zlib: false, crypto: false },
		},
		optimization: {
			splitChunks: false,
		},
		module: {
			rules: [
				{ resourceQuery: /raw/, type: 'asset/source' },
				{ test: /\.(svg|png|jpg|gif|woff2?|eot|ttf)$/, resourceQuery: { not: [/raw/] }, type: 'asset/inline' },
				{ test: /\.vue$/, loader: 'vue-loader' },
				{ test: /\.css$/, use: ['vue-style-loader', 'css-loader'] },
			],
		},
		plugins: [
			new VueLoaderPlugin(),
			new webpack.DefinePlugin({ appName: JSON.stringify(appName), appVersion: JSON.stringify(appVersion) }),
		],
		externals: { OC: 'OC', OCA: 'OCA', OCP: 'OCP' },
	}
}
