import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
                // Journal display face. Instrument Sans carries the shop; the
                // editorial pages need a voice of their own, and a high-contrast
                // serif is the cheapest way to sound like a magazine rather
                // than a product listing.
                bunny('Instrument Serif', {
                    weights: [400],
                    italic: true,
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        // Bind inside the container so the mapped port reaches it...
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        // ...but tell the *browser* localhost. Without `origin`, the plugin
        // writes "http://0.0.0.0:5173" into public/hot, which is a bind-all
        // address the browser cannot resolve — every asset 404s silently and
        // the page renders unstyled with no console error worth noticing.
        origin: 'http://localhost:5173',
        // The app is served from :8080, assets from :5173 — a real
        // cross-origin request, not same-origin the way a bare `vite dev`
        // on one port would be. Vite's dev-server CORS default echoes the
        // *request's own* Origin header back only when it matches the
        // server's own configured origin, so a page loaded from :8080
        // asking :5173 for app.js gets an Access-Control-Allow-Origin of
        // ":5173" — which the browser rejects, since it doesn't match the
        // page's own origin. `cors: true` tells Vite to allow any request
        // origin, which is safe here: this only runs in local dev, behind
        // Docker's own port mapping, never reachable outside the host.
        cors: true,
        hmr: {
            host: 'localhost',
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
