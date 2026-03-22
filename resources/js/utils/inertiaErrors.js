/**
 * Normalize Laravel / Inertia validation messages (string or string[]).
 * Shared `props.errors` and `useForm().errors` may differ after redirects.
 */
export function firstError(...vals) {
    for (const v of vals) {
        if (v == null || v === '') {
            continue
        }
        if (Array.isArray(v)) {
            if (v[0]) {
                return v[0]
            }
        } else if (typeof v === 'string') {
            return v
        }
    }
    return null
}
