import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

// PDF.js requests its fonts, CMaps and image decoders by filename. Ship these
// with the build so Thai PDFs and scanned covers work without a third-party CDN.
function pdfRenderingAssets() {
    const root = fileURLToPath(new URL('./node_modules/pdfjs-dist/', import.meta.url));
    const directories = ['cmaps', 'standard_fonts', 'wasm'];
    return {
        name: 'local-pdf-rendering-assets',
        generateBundle() {
            this.emitFile({ type: 'asset', fileName: 'pdfjs/LICENSE.pdfjs', source: readFileSync(`${root}LICENSE`) });
            for (const directory of directories) {
                for (const filename of readdirSync(`${root}${directory}`)) {
                    this.emitFile({ type: 'asset', fileName: `pdfjs/${directory}/${filename}`, source: readFileSync(`${root}${directory}/${filename}`) });
                }
            }
        },
        configureServer(server) {
            server.middlewares.use((request, response, next) => {
                const match = /^\/pdfjs\/(cmaps|standard_fonts|wasm)\/([a-zA-Z0-9_.-]+)$/.exec((request.url ?? '').split('?')[0]);
                if (!match) return next();
                try {
                    const bytes = readFileSync(`${root}${match[1]}/${match[2]}`);
                    response.setHeader('Content-Type', match[2].endsWith('.wasm') ? 'application/wasm' : 'application/octet-stream');
                    response.end(bytes);
                } catch { response.statusCode = 404; response.end(); }
            });
        },
    };
}

export default defineConfig({
    // Blade resolves entry URLs for the deployment path. Keep lazy chunks,
    // their CSS and fonts relative so the same build also works in /SDL_school/.
    base: './',
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
        pdfRenderingAssets(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
