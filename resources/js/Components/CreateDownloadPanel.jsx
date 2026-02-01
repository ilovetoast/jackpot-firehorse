/**
 * Phase D3 — Download Creation UX
 *
 * Slide-over/modal opened from bucket bar "Create Download".
 * Summary, name (Enterprise only), expiration, access scope, CTA.
 *
 * IMPORTANT (phase locked): Errors must come from usePage().props.errors (redirect back from backend).
 * Do not bypass this — backend uses downloadCreateError() so Create never receives raw JSON.
 */
import { useState, useEffect, useCallback } from 'react'
import { router } from '@inertiajs/react'
import { usePage } from '@inertiajs/react'
import { XMarkIcon, ChevronDownIcon, ChevronUpIcon } from '@heroicons/react/24/outline'

function defaultDownloadName(brandName) {
  const date = new Date().toISOString().slice(0, 10)
  return `${brandName || 'download'}-download-${date}`
}

// Mirrors backend DownloadNameResolver: resolve template and sanitize for filename
function resolveDownloadNameTemplate(template, companyName, brandName) {
  if (!template || typeof template !== 'string' || !template.trim()) return null
  const sanitizeToken = (v) => {
    if (!v || typeof v !== 'string') return ''
    return v.trim().replace(/[^\p{L}\p{N}\s\-_]/gu, '-').replace(/\s+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '')
  }
  const sanitizeFilename = (v) => {
    if (!v || typeof v !== 'string') return 'download'
    let s = v.trim().replace(/[^\p{L}\p{N}\s\-_.]/gu, '-').replace(/\s+/g, '-').replace(/-+/g, '-')
    s = s.replace(/^[-._]+|[-._]+$/g, '')
    return s || 'download'
  }
  const now = new Date()
  const date = now.toISOString().slice(0, 10)
  const datetime = now.toISOString().slice(0, 10) + '-' + now.toTimeString().slice(0, 5).replace(':', '-')
  const company = sanitizeToken(companyName || '')
  const brand = sanitizeToken(brandName || '')
  const resolved = template
    .replace(/\{\{\s*company\s*\}\}/gi, company)
    .replace(/\{\{\s*brand\s*\}\}/gi, brand)
    .replace(/\{\{\s*date\s*\}\}/gi, date)
    .replace(/\{\{\s*datetime\s*\}\}/gi, datetime)
  return sanitizeFilename(resolved)
}

function defaultExpiresAt() {
  const d = new Date()
  d.setDate(d.getDate() + 30)
  return d.toISOString().slice(0, 10)
}

