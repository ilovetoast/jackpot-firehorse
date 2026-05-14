import { useCallback, useEffect, useState } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import DownloadsTray from './DownloadsTray'
import { useDownloadsPanelOptional } from '../../contexts/DownloadsPanelContext'

function useViewportIsMinSm() {
  const [isSm, setIsSm] = useState(() =>
    typeof window !== 'undefined' ? window.matchMedia('(min-width: 640px)').matches : true
  )
  useEffect(() => {
    const mq = window.matchMedia('(min-width: 640px)')
    const apply = () => setIsSm(mq.matches)
    apply()
    mq.addEventListener('change', apply)
    return () => mq.removeEventListener('change', apply)
  }, [])
  return isSm
}

const TRAY_QUERY_BASE = {
  status: '',
  access: '',
  brand_id: '',
  user_id: '',
  sort: 'date_desc',
  page: 1,
}

export default function DownloadsSlideOver() {
  const panel = useDownloadsPanelOptional()
  const isDesktop = useViewportIsMinSm()
  const [payload, setPayload] = useState(null)
  const [loading, setLoading] = useState(false)
  const [trayScope, setTrayScope] = useState('mine')

  const fetchList = useCallback(async (query, opts = {}) => {
    const silent = opts.silent === true
    if (!silent) setLoading(true)
    try {
      const { data } = await window.axios.get(route('downloads.index'), {
        params: query,
        headers: { Accept: 'application/json' },
      })
      setPayload(data)
    } catch (e) {
      console.warn('[DownloadsSlideOver] Failed to load downloads', e)
    } finally {
      if (!silent) setLoading(false)
    }
  }, [])

  useEffect(() => {
    if (!panel?.open) {
      setPayload(null)
      setTrayScope('mine')
      return
    }
    void fetchList({ ...TRAY_QUERY_BASE, scope: trayScope })
  }, [panel?.open, panel?.highlightDownloadId, trayScope, fetchList])

  const refreshTray = useCallback(() => {
    if (!panel?.open) return
    void fetchList({ ...TRAY_QUERY_BASE, scope: trayScope }, { silent: true })
  }, [panel?.open, trayScope, fetchList])

  useEffect(() => {
    if (!payload) return
    const canAll = payload.can_manage === true || payload.can_view_all_brand_downloads === true
    if (!canAll && trayScope === 'all') {
      setTrayScope('mine')
    }
  }, [payload, trayScope])

  return (
    <AnimatePresence>
      {panel?.open ? (
        <motion.div
          key="downloads-tray-shell"
          className="fixed inset-0 z-[110] flex justify-end max-sm:items-end max-sm:justify-center"
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          transition={{ duration: 0.2, ease: [0.25, 0.1, 0.25, 1] }}
        >
          <motion.button
            type="button"
            className="absolute inset-0 bg-slate-950/70"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.18, ease: [0.25, 0.1, 0.25, 1] }}
            aria-label="Close downloads panel"
            onClick={panel.closePanel}
          />
          <motion.div
            role="dialog"
            aria-modal="true"
            aria-labelledby="downloads-slideover-title"
            className="relative z-[115] flex w-full max-w-full flex-col overflow-hidden border border-slate-200 bg-white shadow-lg max-sm:max-h-[min(92dvh,860px)] max-sm:rounded-t-xl max-sm:border-b-0 sm:h-full sm:max-w-[min(100vw-1rem,420px)] sm:rounded-l-xl sm:border-y sm:border-l sm:border-r-0"
            initial={isDesktop ? { x: '100%', opacity: 1 } : { y: '100%', opacity: 1 }}
            animate={isDesktop ? { x: 0, opacity: 1 } : { y: 0, opacity: 1 }}
            exit={isDesktop ? { x: '100%', opacity: 1 } : { y: '100%', opacity: 1 }}
            transition={{ duration: 0.28, ease: [0.25, 0.1, 0.25, 1] }}
          >
            <DownloadsTray
              downloads={payload?.downloads ?? []}
              loading={loading && !payload}
              pagination={payload?.pagination ?? null}
              canManage={payload?.can_manage === true}
              showScopeToggle={
                !!(payload && (payload.can_manage === true || payload.can_view_all_brand_downloads === true))
              }
              scope={trayScope}
              onScopeChange={setTrayScope}
              onRefresh={refreshTray}
              onClose={panel.closePanel}
              highlightDownloadId={panel.highlightDownloadId}
              onHighlightConsumed={panel.consumeHighlight}
            />
          </motion.div>
        </motion.div>
      ) : null}
    </AnimatePresence>
  )
}
