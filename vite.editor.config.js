import { defineConfig } from 'vite';
import path from 'path';

// The field editor ([genoroots_field_editor]) as its own bundle, so the
// public tree doesn't carry it: build/editor.js + build/editor.css, next to
// the main build (run second: it must not empty build/).
export default defineConfig( {
	build: {
		outDir: 'build',
		emptyOutDir: false,
		manifest: false,
		cssCodeSplit: false,
		rollupOptions: {
			input: {
				editor: path.resolve( __dirname, 'src/editor/index.js' ),
			},
			output: {
				// A classic <script>, wrapped like main.js so names stay private.
				format: 'iife',
				entryFileNames: 'editor.js',
				assetFileNames: ( assetInfo ) =>
					assetInfo.name && assetInfo.name.endsWith( '.css' ) ? 'editor.css' : assetInfo.name,
			},
		},
	},
} );
