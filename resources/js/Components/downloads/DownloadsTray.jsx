/**
 * Enterprise-style downloads tray — glanceable rows, quick actions, scope toggle.
 * Full filters and management remain on the Downloads page.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, router, useForm, usePage } from '@inertiajs/react'
import {
  ArrowDownTrayIcon,
  ClipboardDocumentIcon,
  Cog6ToothIcon,
  EllipsisVerticalIcon,
  EnvelopeIcon,
  NoSymbolIcon,
  ShareIcon,
  XMarkIcon,
} from '@heroicons/react/24/outline'
import BrandAvatar from '../BrandAvatar'
import ConfirmDialog from '../ConfirmDialog'
import EditDownloadSettingsModal from '../EditDownloadSettingsModal'
import ShareEmailToField from './ShareEmailToField'
import { useDownloadErrors } from '../../hooks/useDownloadErrors'
import { useProcessingDownloadsPolling } from '../../hooks/useProcessingDownloadsPolling'
import { keyByDownloads, warnIfReplacingRootState } from '../../utils/downloadUtils'
import { formatBytesHuman } from '../../utils/formatBytesHuman'
import { emitSuppressDownloadReadyToast } from '../../utils/downloadReadyToastEvents'

const TRAY_VISIBLE_MAX = 8
const MODAL_Z = 'z-[220]'

function formatDateShort(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

function formatBytes(bytes) {
  if (bytes == null || bytes === 0) return '—'
  return formatBytesHuman(bytes)
}

function pickTrayDownloads(downloads) {
  const list = Array.isArray(downloads) ? [...downloads] : []
  const rank = (d) => {
    const s = d.state || 'processing'
    if (s === 'processing') return 0
    if (s === 'ready') return 1
    if (s === 'failed') return 2
    return 3
  }
  list.sort((a, b) => {
    const ra = rank(a)
    const rb = rank(b)
    if (ra !== rb) return ra - rb
    return 0
  })
  return list.slice(0, TRAY_VISIBLE_MAX)
}

function trayPhaseLabel(d) {
  const s = d.state || 'processing'
  if (s === 'ready') return { label: 'Ready', dot: 'bg-emerald-600', pulse: false }
  if (s === 'failed') return { label: 'Failed', dot: 'bg-red-600', pulse: false }
  if (s === 'expired') return { label: 'Expired', dot: 'bg-slate-400', pulse: false }
  if (s === 'revoked') return { label: 'Revoked', dot: 'bg-slate-400', pulse: false }
  const p = typeof d.zip_progress_percentage === 'number' ? d.zip_progress_percentage : null
  if (p == null || p <= 8) return { label: 'Preparing', dot: 'bg-[color:var(--primary)]', pulse: true }
  if (p < 88) return { label: 'Compressing', dot: 'bg-[color:var(--primary)]', pulse: true }
  return { label: 'Uploading', dot: 'bg-[color:var(--primary)]', pulse: true }
}

function accessSummary(mode, passwordProtected) {
  const m = (mode || 'public').toLowerCase()
  if (m === 'public') return passwordProtected ? 'Public · password' : 'Public'
  if (m === 'brand') return 'Brand'
  if (m === 'company' || m === 'team') return 'Company'
  return 'Restricted'
}

async function tryNavigatorShare(url, title) {
  if (!url || typeof navigator === 'undefined' || !navigator.share) return false
  try {
    await navigator.share({ title: title || 'Download', text: 'Download link', url })
    return true
  } catch (e) {
    if (e && e.name === 'AbortError') return true
    return false
  }
}

function TrayThumbMosaic({ download, className = '' }) {
  const thumbs = (download.thumbnails || []).filter((t) => t.thumbnail_url).slice(0, 4)
  const count = thumbs.length
  return (
    <div className={`relative overflow-hidden rounded-lg bg-slate-100 ring-1 ring-slate-200/80 ${className}`}>
      {count === 0 ? (
        <div
          className="absolute inset-0"
          style={{
            background: 'linear-gradient(145deg, var(--primary, #6366f1), #1e293b)',
          }}
        />
      ) : (
        <div
          className={`grid h-full w-full gap-px bg-slate-200 p-px ${
            count === 1 ? 'grid-cols-1' : count === 2 ? 'grid-cols-2' : 'grid-cols-2 grid-rows-2'
          }`}
        >
          {thumbs.map((t, idx) => (
            <div
              key={t.id || idx}
              className={`relative overflow-hidden bg-slate-100 ${count === 3 && idx === 0 ? 'row-span-2' : ''}`}
            >
              <img src={t.thumbnail_url} alt="" className="h-full w-full object-cover" />
            </div>
          ))}
        </div>
      )}
      <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center px-1 text-center">
        <span className="text-[9px] font-semibold uppercase tracking-wide text-white drop-shadow-sm">
          {download.asset_count ?? 0}
        </span>
        {download.zip_size_bytes > 0 && (
          <span className="mt-px text-[9px] font-medium text-white/95">{formatBytes(download.zip_size_bytes)}</span>
        )}
      </div>
    </div>
  )
}

function actionBtnClass(extra = '') {
  return `inline-flex h-7 items-center justify-center gap-1 rounded-md border border-slate-200/95 bg-white px-2 text-[11px] font-medium text-slate-700 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-slate-400/50 ${extra}`
}

function iconOnlyBtn(extra = '') {
  return `inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-slate-200/95 bg-white text-slate-600 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-slate-400/50 ${extra}`
}

function TrayDownloadRow({
  download: d,
  copiedId,
  onCopy,
  onShareSystem,
  onShareEmail,
  onOpenSettings,
  onOpenRegenerate,
  onOpenRevoke,
  onExtend,
  canManage,
  extendingId,
}) {
  const [moreOpen, setMoreOpen] = useState(false)
  const phase = trayPhaseLabel(d)
  const state = d.state || 'processing'
  const isReady = state === 'ready'
  const progress =
    typeof d.zip_progress_percentage === 'number' && !Number.isNaN(d.zip_progress_percentage)
      ? Math.min(100, Math.max(0, d.zip_progress_percentage))
      : null
  const canExtend = d.can_extend === true && (state === 'ready' || state === 'expired')
  const canRegenerate = d.can_regenerate === true
  const canRevoke = d.can_revoke === true && state !== 'revoked'

  const rawTitle = typeof d.title === 'string' ? d.title : typeof d.name === 'string' ? d.name : ''
  const displayTitle = rawTitle.trim() || (d.id != null ? `Download ${d.id}` : 'Download')

  const brandForAvatar = d.brand || (d.brands && d.brands[0]) || null

  return (
    <article className="rounded-lg border border-slate-200/90 bg-white px-2.5 py-1.5 shadow-sm transition-[box-shadow,border-color,background-color] duration-200 hover:border-slate-300/90 hover:bg-slate-50/50 hover:shadow-md sm:px-3">
      {/*
        Always stack body + actions vertically. The slide-over is ~420px wide while `sm:` uses
        viewport width — a horizontal row squeezed the title (`min-w-0`) to zero while the brand
        avatar stayed visible, which looked like a missing filename and a misplaced icon.
      */}
      <div className="flex flex-col gap-2">
        <div className="flex min-w-0 gap-2.5 sm:gap-3">
          <TrayThumbMosaic download={d} className="h-11 w-11 shrink-0 sm:h-[3.25rem] sm:w-[3.25rem]" />
          <div className="min-w-0 flex-1">
            <div className="flex items-start gap-2">
              <h3
                className="min-w-0 flex-1 truncate text-[13px] font-semibold leading-snug tracking-tight text-slate-900"
                title={displayTitle}
              >
                {displayTitle}
              </h3>
              {brandForAvatar && (
                <span className="shrink-0 translate-y-px">
                  <BrandAvatar
                    logoPath={brandForAvatar.logo_path}
                    name={brandForAvatar.name}
                    primaryColor={brandForAvatar.primary_color ?? '#6366f1'}
                    iconBgColor={brandForAvatar.icon_bg_color}
                    size="sm"
                    className="ring-1 ring-slate-200"
                  />
                </span>
              )}
            </div>
            <div className="mt-0.5 flex min-w-0 flex-nowrap items-center gap-2">
              <span className="inline-flex shrink-0 items-center gap-1 rounded border border-slate-200/80 bg-slate-50/90 px-1 py-px text-[9px] font-semibold uppercase tracking-wide text-slate-600">
                <span className={`h-1 w-1 rounded-full ${phase.dot} ${phase.pulse ? 'motion-safe:animate-pulse' : ''}`} aria-hidden />
                {phase.label}
              </span>
              <span className="min-w-0 shrink truncate text-[10px] leading-tight text-slate-500">
                {accessSummary(d.access_mode, d.password_protected)} · Exp. {formatDateShort(d.expires_at)}
              </span>
              {d.zip_time_estimate && state === 'processing' && (
                <span className="hidden shrink-0 text-[10px] text-slate-400 sm:inline">{d.zip_time_estimate}</span>
              )}
            </div>
            {state === 'processing' && (
              <div className="relative mt-1 h-0.5 overflow-hidden rounded-sm bg-slate-200 sm:mt-1.5">
                <div
                  className="absolute inset-y-0 left-0 rounded-sm bg-[color:var(--primary)] transition-[width] duration-500 ease-out"
                  style={{ width: `${progress != null ? progress : 6}%` }}
                />
                <div className="pointer-events-none absolute inset-0 motion-safe:animate-[shimmer_2.2s_ease-in-out_infinite] bg-gradient-to-r from-transparent via-white/40 to-transparent opacity-50" />
              </div>
            )}
          </div>
        </div>

        <div className="flex min-h-[2rem] flex-wrap items-center justify-end gap-1 border-t border-slate-100/90 pt-1.5">
          {isReady && d.public_url && (
            <>
              <button
                type="button"
                title="Copy link"
                onClick={() => onCopy(d.public_url, d.id)}
                className={actionBtnClass('min-h-[32px] sm:min-h-0')}
              >
                <ClipboardDocumentIcon className="h-3.5 w-3.5 text-slate-500" aria-hidden />
                <span className="hidden sm:inline">{copiedId === d.id ? 'Copied' : 'Copy'}</span>
              </button>
              <a
                href={d.public_url}
                target="_blank"
                rel="noopener noreferrer"
                title="Download"
                onClick={() => emitSuppressDownloadReadyToast(d.id)}
                className={actionBtnClass('min-h-[32px] sm:min-h-0')}
              >
                <ArrowDownTrayIcon className="h-3.5 w-3.5 text-slate-500" aria-hidden />
                <span className="hidden sm:inline">Download</span>
              </a>
              <button
                type="button"
                title="Share"
                onClick={() => onShareSystem(d.public_url, d.title, d.id)}
                className={iconOnlyBtn('min-h-[32px] min-w-[32px] sm:min-h-0')}
              >
                <ShareIcon className="h-4 w-4" aria-hidden />
              </button>
              <button
                type="button"
                title="Email link"
                onClick={() => onShareEmail(d)}
                className={iconOnlyBtn('min-h-[32px] min-w-[32px] sm:min-h-0')}
              >
                <EnvelopeIcon className="h-4 w-4" aria-hidden />
              </button>
            </>
          )}
          {!isReady && d.public_url && state === 'processing' && (
            <span className="px-1 text-[10px] text-slate-400">Link when ready</span>
          )}
          {(canManage || canRegenerate || canRevoke || canExtend) && (
            <button
              type="button"
              title="More actions"
              onClick={() => setMoreOpen((v) => !v)}
              className={`${iconOnlyBtn('min-h-[32px] min-w-[32px] sm:min-h-0')} ${moreOpen ? 'border-slate-400 bg-slate-50' : ''}`}
              aria-expanded={moreOpen}
            >
              <EllipsisVerticalIcon className="h-4 w-4" aria-hidden />
            </button>
          )}
        </div>
      </div>

      {moreOpen && (canManage || canRegenerate || canRevoke || canExtend) && (
        <div className="mt-1.5 border-t border-slate-100 pt-1.5">
          <div className="flex flex-wrap gap-1">
            {canManage && d.source !== 'single_asset' && (
              <button
                type="button"
                onClick={() => {
                  setMoreOpen(false)
                  onOpenSettings(d)
                }}
                className="inline-flex items-center gap-1 rounded-md px-2 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
              >
                <Cog6ToothIcon className="h-3.5 w-3.5 text-slate-500" aria-hidden />
                Access
              </button>
            )}
            {canExtend && (
              <button
                type="button"
                disabled={extendingId === d.id}
                onClick={() => {
                  setMoreOpen(false)
                  onExtend(d)
                }}
                className="rounded-md px-2 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
              >
                Extend
              </button>
            )}
            {canRegenerate && (
              <button
                type="button"
                onClick={() => {
                  setMoreOpen(false)
                  onOpenRegenerate(d.id)
                }}
                className="rounded-md px-2 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
              >
                Regenerate
              </button>
            )}
            {canRevoke && (
              <button
                type="button"
                onClick={() => {
                  setMoreOpen(false)
                  onOpenRevoke(d.id)
                }}
                className="inline-flex items-center gap-1 rounded-md px-2 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50"
              >
                <NoSymbolIcon className="h-3.5 w-3.5" aria-hidden />
                Revoke
              </button>
            )}
          </div>
        </div>
      )}
    </article>
  )
}