export default function CreateDownloadPanel({
  open,
  onClose,
  bucketCount = 0,
  previewItems: initialPreviewItems = [],
  onSuccess,
}) {
  const { auth, download_features: features = {}, errors: pageErrors = {} } = usePage().props
  const brandName = auth?.activeBrand?.name || ''
  const companyName = auth?.activeCompany?.name || ''
  const downloadNameTemplate = auth?.activeCompany?.settings?.download_name_template ?? ''

  const [name, setName] = useState('')
  const [expiresAt, setExpiresAt] = useState('')
  const [neverExpires, setNeverExpires] = useState(false)
  const [accessMode, setAccessMode] = useState('public')
  const [allowedUserIds, setAllowedUserIds] = useState([])
  const [companyUsers, setCompanyUsers] = useState([])
  const [loadingUsers, setLoadingUsers] = useState(false)
  const [previewItems, setPreviewItems] = useState(initialPreviewItems)
  const [loadingPreview, setLoadingPreview] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState(null)
  // D7: Optional password protection
  const [password, setPassword] = useState('')
  // R3.1: Landing page — checkbox + copy overrides only (visuals from brand settings)
  const [usesLandingPage, setUsesLandingPage] = useState(false)
  const [landingHeadline, setLandingHeadline] = useState('')
  const [landingSubtext, setLandingSubtext] = useState('')
  const [advancedOpen, setAdvancedOpen] = useState(false)

  useEffect(() => {
    if (!open || bucketCount <= 0) return
    setLoadingPreview(true)
    fetch(route('download-bucket.items') + '?details=1', {
      method: 'GET',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    })
      .then((r) => (r.ok ? r.json() : { items: [] }))
      .then((data) => setPreviewItems(data.items || []))
      .catch(() => setPreviewItems([]))
      .finally(() => setLoadingPreview(false))
  }, [open, bucketCount])

  const canRename = !!features.rename
  const canExtend = !!features.extend_expiration
  const maxExpirationDays = features.max_expiration_days ?? 30
  const canNonExpiring = !!features.non_expiring
  const canBrand = !!features.restrict_access_brand
  const canCompany = !!features.restrict_access_company
  const canUsers = !!features.restrict_access_users
  const canPasswordProtect = !!features.password_protection // D7: Enterprise only
  const canBrandDownload = !!features.branding // D7: Pro + Enterprise (landing page branding)

  const defaultTemplate = '{{brand}}-download-{{date}}'
  // Inline/dialog errors: from page (redirect back with errors) or from onError
  const inlineMessage =
    error ||
    (typeof pageErrors?.expires_at === 'string' && pageErrors.expires_at) ||
    (typeof pageErrors?.message === 'string' && pageErrors.message) ||
    (Array.isArray(pageErrors?.expires_at) && pageErrors.expires_at[0]) ||
    (Array.isArray(pageErrors?.name) && pageErrors.name[0]) ||
    (Array.isArray(pageErrors?.password) && pageErrors.password[0]) ||
    (Array.isArray(pageErrors?.access_mode) && pageErrors.access_mode[0]) ||
    (Array.isArray(pageErrors?.branding_options) && pageErrors.branding_options[0]) ||
    (Array.isArray(pageErrors?.message) && pageErrors.message[0]) ||
    null

  useEffect(() => {
    if (!open) return
    const templateToUse = (downloadNameTemplate && downloadNameTemplate.trim()) ? downloadNameTemplate : defaultTemplate
    const fromTemplate = resolveDownloadNameTemplate(templateToUse, companyName, brandName)
    setName(fromTemplate ?? defaultDownloadName(brandName))
    setExpiresAt(defaultExpiresAt())
    setNeverExpires(false)
    setAccessMode('public')
    setAllowedUserIds([])
    setError(null)
    setPassword('')
    setUsesLandingPage(false)
    setLandingHeadline('')
    setLandingSubtext('')
    setAdvancedOpen(false)
  }, [open, brandName, companyName, downloadNameTemplate])

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

  const maxDate = useCallback(() => {
    const d = new Date()
    d.setDate(d.getDate() + maxExpirationDays)
    return d.toISOString().slice(0, 10)
  }, [maxExpirationDays])

  const handleSubmit = (e) => {
    e.preventDefault()
    setError(null)
    setSubmitting(true)

    const payload = {
      source: 'grid',
      access_mode: accessMode,
    }
    if (canRename && name.trim()) payload.name = name.trim()
    if (neverExpires && canNonExpiring) {
      payload.expires_at = 'never'
    } else {
      payload.expires_at = expiresAt || defaultExpiresAt()
    }
    if (accessMode === 'users' && allowedUserIds.length) {
      payload.allowed_users = allowedUserIds
    }
    if (canPasswordProtect && password.trim()) {
      payload.password = password.trim()
    }
    // R3.1: Landing page — opt-in + copy overrides only (logo/color from brand settings)
    if (canBrandDownload) {
      payload.uses_landing_page = !!usesLandingPage
      if (usesLandingPage && (landingHeadline.trim() || landingSubtext.trim())) {
        payload.landing_copy = {}
        if (landingHeadline.trim()) payload.landing_copy.headline = landingHeadline.trim()
        if (landingSubtext.trim()) payload.landing_copy.subtext = landingSubtext.trim()
      }
    }

    router.post(route('downloads.store'), payload, {
      preserveScroll: false,
      onSuccess: () => {
        setSubmitting(false)
        onClose()
        if (onSuccess) onSuccess()
        // Backend redirects to downloads.index; Inertia follows. Toast via flash from backend.
      },
      onError: (errors) => {
        setSubmitting(false)
        const msg =
          (typeof errors?.message === 'string' && errors.message) ||
          (Array.isArray(errors?.message) && errors.message[0]) ||
          (Array.isArray(errors?.expires_at) && errors.expires_at[0]) ||
          (Array.isArray(errors?.name) && errors.name[0]) ||
          (Array.isArray(errors?.password) && errors.password[0]) ||
          (Array.isArray(errors?.landing_copy) && errors.landing_copy[0]) ||
          'Could not create download.'
        setError(msg)
      },
      onFinish: () => setSubmitting(false),
    })
  }

  const toggleUser = (userId) => {
    setAllowedUserIds((prev) =>
      prev.includes(userId) ? prev.filter((id) => id !== userId) : [...prev, userId]
    )
  }

  if (!open) return null

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto">
      <div className="flex min-h-full items-end justify-center p-0 text-center sm:items-center sm:p-0">
        <div
          className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
          onClick={() => !submitting && onClose()}
          aria-hidden="true"
        />
        <div className="relative transform overflow-hidden rounded-none bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:rounded-lg">
          <div className="absolute right-0 top-0 pr-4 pt-4">
            <button
              type="button"
              className="rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
              onClick={onClose}
              disabled={submitting}
            >
              <span className="sr-only">Close</span>
              <XMarkIcon className="h-6 w-6" />
            </button>
          </div>

          <form onSubmit={handleSubmit} className="px-4 pb-6 pt-6 sm:px-6 sm:pt-8">
            <h2 className="text-lg font-semibold leading-6 text-gray-900">Create Download</h2>

            {inlineMessage && (
              <div className="mt-4 rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-800" role="alert">
                {inlineMessage}
              </div>
            )}

            {/* Section 1 — Summary */}
            <div className="mt-4">
              <p className="text-sm text-gray-600">
                {bucketCount} asset{bucketCount !== 1 ? 's' : ''} selected
              </p>
              {(loadingPreview || previewItems.length > 0) && (
                <div className="mt-2 flex flex-wrap gap-2">
                  {loadingPreview ? (
                    <span className="flex h-12 items-center text-sm text-gray-500">Loading…</span>
                  ) : (
                    previewItems.slice(0, 12).map((item) => {
                    const thumbUrl = item.thumbnail_url || item.final_thumbnail_url || item.preview_thumbnail_url
                    return (
                      <div
                        key={item.id}
                        className="h-12 w-12 flex-shrink-0 overflow-hidden rounded border border-gray-200 bg-gray-50"
                      >
                        {thumbUrl ? (
                          <img src={thumbUrl} alt="" className="h-full w-full object-cover" />
                        ) : (
                          <span className="flex h-full w-full items-center justify-center text-xs text-gray-400">—</span>
                        )}
                      </div>
                    )
                  })
                  )}
                  {!loadingPreview && previewItems.length > 12 && (
                    <span className="flex h-12 items-center text-xs text-gray-500">+{previewItems.length - 12} more</span>
                  )}
                </div>
              )}
            </div>

            {/* Section 2 — Download Name */}
            <div className="mt-4">
              <label htmlFor="create-download-name" className="block text-sm font-medium text-gray-700">
                Download name
              </label>
              {canRename ? (
                <input
                  id="create-download-name"
                  type="text"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                  placeholder={defaultDownloadName(brandName)}
                />
              ) : (
                <>
                  <input
                    id="create-download-name"
                    type="text"
                    value={name}
                    readOnly
                    disabled
                    className="mt-1 block w-full rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-gray-500 sm:text-sm"
                  />
                  <p className="mt-1 text-xs text-gray-500">Upgrade to rename downloads</p>
                </>
              )}
            </div>

            {/* Access — default visible: Public only */}
            <div className="mt-4">
              <label className="block text-sm font-medium text-gray-700">Access</label>
              <p className="mt-1 text-sm text-gray-600">
                {accessMode === 'public' && 'Public (anyone with the link)'}
                {accessMode === 'brand' && 'Brand members'}
                {accessMode === 'company' && 'Company members'}
                {accessMode === 'users' && 'Specific users'}
              </p>
            </div>

            {/* Advanced options — collapsible */}
            <div className="mt-4">
              <button
                type="button"
                onClick={() => setAdvancedOpen((v) => !v)}
                className="flex w-full items-center justify-between rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-left text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1"
                aria-expanded={advancedOpen}
              >
                <span>Advanced options</span>
                {advancedOpen ? (
                  <ChevronUpIcon className="h-5 w-5 text-gray-400" />
                ) : (
                  <ChevronDownIcon className="h-5 w-5 text-gray-400" />
                )}
              </button>
              {advancedOpen && (
                <div className="mt-3 space-y-4 rounded-md border border-gray-200 bg-gray-50/50 p-4">
                  {/* Access scope (all options) */}
                  <div>
                    <label className="block text-sm font-medium text-gray-700">Access</label>
                    <div className="mt-2 space-y-2">
                      <label className="flex items-center gap-2">
                        <input
                          type="radio"
                          name="access_mode"
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
                            name="access_mode"
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
                            name="access_mode"
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
                            name="access_mode"
                            value="users"
                            checked={accessMode === 'users'}
                            onChange={() => setAccessMode('users')}
                            className="h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500"
                          />
                          <span className="text-sm text-gray-700">Specific users</span>
                        </label>
                      )}
                      {!canBrand && !canCompany && !canUsers && (
                        <p className="text-xs text-gray-500">Upgrade to restrict access</p>
                      )}
                    </div>
                    {accessMode === 'users' && canUsers && (
                      <div className="mt-3 max-h-40 overflow-y-auto rounded border border-gray-200 bg-white p-2">
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

                  {/* Expiration */}
                  <div>
                    <label className="block text-sm font-medium text-gray-700">Expiration</label>
                    {canNonExpiring && (
                      <label className="mt-2 flex items-center gap-2">
                        <input
                          type="checkbox"
                          checked={neverExpires}
                          onChange={(e) => setNeverExpires(e.target.checked)}
                          className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                        />
                        <span className="text-sm text-gray-700">Never expires</span>
                      </label>
                    )}
                    {!neverExpires && (
                      <>
                        {canExtend ? (
                          <>
                            <input
                              type="date"
                              value={expiresAt}
                              onChange={(e) => setExpiresAt(e.target.value)}
                              min={new Date().toISOString().slice(0, 10)}
                              max={maxDate()}
                              className={`mt-1 block w-full rounded-md px-3 py-2 shadow-sm sm:text-sm ${(pageErrors?.expires_at) ? 'border-red-500 focus:border-red-500 focus:ring-red-500' : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500'} bg-white`}
                              aria-invalid={!!pageErrors?.expires_at}
                              aria-describedby={pageErrors?.expires_at ? 'expires_at-error' : undefined}
                            />
                            {pageErrors?.expires_at && (
                              <p id="expires_at-error" className="mt-1 text-sm text-red-600">
                                {typeof pageErrors.expires_at === 'string' ? pageErrors.expires_at : (Array.isArray(pageErrors.expires_at) ? pageErrors.expires_at[0] : null)}
                              </p>
                            )}
                          </>
                        ) : (
                          <div className="mt-1">
                            <input
                              type="text"
                              readOnly
                              disabled
                              value={expiresAt ? `Expires on ${expiresAt}` : '—'}
                              className="block w-full rounded-md border border-gray-200 bg-gray-100 px-3 py-2 text-gray-500 sm:text-sm"
                            />
                            <p className="mt-1 text-xs text-gray-500">Downloads expire after 30 days</p>
                          </div>
                        )}
                      </>
                    )}
                  </div>

                  {/* Password (optional) */}
                  {canPasswordProtect && (
                    <div>
                      <label htmlFor="create-download-password" className="block text-sm font-medium text-gray-700">
                        Password (optional)
                      </label>
                      <input
                        id="create-download-password"
                        type="password"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        placeholder="Leave blank for no password"
                        className="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        autoComplete="new-password"
                      />
                      <p className="mt-1 text-xs text-gray-500">Recipients will need this password to access the download link.</p>
                    </div>
                  )}

                  {/* Enable landing page */}
                  {canBrandDownload && (
                    <div className="space-y-3 rounded border border-gray-200 bg-white p-3">
                      <label className="flex items-center gap-2">
                        <input
                          type="checkbox"
                          checked={usesLandingPage}
                          onChange={(e) => setUsesLandingPage(e.target.checked)}
                          className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                        />
                        <span className="text-sm font-medium text-gray-700">Enable landing page for this download</span>
                      </label>
                      <p className="text-xs text-gray-500">When unchecked, the link goes straight to the ZIP. When checked, recipients see a branded landing page (using your brand’s logo and colors).</p>
                      {usesLandingPage && (
                        <div className="space-y-3 border-t border-gray-200 pt-2">
                          <div>
                            <label htmlFor="create-download-headline" className="block text-xs font-medium text-gray-600">Headline (optional)</label>
                            <input
                              id="create-download-headline"
                              type="text"
                              value={landingHeadline}
                              onChange={(e) => setLandingHeadline(e.target.value)}
                              placeholder="e.g. Press Kit"
                              className="mt-1 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"
                            />
                          </div>
                          <div>
                            <label htmlFor="create-download-subtext" className="block text-xs font-medium text-gray-600">Subtext (optional)</label>
                            <input
                              id="create-download-subtext"
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
                </div>
              )}
            </div>

            {error && (
              <div className="mt-4 rounded-md bg-red-50 p-3">
                <p className="text-sm text-red-700">{error}</p>
              </div>
            )}

            {/* Section 5 — CTA */}
            <div className="mt-6 flex justify-end gap-3">
              <button
                type="button"
                onClick={onClose}
                disabled={submitting}
                className="rounded-md bg-white px-4 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={submitting}
                className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {submitting ? 'Creating…' : 'Create Download'}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  )
}
