import { defineConfig } from 'vite';
import { resolve } from 'node:path';

/**
 * Build configuration for the ReserveChain theme.
 *
 * WordPress reads `assets/build/.vite/manifest.json` at runtime to resolve
 * hashed filenames, so nothing here needs to be mirrored in PHP.
 *
 * Three.js is code-split into its own chunk and imported dynamically by the
 * scene loader. The 3D bundle is large relative to everything else, and the
 * brief is explicit that decorative animation must not degrade loading or
 * mobile performance — so it is never part of the critical path and is only
 * fetched once a scene has decided it will actually run.
 */
export default defineConfig({
	root: resolve(__dirname),
	base: '',

	build: {
		outDir: 'assets/build',
		emptyOutDir: true,
		manifest: true,
		target: 'es2020',
		cssCodeSplit: false,
		sourcemap: false,

		rollupOptions: {
			input: resolve(__dirname, 'src/main.ts'),
			output: {
				entryFileNames: 'js/[name].[hash].js',
				chunkFileNames: 'js/[name].[hash].js',
				assetFileNames: (info) => {
					const name = info.names?.[0] ?? '';
					if (/\.css$/.test(name)) return 'css/[name].[hash][extname]';
					if (/\.(woff2?|ttf|otf)$/.test(name)) return 'fonts/[name].[hash][extname]';
					return 'assets/[name].[hash][extname]';
				},
				manualChunks(id) {
					if (id.includes('node_modules/three')) {
						return 'three';
					}
					return undefined;
				},
			},
		},
	},

	esbuild: {
		legalComments: 'none',
	},
});
