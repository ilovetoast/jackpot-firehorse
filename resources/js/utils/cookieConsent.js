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

export async function postConsentToServer(purposes, policyVersion) {
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
        body: JSON.stringify({
            purposes,
            policy_version: policyVersion,
        }),
    })
    if (!res.ok) {
        const t = await res.text()
        throw new Error(t || `Consent save failed (${res.status})`)
    }
    return res.json()
}
