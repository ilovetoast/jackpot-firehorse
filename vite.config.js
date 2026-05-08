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
        strictPort: false,
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
