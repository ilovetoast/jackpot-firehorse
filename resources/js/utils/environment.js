/**
 * 🔒 Phase 2.5 — Observability Layer (LOCKED)
 * This file is part of a locked phase. Do not refactor or change behavior.
 * Future phases may consume emitted signals only.
 * 
 * Phase 2.5 Step 4: Centralized Environment Detection Utility
 * 
 * Provides consistent environment detection across the application.
 * Centralizes all environment checks to ensure consistent behavior
 * and make future changes easier.
 * 
 * IMPORTANT: Environment detection is critical for:
 * - Debugging/logging behavior
 * - Diagnostics panel visibility
 * - Error detail exposure
 * - Performance optimizations
 * 
 * @module environment
 */

/**
 * Check if the application is running in development mode
 * 
 * Uses multiple fallbacks to detect development environment:
 * - Vite: import.meta.env.DEV or MODE !== 'production'
 * - Webpack: process.env.NODE_ENV === 'development'
 * - Runtime override: window.__DEV_UPLOAD_DIAGNOSTICS__
 * 
 * @returns {boolean} True if in development mode
 */
export function isDev() {
    if (typeof window !== 'undefined' && window.__DEV_UPLOAD_DIAGNOSTICS__ === true) {
        return true
    }

    // Vite environment detection
    if (typeof import !== 'undefined' && import.meta && import.meta.env) {
        if (import.meta.env.DEV === true) {
            return true
        }
        if (import.meta.env.MODE && import.meta.env.MODE !== 'production') {
            return true
        }
    }

    // Webpack/Node environment detection
    if (typeof process !== 'undefined' && process.env && process.env.NODE_ENV === 'development') {
        return true
    }

    return false
}

/**
 * Check if the application is running in production mode
 * 
 * @returns {boolean} True if in production mode
 */
export function isProd() {
    return !isDev()
}

/**
 * Check if diagnostics/developer features should be allowed
 * 
 * This is typically the same as isDev(), but can be extended
 * in the future for more granular control (e.g., staging environments).
 * 
 * Use this for:
 * - Diagnostics panels
 * - Detailed error information
 * - Debug logging
 * - Developer-only UI features
 * 
 * @returns {boolean} True if diagnostics should be enabled
 */
export function allowDiagnostics() {
    // Currently same as isDev(), but centralized for future flexibility
    return isDev()
}