export default function DownloadsTray({
  downloads = [],
  loading = false,
  pagination = null,
  canManage = false,
  showScopeToggle = false,
  scope = 'mine',
  onScopeChange,
  onRefresh,
  onClose,
  highlightDownloadId = null,
  onHighlightConsumed = null,
}) {
  const { flash = {}, errors: pageErrors = {} } = usePage().props
  const features = usePage().props.download_features || {}
  const { bannerMessage: downloadActionError } = useDownloadErrors(['message'])

  const [downloadsById, setDownloadsByIdState] = useState(() => keyByDownloads(downloads))
  const propsKey = useMemo(() => (downloads || []).map((d) => d.id).join(','), [downloads])
  useEffect(() => {
    setDownloadsByIdState(() => keyByDownloads(downloads || []))
  }, [propsKey])

  const downloadIds = useMemo(() => (downloads || []).map((d) => d.id), [downloads])
  const setDownloadsById = useCallback((updater) => {
    if (typeof updater !== 'function') {
      warnIfReplacingRootState('downloadsTray downloadsById')
      return
    }
    setDownloadsByIdState(updater)
  }, [])

  useProcessingDownloadsPolling(downloadsById, setDownloadsById, downloadIds)

  const mergedList = useMemo(() => downloadIds.map((id) => downloadsById[id]).filter(Boolean), [downloadIds, downloadsById])
  const trayItems = useMemo(() => pickTrayDownloads(mergedList), [mergedList])
  const total = pagination?.total ?? mergedList.length
  const remainder = Math.max(0, total - trayItems.length)

  const [copiedId, setCopiedId] = useState(null)
  const copyLink = useCallback((url, id) => {
    if (!url) return
    const done = () => {
      setCopiedId(id)
      setTimeout(() => setCopiedId(null), 2000)
      emitSuppressDownloadReadyToast(id)
    }
    const fallback = () => {
      try {
        const input = document.createElement('textarea')
        input.value = url
        input.style.position = 'fixed'
        input.style.opacity = '0'
        document.body.appendChild(input)
        input.select()
        const ok = document.execCommand('copy')
        document.body.removeChild(input)
        if (ok) done()
      } catch (e) {
        console.warn('[DownloadsTray] copy failed', e)
      }
    }
    if (navigator.clipboard?.writeText) {
      navigator.clipboard.writeText(url).then(done).catch(fallback)
    } else {
      fallback()
    }
  }, [])

  const shareSystem = useCallback(
    async (url, title, id) => {
      const ok = await tryNavigatorShare(url, title)
      if (!ok) copyLink(url, id)
    },
    [copyLink]
  )

  const [shareEmailDownload, setShareEmailDownload] = useState(null)
  const shareEmailForm = useForm({ to: '', message: '' })

  const [settingsDownload, setSettingsDownload] = useState(null)
  const [revokeId, setRevokeId] = useState(null)
  const [regenerateId, setRegenerateId] = useState(null)
  const [revoking, setRevoking] = useState(false)
  const [regenerating, setRegenerating] = useState(false)
  const [extendingId, setExtendingId] = useState(null)

  const highlightRef = useRef(null)
  useEffect(() => {
    if (!highlightDownloadId) return
    const t = window.setTimeout(() => {
      highlightRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
      onHighlightConsumed?.()
    }, 120)
    return () => window.clearTimeout(t)
  }, [highlightDownloadId, onHighlightConsumed])

  const handleRevoke = useCallback(
    (d) => {
      setRevoking(true)
      router.post(
        route('downloads.revoke', d.id),
        {},
        {
          preserveScroll: true,
          onFinish: () => setRevoking(false),
          onSuccess: () => {
            setRevokeId(null)
            onRefresh?.()
          },
        }
      )
    },
    [onRefresh]
  )

  const handleRegenerate = useCallback(
    (d) => {
      setRegenerating(true)
      router.post(
        route('downloads.regenerate', d.id),
        {},
        {
          preserveScroll: true,
          onFinish: () => setRegenerating(false),
          onSuccess: () => {
            setRegenerateId(null)
            onRefresh?.()
          },
        }
      )
    },
    [onRefresh]
  )

  const handleExtend = useCallback(
    (d) => {
      const days = features.max_expiration_days ?? 30
      const date = new Date()
      date.setDate(date.getDate() + days)
      const expiresAt = date.toISOString().slice(0, 10)
      setExtendingId(d.id)
      router.post(
        route('downloads.extend', d.id),
        { expires_at: expiresAt },
        {
          preserveScroll: true,
          onFinish: () => setExtendingId(null),
          onSuccess: () => onRefresh?.(),
        }
      )
    },
    [features.max_expiration_days, onRefresh]
  )

  return (
    <>
      <style>{`
        @keyframes shimmer {
          0% { transform: translateX(-100%); }
          100% { transform: translateX(100%); }
        }
      `}</style>
      <div className="flex min-h-0 flex-1 flex-col bg-slate-50">
        <header className="sticky top-0 z-10 shrink-0 border-b border-slate-200 bg-white px-3 py-2.5 sm:px-4">
          <div className="flex items-center justify-between gap-2">
            <h2 id="downloads-slideover-title" className="text-sm font-semibold tracking-tight text-slate-900 sm:text-base">
              Downloads
            </h2>
            <div className="flex items-center gap-1 sm:gap-2">
              <Link
                href={route('downloads.index')}
                onClick={onClose}
                className="truncate text-xs font-medium text-[color:var(--primary)] hover:underline sm:text-sm"
              >
                View all downloads
              </Link>
              <button
                type="button"
                onClick={onClose}
                className="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-slate-400/50"
                aria-label="Close"
              >
                <XMarkIcon className="h-5 w-5" aria-hidden />
              </button>
            </div>
          </div>
          {showScopeToggle && (
            <div className="mt-2 inline-flex rounded-md border border-slate-200 bg-slate-100/80 p-0.5">
              <button
                type="button"
                onClick={() => onScopeChange?.('mine')}
                className={`rounded-md px-2.5 py-1 text-xs font-medium transition sm:px-3 ${
                  scope === 'mine' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                My Downloads
              </button>
              <button
                type="button"
                onClick={() => onScopeChange?.('all')}
                className={`rounded-md px-2.5 py-1 text-xs font-medium transition sm:px-3 ${
                  scope === 'all' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                All Downloads
              </button>
            </div>
          )}
          <p className="mt-1.5 text-[11px] leading-snug text-slate-500">Recent &amp; in progress — open the full page to filter and manage.</p>
        </header>

        <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-3 py-2 sm:px-4 sm:py-2.5">
          {loading && (
            <div className="space-y-2" aria-busy="true">
              {[0, 1, 2].map((i) => (
                <div key={i} className="animate-pulse rounded-lg border border-slate-200 bg-white px-3 py-2">
                  <div className="flex gap-3">
                    <div className="h-12 w-12 shrink-0 rounded-lg bg-slate-200" />
                    <div className="flex-1 space-y-1.5 py-0.5">
                      <div className="h-3.5 w-[70%] rounded bg-slate-200" />
                      <div className="h-2.5 w-[45%] rounded bg-slate-200/80" />
                      <div className="h-0.5 w-full rounded bg-slate-200/60" />
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}

          {!loading && trayItems.length === 0 && (
            <div className="py-10 text-center">
              <p className="text-sm font-medium text-slate-800">No downloads in this view</p>
              <p className="mt-1 text-xs leading-relaxed text-slate-500">
                Create a download from Assets or Collections — status and sharing tools appear here.
              </p>
            </div>
          )}

          {!loading && trayItems.length > 0 && (
            <ul className="space-y-2 pb-2">
              {trayItems.map((d) => (
                <li
                  key={d.id}
                  ref={highlightDownloadId === d.id ? highlightRef : undefined}
                  className={highlightDownloadId === d.id ? 'rounded-lg ring-1 ring-[color:var(--primary)]/40 ring-offset-1 ring-offset-slate-50' : undefined}
                >
                  <TrayDownloadRow
                    download={d}
                    copiedId={copiedId}
                    onCopy={copyLink}
                    onShareSystem={shareSystem}
                    onShareEmail={(row) => {
                      setShareEmailDownload(row)
                      shareEmailForm.reset()
                    }}
                    onOpenSettings={(row) => setSettingsDownload(row)}
                    onOpenRegenerate={(id) => setRegenerateId(id)}
                    onOpenRevoke={(id) => setRevokeId(id)}
                    onExtend={handleExtend}
                    canManage={canManage}
                    extendingId={extendingId}
                  />
                </li>
              ))}
            </ul>
          )}

          {!loading && remainder > 0 && (
            <p className="pb-2 text-center text-[11px] text-slate-500">
              +{remainder} more — <Link href={route('downloads.index')} onClick={onClose} className="font-medium text-[color:var(--primary)] hover:underline">view all downloads</Link>
            </p>
          )}
        </div>
      </div>

      <ConfirmDialog
        open={!!revokeId}
        onClose={() => setRevokeId(null)}
        zIndexClass={MODAL_Z}
        onConfirm={() => {
          const row = downloadsById[revokeId] || mergedList.find((x) => x.id === revokeId)
          if (row) handleRevoke(row)
        }}
        title="Revoke download"
        message="This will invalidate the link and remove the ZIP. This cannot be undone."
        confirmText="Revoke"
        cancelText="Cancel"
        variant="danger"
        loading={revoking}
        error={revokeId && flash?.download_action === 'revoke' ? downloadActionError : null}
      />

      <ConfirmDialog
        open={!!regenerateId}
        onClose={() => setRegenerateId(null)}
        zIndexClass={MODAL_Z}
        onConfirm={() => {
          const row = downloadsById[regenerateId] || mergedList.find((x) => x.id === regenerateId)
          if (row) handleRegenerate(row)
        }}
        title="Regenerate download"
        message="This will rebuild the ZIP. Existing links will be replaced."
        confirmText="Regenerate"
        cancelText="Cancel"
        variant="warning"
        loading={regenerating}
        error={regenerateId && flash?.download_action === 'regenerate' ? downloadActionError : null}
      />

      <EditDownloadSettingsModal
        open={!!settingsDownload}
        download={settingsDownload}
        onClose={() => setSettingsDownload(null)}
        overlayZClass={MODAL_Z}
        onSaved={() => {
          setSettingsDownload(null)
          onRefresh?.()
        }}
      />

      {shareEmailDownload && (
        <div className={`fixed inset-0 z-[220] flex items-center justify-center bg-slate-950/60 p-4`} aria-modal="true" role="dialog">
          <div className="max-h-[90dvh] w-full max-w-md overflow-y-auto rounded-lg border border-slate-200 bg-white p-5 shadow-xl">
            <h3 className="text-sm font-semibold text-slate-900">Email download link</h3>
            <p className="mt-0.5 text-xs text-slate-500">Send the link to a recipient.</p>
            <form
              onSubmit={(e) => {
                e.preventDefault()
                shareEmailForm.post(route('downloads.public.share-email', { download: shareEmailDownload.id }), {
                  preserveScroll: true,
                  onSuccess: () => {
                    setShareEmailDownload(null)
                    shareEmailForm.reset()
                  },
                })
              }}
              className="mt-3 space-y-3"
            >
              <ShareEmailToField
                id="tray-share-email-to"
                value={shareEmailForm.data.to}
                onChange={(v) => shareEmailForm.setData('to', v)}
                disabled={shareEmailForm.processing}
                error={shareEmailForm.errors?.to || pageErrors?.to || null}
              />
              <div>
                <label htmlFor="tray-share-email-message" className="block text-xs font-medium text-slate-700">
                  Optional message
                </label>
                <textarea
                  id="tray-share-email-message"
                  rows={3}
                  value={shareEmailForm.data.message}
                  onChange={(e) => shareEmailForm.setData('message', e.target.value)}
                  className="mt-1 block w-full rounded-md border border-slate-200 px-2.5 py-1.5 text-sm shadow-sm focus:border-[color:var(--primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--primary)]/30"
                  placeholder="Short note…"
                />
              </div>
              <div className="flex justify-end gap-2 pt-1">
                <button
                  type="button"
                  onClick={() => {
                    setShareEmailDownload(null)
                    shareEmailForm.reset()
                  }}
                  className="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={shareEmailForm.processing}
                  className="rounded-md bg-[color:var(--primary)] px-3 py-1.5 text-xs font-semibold text-white shadow-sm disabled:opacity-60"
                >
                  {shareEmailForm.processing ? 'Sending…' : 'Send'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </>
  )
}
