import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Helper function to update CSRF token in meta tag and axios defaults
function updateCsrfToken(newToken) {
    const metaTag = document.head.querySelector('meta[name="csrf-token"]');
    if (metaTag) {
        metaTag.setAttribute('content', newToken);
    }
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = newToken;
}

// Set initial CSRF token for all axios requests
const token = document.head.querySelector('meta[name="csrf-token"]');
if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
} else {
    console.error('CSRF token not found: https://laravel.com/docs/csrf#csrf-x-csrf-token');
}

// Intercept 419 CSRF token mismatch errors and automatically refresh token
window.axios.interceptors.response.use(
    (response) => response,
    async (error) => {
        const originalRequest = error.config;

        // Check if error is 419 CSRF token mismatch
        if (error.response?.status === 419 && !originalRequest._retry) {
            originalRequest._retry = true;

            try {
                // Fetch new CSRF token from server
                const response = await axios.get('/csrf-token', {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (response.data?.token) {
                    // Update CSRF token in meta tag and axios defaults
                    updateCsrfToken(response.data.token);

                    // Update the original request's CSRF token header
                    originalRequest.headers['X-CSRF-TOKEN'] = response.data.token;

                    // Retry the original request
                    return axios(originalRequest);
                }
            } catch (refreshError) {
                // If token refresh fails, return the original error
                console.error('Failed to refresh CSRF token:', refreshError);
            }
        }

        return Promise.reject(error);
    }
);
