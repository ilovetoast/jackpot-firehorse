import { usePage } from '@inertiajs/react'
import { useMemo } from 'react'

/**
 * Shared hook for download dialogs (Create, Settings, Revoke, Regenerate) to consume
 * usePage().props.errors consistently. Use for banner + inline field errors.
 *
 * @param {string[]} keys - Error keys to consider (e.g. ['message', 'password', 'access_mode'])
 * @returns {{ bannerMessage: string|null, getFieldError: (key: string) => string|null }}
 */
export function useDownloadErrors(keys = ['message']) {
  const { errors: pageErrors = {} } = usePage().props

  const normalized = useMemo(() => {
    const obj = {}
    for (const key of Object.keys(pageErrors)) {
      const v = pageErrors[key]
      if (typeof v === 'string') obj[key] = v
      else if (Array.isArray(v) && v[0]) obj[key] = v[0]
      else if (v != null) obj[key] = String(v)
    }
    return obj
  }, [pageErrors])

  const bannerMessage = useMemo(() => {
    const k = Array.isArray(keys) ? keys : [keys]
    for (const key of k) {
      if (normalized[key]) return normalized[key]
    }
    return null
  }, [keys, normalized])

  const getFieldError = useMemo(
    () => (key) => normalized[key] ?? null,
    [normalized]
  )

  return { bannerMessage, getFieldError }
}
