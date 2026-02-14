/**
 * usePresencePolling Hook
 *
 * Polls presence heartbeat and online users. Presence is ambient, not realtime.
 * Only runs when enabled (user has team.manage or brand_settings.manage).
 *
 * RULES:
 * - POST /app/presence/heartbeat (with page)
 * - GET /app/presence/online (if authorized)
 * - Poll interval: 30s (TTL is 90s; no need for sub-minute resolution)
 */

import { useEffect, useRef, useState } from 'react'

const POLL_INTERVAL_MS = 30000 // 30 seconds — presence is ambient, not realtime

/**
 * @param {boolean} enabled - Whether to run presence polling (user has permission)
 * @returns {{ online: Array<{id: number, name: string, role: string|null, page: string|null, last_seen: number}> }}
 */
export function usePresencePolling(enabled) {
  const [online, setOnline] = useState([])
  const intervalIdRef = useRef(null)

  useEffect(() => {
    if (!enabled) {
      setOnline([])
      return
    }

    const poll = async () => {
      try {
        const page = typeof window !== 'undefined' ? window.location.pathname : null
        await window.axios.post('/app/presence/heartbeat', { page }, {
          headers: { Accept: 'application/json' },
        })
        const response = await window.axios.get('/app/presence/online', {
          headers: { Accept: 'application/json' },
        })
        const data = Array.isArray(response.data) ? response.data : []
        setOnline(data)
      } catch (err) {
        if (err.response?.status === 403) {
          setOnline([])
        }
        // Silently ignore network/other errors (e.g. Redis unavailable)
      }
    }

    intervalIdRef.current = setInterval(poll, POLL_INTERVAL_MS)
    poll()

    return () => {
      if (intervalIdRef.current) {
        clearInterval(intervalIdRef.current)
        intervalIdRef.current = null
      }
    }
  }, [enabled])

  return { online }
}
