/**
 * Modal to edit an existing download's settings: access (public/brand/company/specific people),
 * landing page (enable + headline/subtext), password. Plan-gated; only for ZIP downloads.
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
} from '@heroicons/react/24/outline'

function formatAnalyticsDate(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const SETTINGS_ERROR_KEYS = ['message', 'password', 'access_mode', 'user_ids', 'uses_landing_page', 'landing_copy']

export default function EditDownloadSettingsModal({ open, download, onClose, onSaved }) {
  const { download_features: features = {} } = usePage().props
  const { bannerMessage: pageBannerError, getFieldError } = useDownloadErrors(SETTINGS_ERROR_KEYS)
  const canBrand = !!features.restrict_access_brand
  const canCompany = !!features.restrict_access_company
  const canUsers = !!features.restrict_access_users
  const canPasswordProtect = !!features.password_protection
  const canBrandDownload = !!features.branding

  const [activeTab, setActiveTab] = useState('settings')
  const [accessMode, setAccessMode] = useState('public')
  const [allowedUserIds, setAllowedUserIds] = useState([])
  const [companyUsers, setCompanyUsers] = useState([])
  const [loadingUsers, setLoadingUsers] = useState(false)
  const [usesLandingPage, setUsesLandingPage] = useState(false)
  const [landingHeadline, setLandingHeadline] = useState('')
  const [landingSubtext, setLandingSubtext] = useState('')
  const [password, setPassword] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState(null)

  const [analytics, setAnalytics] = useState({ loading: false, error: null, data: null })
  const analyticsFetchedRef = useRef(false)

  useEffect(() => {
    if (!open || !download) return
    setActiveTab('settings')
    setAccessMode(download.access_mode || 'public')
    setAllowedUserIds(Array.isArray(download.allowed_user_ids) ? [...download.allowed_user_ids] : [])
    setUsesLandingPage(!!download.uses_landing_page)
    const lc = download.landing_copy || {}
    setLandingHeadline(lc.headline || '')
    setLandingSubtext(lc.subtext || '')
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
      uses_landing_page: usesLandingPage,
      landing_copy: { headline: landingHeadline.trim(), subtext: landingSubtext.trim() },
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
  const showLanding = canBrandDownload
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
                    <label className="flex items-center gap-2">
                      <input
                        type="radio"
                        name="edit_access_mode"
                        value="brand"
                        checked={accessMode === 'brand'}
                        onChange={() => setAccessMode('brand')}
                        className="h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500"
                      />
                      <span className="text-sm text-gray-700">Brand members</span>
                    </label>
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

            {showLanding && (
              <div className="mb-4 space-y-3 rounded border border-gray-200 bg-gray-50/50 p-3">
                <label className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    checked={usesLandingPage}
                    onChange={(e) => setUsesLandingPage(e.target.checked)}
                    className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                  />
                  <span className="text-sm font-medium text-gray-700">Enable landing page</span>
                </label>
                <p className="text-xs text-gray-500">When unchecked, the link goes straight to the download. When checked, recipients see a branded landing page first.</p>
                {usesLandingPage && (
                  <div className="space-y-3 border-t border-gray-200 pt-2">
                    <div>
                      <label htmlFor="edit-download-headline" className="block text-xs font-medium text-gray-600">Headline (optional)</label>
                      <input
                        id="edit-download-headline"
                        type="text"
                        value={landingHeadline}
                        onChange={(e) => setLandingHeadline(e.target.value)}
                        placeholder="e.g. Press Kit"
                        className="mt-1 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"
                      />
                    </div>
                    <div>
                      <label htmlFor="edit-download-subtext" className="block text-xs font-medium text-gray-600">Subtext (optional)</label>
                      <input
                        id="edit-download-subtext"
                        type="text"
                        value={landingSubtext}
                        onChange={(e) => setLandingSubtext(e.target.value)}
                        placeholder="e.g. Approved brand assets"
                        className="mt-1 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"
                      />
                    </div>
                  </div>
                )}
              </div>
            )}

            {showPassword && (
              <div className="mb-4">
                <label htmlFor="edit-download-password" className="block text-sm font-medium text-gray-700">
                  Password (optional)
                </label>
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

            {!hasAccessOptions && !showLanding && !showPassword && (
              <p className="mb-4 text-sm text-gray-500">No settings available for your plan. Upgrade to configure access, landing page, or password.</p>
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
                disabled={submitting || (!hasAccessOptions && !showLanding && !showPassword)}
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
