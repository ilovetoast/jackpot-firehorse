import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import react from '@vitejs/plugin-react'

// Must match compose.yaml: "${VITE_PORT:-5173}:${VITE_PORT:-5173}" (Sail publishes 5173 by default).
const VITE_PORT = Number.parseInt(process.env.VITE_PORT || '5173', 10) || 5173

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.jsx'],
            refresh: true,
        }),
        react(),
    ],
    server: {
        host: '0.0.0.0',
        port: VITE_PORT,
        // Must stay on VITE_PORT: Laravel's @vite() uses VITE_DEV_SERVER_URL (default :5173).
        // If something else grabs this port, Vite used to pick 5174+ silently → white screen / ERR_EMPTY_RESPONSE.
        strictPort: true,
        // Vite 6+ host checks; custom APP_URL hosts must be allowed or the dev server closes the connection.
        allowedHosts: ['jackpot.local', 'localhost', '127.0.0.1'],
        hmr: {
            host: 'jackpot.local',
            protocol: 'ws',
            clientPort: VITE_PORT,
        },
        cors: true,
        headers: {
            'Access-Control-Allow-Origin': '*',
            'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
            'Access-Control-Allow-Headers': 'X-Requested-With, Content-Type, X-Token, Authorization, Accept, Application',
        }
    }
})
