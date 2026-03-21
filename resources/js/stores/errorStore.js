import { create } from 'zustand'

/**
 * Global application error surface (modal). Not for Inertia form validation (422 + props.errors).
 */
export const useErrorStore = create((set) => ({
    error: null,
    showError: (payload) => set({ error: payload }),
    clearError: () => set({ error: null }),
}))

/**
 * @param {object} payload
 * @param {string} payload.message
 * @param {'server'|'validation'|'network'} [payload.type]
 * @param {number} [payload.statusCode]
 * @param {() => void} [payload.retry]
 * @param {number} [payload.autoDismissMs] — e.g. network blips
 */
export function showGlobalError(payload) {
    const p = typeof payload === 'string' ? { message: payload } : payload || {}
    useErrorStore.getState().showError({
        message: p.message || 'Something went wrong.',
        type: p.type || 'server',
        statusCode: p.statusCode,
        retry: p.retry,
        autoDismissMs: p.autoDismissMs,
    })
}

function isInertiaAxiosRequest(error) {
    const h = error.config?.headers
    if (!h) return false
    const inertia = h['X-Inertia'] ?? h['x-inertia']
    return inertia === true || inertia === 'true'
}

/**
 * Axios error hook — skips Inertia page visits (handled by inertiaGlobalErrorHandling),
 * 403 (permission modal), 422 (forms), 419 (CSRF retry).
 */
export function showGlobalErrorFromAxios(error) {
    if (isInertiaAxiosRequest(error)) {
        return
    }

    const status = error.response?.status

    if (status === 403) {
        return
    }
    if (status === 422) {
        return
    }
    if (status === 419) {
        return
    }

    if (!error.response) {
        showGlobalError({
            message: error.message || 'Network error — check your connection.',
            type: 'network',
            autoDismissMs: 8000,
        })
        return
    }

    const data = error.response.data
    let message = 'Request failed'
    if (data && typeof data === 'object' && data.message) {
        message = String(data.message)
    } else if (typeof data === 'string' && data.trim().length && data.length < 2000 && !data.trim().startsWith('<')) {
        message = data.trim().slice(0, 500)
    }

    const type =
        status >= 500 ? 'server' : status === 422 ? 'validation' : 'server'

    showGlobalError({
        message,
        type,
        statusCode: status,
    })
}
