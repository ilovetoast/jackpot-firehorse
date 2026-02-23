/**
 * Modal to edit an existing download's settings: access (public/brand/company/specific people),
 * password. Plan-gated; only for ZIP downloads. Layout/copy for landing come from Brand → Downloads.
 * D9: Activity & Analytics tab — read-only, fetch on tab open.
 */
import { useState, useEffect, useRef } from 'react'
import { router } from '@inertiajs/react'
import { usePage } from '@inertiajs/react'
import { useDownloadErrors } from '../hooks/useDownloadErrors'
import {
  XMarkIcon,
  ChartBarIcon,
  Cog6ToothIcon,
  ArrowDownTrayIcon,
  UserGroupIcon,
  CalendarIcon,
  GlobeAltIcon,
  LockClosedIcon,
} from '@heroicons/react/24/outline'

function formatAnalyticsDate(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const SETTINGS_ERROR_KEYS = ['message', 'password', 'access_mode', 'user_ids', 'landing_copy']

export default function EditDownloadSettingsModal({ open, download, onClose, onSaved }) {
  const { download_features: features = {} } = usePage().props
  const { bannerMessage: pageBannerError, getFieldError } = useDownloadErrors(SETTINGS_ERROR_KEYS)
  const canBrand = !!features.restrict_access_brand
  // Multi-brand safety: brand-based access only when all assets are from a single brand (hard constraint)
  const canRestrictToBrand = download
    ? (download.can_restrict_to_brand ?? !(download.brands && download.brands.length > 1))
    : true
  const isMultiBrand = canBrand && !canRestrictToBrand
  const canCompany = !!features.restrict_access_company
  const canUsers = !!features.restrict_access_users
  const canPasswordProtect = !!features.password_protection

  const [activeTab, setActiveTab] = useState('settings')
  const [accessMode, setAccessMode] = useState('public')
  const [allowedUserIds, setAllowedUserIds] = useState([])
  const [companyUsers, setCompanyUsers] = useState([])
  const [loadingUsers, setLoadingUsers] = useState(false)
  const [password, setPassword] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState(null)

  const [analytics, setAnalytics] = useState({ loading: false, error: null, data: null })
  const analyticsFetchedRef = useRef(false)

  useEffect(() => {
    if (!open || !download) return
    setActiveTab('settings')
    const canRestrict = download.can_restrict_to_brand ?? !(download.brands && download.brands.length > 1)
    const mode = download.access_mode || 'public'
    setAccessMode(mode === 'brand' && !canRestrict ? 'public' : mode)
    setAllowedUserIds(Array.isArray(download.allowed_user_ids) ? [...download.allowed_user_ids] : [])
    setPassword('')
    setError(null)
    setAnalytics({ loading: false, error: null, data: null })
    analyticsFetchedRef.current = false
  }, [open, download])

  useEffect(() => {
    if (!open || !download || activeTab !== 'analytics') return
    if (analyticsFetchedRef.current || analytics.loading || analytics.data) return
    analyticsFetchedRef.current = true
    setAnalytics((prev) => ({ ...prev, loading: true, error: null }))
    fetch(route('downloads.analytics', { download: download.id }), {
      method: 'GET',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    })
      .then((r) => {
        if (!r.ok) throw new Error('Analytics unavailable')
        return r.json()
      })
      .then((data) => setAnalytics({ loading: false, error: null, data }))
      .catch(() => setAnalytics({ loading: false, error: true, data: null }))
  }, [open, download, activeTab, analytics.loading, analytics.data])

  useEffect(() => {
    if (!open || accessMode !== 'users' || !canUsers) return
    setLoadingUsers(true)
    fetch(route('downloads.company-users'), {
      method: 'GET',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    })
      .then((r) => (r.ok ? r.json() : { users: [] }))
      .then((data) => setCompanyUsers(data.users || []))
      .catch(() => setCompanyUsers([]))
      .finally(() => setLoadingUsers(false))
  }, [open, accessMode, canUsers])

  const toggleUser = (userId) => {
    setAllowedUserIds((prev) =>
      prev.includes(userId) ? prev.filter((id) => id !== userId) : [...prev, userId]
    )
  }

  const handleSubmit = (e) => {
    e.preventDefault()
    if (!download) return
    setError(null)
    setSubmitting(true)

    const payload = {
      access_mode: accessMode,
    }
    if (accessMode === 'users') payload.user_ids = allowedUserIds
    if (canPasswordProtect && typeof password === 'string' && password.trim() !== '') payload.password = password.trim()

    router.put(route('downloads.settings', { download: download.id }), payload, {
      preserveScroll: true,
      onSuccess: () => {
        setSubmitting(false)
        onSaved?.()
        onClose()
      },
      onError: (errors) => {
        setSubmitting(false)
        setError(errors?.message || Object.values(errors || {}).flat().join(' ') || 'Failed to update settings.')
      },
      onFinish: () => setSubmitting(false),
    })
  }

  if (!open) return null

  const hasAccessOptions = canBrand || canCompany || canUsers
  const showPassword = canPasswordProtect

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto" aria-modal="true" role="dialog">
      <div className="flex min-h-full items-center justify-center p-4">
        <div className="fixed inset-0 bg-black/50 transition-opacity" onClick={onClose} aria-hidden="true" />
        <div className="relative w-full max-w-lg rounded-lg bg-white shadow-xl">
          <div className="flex items-center justify-between border-b border-gray-200 px-4 py-3">
            <h2 className="text-lg font-semibold text-gray-900">Download settings</h2>
            <button
              type="button"
              onClick={onClose}
              className="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
              aria-label="Close"
            >
              <XMarkIcon className="h-5 w-5" />
            </button>
          </div>
          <nav className="flex border-b border-gray-200 px-4" aria-label="Tabs">
            <button
              type="button"
              onClick={() => setActiveTab('settings')}
              className={`border-b-2 py-3 px-4 text-sm font-medium ${activeTab === 'settings' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'}`}
            >
              <span className="flex items-center gap-2">
                <Cog6ToothIcon className="h-4 w-4" />
                Settings
              </span>
            </button>
            <button
              type="button"
              onClick={() => setActiveTab('analytics')}
              className={`border-b-2 py-3 px-4 text-sm font-medium ${activeTab === 'analytics' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'}`}
            >
              <span className="flex items-center gap-2">
                <ChartBarIcon className="h-4 w-4" />
                Activity & Analytics
              </span>
            </button>
          </nav>
          {activeTab === 'analytics' ? (
            <div className="max-h-[60vh] overflow-y-auto px-4 py-4">
              {analytics.loading && (
                <p className="text-sm text-gray-500">Loading analytics…</p>
              )}
              {analytics.error && (
                <p className="text-sm text-red-600">Analytics unavailable for this download.</p>
              )}
              {analytics.data && !analytics.loading && !analytics.error && (
                <div className="space-y-4">
                  <div className="rounded-lg border border-gray-200 bg-gray-50 p-3">
                    <p className="text-xs font-medium uppercase text-gray-500">Summary</p>
                    <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                      <dt className="text-gray-500">Total downloads</dt>
                      <dd className="font-medium text-gray-900">{analytics.data.summary?.total_downloads ?? 0}</dd>
                      <dt className="text-gray-500">Unique users</dt>
                      <dd className="font-medium text-gray-900">{analytics.data.summary?.unique_users ?? '—'}</dd>
                      <dt className="text-gray-500">Landing page views</dt>
                      <dd className="font-medium text-gray-900">{analytics.data.summary?.landing_page_views ?? 0}</dd>
                    </dl>
                  </div>
                  {analytics.data.recent_activity?.length > 0 && (
                    <div>
                      <p className="text-xs font-medium uppercase text-gray-500">Recent activity</p>
                      <ul className="mt-2 space-y-1 text-sm">
                        {analytics.data.recent_activity.map((a, i) => (
                          <li key={i} className="flex justify-between text-gray-700">
                            <span>{a.event ?? 'Downloaded'}</span>
                            <span className="text-gray-500">{formatAnalyticsDate(a.at)}</span>
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}
                  {analytics.data.asset_breakdown?.length > 0 && (
                    <div>
                      <p className="text-xs font-medium uppercase text-gray-500">Asset breakdown</p>
                      <ul className="mt-2 space-y-1 text-sm">
                        {analytics.data.asset_breakdown.map((a, i) => (
                          <li key={i} className="flex justify-between text-gray-700">
                            <span className="truncate max-w-[70%]" title={a.name}>{a.name || `Asset ${i + 1}`}</span>
                            <span className="text-gray-500">{a.download_count ?? 0} downloads</span>
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}
                  {(!analytics.data.recent_activity?.length && !analytics.data.asset_breakdown?.length && (analytics.data.summary?.total_downloads ?? 0) === 0) && (
                    <p className="text-sm text-gray-500">No activity yet.</p>
                  )}
                </div>
              )}
            </div>
          ) : (
          <form onSubmit={handleSubmit} className="px-4 py-4">
            {(pageBannerError || error) && (
              <div className="mb-4 rounded-md bg-red-50 p-3">
                <p className="text-sm text-red-700">{pageBannerError || error}</p>
              </div>
            )}
            {hasAccessOptions && (
              <div className="mb-4">
                <label className="block text-sm font-medium text-gray-700">Access</label>
                <div className="mt-2 space-y-2">
                  <label className="flex items-center gap-2">
                    <input
                      type="radio"
                      name="edit_access_mode"
                      value="public"
                      checked={accessMode === 'public'}
                      onChange={() => setAccessMode('public')}
                      className="h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500"
                    />
                    <span className="text-sm text-gray-700">Public (anyone with the link)</span>
                  </label>
                  {canBrand && (
                    <>
                      <label className={`flex items-center gap-2 ${isMultiBrand ? 'cursor-not-allowed opacity-75' : ''}`}>
                        <input
                          type="radio"
                          name="edit_access_mode"
                          value="brand"
                          checked={accessMode === 'brand'}
                          onChange={() => !isMultiBrand && setAccessMode('brand')}
                          disabled={isMultiBrand}
                          className="h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50"
                        />
                        <span className="text-sm text-gray-700">Brand members</span>
                      </label>
                      {isMultiBrand && (
                        <p className="ml-6 text-xs text-amber-700" role="status">
                          Brand-based access is only available when all assets are from a single brand. This download contains assets from multiple brands.
                        </p>
                      )}
                    </>
                  )}
                  {canCompany && (
                    <label className="flex items-center gap-2">
                      <input
                        type="radio"
                        name="edit_access_mode"
                        value="company"
                        checked={accessMode === 'company'}
                        onChange={() => setAccessMode('company')}
                        className="h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500"
                      />
                      <span className="text-sm text-gray-700">Company members</span>
                    </label>
                  )}
                  {canUsers && (
                    <label className="flex items-center gap-2">
                      <input
                        type="radio"
                        name="edit_access_mode"
                        value="users"
                        checked={accessMode === 'users'}
                        onChange={() => setAccessMode('users')}
                        className="h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500"
                      />
                      <span className="text-sm text-gray-700">Specific people</span>
                    </label>
                  )}
                </div>
                {accessMode === 'users' && canUsers && (
                  <div className="mt-3 max-h-40 overflow-y-auto rounded border border-gray-200 bg-gray-50 p-2">
                    {loadingUsers ? (
                      <p className="text-sm text-gray-500">Loading users…</p>
                    ) : companyUsers.length === 0 ? (
                      <p className="text-sm text-gray-500">No users in company</p>
                    ) : (
                      <div className="space-y-1">
                        {companyUsers.map((u) => (
                          <label key={u.id} className="flex items-center gap-2">
                            <input
                              type="checkbox"
                              checked={allowedUserIds.includes(u.id)}
                              onChange={() => toggleUser(u.id)}
                              className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                            />
                            <span className="text-sm text-gray-700">
                              {[u.first_name, u.last_name].filter(Boolean).join(' ') || u.email}
                            </span>
                          </label>
                        ))}
                      </div>
                    )}
                  </div>
                )}
              </div>
            )}

            {showPassword && (
              <div className="mb-4">
                <label htmlFor="edit-download-password" className="block text-sm font-medium text-gray-700">
                  Password (optional)
                </label>
                {download.password_protected && (
                  <p className="mb-1 flex items-center gap-1.5 text-xs font-medium text-emerald-700" role="status">
                    <LockClosedIcon className="h-4 w-4 shrink-0" aria-hidden />
                    Password is set — enter a new one to change, or leave blank to keep it.
                  </p>
                )}
                <input
                  id="edit-download-password"
                  type="password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="Leave blank to keep current; enter new to change; not shown"
                  className={`mt-1 block w-full rounded-md border px-3 py-2 shadow-sm sm:text-sm ${getFieldError('password') ? 'border-red-500 focus:border-red-500 focus:ring-red-500' : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500'}`}
                  autoComplete="new-password"
                />
                {getFieldError('password') && (
                  <p className="mt-1 text-sm text-red-600">{getFieldError('password')}</p>
                )}
                <p className="mt-1 text-xs text-gray-500">Enter a new password to change it, or leave blank to keep the current one.</p>
              </div>
            )}

            {!hasAccessOptions && !showPassword && (
              <p className="mb-4 text-sm text-gray-500">No settings available for your plan. Upgrade to configure access or password.</p>
            )}

            <div className="flex justify-end gap-3">
              <button
                type="button"
                onClick={onClose}
                className="rounded-md bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={submitting || (!hasAccessOptions && !showPassword)}
                className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
              >
                {submitting ? 'Saving…' : 'Save settings'}
              </button>
            </div>
          </form>
          )}
        </div>
      </div>
    </div>
  )
}
