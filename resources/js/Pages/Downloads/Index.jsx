import { useState } from 'react'
import { Link, router, usePage } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import ConfirmDialog from '../../Components/ConfirmDialog'
import { ArrowDownTrayIcon, ChevronDownIcon, ChevronUpIcon, ClipboardDocumentIcon, NoSymbolIcon } from '@heroicons/react/24/outline'

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

function statusLabel(status, zipStatus) {
  if (zipStatus === 'building') return 'Building…'
  if (zipStatus === 'ready' && status === 'ready') return 'Ready'
  if (zipStatus === 'failed') return 'Failed'
  if (status === 'failed') return 'Failed'
  return 'Pending'
}

export default function DownloadsIndex({
  downloads = [],
  bucket_count: bucketCount = 0,
  can_manage: canManage = false,
  filters: initialFilters = {},
  download_features: features = {},
}) {
  const { auth } = usePage().props
  const [expandedId, setExpandedId] = useState(null)
  const [copiedId, setCopiedId] = useState(null)
  const [revokeConfirmId, setRevokeConfirmId] = useState(null)
  const [revoking, setRevoking] = useState(false)
  const [filters, setFilters] = useState({
    scope: initialFilters.scope ?? 'mine',
    status: initialFilters.status ?? '',
    access: initialFilters.access ?? '',
  })

  const copyLink = (url, id) => {
    navigator.clipboard.writeText(url).then(() => {
      setCopiedId(id)
      setTimeout(() => setCopiedId(null), 2000)
    })
  }

  const handleScopeChange = (e) => {
    const scope = e.target.value
    const next = { ...filters, scope }
    setFilters(next)
    router.get('/app/downloads', next, { preserveState: true })
  }

  const handleStatusChange = (e) => {
    const status = e.target.value
    const next = { ...filters, status }
    setFilters(next)
    router.get('/app/downloads', next, { preserveState: true })
  }

  const handleAccessChange = (e) => {
    const access = e.target.value
    const next = { ...filters, access }
    setFilters(next)
    router.get('/app/downloads', next, { preserveState: true })
  }

  const handleRevoke = (d) => {
    setRevoking(true)
    router.post(route('downloads.revoke', d.id), {}, {
      preserveScroll: true,
      onFinish: () => setRevoking(false),
      onSuccess: () => setRevokeConfirmId(null),
    })
  }

  return (
    <div className="min-h-screen flex flex-col bg-gray-50">
      <AppNav />
      <main className="flex-1 py-6 px-4 sm:px-6 lg:px-8">
        <div className="max-w-4xl mx-auto">
          <div className="flex flex-wrap items-center justify-between gap-4">
            <div>
              <h1 className="text-2xl font-bold text-gray-900">
                {canManage && filters.scope === 'all' ? 'All Downloads' : 'My Downloads'}
              </h1>
              <p className="mt-1 text-sm text-gray-500">
                Download links expire after 30 days. Anyone with the link can download (unless restricted).
              </p>
            </div>
            {canManage && (
              <div className="flex items-center gap-2">
                <select
                  value={filters.scope}
                  onChange={handleScopeChange}
                  className="rounded-md border-gray-300 text-sm"
                >
                  <option value="mine">My Downloads</option>
                  <option value="all">All Downloads</option>
                </select>
                <select
                  value={filters.status}
                  onChange={handleStatusChange}
                  className="rounded-md border-gray-300 text-sm"
                >
                  <option value="">All statuses</option>
                  <option value="active">Active</option>
                  <option value="expired">Expired</option>
                  <option value="revoked">Revoked</option>
                </select>
                <select
                  value={filters.access}
                  onChange={handleAccessChange}
                  className="rounded-md border-gray-300 text-sm"
                >
                  <option value="">All access</option>
                  <option value="public">Public</option>
                  <option value="restricted">Restricted</option>
                </select>
              </div>
            )}
          </div>

          {bucketCount > 0 && (
            <div className="mt-4 p-3 rounded-lg bg-indigo-50 border border-indigo-200">
              <p className="text-sm text-indigo-800">
                You have {bucketCount} item{bucketCount !== 1 ? 's' : ''} in your download bucket.{' '}
                <Link href="/app/assets" className="font-medium text-indigo-600 hover:text-indigo-500 underline">
                  Go to Assets
                </Link>{' '}
                to create a download.
              </p>
            </div>
          )}

          <div className="mt-6 space-y-4">
            {downloads.length === 0 ? (
              <div className="rounded-lg border border-gray-200 bg-white p-8 text-center">
                <ArrowDownTrayIcon className="mx-auto h-12 w-12 text-gray-400" />
                <h2 className="mt-2 text-lg font-medium text-gray-900">No downloads yet</h2>
                <p className="mt-1 text-sm text-gray-500">
                  Select assets on the Assets or Collections page, then use &quot;Create Download&quot; to generate a ZIP.
                </p>
                <div className="mt-4">
                  <Link
                    href="/app/assets"
                    className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                  >
                    Go to Assets
                  </Link>
                </div>
              </div>
            ) : (
              downloads.map((d) => {
                const isRevoked = !!d.revoked_at
                const isExpired = !isRevoked && d.expires_at && new Date(d.expires_at) < new Date()
                const isBuilding = d.zip_status === 'building'
                const isReady = d.zip_status === 'ready' && d.status === 'ready' && !isRevoked
                const isOpen = expandedId === d.id
                const canRevoke = canManage && features.revoke && !isRevoked

                return (
                  <div
                    key={d.id}
                    className="rounded-lg border border-gray-200 bg-white shadow-sm overflow-hidden"
                  >
                    <div className="p-4 flex flex-wrap items-center justify-between gap-3">
                      <div className="flex items-center gap-3 min-w-0">
                        <span
                          className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                            isRevoked
                              ? 'bg-red-100 text-red-800'
                              : isBuilding
                                ? 'bg-amber-100 text-amber-800'
                                : isReady && !isExpired
                                  ? 'bg-green-100 text-green-800'
                                  : isExpired
                                    ? 'bg-gray-100 text-gray-600'
                                    : 'bg-gray-100 text-gray-700'
                          }`}
                        >
                          {isRevoked ? 'Revoked' : isBuilding ? 'Building…' : isExpired ? 'Expired' : isReady ? 'Ready' : 'Pending'}
                        </span>
                        <span className="text-sm text-gray-500">
                          Expires {formatDate(d.expires_at)}
                        </span>
                        <span className="text-sm text-gray-500">
                          {d.asset_count} asset{d.asset_count !== 1 ? 's' : ''}
                          {d.zip_size_bytes != null && d.zip_size_bytes > 0 && ` · ${formatBytes(d.zip_size_bytes)}`}
                        </span>
                      </div>
                      <div className="flex items-center gap-2">
                        {isReady && !isExpired && !isRevoked && (
                          <>
                            <button
                              type="button"
                              onClick={() => copyLink(d.public_url, d.id)}
                              className="inline-flex items-center rounded-md bg-white px-2.5 py-1.5 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                              title="Copy link"
                            >
                              {copiedId === d.id ? (
                                'Copied!'
                              ) : (
                                <>
                                  <ClipboardDocumentIcon className="mr-1.5 h-4 w-4 text-gray-400" />
                                  Copy link
                                </>
                              )}
                            </button>
                            <a
                              href={d.public_url}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="inline-flex items-center rounded-md bg-indigo-600 px-2.5 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                            >
                              Download
                            </a>
                          </>
                        )}
                        {canRevoke && (
                          <button
                            type="button"
                            onClick={() => setRevokeConfirmId(d.id)}
                            className="inline-flex items-center rounded-md bg-white px-2.5 py-1.5 text-sm font-medium text-red-700 shadow-sm ring-1 ring-inset ring-red-200 hover:bg-red-50"
                            title="Revoke download"
                          >
                            <NoSymbolIcon className="mr-1.5 h-4 w-4 text-red-500" />
                            Revoke
                          </button>
                        )}
                        <button
                          type="button"
                          onClick={() => setExpandedId(isOpen ? null : d.id)}
                          className="p-1 rounded text-gray-500 hover:bg-gray-100"
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
                      <div className="border-t border-gray-200 bg-gray-50 px-4 py-3">
                        {isRevoked ? (
                          <p className="text-sm text-red-600 mb-3">This download has been revoked. The link no longer works.</p>
                        ) : (
                          <>
                            <p className="text-xs font-medium text-gray-500 mb-2">
                              {d.access_mode === 'public' ? 'Public link (anyone with this link can download):' : 'Restricted link (access limited):'}
                            </p>
                            <div className="flex items-center gap-2 mb-3">
                              <code className="flex-1 text-xs bg-white px-2 py-1.5 rounded border border-gray-200 truncate">
                                {d.public_url}
                              </code>
                              <button
                                type="button"
                                onClick={() => copyLink(d.public_url, d.id)}
                                className="shrink-0 text-xs text-indigo-600 hover:text-indigo-500"
                              >
                                Copy
                              </button>
                            </div>
                          </>
                        )}
                        {d.thumbnails && d.thumbnails.length > 0 && (
                          <>
                            <p className="text-xs font-medium text-gray-500 mb-2">Assets in this download (click to preview):</p>
                            <div className="flex flex-wrap gap-2">
                              {d.thumbnails.map((t) => (
                                <button
                                  key={t.id}
                                  type="button"
                                  onClick={() => router.visit('/app/assets?asset=' + encodeURIComponent(t.id))}
                                  className="w-16 h-16 rounded border border-gray-200 bg-white overflow-hidden flex items-center justify-center hover:ring-2 hover:ring-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                >
                                  {t.thumbnail_url ? (
                                    <img
                                      src={t.thumbnail_url}
                                      alt=""
                                      className="w-full h-full object-cover"
                                    />
                                  ) : (
                                    <span className="text-xs text-gray-400 truncate px-1">
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
              })
            )}
          </div>
        </div>
      </main>
      <AppFooter />

      <ConfirmDialog
        open={!!revokeConfirmId}
        onClose={() => setRevokeConfirmId(null)}
        onConfirm={() => {
          const d = downloads.find((x) => x.id === revokeConfirmId)
          if (d) handleRevoke(d)
        }}
        title="Revoke download"
        message="This will immediately invalidate the download link and delete the ZIP file. This cannot be undone."
        confirmText="Revoke"
        cancelText="Cancel"
        variant="danger"
        loading={revoking}
      />
    </div>
  )
}
