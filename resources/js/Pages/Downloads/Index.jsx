import { useState, useMemo, useEffect, useRef, useCallback } from 'react'
import { Link, router, usePage, useForm } from '@inertiajs/react'
import { useDownloadErrors } from '../../hooks/useDownloadErrors'
import { useProcessingDownloadsPolling } from '../../hooks/useProcessingDownloadsPolling'
import { keyByDownloads, warnIfReplacingRootState } from '../../utils/downloadUtils'
import AppNav from '../../Components/AppNav'
import AppHead from '../../Components/AppHead'
import AppFooter from '../../Components/AppFooter'
import ConfirmDialog from '../../Components/ConfirmDialog'
import {
  ArrowDownTrayIcon,
  CheckIcon,
  ChevronDownIcon,
  ChevronUpIcon,
  ClipboardDocumentIcon,
  EnvelopeIcon,
  NoSymbolIcon,
  Cog6ToothIcon,
  MagnifyingGlassIcon,
  Squares2X2Icon,
  UserCircleIcon,
  ClockIcon,
  LockOpenIcon,
  LockClosedIcon,
} from '@heroicons/react/24/outline'
import Avatar from '../../Components/Avatar'
import BrandAvatar from '../../Components/BrandAvatar'
import EditDownloadSettingsModal from '../../Components/EditDownloadSettingsModal'

