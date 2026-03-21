/**
 * Global Inertia error wiring (runs once at app boot).
 * - inertia:invalid 403 (logged-in): existing permission-denied modal
 * - other invalid responses: global error modal (expects JSON from Laravel for X-Inertia)
 * - inertia:exception: network / unexpected failures
 * - inertia:error: validation (props.errors) — left to forms; no global modal
 */
import { router } from '@inertiajs/react'
import { showGlobalError } from './stores/errorStore'
import { parsePermissionDeniedHtml } from './utils/parsePermissionDeniedHtml'
import { resolvePermissionTheme } from './utils/resolvePermissionTheme'

function messageFromInvalidResponse(response) {
    const data = response?.data
    if (data && typeof data === 'object' && data.message) {
        return String(data.message)
    }
    if (typeof data === 'string') {
        const t = data.trim()
        if (t.length && t.length < 4000 && !t.includes('<body')) {
            return t.slice(0, 500)
        }
    }
    return 'Something went wrong.'
}

if (typeof document !== 'undefined') {
    document.addEventListener('inertia:invalid', (event) => {
        const res = event.detail.response
        if (!res || res.status !== 403) return

        let user = null
        try {
            user = router.page?.props?.auth?.user
        } catch {
            /* ignore */
        }
        if (!user) return

        event.preventDefault()

        const raw = res.data
        let title = 'Access denied'
        let message = 'You do not have permission to perform this action.'

        if (typeof raw === 'string' && raw.includes('<')) {
            const parsed = parsePermissionDeniedHtml(raw)
            title = parsed.title
            message = parsed.message
        } else if (raw && typeof raw === 'object') {
            if (raw.message) message = String(raw.message)
            if (raw.title) title = String(raw.title)
        } else if (typeof raw === 'string' && raw.trim().length) {
            message = raw.trim()
        }

        const theme = resolvePermissionTheme(
            router.page?.url || '',
            router.page?.props?.auth?.activeBrand
        )

        window.dispatchEvent(
            new CustomEvent('jackpot:permission-denied', {
                detail: { title, message, theme, source: 'inertia' },
            })
        )
    })
}

router.on('error', () => {
    // Validation errors — surfaced via useForm / props.errors; do not show global modal.
})

router.on('exception', (event) => {
    const ex = event.detail?.exception
    const msg =
        (ex && typeof ex.message === 'string' && ex.message) ||
        'Request failed — check your connection.'
    showGlobalError({
        message: msg,
        type: 'network',
        autoDismissMs: 8000,
    })
})

router.on('invalid', (event) => {
    const res = event.detail?.response
    if (!res) return
    if (event.defaultPrevented) return

    event.preventDefault()

    const status = res.status ?? 500
    const message = messageFromInvalidResponse(res)
    const type = status >= 500 ? 'server' : 'server'

    showGlobalError({
        message,
        type,
        statusCode: status,
        retry: () =>
            router.reload({
                preserveScroll: true,
                preserveState: false,
            }),
    })
})
