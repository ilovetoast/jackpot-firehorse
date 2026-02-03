/**
 * useProcessingDownloadsPolling Hook
 *
 * Patch-based polling for processing downloads. Fetches only mutable fields
 * and patches downloadsById; never replaces arrays or triggers Inertia.
 * Scoped to processing download IDs only; stops when none remain.
 *
 * RULES:
 * - Poll ONLY for downloads with state === 'processing'
 * - Fetch via GET /app/api/downloads/poll?ids=...
 * - Patch only: setDownloadsById(prev => ({ ...prev, [id]: mergeDownloadPatch(prev[id], patch) }))
 * - Never call router.reload or replace page-level state
 * - Polling causes only leaf re-renders (updated download objects)
 */

import { useEffect, useRef } from 'react'
import { mergeDownloadPatch } from '../utils/downloadUtils'

const POLL_INTERVAL_MS = 3000

/**
 * @param {Record<string, object>} downloadsById - Map of id -> download (state)
 * @param {Function} setDownloadsById - Setter for downloadsById (patch-only updates)
 * @param {string[]} downloadIds - Stable list of download IDs (order); never modified by polling
 */
export function useProcessingDownloadsPolling(downloadsById, setDownloadsById, downloadIds) {
  const downloadsByIdRef = useRef(downloadsById)
  const intervalIdRef = useRef(null)

  useEffect(() => {
    downloadsByIdRef.current = downloadsById
  }, [downloadsById])

  useEffect(() => {
    const byId = downloadsByIdRef.current || {}
    const processingIds = (downloadIds || []).filter((id) => {
      const d = byId[id]
      return d && (d.state || '') === 'processing'
    })

    if (processingIds.length === 0) {
      if (intervalIdRef.current) {
        clearInterval(intervalIdRef.current)
        intervalIdRef.current = null
      }
      return
    }

    const poll = async () => {
      const currentById = downloadsByIdRef.current || {}
      const ids = (downloadIds || []).filter((id) => {
        const d = currentById[id]
        return d && (d.state || '') === 'processing'
      })
      if (ids.length === 0) return

      try {
        const response = await window.axios.get('/app/api/downloads/poll', {
          params: { ids: ids.join(',') },
          headers: { Accept: 'application/json' },
        })
        const patches = response.data?.downloads || []
        if (patches.length === 0) return

        setDownloadsById((prev) => {
          const next = { ...prev }
          for (const patch of patches) {
            if (!patch.id) continue
            if (!prev[patch.id]) continue
            next[patch.id] = mergeDownloadPatch(prev[patch.id], patch)
          }
          return next
        })
      } catch (err) {
        console.warn('[useProcessingDownloadsPolling] Poll error:', err)
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
  }, [downloadIds, setDownloadsById])
}
