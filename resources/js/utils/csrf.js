/**
 * CSRF helpers: Inertia visits use the <meta name="csrf-token"> value, not window.axios,
 * so the axios 419 interceptor in bootstrap.js does not apply to useForm().post() / router.post().
 * After session regeneration, workspace switches, or a long-idle tab, the meta tag can be stale — refresh before gateway POSTs.
 */

export function applyCsrfTokenToPage(token) {
    if (!token || typeof document === 'undefined') {
        return
    }
    const meta = document.head?.querySelector('meta[name="csrf-token"]')
    if (meta) {
        meta.setAttribute('content', token)
    }
    if (typeof window !== 'undefined' && window.axios?.defaults?.headers?.common) {
        window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token
    }
}

/**
 * GET /csrf-token — aligns meta + axios with the current session (see routes/web.php).
 */
export async function refreshCsrfTokenFromServer() {
    const axios = typeof window !== 'undefined' ? window.axios : null
    if (!axios) {
        return null
    }
    const { data } = await axios.get('/csrf-token', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
    const token = data?.token
    applyCsrfTokenToPage(token)
    return token ?? null
}
