/**
 * Client-side cookie / similar-tech consent (Privacy Policy §9).
 * Synced with POST /privacy/consent for logged-in audit trail.
 */

export const STORAGE_KEY = 'jackpot_cookie_consent_v1'

/**
 * @returns {{ policy_version: string, purposes: { functional: boolean, analytics: boolean, marketing: boolean }, updated_at: string } | null}
 */
export function readStoredConsent() {
    if (typeof window === 'undefined') {
        return null
    }
    try {
        const raw = localStorage.getItem(STORAGE_KEY)
        if (!raw) {
            return null
        }
        return JSON.parse(raw)
    } catch {
        return null
    }
}

export function writeStoredConsent(purposes, policyVersion) {
    if (typeof window === 'undefined') {
        return
    }
    const payload = {
        policy_version: policyVersion,
        purposes: {
            functional: !!purposes.functional,
            analytics: !!purposes.analytics,
            marketing: !!purposes.marketing,
        },
        updated_at: new Date().toISOString(),
    }
    localStorage.setItem(STORAGE_KEY, JSON.stringify(payload))
}

export function consentIsCurrent(stored, policyVersion) {
    if (!stored || !stored.policy_version || !policyVersion) {
        return false
    }
    return stored.policy_version === policyVersion
}

export function allowsAnalyticsCookies() {
    if (typeof window === 'undefined') {
        return false
    }
    if (window.__jackpotPrivacyBootstrap?.gpc) {
        return false
    }
    const s = readStoredConsent()
    const pv = window.__jackpotPrivacyBootstrap?.cookie_policy_version
    if (!s || !pv || s.policy_version !== pv) {
        return false
    }
    return s.purposes?.analytics === true
}

export function allowsFunctionalCookies() {
    if (typeof window === 'undefined') {
        return false
    }
    const s = readStoredConsent()
    const pv = window.__jackpotPrivacyBootstrap?.cookie_policy_version
    if (!s || !pv || s.policy_version !== pv) {
        return false
    }
    return s.purposes?.functional === true
}

/**
 * Human-readable error for UI (avoids dumping raw JSON like `{ "message": "CSRF token mismatch." }`).
 *
 * @param {unknown} error
 * @returns {string}
 */
export function formatConsentSaveError(error) {
    if (!error) {
        return 'Could not save preferences.'
    }
    const ax = error.response?.data
    if (ax && typeof ax.message === 'string') {
        return ax.message
    }
    const msg = typeof error.message === 'string' ? error.message : ''
    if (msg.startsWith('{')) {
        try {
            const parsed = JSON.parse(msg)
            if (parsed && typeof parsed.message === 'string') {
                return parsed.message
            }
        } catch {
            /* ignore */
        }
    }
    return msg || 'Could not save preferences.'
}

/**
 * POST /privacy/consent — uses window.axios (from bootstrap) so X-CSRF-TOKEN matches session and 419 is retried.
 */
export async function postConsentToServer(purposes, policyVersion) {
    const body = {
        purposes: {
            functional: !!purposes.functional,
            analytics: !!purposes.analytics,
            marketing: !!purposes.marketing,
        },
        policy_version: policyVersion,
    }

    const axios = typeof window !== 'undefined' ? window.axios : null
    if (axios) {
        const { data } = await axios.post('/privacy/consent', body, {
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
        return data
    }

    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
    const res = await fetch('/privacy/consent', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-CSRF-TOKEN': token } : {}),
        },
        body: JSON.stringify(body),
    })
    if (!res.ok) {
        const t = await res.text()
        let msg = t || `Consent save failed (${res.status})`
        try {
            const j = JSON.parse(t)
            if (j?.message) {
                msg = j.message
            }
        } catch {
            /* keep */
        }
        throw new Error(msg)
    }
    return res.json()
}
