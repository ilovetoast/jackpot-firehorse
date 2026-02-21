import { useEffect } from 'react'

const RELOAD_COOLDOWN_MS = 60_000

/**
 * Hook to recover from CDN 403 (expired signed URLs) on public pages.
 *
 * When an img with a CDN URL fails to load (e.g. expired signed URL → 403),
 * reloads the page to fetch fresh signed URLs from the server.
 *
 * @param {string|null} cdnDomain - CloudFront domain (e.g. "d123.cloudfront.net" or "cdn.example.com").
 *   When null/empty, the hook does nothing.
 */
export function useCdn403Recovery(cdnDomain) {
  useEffect(() => {
    if (!cdnDomain || typeof cdnDomain !== 'string') return

    const domain = cdnDomain.replace(/^https?:\/\//, '').split('/')[0]

    const handleError = (e) => {
      const target = e.target
      if (target?.tagName !== 'IMG') return

      const src = target.src
      if (!src || !src.includes(domain)) return

      // Rate limit: avoid reload loops
      const lastReload = sessionStorage.getItem('cdn_403_reload_ts')
      if (lastReload && Date.now() - parseInt(lastReload, 10) < RELOAD_COOLDOWN_MS) {
        return
      }

      sessionStorage.setItem('cdn_403_reload_ts', String(Date.now()))
      window.location.reload()
    }

    document.addEventListener('error', handleError, true)
    return () => document.removeEventListener('error', handleError, true)
  }, [cdnDomain])
}
