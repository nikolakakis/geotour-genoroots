import { defineConfig } from 'vite';
import path from 'path';

export default defineConfig( {
	build: {
		outDir: 'build',
		manifest: true,
		// One main.css next to main.js (enqueued by PHP). Without this, the iife
		// format below injects the CSS from the script instead.
		cssCodeSplit: false,
		rollupOptions: {
			input: {
				main: path.resolve( __dirname, 'src/index.js' ),
			},
			output: {
				// Loaded as a classic <script>: wrap everything in one function so
				// the minified names (e.g. `mr`) stay private. Unwrapped, they
				// collided with another plugin's bundle on GeoTour, and whichever
				// script ran second failed outright.
				format: 'iife',
				entryFileNames: 'main.js',
				assetFileNames: ( assetInfo ) =>
					assetInfo.name && assetInfo.name.endsWith( '.css' ) ? 'main.css' : assetInfo.name,
			},
		},
	},
	resolve: {
		alias: {
			'@': path.resolve( __dirname, 'src' ),
		},
	},
} );
