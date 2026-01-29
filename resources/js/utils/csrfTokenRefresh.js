/**
 * CSRF Token Refresh Utility
 * 
 * Handles automatic CSRF token refresh when 419 errors occur.
 * This prevents "session expired" errors after login when the session
 * is regenerated but the frontend still has the old token cached.
 * 
 * Usage:
 * - Automatically handled by axios interceptor in bootstrap.js
 * - For fetch() calls, use refreshCsrfToken() before retrying
 */

/**
 * Refresh the CSRF token from the server and update the meta tag and axios defaults.
 * 
 * @returns {Promise<string|null>} The new CSRF token, or null if refresh failed
 */
export async function refreshCsrfToken() {
    try {
        const response = await fetch('/csrf-token', {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            console.error('Failed to refresh CSRF token: HTTP', response.status);
            return null;
        }

        const data = await response.json();
        const newToken = data?.token;

        if (!newToken) {
            console.error('CSRF token refresh response missing token');
            return null;
        }

        // Update meta tag
        const metaTag = document.head.querySelector('meta[name="csrf-token"]');
        if (metaTag) {
            metaTag.setAttribute('content', newToken);
        }

        // Update axios defaults if available
        if (window.axios) {
            window.axios.defaults.headers.common['X-CSRF-TOKEN'] = newToken;
        }

        return newToken;
    } catch (error) {
        console.error('Error refreshing CSRF token:', error);
        return null;
    }
}

/**
 * Check if an error response is a 419 CSRF token mismatch.
 * 
 * @param {Response|Error} error - The error to check
 * @returns {boolean} True if the error is a 419 CSRF mismatch
 */
export function isCsrfTokenMismatch(error) {
    if (error?.response?.status === 419) {
        return true;
    }
    if (error?.status === 419) {
        return true;
    }
    if (error instanceof Response && error.status === 419) {
        return true;
    }
    return false;
}
