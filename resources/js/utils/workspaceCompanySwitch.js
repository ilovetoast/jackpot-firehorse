import { showWorkspaceSwitchingOverlay } from './workspaceSwitchOverlay'

/**
 * Company (+ optional brand) workspace switch with exactly one full-page navigation.
 *
 * Do not use `router.post` + `onSuccess(() => window.location = …)` for switches: Inertia sends
 * X-Inertia, the server issues a redirect that Inertia already follows, then the manual
 * `window.location` runs — double load on the destination (e.g. brand overview).
 *
 * This matches {@see AppNav} handleSwitchCompanyTo: JSON response + single assign to location.
 *
 * @param {object} opts
 * @param {number|string} opts.companyId
 * @param {number|string|null} [opts.brandId]
 * @param {string} [opts.redirect='/app/overview']
 */
export function switchCompanyWorkspace({ companyId, brandId = null, redirect = '/app/overview' }) {
    showWorkspaceSwitchingOverlay('company')
    const csrfToken = typeof document !== 'undefined'
        ? document.querySelector('meta[name="csrf-token"]')?.content
        : ''
    const fd = new FormData()
    fd.append('_token', csrfToken)
    fd.append('redirect', redirect)
    if (brandId != null && brandId !== '') {
        fd.append('brand_id', String(brandId))
    }

    return fetch(`/app/companies/${companyId}/switch`, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    })
        .then(async (res) => {
            let data = {}
            try {
                data = await res.json()
            } catch {
                /* non-JSON error body */
            }
            if (!res.ok && typeof console !== 'undefined' && console.warn) {
                console.warn('[workspaceCompanySwitch] company switch failed', {
                    status: res.status,
                    companyId,
                    message: data?.message ?? data?.error ?? null,
                })
            }
            const target =
                res.ok &&
                typeof data.redirect === 'string' &&
                data.redirect.startsWith('/app') &&
                !data.redirect.startsWith('/app/api')
                    ? data.redirect
                    : redirect
            // `replace()` (not `href =`) so the previous tenant's URL is dropped
            // from the back stack. Without this, browser-Back returns the user to
            // a page rendered for the *old* tenant — server then renders that URL
            // for the *new* tenant's session, producing 403/404/empty-state UI
            // ("the back button got stuck"). See docs/WORKSPACE_SWITCHING.md.
            window.location.replace(target)
        })
        .catch(() => {
            window.location.replace(redirect)
        })
}
