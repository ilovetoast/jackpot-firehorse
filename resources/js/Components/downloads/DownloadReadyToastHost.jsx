/**
 * Global “download ready” toast when a tracked ZIP leaves processing → ready while the tray is closed
 * and the user is not on the full Downloads page. Deduped per download per tab session.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import { Link, usePage } from '@inertiajs/react'
import {
  ArrowDownTrayIcon,
  ArrowTopRightOnSquareIcon,
  ClipboardDocumentIcon,
  XMarkIcon,
} from '@heroicons/react/24/outline'
import { CheckCircleIcon } from '@heroicons/react/24/outline'
import { useDownloadsPanelOptional } from '../../contexts/DownloadsPanelContext'
import { useProcessingDownloadsPolling } from '../../hooks/useProcessingDownloadsPolling'
import { keyByDownloads, warnIfReplacingRootState } from '../../utils/downloadUtils'
import { formatBytesHuman } from '../../utils/formatBytesHuman'
import {
  DOWNLOAD_PROCESSING_EVENT,
  DOWNLOAD_SUPPRESS_READY_TOAST_EVENT,
} from '../../utils/downloadReadyToastEvents'

const TOAST_MS = 14000

function isDownloadsIndexPage(component, url) {
  if (component === 'Downloads/Index') return true
  const path = (url || '').split('?')[0]
  return /\/downloads\/?$/.test(path)
}

function formatBytes(bytes) {
  if (bytes == null || bytes === 0) return null
  return formatBytesHuman(bytes)
}

export default function DownloadReadyToastHost() {
  const page = usePage()
  const { auth } = page.props
  const onDownloadsPage = useMemo(
    () => isDownloadsIndexPage(page.component, page.url),
    [page.component, page.url]
  )
  const downloadsPanel = useDownloadsPanelOptional()

  const [byId, setByIdState] = useState({})
  const setById = useCallback((updater) => {
    if (typeof updater !== 'function') {
      warnIfReplacingRootState('DownloadReadyToastHost byId')
      return
    }
    setByIdState(updater)
  }, [])

  const processingIds = useMemo(
    () => Object.keys(byId).filter((id) => (byId[id]?.state || '') === 'processing'),
    [byId]
  )

  useProcessingDownloadsPolling(byId, setById, processingIds)

  const notifiedReadyRef = useRef(new Set())
  const suppressedRef = useRef(new Set())
  const prevStateByIdRef = useRef({})
  /** Downloads that reached `ready` while the tray was open — toast after tray closes (if not on /downloads). */
  const pendingToastAfterTrayCloseRef = useRef({})
  const prevPanelOpenRef = useRef(false)
  const dismissTimerRef = useRef(null)

  const [activeToast, setActiveToast] = useState(null)
  const queueRef = useRef([])
  const pushToastRef = useRef(() => {})

  const clearDismissTimer = useCallback(() => {
    if (dismissTimerRef.current) {
      clearTimeout(dismissTimerRef.current)
      dismissTimerRef.current = null
    }
  }, [])

  const dismissRef = useRef(() => {})

  const dismiss = useCallback(() => {
    clearDismissTimer()
    setActiveToast((cur) => {
      if (cur?.id) notifiedReadyRef.current.add(cur.id)
      return null
    })
    window.setTimeout(() => {
      const next = queueRef.current.shift()
      if (next) {
        setActiveToast(next)
        dismissTimerRef.current = window.setTimeout(() => dismissRef.current(), TOAST_MS)
      }
    }, 0)
  }, [clearDismissTimer])

  dismissRef.current = dismiss

  pushToastRef.current = (download) => {
    setActiveToast((cur) => {
      if (cur) {
        queueRef.current.push(download)
        return cur
      }
      clearDismissTimer()
      dismissTimerRef.current = window.setTimeout(() => dismissRef.current(), TOAST_MS)
      return download
    })
  }

  useEffect(() => {
    if (downloadsPanel?.open || onDownloadsPage) {
      clearDismissTimer()
      setActiveToast(null)
    }
  }, [downloadsPanel?.open, onDownloadsPage, clearDismissTimer])

  // Bootstrap: pick up in-flight downloads after navigation / refresh
  useEffect(() => {
    if (!auth?.user) return
    let cancelled = false
    ;(async () => {
      try {
        const { data } = await window.axios.get(route('downloads.index'), {
          params: {
            scope: 'mine',
            status: '',
            access: '',
            brand_id: '',
            user_id: '',
            sort: 'date_desc',
            page: 1,
          },
          headers: { Accept: 'application/json' },
        })
        if (cancelled) return
        const processing = (data.downloads || []).filter((d) => (d.state || '') === 'processing')
        if (processing.length === 0) return
        setByIdState((prev) => ({ ...prev, ...keyByDownloads(processing) }))
      } catch {
        /* ignore */
      }
    })()
    return () => {
      cancelled = true
    }
  }, [auth?.user?.id])

  useEffect(() => {
    const onProcessing = (e) => {
      const id = e.detail?.id
      if (!id) return
      notifiedReadyRef.current.delete(id)
      suppressedRef.current.delete(id)
      setByIdState((prev) => ({
        ...prev,
        [id]: prev[id] || {
          id,
          state: 'processing',
          title: 'Download',
          public_url: null,
          asset_count: 0,
          zip_size_bytes: 0,
          thumbnails: [],
        },
      }))
    }
    const onSuppress = (e) => {
      const id = e.detail?.id
      if (!id) return
      suppressedRef.current.add(id)
      delete pendingToastAfterTrayCloseRef.current[id]
      setActiveToast((cur) => (cur?.id === id ? null : cur))
    }
    window.addEventListener(DOWNLOAD_PROCESSING_EVENT, onProcessing)
    window.addEventListener(DOWNLOAD_SUPPRESS_READY_TOAST_EVENT, onSuppress)
    return () => {
      window.removeEventListener(DOWNLOAD_PROCESSING_EVENT, onProcessing)
      window.removeEventListener(DOWNLOAD_SUPPRESS_READY_TOAST_EVENT, onSuppress)
    }
  }, [])

  useEffect(() => {
    const panelOpen = downloadsPanel?.open === true
    const prev = prevStateByIdRef.current
    const toRemove = []

    for (const id of Object.keys(byId)) {
      const d = byId[id]
      const prevState = prev[id]?.state
      const nextState = d?.state || ''
      if (prevState === 'processing' && nextState === 'ready' && d.public_url) {
        const suppressed = suppressedRef.current.has(id)
        const already = notifiedReadyRef.current.has(id)

        if (suppressed || onDownloadsPage) {
          if (!already) notifiedReadyRef.current.add(id)
          toRemove.push(id)
        } else if (panelOpen) {
          pendingToastAfterTrayCloseRef.current[id] = { ...d }
          toRemove.push(id)
        } else if (!already) {
          notifiedReadyRef.current.add(id)
          pushToastRef.current(d)
          toRemove.push(id)
        } else {
          toRemove.push(id)
        }
      }
    }

    prevStateByIdRef.current = Object.fromEntries(
      Object.keys(byId).map((id) => [id, { state: byId[id]?.state }])
    )

    if (toRemove.length > 0) {
      setByIdState((prevMap) => {
        const next = { ...prevMap }
        for (const id of toRemove) delete next[id]
        return next
      })
    }
  }, [byId, downloadsPanel?.open, onDownloadsPage])

  useEffect(() => {
    if (onDownloadsPage && Object.keys(pendingToastAfterTrayCloseRef.current).length > 0) {
      for (const id of Object.keys(pendingToastAfterTrayCloseRef.current)) {
        notifiedReadyRef.current.add(id)
      }
      pendingToastAfterTrayCloseRef.current = {}
    }
  }, [onDownloadsPage])

  useEffect(() => {
    const open = downloadsPanel?.open === true
    const wasOpen = prevPanelOpenRef.current
    prevPanelOpenRef.current = open
    if (!wasOpen || open) return

    const pending = pendingToastAfterTrayCloseRef.current
    pendingToastAfterTrayCloseRef.current = {}
    if (onDownloadsPage) {
      for (const id of Object.keys(pending)) {
        notifiedReadyRef.current.add(id)
      }
      return
    }
    for (const id of Object.keys(pending)) {
      const d = pending[id]
      if (!d) continue
      if (suppressedRef.current.has(id)) continue
      if (notifiedReadyRef.current.has(id)) continue
      notifiedReadyRef.current.add(id)
      pushToastRef.current(d)
    }
  }, [downloadsPanel?.open, onDownloadsPage])

  const copyUrl = useCallback((url) => {
    if (!url) return
    if (navigator.clipboard?.writeText) void navigator.clipboard.writeText(url)
  }, [])

  useEffect(() => () => clearDismissTimer(), [clearDismissTimer])

  if (!auth?.user) return null

  const t = activeToast
  const metaParts = []
  if (t?.asset_count != null) metaParts.push(`${t.asset_count} file${t.asset_count !== 1 ? 's' : ''}`)
  const sz = formatBytes(t?.zip_size_bytes)
  if (sz) metaParts.push(sz)
  const metaLine = metaParts.length ? metaParts.join(' · ') : null
  const filename = t?.title || (t?.id ? `Download ${t.id}` : '')

  return (
    <div className="pointer-events-none fixed bottom-4 right-4 z-[125] flex max-w-[min(100vw-2rem,380px)] flex-col items-end sm:bottom-6 sm:right-6">
      <AnimatePresence mode="wait">
        {t && (
          <motion.div
            key={t.id}
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: 4 }}
            transition={{ duration: 0.2, ease: [0.25, 0.1, 0.25, 1] }}
            className="pointer-events-auto w-full overflow-hidden rounded-lg border border-slate-700/60 bg-slate-900 text-white shadow-[0_12px_40px_-12px_rgba(0,0,0,0.65)] ring-1 ring-white/[0.06]"
            role="status"
            aria-live="polite"
          >
            <div className="flex gap-2.5 p-2.5 sm:gap-3 sm:p-3">
              <div className="flex shrink-0 pt-0.5">
                <CheckCircleIcon className="h-5 w-5 text-emerald-400" strokeWidth={1.75} aria-hidden />
              </div>
              <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold tracking-tight text-white">Download ready</p>
                <p className="mt-0.5 truncate text-[13px] font-medium text-slate-100">{filename}</p>
                {metaLine && (
                  <p className="mt-0.5 truncate text-[11px] font-medium tracking-tight text-slate-400">{metaLine}</p>
                )}
                <div className="mt-2 flex flex-wrap items-center gap-1">
                  <button
                    type="button"
                    onClick={() => copyUrl(t.public_url)}
                    className="inline-flex h-7 items-center gap-1 rounded-md border border-slate-600 bg-slate-800 px-2 text-[11px] font-medium text-white transition hover:border-slate-500 hover:bg-slate-700"
                  >
                    <ClipboardDocumentIcon className="h-3.5 w-3.5 text-slate-300" aria-hidden />
                    Copy link
                  </button>
                  <a
                    href={t.public_url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex h-7 items-center gap-1 rounded-md border border-slate-600 bg-slate-800 px-2 text-[11px] font-medium text-white transition hover:border-slate-500 hover:bg-slate-700"
                  >
                    <ArrowDownTrayIcon className="h-3.5 w-3.5 text-slate-300" aria-hidden />
                    Download
                  </a>
                  <Link
                    href={route('downloads.index')}
                    className="inline-flex h-7 items-center gap-1 rounded-md border border-slate-600 bg-slate-800 px-2 text-[11px] font-medium text-white transition hover:border-slate-500 hover:bg-slate-700"
                  >
                    <ArrowTopRightOnSquareIcon className="h-3.5 w-3.5 text-slate-300" aria-hidden />
                    Open downloads
                  </Link>
                </div>
              </div>
              <button
                type="button"
                onClick={dismiss}
                className="shrink-0 self-start rounded-md p-1 text-slate-300 transition hover:bg-white/[0.08] hover:text-white"
                aria-label="Dismiss"
              >
                <XMarkIcon className="h-4 w-4" />
              </button>
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  )
}