function formatDate(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

function formatBytes(bytes) {
  if (bytes == null || bytes === 0) return '—'
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

// Phase D4: Badge label and tooltip from backend state (processing|ready|expired|revoked|failed)
function stateBadge(state, isPossiblyStuck = false) {
  const map = {
    processing: {
      label: isPossiblyStuck ? 'Stuck?' : 'Preparing',
      className: isPossiblyStuck ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800',
      title: isPossiblyStuck ? 'This may have failed. Use Regenerate to retry.' : "We're building your download.",
    },
    ready: { label: 'Ready', className: 'bg-green-100 text-green-800', title: 'Available to download.' },
    expired: { label: 'Expired', className: 'bg-gray-100 text-gray-600', title: 'This download has expired.' },
    revoked: { label: 'Revoked', className: 'bg-red-100 text-red-800', title: 'This download was revoked.' },
    failed: { label: 'Failed', className: 'bg-red-100 text-red-800', title: "Something went wrong while preparing this download." },
  }
  return map[state] || { label: 'Pending', className: 'bg-gray-100 text-gray-700', title: '' }
}

function getStateBadge(d) {
  const state = d.state || 'processing'
  const isPossiblyStuck = d.is_possibly_stuck === true && state === 'processing'
  return stateBadge(state, isPossiblyStuck)
}

// Access mode badge: public | brand | company | specific users. When public + password, label clarifies password is required.
function accessBadge(accessMode, passwordProtected = false) {
  const map = {
    public: {
      label: passwordProtected ? 'Public (password required)' : 'Public',
      className: 'bg-sky-100 text-sky-800',
      title: passwordProtected ? 'Anyone with the link can open the page; a password is required to download.' : 'Anyone with the link can download.',
    },
    brand: { label: 'Brand', className: 'bg-violet-100 text-violet-800', title: 'Only brand members can access.' },
    company: { label: 'Company', className: 'bg-indigo-100 text-indigo-800', title: 'Only company members can access.' },
    team: { label: 'Company', className: 'bg-indigo-100 text-indigo-800', title: 'Only company members can access.' },
    users: { label: 'Specific users', className: 'bg-slate-100 text-slate-700', title: 'Only selected users can access.' },
    restricted: { label: 'Specific users', className: 'bg-slate-100 text-slate-700', title: 'Only selected users can access.' },
  }
  return map[accessMode] || { label: 'Restricted', className: 'bg-slate-100 text-slate-600', title: 'Access is restricted.' }
}

// Error keys that indicate a download mutation error (revoke/regenerate/settings); used to auto-open the correct dialog.
const DOWNLOAD_ACTION_ERROR_KEYS = ['message']

export default function DownloadsIndex({
  downloads = [],
  bucket_count: bucketCount = 0,
  can_manage: canManage = false,
  filters: initialFilters = {},
  download_features: features = {},
  pagination: paginationMeta = null,
  download_users: downloadUsers = [],
  download_brands: downloadBrands = [],
}) {
  const { auth, flash = {}, errors: pageErrors = {} } = usePage().props
  const { bannerMessage: downloadActionError } = useDownloadErrors(['message'])

  const [expandedId, setExpandedId] = useState(null)
  const [copiedId, setCopiedId] = useState(null)
  const [revokeConfirmId, setRevokeConfirmId] = useState(null)
  const [regenerateConfirmId, setRegenerateConfirmId] = useState(null)
  const [settingsModalDownload, setSettingsModalDownload] = useState(null)
  const [shareEmailDownload, setShareEmailDownload] = useState(null)
  const [settingsExpandedId, setSettingsExpandedId] = useState(null)
  const [revoking, setRevoking] = useState(false)
  const [regenerating, setRegenerating] = useState(false)
  const [extendingId, setExtendingId] = useState(null)
  const [userDropdownOpen, setUserDropdownOpen] = useState(false)
  const userDropdownRef = useRef(null)
  const [brandDropdownOpen, setBrandDropdownOpen] = useState(false)
  const brandDropdownRef = useRef(null)

  // Normalized state: patch-based polling only updates downloadsById; downloadIds stay stable from props
  const [downloadsById, setDownloadsByIdState] = useState(() => keyByDownloads(downloads || []))
  const propsDownloadKey = useMemo(
    () => (downloads || []).map((d) => d.id).join(','),
    [downloads]
  )
  useEffect(() => {
    setDownloadsByIdState(() => keyByDownloads(downloads || []))
  }, [propsDownloadKey])
  const downloadIds = useMemo(() => (downloads || []).map((d) => d.id), [downloads])
  const setDownloadsById = useCallback((updater) => {
    if (typeof updater !== 'function') {
      warnIfReplacingRootState('downloadsById (non-function setter)')
    }
    setDownloadsByIdState(updater)
  }, [])
  const downloadsList = useMemo(
    () => downloadIds.map((id) => downloadsById[id]).filter(Boolean),
    [downloadIds, downloadsById]
  )
  useProcessingDownloadsPolling(downloadsById, setDownloadsById, downloadIds)

  const shareEmailForm = useForm({ to: '', message: '' })
  const [filters, setFilters] = useState({
    scope: initialFilters.scope ?? 'mine',
    status: initialFilters.status ?? '',
    access: initialFilters.access ?? '',
    brand_id: initialFilters.brand_id ?? '',
    user_id: initialFilters.user_id ?? '',
    sort: initialFilters.sort ?? 'date_desc',
  })
  const [searchQuery, setSearchQuery] = useState('')

  useEffect(() => {
    setFilters((prev) => ({
      ...prev,
      scope: initialFilters.scope ?? prev.scope,
      status: initialFilters.status ?? prev.status,
      access: initialFilters.access ?? prev.access,
      brand_id: initialFilters.brand_id ?? prev.brand_id,
      user_id: initialFilters.user_id ?? prev.user_id,
      sort: initialFilters.sort ?? prev.sort,
    }))
  }, [initialFilters.scope, initialFilters.status, initialFilters.access, initialFilters.brand_id, initialFilters.user_id, initialFilters.sort])

  // Close user dropdown when clicking outside
  useEffect(() => {
    const handleClickOutside = (e) => {
      if (userDropdownRef.current && !userDropdownRef.current.contains(e.target)) {
        setUserDropdownOpen(false)
      }
    }
    if (userDropdownOpen) {
      document.addEventListener('mousedown', handleClickOutside)
      return () => document.removeEventListener('mousedown', handleClickOutside)
    }
  }, [userDropdownOpen])

  // Close brand dropdown when clicking outside
  useEffect(() => {
    const handleClickOutside = (e) => {
      if (brandDropdownRef.current && !brandDropdownRef.current.contains(e.target)) {
        setBrandDropdownOpen(false)
      }
    }
    if (brandDropdownOpen) {
      document.addEventListener('mousedown', handleClickOutside)
      return () => document.removeEventListener('mousedown', handleClickOutside)
    }
  }, [brandDropdownOpen])

  // Polling is handled by useProcessingDownloadsPolling (patch-only, no Inertia). Never router.reload here.

  const applyFilters = (next, page = 1) => {
    setFilters(next)
    router.get(route('downloads.index'), { scope: next.scope, status: next.status, access: next.access, brand_id: next.brand_id, user_id: next.user_id, sort: next.sort, page }, { preserveState: true })
  }
  const goToPage = (page) => {
    router.get(route('downloads.index'), { scope: filters.scope, status: filters.status, access: filters.access, brand_id: filters.brand_id, user_id: filters.user_id, sort: filters.sort, page }, { preserveState: true })
  }

  const brandAccent = auth?.activeBrand?.primary_color || '#6366f1'

  const copyLink = (url, id) => {
    if (!url) return
    const onSuccess = () => {
      setCopiedId(id)
      setTimeout(() => setCopiedId(null), 2000)
    }
    const fallback = () => {
      try {
        const input = document.createElement('textarea')
        input.value = url
        input.style.position = 'fixed'
        input.style.opacity = '0'
        document.body.appendChild(input)
        input.select()
        input.setSelectionRange(0, url.length)
        const ok = document.execCommand('copy')
        document.body.removeChild(input)
        if (ok) onSuccess()
      } catch (e) {
        console.warn('[Downloads] Copy failed', e)
      }
    }
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      navigator.clipboard.writeText(url).then(onSuccess).catch(fallback)
    } else {
      fallback()
    }
  }

  const handleRevoke = (d) => {
    setRevoking(true)
    router.post(route('downloads.revoke', d.id), {}, {
      preserveScroll: true,
      onFinish: () => setRevoking(false),
      onSuccess: () => setRevokeConfirmId(null),
    })
  }

  const handleRegenerate = (d) => {
    setRegenerating(true)
    router.post(route('downloads.regenerate', d.id), {}, {
      preserveScroll: true,
      onFinish: () => setRegenerating(false),
      onSuccess: () => setRegenerateConfirmId(null),
    })
  }

  const handleExtend = (d) => {
    const days = features.max_expiration_days ?? 30
    const date = new Date()
    date.setDate(date.getDate() + days)
    const expiresAt = date.toISOString().slice(0, 10)
    setExtendingId(d.id)
    router.post(route('downloads.extend', d.id), { expires_at: expiresAt }, {
      preserveScroll: true,
      onFinish: () => setExtendingId(null),
    })
  }

  const zipDownloads = useMemo(
    () => (downloadsList || []).filter((d) => d.source !== 'single_asset'),
    [downloadsList]
  )
  const singleAssetDownloads = useMemo(
    () => (downloadsList || []).filter((d) => d.source === 'single_asset'),
    [downloadsList]
  )
  const filteredZipDownloads = useMemo(() => {
    if (!searchQuery.trim()) return zipDownloads
    const q = searchQuery.trim().toLowerCase()
    return zipDownloads.filter((d) => {
      const title = (d.title || '').toLowerCase()
      const exp = formatDate(d.expires_at).toLowerCase()
      const count = String(d.asset_count || '')
      const size = formatBytes(d.zip_size_bytes).toLowerCase()
      const badgeLabel = (stateBadge(d.state || '').label || '').toLowerCase()
      const assetNames = (d.thumbnails || []).map((t) => (t.original_filename || '').toLowerCase()).join(' ')
      return (
        title.includes(q) ||
        exp.includes(q) ||
        count.includes(q) ||
        size.includes(q) ||
        badgeLabel.includes(q) ||
        assetNames.includes(q)
      )
    })
  }, [zipDownloads, searchQuery])

  // Ids of downloads currently visible in the list (ZIP cards + single-asset rows).
  const visibleDownloadIds = useMemo(
    () => new Set([
      ...filteredZipDownloads.map((d) => d.id),
      ...singleAssetDownloads.map((d) => d.id),
    ]),
    [filteredZipDownloads, singleAssetDownloads]
  )

  // Auto-open the correct dialog on redirect errors (mirror DownloadBucketBar create-panel behavior).
  // Only open when the relevant download row is visible; never open multiple dialogs.
  const hasDownloadActionError = DOWNLOAD_ACTION_ERROR_KEYS.some(
    (key) =>
      pageErrors[key] !== undefined &&
      (typeof pageErrors[key] === 'string' || Array.isArray(pageErrors[key]))
  )
  useEffect(() => {
    const action = flash?.download_action
    const id = flash?.download_action_id
    if (!action || !id || !hasDownloadActionError) return
    if (!visibleDownloadIds.has(id)) return
    if (action === 'revoke') setRevokeConfirmId(id)
    else if (action === 'regenerate') setRegenerateConfirmId(id)
    else if (action === 'settings') {
      const d = downloadsById[id] || downloadsList.find((x) => x.id === id)
      setSettingsModalDownload(d || { id })
    }
  }, [flash?.download_action, flash?.download_action_id, hasDownloadActionError, visibleDownloadIds, downloadsById, downloadsList])

  return (
    <div className="min-h-screen flex flex-col bg-slate-50">
      <AppHead title="Downloads" />
      <AppNav />
      <main className="flex-1 py-6 px-4 sm:px-6 lg:px-8">
        <div className="mx-auto max-w-7xl">
          {/* Tabs: My Downloads (default) | All Downloads */}
          <div className="mb-6">
            <h1 className="text-2xl font-bold text-slate-900">Downloads</h1>
            <p className="mt-1 text-sm text-slate-500">
              Download links expire after 30 days. Anyone with the link can download (unless restricted).
            </p>
            <p className="mt-0.5 text-xs text-slate-400">
              Downloads expire automatically to protect storage and access.
            </p>
            <nav className="mt-4 flex border-b border-slate-200" aria-label="Download scope">
              <button
                type="button"
                onClick={() => applyFilters({ ...filters, scope: 'mine', user_id: '' })}
                className={`border-b-2 px-4 py-3 text-sm font-medium transition-colors -mb-px ${
                  filters.scope === 'mine'
                    ? 'border-current text-slate-900'
                    : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'
                }`}
                style={filters.scope === 'mine' ? { borderColor: brandAccent, color: brandAccent } : {}}
              >
                My Downloads
              </button>
              {canManage && (
                <button
                  type="button"
                  onClick={() => applyFilters({ ...filters, scope: 'all' })}
                  className={`border-b-2 px-4 py-3 text-sm font-medium transition-colors -mb-px ${
                    filters.scope === 'all'
                      ? 'border-current text-slate-900'
                      : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'
                  }`}
                  style={filters.scope === 'all' ? { borderColor: brandAccent, color: brandAccent } : {}}
                >
                  All Downloads
                </button>
              )}
            </nav>
          </div>

          {/* Filters: full line(s) inside tab context */}
          <div className="space-y-4 mb-6">
            {canManage && (
              <div className="flex flex-wrap items-center gap-6">
                <div className="flex items-center gap-3">
                  <span className="text-sm font-medium text-slate-600">Status</span>
                  <div className="inline-flex rounded-full p-0.5 bg-slate-200/80" role="group" aria-label="Status">
                    {['', 'active', 'expired', 'revoked'].map((val) => {
                      const label = val === '' ? 'All' : val === 'active' ? 'Active' : val === 'expired' ? 'Expired' : 'Revoked'
                      const active = filters.status === val
                      return (
                        <button
                          key={val || 'all'}
                          type="button"
                          onClick={() => applyFilters({ ...filters, status: val })}
                          className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium transition-colors ${active ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}
                          style={active ? { color: brandAccent } : {}}
                        >
                          <ClockIcon className="h-4 w-4" />
                          {label}
                        </button>
                      )
                    })}
                  </div>
                </div>
                <div className="flex items-center gap-3">
                  <span className="text-sm font-medium text-slate-600">Access</span>
                  <div className="inline-flex rounded-full p-0.5 bg-slate-200/80" role="group" aria-label="Access">
                    <button
                      type="button"
                      onClick={() => applyFilters({ ...filters, access: '' })}
                      className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium transition-colors ${filters.access === '' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}
                      style={filters.access === '' ? { color: brandAccent } : {}}
                    >
                      <LockOpenIcon className="h-4 w-4" />
                      All
                    </button>
                    <button
                      type="button"
                      onClick={() => applyFilters({ ...filters, access: 'public' })}
                      className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium transition-colors ${filters.access === 'public' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}
                      style={filters.access === 'public' ? { color: brandAccent } : {}}
                    >
                      Public
                    </button>
                    <button
                      type="button"
                      onClick={() => applyFilters({ ...filters, access: 'restricted' })}
                      className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium transition-colors ${filters.access === 'restricted' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}
                      style={filters.access === 'restricted' ? { color: brandAccent } : {}}
                    >
                      Restricted
                    </button>
                  </div>
                </div>
                {(downloadBrands || []).length > 0 && (
                  <div className="flex items-center gap-2" ref={brandDropdownRef}>
                    <span className="text-sm font-medium text-slate-600">Brand</span>
                    <div className="relative">
                      <button
                        type="button"
                        onClick={() => setBrandDropdownOpen((o) => !o)}
                        className="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white pl-2 pr-3 py-1.5 text-sm text-slate-900 shadow-sm hover:border-slate-300 focus:border-slate-300 focus:outline-none focus:ring-2 focus:ring-slate-400/20 min-w-[160px]"
                        aria-haspopup="listbox"
                        aria-expanded={brandDropdownOpen}
                        id="downloads-brand-filter"
                      >
                        {(() => {
                          const selected = (downloadBrands || []).find((b) => String(b.id) === String(filters.brand_id))
                          if (selected) {
                            return (
                              <>
                                <BrandAvatar
                                  logoPath={selected.logo_path}
                                  iconPath={selected.icon_path}
                                  name={selected.name}
                                  primaryColor={selected.primary_color || '#6366f1'}
                                  icon={selected.icon}
                                  iconBgColor={selected.icon_bg_color}
                                  showIcon={!!(selected.icon || selected.icon_path)}
                                  size="sm"
                                  className="bg-slate-200 text-slate-600 shrink-0"
                                />
                                <span className="max-w-[140px] truncate">{selected.name}</span>
                              </>
                            )
                          }
                          return (
                            <>
                              <Squares2X2Icon className="h-6 w-6 text-slate-400 flex-shrink-0" aria-hidden />
                              <span>All brands</span>
                            </>
                          )
                        })()}
                        {brandDropdownOpen ? (
                          <ChevronUpIcon className="h-4 w-4 text-slate-400 flex-shrink-0 ml-auto" aria-hidden />
                        ) : (
                          <ChevronDownIcon className="h-4 w-4 text-slate-400 flex-shrink-0 ml-auto" aria-hidden />
                        )}
                      </button>
                      {brandDropdownOpen && (
                        <div
                          className="absolute z-20 mt-1 min-w-[200px] rounded-lg border border-slate-200 bg-white py-1 shadow-lg ring-1 ring-black/5"
                          role="listbox"
                        >
                          <button
                            type="button"
                            role="option"
                            aria-selected={!filters.brand_id}
                            onClick={() => {
                              applyFilters({ ...filters, brand_id: '' })
                              setBrandDropdownOpen(false)
                            }}
                            className={`flex w-full items-center gap-2 px-3 py-2 text-left text-sm ${!filters.brand_id ? 'bg-slate-100 text-slate-900' : 'text-slate-700 hover:bg-slate-50'}`}
                          >
                            <span className="w-5 flex-shrink-0 flex justify-center">
                              {!filters.brand_id && <CheckIcon className="h-4 w-4" style={{ color: brandAccent }} />}
                            </span>
                            <Squares2X2Icon className="h-5 w-5 text-slate-400 flex-shrink-0" aria-hidden />
                            <span>All brands</span>
                          </button>
                          {(downloadBrands || []).map((b) => {
                            const isSelected = String(b.id) === String(filters.brand_id)
                            return (
                              <button
                                key={b.id}
                                type="button"
                                role="option"
                                aria-selected={isSelected}
                                onClick={() => {
                                  applyFilters({ ...filters, brand_id: String(b.id) })
                                  setBrandDropdownOpen(false)
                                }}
                                className={`flex w-full items-center gap-2 px-3 py-2 text-left text-sm ${isSelected ? 'bg-slate-100 text-slate-900' : 'text-slate-700 hover:bg-slate-50'}`}
                              >
                                <span className="w-5 flex-shrink-0 flex justify-center">
                                  {isSelected && <CheckIcon className="h-4 w-4" style={{ color: brandAccent }} />}
                                </span>
                                <BrandAvatar
                                  logoPath={b.logo_path}
                                  iconPath={b.icon_path}
                                  name={b.name}
                                  primaryColor={b.primary_color || '#6366f1'}
                                  icon={b.icon}
                                  iconBgColor={b.icon_bg_color}
                                  showIcon={!!(b.icon || b.icon_path)}
                                  size="sm"
                                  className="bg-slate-200 text-slate-600 shrink-0"
                                />
                                <span className="truncate">{b.name}</span>
                              </button>
                            )
                          })}
                        </div>
                      )}
                    </div>
                  </div>
                )}
                {filters.scope === 'all' && (
                  <div className="flex items-center gap-2" ref={userDropdownRef}>
                    <span className="text-sm font-medium text-slate-600">User</span>
                    <div className="relative">
                      <button
                        type="button"
                        onClick={() => setUserDropdownOpen((o) => !o)}
                        className="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white pl-2 pr-3 py-1.5 text-sm text-slate-900 shadow-sm hover:border-slate-300 focus:border-slate-300 focus:outline-none focus:ring-2 focus:ring-slate-400/20"
                        aria-haspopup="listbox"
                        aria-expanded={userDropdownOpen}
                        id="downloads-user-filter"
                      >
                        {(() => {
                          const selected = (downloadUsers || []).find((u) => String(u.id) === String(filters.user_id))
                          if (selected) {
                            return (
                              <>
                                <Avatar
                                  avatarUrl={selected.avatar_url}
                                  firstName={selected.first_name}
                                  lastName={selected.last_name}
                                  email={selected.email}
                                  size="sm"
                                  className="bg-slate-200 text-slate-600"
                                />
                                <span className="max-w-[140px] truncate">{selected.name || selected.email || `User ${selected.id}`}</span>
                              </>
                            )
                          }
                          return (
                            <>
                              <UserCircleIcon className="h-6 w-6 text-slate-400 flex-shrink-0" />
                              <span>All users</span>
                            </>
                          )
                        })()}
                        {userDropdownOpen ? (
                          <ChevronUpIcon className="h-4 w-4 text-slate-400 flex-shrink-0" />
                        ) : (
                          <ChevronDownIcon className="h-4 w-4 text-slate-400 flex-shrink-0" />
                        )}
                      </button>
                      {userDropdownOpen && (
                        <div
                          className="absolute z-20 mt-1 min-w-[200px] rounded-lg border border-slate-200 bg-white py-1 shadow-lg ring-1 ring-black/5"
                          role="listbox"
                        >
                          <button
                            type="button"
                            role="option"
                            aria-selected={!filters.user_id}
                            onClick={() => {
                              applyFilters({ ...filters, user_id: '' })
                              setUserDropdownOpen(false)
                            }}
                            className={`flex w-full items-center gap-2 px-3 py-2 text-left text-sm ${!filters.user_id ? 'bg-slate-100 text-slate-900' : 'text-slate-700 hover:bg-slate-50'}`}
                          >
                            <span className="w-5 flex-shrink-0 flex justify-center">
                              {!filters.user_id && <CheckIcon className="h-4 w-4" style={{ color: brandAccent }} />}
                            </span>
                            <UserCircleIcon className="h-5 w-5 text-slate-400 flex-shrink-0" />
                            <span>All users</span>
                          </button>
                          {(downloadUsers || []).map((u) => {
                            const isSelected = String(u.id) === String(filters.user_id)
                            return (
                              <button
                                key={u.id}
                                type="button"
                                role="option"
                                aria-selected={isSelected}
                                onClick={() => {
                                  applyFilters({ ...filters, user_id: String(u.id) })
                                  setUserDropdownOpen(false)
                                }}
                                className={`flex w-full items-center gap-2 px-3 py-2 text-left text-sm ${isSelected ? 'bg-slate-100 text-slate-900' : 'text-slate-700 hover:bg-slate-50'}`}
                              >
                                <span className="w-5 flex-shrink-0 flex justify-center">
                                  {isSelected && <CheckIcon className="h-4 w-4" style={{ color: brandAccent }} />}
                                </span>
                                <Avatar
                                  avatarUrl={u.avatar_url}
                                  firstName={u.first_name}
                                  lastName={u.last_name}
                                  email={u.email}
                                  size="sm"
                                  className="bg-slate-200 text-slate-600"
                                />
                                <span className="truncate">{u.name || u.email || `User ${u.id}`}</span>
                              </button>
                            )
                          })}
                        </div>
                      )}
                    </div>
                  </div>
                )}
                <div className="flex items-center gap-2">
                  <label htmlFor="downloads-sort" className="text-sm font-medium text-slate-600">Sort</label>
                  <select
                    id="downloads-sort"
                    value={filters.sort}
                    onChange={(e) => applyFilters({ ...filters, sort: e.target.value })}
                    className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-900 focus:border-slate-300 focus:outline-none focus:ring-2 focus:ring-slate-400/20"
                  >
                    <option value="date_desc">Newest first</option>
                    <option value="date_asc">Oldest first</option>
                    <option value="size_desc">Size (high–low)</option>
                    <option value="size_asc">Size (low–high)</option>
                  </select>
                </div>
              </div>
            )}
            {!canManage && (
              <div className="flex items-center gap-2">
                <label htmlFor="downloads-sort-own" className="text-sm font-medium text-slate-600">Sort</label>
                <select
                  id="downloads-sort-own"
                  value={filters.sort}
                  onChange={(e) => applyFilters({ ...filters, sort: e.target.value })}
                  className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-900 focus:border-slate-300 focus:outline-none focus:ring-2 focus:ring-slate-400/20"
                >
                  <option value="date_desc">Newest first</option>
                  <option value="date_asc">Oldest first</option>
                  <option value="size_desc">Size (high–low)</option>
                  <option value="size_asc">Size (low–high)</option>
                </select>
              </div>
            )}
            <div className="w-full max-w-md">
              <div className="relative">
                <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400 z-0" aria-hidden />
                <input
                  type="search"
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  placeholder="Search by name, assets, date, size…"
                  className="relative z-10 w-full rounded-lg border border-slate-200 bg-white py-2 pl-10 pr-3 text-sm text-slate-900 placeholder-slate-400 focus:border-slate-300 focus:outline-none focus:ring-2 focus:ring-slate-400/20 focus:ring-offset-0"
                  aria-label="Search downloads"
                />
              </div>
            </div>
          </div>

          {bucketCount > 0 && (
            <div className="mt-4 p-4 rounded-xl bg-white border border-slate-200 shadow-sm">
              <p className="text-sm text-slate-700">
                You have {bucketCount} item{bucketCount !== 1 ? 's' : ''} in your download bucket.{' '}
                <Link href="/app/assets" className="font-medium underline hover:no-underline" style={{ color: brandAccent }}>
                  Go to Assets
                </Link>{' '}
                to create a download.
              </p>
            </div>
          )}

          {/* ZIP / collection downloads — card-based layout */}
          <div className="mt-6 space-y-5">
            {zipDownloads.length === 0 && (downloadsList || []).length === 0 ? (
              <div className="rounded-xl border border-slate-200 bg-white p-10 text-center shadow-sm">
                <ArrowDownTrayIcon className="mx-auto h-12 w-12 text-slate-400" />
                <h2 className="mt-3 text-lg font-semibold text-slate-900">No downloads yet</h2>
                <p className="mt-1 text-sm text-slate-500">
                  Select assets on the Assets or Collections page, then use &quot;Create Download&quot; to generate a ZIP.
                </p>
                <div className="mt-5">
                  <Link
                    href="/app/assets"
                    className="inline-flex items-center rounded-lg px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:opacity-95"
                    style={{ backgroundColor: brandAccent }}
                  >
                    Go to Assets
                  </Link>
                </div>
              </div>
            ) : filteredZipDownloads.length === 0 && singleAssetDownloads.length === 0 ? null : (
              <>
                {filteredZipDownloads.map((d) => {
                const state = d.state || 'processing'
                const badge = getStateBadge(d)
                const isOpen = expandedId === d.id
                const canRevoke = (d.can_revoke === true) && state !== 'revoked'
                const canRegenerate = d.can_regenerate === true
                const canExtend = d.can_extend === true
                const isReady = state === 'ready'
                const isProcessing = state === 'processing'
                const isExpired = state === 'expired'
                const isRevoked = state === 'revoked'
                const isFailed = state === 'failed'

                const thumbnails = d.thumbnails || []
                const thumbsWithUrl = thumbnails.filter((t) => t.thumbnail_url).slice(0, 4)
                const thumbCount = thumbsWithUrl.length
                const displayTitle = d.title || `Download ${d.id}`

                return (
                  <div
                    key={d.id}
                    className="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden flex flex-wrap"
                  >
                    {/* Left: 1–4 thumbnails in a grid (1 fill, 2 split, 3 split, 4 in 2x2) */}
                    <div
                      className={`relative w-48 h-48 flex-shrink-0 overflow-hidden bg-slate-100 ${
                        thumbCount === 1
                          ? ''
                          : thumbCount === 2
                            ? 'grid grid-cols-2'
                            : thumbCount === 3
                              ? 'grid grid-cols-2 grid-rows-2'
                              : thumbCount === 4
                                ? 'grid grid-cols-2 grid-rows-2'
                                : ''
                      }`}
                    >
                      {thumbCount === 0 ? (
                        <div
                          className="absolute inset-0"
                          style={{ background: `linear-gradient(135deg, ${brandAccent}, ${brandAccent}cc)` }}
                        />
                      ) : (
                        thumbsWithUrl.map((t, idx) => (
                          <div
                            key={t.id || idx}
                            className={`relative overflow-hidden ${
                              thumbCount === 1 ? 'absolute inset-0 w-full h-full' : ''
                            } ${thumbCount === 3 && idx === 0 ? 'row-span-2' : ''}`}
                          >
                            <img
                              src={t.thumbnail_url}
                              alt=""
                              className={`block w-full h-full object-cover ${thumbCount === 1 ? 'object-center absolute inset-0' : ''}`}
                            />
                            <div
                              className="absolute inset-0"
                              style={{
                                background: `linear-gradient(135deg, ${brandAccent}99 0%, ${brandAccent}cc 50%, ${brandAccent}cc 100%)`,
                              }}
                              aria-hidden
                            />
                          </div>
                        ))
                      )}
                      <div className="absolute inset-0 flex flex-col items-center justify-center p-2 text-center pointer-events-none">
                        <span className="text-base font-semibold text-white drop-shadow-md leading-tight">
                          {d.asset_count} file{d.asset_count !== 1 ? 's' : ''}
                        </span>
                        {d.zip_size_bytes != null && d.zip_size_bytes > 0 && (
                          <span className="text-sm font-medium text-white/95 drop-shadow-md mt-0.5">
                            {formatBytes(d.zip_size_bytes)}
                          </span>
                        )}
                      </div>
                    </div>

                    <div className="flex-1 min-w-0 p-4 flex flex-wrap items-center justify-between gap-3">
                      <div className="flex flex-col gap-1 min-w-0">
                        <div className="flex items-center gap-2 flex-wrap">
                          <span
                            className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ${badge.className}`}
                            title={badge.title}
                          >
                            {badge.label}
                          </span>
                          {(() => {
                            const access = accessBadge(d.access_mode || 'public', d.password_protected)
                            return (
                              <span
                                className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ${access.className}`}
                                title={access.title}
                              >
                                {access.label}
                              </span>
                            )
                          })()}
                          {d.password_protected && (
                            <span
                              className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-800"
                              title="Recipients must enter a password to access this download."
                            >
                              <LockClosedIcon className="h-3.5 w-3.5 shrink-0" aria-hidden />
                              Password
                            </span>
                          )}
                          {((d.brands && d.brands.length > 1) ? d.brands : (d.brand ? [d.brand] : [])).length > 0 && (
                            <span
                              className="inline-flex items-center gap-1 rounded-full bg-slate-100 pl-1 pr-2 py-1 text-xs font-medium text-slate-700"
                              title={(d.brands && d.brands.length > 1) ? `${d.brands.length} brands` : `${d.brand?.name || d.brand?.slug || 'Brand'} — Landing page uses this brand's logo, colors, and background.`}
                            >
                              {(d.brands && d.brands.length > 1 ? d.brands : [d.brand]).slice(0, 3).map((b) => (
                                <BrandAvatar
                                  key={b.id}
                                  logoPath={b.logo_path}
                                  iconPath={b.icon_path}
                                  name={b.name}
                                  primaryColor={b.primary_color || '#6366f1'}
                                  icon={b.icon}
                                  iconBgColor={b.icon_bg_color}
                                  showIcon={!!(b.icon || b.icon_path)}
                                  size="sm"
                                  className="ring-2 ring-white shrink-0"
                                />
                              ))}
                              {d.brands && d.brands.length > 1 ? (
                                <span className="truncate max-w-[6rem]">{d.brands.length} brands</span>
                              ) : (
                                <span className="truncate max-w-[8rem]">{d.brand?.name || d.brand?.slug || 'Brand'}</span>
                              )}
                            </span>
                          )}
                        </div>
                        <span className="text-sm font-medium text-slate-900 truncate">{displayTitle}</span>
                        <span className="text-sm text-slate-500">Expires {formatDate(d.expires_at)}</span>
                        {filters.scope === 'all' && d.created_by?.name && (
                          <span className="text-sm text-slate-500" title="Who created this download">
                            Created by {d.created_by.name}
                          </span>
                        )}
                        <span className="text-sm text-slate-500" title="Number of times this download was accessed">
                          {d.access_count != null && d.access_count > 0
                            ? `Accessed ${d.access_count} time${d.access_count !== 1 ? 's' : ''}`
                            : 'Not accessed yet'}
                        </span>
                        {d.landing_page_views != null && d.landing_page_views > 0 && (
                          <span className="text-sm text-slate-500" title="Landing page views">
                            {d.landing_page_views} landing view{d.landing_page_views !== 1 ? 's' : ''}
                          </span>
                        )}
                      </div>
                      <div className="flex items-center gap-2 flex-wrap">
                        {isReady && d.public_url && (
                          <>
                            <button
                              type="button"
                              onClick={() => copyLink(d.public_url, d.id)}
                              className="inline-flex items-center rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50"
                              title="Copy link"
                            >
                              {copiedId === d.id ? (
                                'Copied!'
                              ) : (
                                <>
                                  <ClipboardDocumentIcon className="mr-1.5 h-4 w-4 text-slate-400" />
                                  Copy link
                                </>
                              )}
                            </button>
                            <button
                              type="button"
                              onClick={() => {
                                setShareEmailDownload(d)
                                shareEmailForm.reset()
                              }}
                              className="inline-flex items-center rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50"
                              title="Share via email"
                            >
                              <EnvelopeIcon className="mr-1.5 h-4 w-4 text-slate-400" />
                              Share via email
                            </button>
                            <a
                              href={d.public_url}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="inline-flex items-center rounded-lg px-2.5 py-1.5 text-sm font-semibold text-white shadow-sm hover:opacity-95"
                              style={{ backgroundColor: brandAccent }}
                            >
                              Download
                            </a>
                            {canRegenerate && (
                              <button
                                type="button"
                                onClick={() => setRegenerateConfirmId(d.id)}
                                className="inline-flex items-center rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50"
                                title="Rebuild download ZIP"
                              >
                                Regenerate
                              </button>
                            )}
                            {canManage && d.source !== 'single_asset' && (
                              <button
                                type="button"
                                onClick={() => setSettingsModalDownload(d)}
                                className="inline-flex items-center rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50"
                                title="Configure access, landing page, password"
                              >
                                <Cog6ToothIcon className="mr-1.5 h-4 w-4 text-slate-400" />
                                Settings
                              </button>
                            )}
                          </>
                        )}
                        {isProcessing && (
                          d.is_possibly_stuck && canRegenerate ? (
                            <button
                              type="button"
                              onClick={() => setRegenerateConfirmId(d.id)}
                              className="inline-flex items-center rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-sm font-medium text-amber-800 hover:bg-amber-100"
                              title="This may have failed. Click to retry."
                            >
                              May have failed — Regenerate
                            </button>
                          ) : (
                            <span className="text-sm text-slate-500">
                              {d.zip_total_chunks != null && d.zip_total_chunks > 0
                                ? `Preparing — ${d.zip_chunk_index ?? 0} of ${d.zip_total_chunks} chunks`
                                : d.zip_progress_percentage != null
                                  ? `Preparing — about ${d.zip_progress_percentage}% complete`
                                  : 'Preparing…'}
                            </span>
                          )
                        )}
                        {isExpired && (
                          <>
                            {canExtend && (
                              <button
                                type="button"
                                onClick={() => handleExtend(d)}
                                disabled={!!extendingId}
                                className="inline-flex items-center rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 disabled:opacity-50"
                              >
                                {extendingId === d.id ? 'Extending…' : 'Extend expiration'}
                              </button>
                            )}
                            {!canExtend && (
                              <span className="text-xs text-slate-500">Expired — upgrade to extend</span>
                            )}
                            {canRegenerate && (
                              <button
                                type="button"
                                onClick={() => setRegenerateConfirmId(d.id)}
                                className="inline-flex items-center rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50"
                              >
                                Regenerate
                              </button>
                            )}
                          </>
                        )}
                        {isFailed && canRegenerate && (
                          <button
                            type="button"
                            onClick={() => setRegenerateConfirmId(d.id)}
                            className="inline-flex items-center rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50"
                            title="Retry download build"
                          >
                            Regenerate
                          </button>
                        )}
                        {canRevoke && (
                          <button
                            type="button"
                            onClick={() => setRevokeConfirmId(d.id)}
                            className="inline-flex items-center rounded-lg border border-red-200 bg-white px-2.5 py-1.5 text-sm font-medium text-red-700 shadow-sm hover:bg-red-50"
                            title="Revoke download"
                          >
                            <NoSymbolIcon className="mr-1.5 h-4 w-4 text-red-500" />
                            Revoke
                          </button>
                        )}
                        <button
                          type="button"
                          onClick={() => setExpandedId(isOpen ? null : d.id)}
                          className="p-2 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700"
                          aria-expanded={isOpen}
                        >
                          {isOpen ? (
                            <ChevronUpIcon className="h-5 w-5" />
                          ) : (
                            <ChevronDownIcon className="h-5 w-5" />
                          )}
                        </button>
                      </div>
                    </div>

                    {isOpen && (
                      <div className="w-full min-w-full flex-shrink-0 border-t border-slate-200 bg-slate-50 p-4 rounded-b-xl">
                        {isProcessing && (
                          <p className={`text-sm mb-3 ${d.is_possibly_stuck ? 'text-red-600' : d.is_zip_stalled ? 'text-amber-700' : 'text-amber-700'}`}>
                            {d.is_possibly_stuck
                              ? 'This download may have failed. Use Regenerate to retry.'
                              : d.is_zip_stalled
                                ? 'This download is taking longer than usual. We\'re still working on it.'
                                : (() => {
                                    const chunkLine = d.zip_total_chunks != null && d.zip_total_chunks > 0
                                      ? `Preparing download — ${d.zip_chunk_index ?? 0} of ${d.zip_total_chunks} chunks complete`
                                      : d.zip_progress_percentage != null
                                        ? `Preparing download — about ${d.zip_progress_percentage}% complete`
                                        : null
                                    const timeRange = d.zip_time_estimate
                                      ? `Based on similar downloads, this usually takes ~${d.zip_time_estimate.min_minutes}–${d.zip_time_estimate.max_minutes} minutes.`
                                      : d.estimated_bytes != null && d.estimated_bytes > 0
                                        ? `This download is about ${(d.estimated_bytes / (1024 * 1024)).toFixed(1)} MB. Preparation may take a few minutes.`
                                        : null
                                    if (chunkLine && timeRange) return `${chunkLine} ${timeRange}`
                                    if (chunkLine) return chunkLine
                                    if (timeRange) return timeRange
                                    return 'Preparing download. Larger downloads may take a few minutes.'
                                  })()}
                          </p>
                        )}
                        {isFailed && (
                          <p className="text-sm text-red-600 mb-3">Something went wrong while preparing this download. Use Regenerate to retry.</p>
                        )}
                        {isRevoked ? (
                          <p className="text-sm text-red-600 mb-3">This download has been revoked. The link no longer works.</p>
                        ) : (
                          <>
                            <p className="text-xs font-medium text-slate-500 mb-2">
                              {d.access_mode === 'public' ? 'Public link (anyone with this link can download):' : 'Restricted link (access limited):'}
                            </p>
                            <div className="flex items-center gap-2 mb-3">
                              <input
                                type="text"
                                readOnly
                                value={d.public_url || ''}
                                className="flex-1 min-w-0 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs text-slate-700 font-mono truncate focus:outline-none focus:ring-2 focus:ring-slate-300 focus:border-slate-300"
                                aria-label="Public download link"
                              />
                              <button
                                type="button"
                                onClick={() => copyLink(d.public_url, d.id)}
                                className="shrink-0 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50"
                                style={{ color: brandAccent }}
                              >
                                {copiedId === d.id ? 'Copied!' : 'Copy'}
                              </button>
                            </div>
                          </>
                        )}
                        {/* Collapsible Download settings (read-only summary) */}
                        <div className="mb-3">
                          <button
                            type="button"
                            onClick={() => setSettingsExpandedId(settingsExpandedId === d.id ? null : d.id)}
                            className="flex items-center gap-2 w-full text-left text-xs font-medium text-slate-600 hover:text-slate-800 py-1.5 rounded focus:outline-none focus:ring-2 focus:ring-slate-300"
                            aria-expanded={settingsExpandedId === d.id}
                          >
                            {settingsExpandedId === d.id ? (
                              <ChevronUpIcon className="h-4 w-4 text-slate-400" />
                            ) : (
                              <ChevronDownIcon className="h-4 w-4 text-slate-400" />
                            )}
                            Download settings
                          </button>
                          {settingsExpandedId === d.id && (
                            <dl className="mt-2 pl-6 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1.5 text-xs text-slate-600">
                              <div>
                                <dt className="font-medium text-slate-500">Brand</dt>
                                <dd>{d.brand ? (d.brand.name || d.brand.slug || '—') : '—'}</dd>
                              </div>
                              <div>
                                <dt className="font-medium text-slate-500">Access</dt>
                                <dd>{accessBadge(d.access_mode || 'public', d.password_protected).label}</dd>
                              </div>
                              <div>
                                <dt className="font-medium text-slate-500">Expires</dt>
                                <dd>{d.expires_at ? formatDate(d.expires_at) : 'Never'}</dd>
                              </div>
                              <div>
                                <dt className="font-medium text-slate-500">Password</dt>
                                <dd>{d.password_protected ? 'Yes' : 'No'}</dd>
                              </div>
                            </dl>
                          )}
                        </div>
                        {d.thumbnails && d.thumbnails.length > 0 && (
                          <>
                            <p className="text-xs font-medium text-slate-500 mb-2">Assets in this download (click to preview):</p>
                            <div className="flex flex-wrap gap-2">
                              {d.thumbnails.map((t) => (
                                <button
                                  key={t.id}
                                  type="button"
                                  onClick={() => router.visit('/app/assets?asset=' + encodeURIComponent(t.id))}
                                  className="w-16 h-16 rounded-lg border border-slate-200 bg-white overflow-hidden flex items-center justify-center shadow-sm hover:ring-2 hover:ring-slate-300 hover:ring-offset-1 focus:outline-none focus:ring-2 focus:ring-slate-300 focus:ring-offset-1"
                                >
                                  {t.thumbnail_url ? (
                                    <img
                                      src={t.thumbnail_url}
                                      alt=""
                                      className="w-full h-full object-cover"
                                    />
                                  ) : (
                                    <span className="text-xs text-slate-400 truncate px-1">
                                      {t.original_filename || '—'}
                                    </span>
                                  )}
                                </button>
                              ))}
                            </div>
                          </>
                        )}
                      </div>
                    )}
                  </div>
                )
                  })}
                {searchQuery.trim() && filteredZipDownloads.length === 0 && zipDownloads.length > 0 && (
                  <div className="rounded-xl border border-slate-200 bg-white px-4 py-6 text-center text-sm text-slate-500 shadow-sm">
                    No downloads match your search. Try a different term or clear the search.
                  </div>
                )}
                {/* Pager */}
                {paginationMeta && paginationMeta.last_page > 1 && (
                  <div className="mt-6 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                    <p className="text-sm text-slate-600">
                      Showing <span className="font-medium">{((paginationMeta.current_page - 1) * paginationMeta.per_page) + 1}</span> to{' '}
                      <span className="font-medium">{Math.min(paginationMeta.current_page * paginationMeta.per_page, paginationMeta.total)}</span> of{' '}
                      <span className="font-medium">{paginationMeta.total}</span> downloads
                    </p>
                    <div className="flex items-center gap-2">
                      <button
                        type="button"
                        onClick={() => goToPage(paginationMeta.current_page - 1)}
                        disabled={paginationMeta.current_page <= 1}
                        className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:pointer-events-none disabled:opacity-50"
                      >
                        Previous
                      </button>
                      <span className="text-sm text-slate-600">
                        Page {paginationMeta.current_page} of {paginationMeta.last_page}
                      </span>
                      <button
                        type="button"
                        onClick={() => goToPage(paginationMeta.current_page + 1)}
                        disabled={paginationMeta.current_page >= paginationMeta.last_page}
                        className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:pointer-events-none disabled:opacity-50"
                      >
                        Next
                      </button>
                    </div>
                  </div>
                )}
                  {/* Individual asset downloads — small thumb (or avatar), name + expires stacked, size */}
                  {singleAssetDownloads.length > 0 && (
                    <div className="mt-10 pt-8 border-t border-slate-200">
                      <h2 className="text-lg font-semibold text-slate-900 mb-1">Individual asset downloads</h2>
                      <p className="text-sm text-slate-500 mb-4">Single-file downloads from the asset drawer. No shareable link.</p>
                      <ul className="space-y-2" role="list">
                        {singleAssetDownloads.map((d) => {
                          const thumb = (d.thumbnails || [])[0]
                          const thumbUrl = thumb?.thumbnail_url
                          return (
                            <li key={d.id} className="flex items-center gap-3 py-1.5 text-sm text-slate-600">
                              {thumbUrl ? (
                                <img src={thumbUrl} alt="" className="h-8 w-8 rounded object-cover flex-shrink-0 bg-slate-100" />
                              ) : canManage && d.created_by ? (
                                d.created_by.avatar_url ? (
                                  <img src={d.created_by.avatar_url} alt="" className="h-8 w-8 rounded-full object-cover flex-shrink-0" />
                                ) : (
                                  <span className="h-8 w-8 rounded-full bg-slate-200 flex items-center justify-center text-xs text-slate-600 font-medium flex-shrink-0">
                                    {(d.created_by.name || '?').charAt(0).toUpperCase()}
                                  </span>
                                )
                              ) : (
                                <span className="h-8 w-8 rounded bg-slate-200 flex-shrink-0 flex items-center justify-center text-xs text-slate-500">—</span>
                              )}
                              <div className="flex flex-col gap-0.5 min-w-0">
                                <span className="truncate font-medium text-slate-900">{canManage && d.created_by ? (d.created_by.name || '—') : 'Download'}</span>
                                <span className="text-slate-500 text-xs">Expires {formatDate(d.expires_at)}</span>
                                <span className="text-slate-500 text-xs" title="Number of times this download was accessed">
                                  {d.access_count != null && d.access_count > 0
                                    ? `Accessed ${d.access_count} time${d.access_count !== 1 ? 's' : ''}`
                                    : 'Not accessed yet'}
                                </span>
                                {d.landing_page_views != null && d.landing_page_views > 0 && (
                                  <span className="text-slate-500 text-xs" title="Landing page views">
                                    {d.landing_page_views} landing view{d.landing_page_views !== 1 ? 's' : ''}
                                  </span>
                                )}
                              </div>
                              {d.zip_size_bytes != null && d.zip_size_bytes > 0 && (
                                <span className="text-slate-500">{formatBytes(d.zip_size_bytes)}</span>
                              )}
                            </li>
                          )
                        })}
                      </ul>
                    </div>
                  )}
                </>
              )}
          </div>
        </div>
      </main>
      <AppFooter />

      <ConfirmDialog
        open={!!revokeConfirmId}
        onClose={() => setRevokeConfirmId(null)}
        onConfirm={() => {
          const d = downloadsById[revokeConfirmId] || downloadsList.find((x) => x.id === revokeConfirmId)
          if (d) handleRevoke(d)
        }}
        title="Revoke download"
        message="This will immediately invalidate the download link and delete the ZIP file. This cannot be undone."
        confirmText="Revoke"
        cancelText="Cancel"
        variant="danger"
        loading={revoking}
        error={revokeConfirmId && flash?.download_action === 'revoke' ? downloadActionError : null}
      />

      <ConfirmDialog
        open={!!regenerateConfirmId}
        onClose={() => setRegenerateConfirmId(null)}
        onConfirm={() => {
          const d = downloadsById[regenerateConfirmId] || downloadsList.find((x) => x.id === regenerateConfirmId)
          if (d) handleRegenerate(d)
        }}
        title="Regenerate download"
        message="This will rebuild the download ZIP. Existing links will be replaced."
        confirmText="Regenerate"
        cancelText="Cancel"
        variant="warning"
        loading={regenerating}
        error={regenerateConfirmId && flash?.download_action === 'regenerate' ? downloadActionError : null}
      />

      <EditDownloadSettingsModal
        open={!!settingsModalDownload}
        download={settingsModalDownload}
        onClose={() => setSettingsModalDownload(null)}
        onSaved={() => router.reload()}
      />

      {/* Share via email modal */}
      {shareEmailDownload && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" aria-modal="true" role="dialog">
          <div className="bg-white rounded-xl shadow-xl max-w-md w-full p-6">
            <h3 className="text-lg font-semibold text-gray-900">Share via email</h3>
            <p className="mt-1 text-sm text-gray-500">
              Send the download link to someone
            </p>
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
              className="mt-4 space-y-4"
            >
              <div>
                <label htmlFor="share-email-to" className="block text-sm font-medium text-gray-700">
                  To
                </label>
                <input
                  id="share-email-to"
                  type="email"
                  required
                  value={shareEmailForm.data.to}
                  onChange={(e) => shareEmailForm.setData('to', e.target.value)}
                  className="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                  placeholder="email@example.com"
                />
                {(shareEmailForm.errors?.to || pageErrors?.to) && (
                  <p className="mt-1 text-sm text-red-600">{shareEmailForm.errors.to || pageErrors.to}</p>
                )}
              </div>
              <div>
                <label htmlFor="share-email-message" className="block text-sm font-medium text-gray-700">
                  Optional message
                </label>
                <textarea
                  id="share-email-message"
                  rows={3}
                  value={shareEmailForm.data.message}
                  onChange={(e) => shareEmailForm.setData('message', e.target.value)}
                  className="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                  placeholder="Add a personal note..."
                />
              </div>
              <div className="flex gap-3 justify-end pt-2">
                <button
                  type="button"
                  onClick={() => {
                    setShareEmailDownload(null)
                    shareEmailForm.reset()
                  }}
                  className="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={shareEmailForm.processing}
                  className="rounded-lg px-4 py-2 text-sm font-medium text-white shadow-sm disabled:opacity-70"
                  style={{ backgroundColor: brandAccent }}
                >
                  {shareEmailForm.processing ? 'Sending…' : 'Send'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
