/**
 * Phase D4 — Public download page (trust signals).
 * D7: Password-protected view + branded skinning (logo, accent, headline, subtext).
 * D10.1: When show_landing_layout is true, the SAME branded wrapper is used for ALL states.
 * D-SHARE: Share page (state=ready) with download info, Download button, Share via email, plan-based footer.
 */
import { useState } from 'react'
import { useForm } from '@inertiajs/react'
import { usePage } from '@inertiajs/react'

function formatBytes(bytes) {
  if (bytes == null || bytes === 0) return null
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}

function formatExpiration(iso) {
  if (!iso) return null
  try {
    const d = new Date(iso)
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
  } catch {
    return null
  }
}

export default function DownloadsPublic({
  state = 'processing',
  message = '',
  password_required = false,
  download_id = null,
  unlock_url = '',
  show_landing_layout = false,
  branding_options = {},
  zip_time_estimate = null,
  zip_progress_percentage = null,
  zip_chunk_index = 0,
  zip_total_chunks = null,
  is_zip_stalled = false,
  download_title = '',
  asset_count = 0,
  zip_size_bytes = null,
  expires_at = null,
  file_url = '',
  share_email_url = '',
  show_jackpot_promo = false,
  footer_promo = {},
}) {
  const { errors: pageErrors = {}, flash = {} } = usePage().props
  const isProcessing = state === 'processing'
  const isReady = state === 'ready'
  const isPasswordRequired = password_required && unlock_url && download_id

  const [shareModalOpen, setShareModalOpen] = useState(false)

  const accentColor = branding_options?.accent_color || '#4F46E5'
  const overlayColor = branding_options?.overlay_color || accentColor
  const logoUrl = branding_options?.logo_url || null
  const headline = branding_options?.headline || null
  const subtext = branding_options?.subtext || null
  const backgroundImageUrl = branding_options?.background_image_url || null
  const hasBranding = show_landing_layout || logoUrl || headline || subtext || accentColor

  const { data, setData, post, processing, error: formError } = useForm({
    password: '',
  })

  const shareForm = useForm({
    to: '',
    message: '',
  })

  const handleUnlock = (e) => {
    e.preventDefault()
    post(unlock_url)
  }

  const handleShareEmail = (e) => {
    e.preventDefault()
    shareForm.post(share_email_url, {
      preserveScroll: true,
      onSuccess: () => {
        setShareModalOpen(false)
        shareForm.reset()
      },
    })
  }

  const isLandingLayout = show_landing_layout
  const containerClass = isLandingLayout
    ? 'min-h-screen flex flex-col items-center justify-center px-4 py-12 relative'
    : hasBranding
      ? 'min-h-screen flex flex-col items-center justify-center px-4 py-12'
      : 'min-h-screen bg-gray-50 flex flex-col items-center justify-center px-4 py-12'

  const accentStyle = hasBranding ? { '--accent': accentColor } : {}

  const backgroundSection = isLandingLayout && (
    <>
      <div
        className={backgroundImageUrl ? 'fixed inset-0 bg-cover bg-center bg-no-repeat' : 'absolute inset-0'}
        style={backgroundImageUrl ? { backgroundImage: `url(${backgroundImageUrl})` } : { backgroundColor: overlayColor }}
        aria-hidden
      />
      <div
        className={backgroundImageUrl ? 'fixed inset-0' : 'absolute inset-0'}
        style={{
          backgroundColor: overlayColor,
          opacity: backgroundImageUrl ? 0.65 : 1,
        }}
        aria-hidden
      />
    </>
  )

  const textClass = (ready) =>
    isLandingLayout && !ready ? 'text-white drop-shadow-md' : 'text-gray-900'
  const textMutedClass = (ready) =>
    isLandingLayout && !ready ? 'text-white/95 drop-shadow' : 'text-gray-600'

  return (
    <div className={containerClass} style={accentStyle}>
      {backgroundSection}
      <div className={`max-w-md w-full text-center ${isLandingLayout ? 'relative z-10' : ''}`}>
        {hasBranding && logoUrl && (
          <div className="flex justify-center mb-6">
            <img
              src={logoUrl}
              alt=""
              className={`object-contain object-center ${isLandingLayout ? 'max-h-24 w-auto' : 'h-12'}`}
              style={isLandingLayout ? { maxHeight: '96px' } : {}}
            />
          </div>
        )}
        {hasBranding && headline && (
          <h2 className={`font-medium mb-1 ${isLandingLayout ? 'text-xl text-white drop-shadow-md' : 'text-lg text-gray-900'}`}>{headline}</h2>
        )}
        {hasBranding && subtext && (
          <p className={`mb-6 ${isLandingLayout ? 'text-sm text-white/95 drop-shadow' : 'text-sm text-gray-600'}`}>{subtext}</p>
        )}

        {isPasswordRequired && (
          <>
            <h1 className={`font-semibold text-xl ${textClass(false)}`}>This download is protected.</h1>
            <p className={`mt-2 text-sm ${textMutedClass(false)}`}>{message || 'Enter the password to continue.'}</p>
            <form onSubmit={handleUnlock} className="mt-6 text-left">
              <label htmlFor="password" className="block text-sm font-medium text-gray-700">
                Password
              </label>
              <input
                id="password"
                type="password"
                value={data.password}
                onChange={(e) => setData('password', e.target.value)}
                className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                autoComplete="current-password"
                autoFocus
              />
              {(pageErrors?.password || formError) && (
                <p className="mt-1 text-sm text-red-600">
                  {Array.isArray(pageErrors.password) ? pageErrors.password[0] : pageErrors.password || formError}
                </p>
              )}
              <button
                type="submit"
                disabled={processing}
                className="mt-4 w-full rounded-md border border-transparent px-4 py-2 text-sm font-medium text-white shadow-sm focus:ring-2 focus:ring-offset-2"
                style={{ backgroundColor: accentColor || '#4F46E5' }}
              >
                {processing ? 'Checking…' : 'Unlock'}
              </button>
            </form>
          </>
        )}

        {isReady && (
          <>
            <h1 className={`text-xl font-semibold ${textClass(true)}`}>
              {download_title || 'Your download is ready'}
            </h1>
            <div className={`mt-4 text-sm ${textMutedClass(true)} space-y-1`}>
              {asset_count != null && asset_count > 0 && (
                <p>{asset_count} {asset_count === 1 ? 'file' : 'files'}</p>
              )}
              {zip_size_bytes != null && zip_size_bytes > 0 && (
                <p>{formatBytes(zip_size_bytes)}</p>
              )}
              {expires_at && (
                <p>Expires {formatExpiration(expires_at)}</p>
              )}
            </div>
            <div className="mt-6 space-y-3">
              <a
                href={file_url}
                className="inline-block w-full rounded-md border border-transparent px-4 py-3 text-sm font-medium text-white shadow-sm focus:ring-2 focus:ring-offset-2"
                style={{ backgroundColor: accentColor || '#4F46E5' }}
              >
                Download
              </a>
              {share_email_url && (
                <button
                  type="button"
                  onClick={() => setShareModalOpen(true)}
                  className="w-full rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                  Share via email
                </button>
              )}
            </div>
            {flash.share_email_sent && (
              <p className="mt-4 text-sm text-green-600">Email sent.</p>
            )}
          </>
        )}

        {!isPasswordRequired && !isReady && (
          <>
            {isProcessing && (
              <div className="flex justify-center mb-6">
                <svg
                  className="animate-spin h-12 w-12"
                  xmlns="http://www.w3.org/2000/svg"
                  fill="none"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                  style={isLandingLayout ? { color: 'rgba(255,255,255,0.9)' } : { color: accentColor || '#4F46E5' }}
                >
                  <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                  <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                </svg>
              </div>
            )}
            <h1 className={`text-xl font-semibold ${textClass(false)}`}>
              {isProcessing ? "We're preparing your download"
                : state === 'not_found' ? 'Download not found'
                : state === 'expired' ? 'This download has expired'
                : state === 'revoked' ? 'This download has been revoked'
                : state === 'access_denied' ? 'Access denied'
                : state === 'failed' ? 'Download not available'
                : 'Download not available'}
            </h1>
            <p className={`mt-2 text-sm ${textMutedClass(false)}`}>{message}</p>
            {isProcessing && (
              <p className={`mt-4 text-xs ${isLandingLayout ? 'text-white/80' : 'text-gray-500'}`}>
                {is_zip_stalled
                  ? "This download is taking longer than usual. We're still working on it."
                  : zip_total_chunks != null && zip_total_chunks > 0
                    ? `Preparing download — ${zip_chunk_index ?? 0} of ${zip_total_chunks} chunks complete`
                    : zip_progress_percentage != null
                      ? `Preparing download — about ${zip_progress_percentage}% complete`
                      : zip_time_estimate
                        ? `Based on similar downloads, this usually takes ~${zip_time_estimate.min_minutes}–${zip_time_estimate.max_minutes} minutes.`
                        : 'Preparing download. Larger downloads may take a few minutes.'}
              </p>
            )}
          </>
        )}
      </div>

      {/* Footer: plan-based promo (FREE only) */}
      {show_jackpot_promo && footer_promo && (footer_promo.line1 || footer_promo.line2) && (
        <footer className={`mt-auto py-6 text-center text-sm ${isLandingLayout ? 'text-white/80 relative z-10' : 'text-gray-500'}`}>
          {footer_promo.line1 && <p>{footer_promo.line1}</p>}
          {footer_promo.line2 && footer_promo.signup_url && (
            <p className="mt-1">
              <a href={footer_promo.signup_url} className={isLandingLayout ? 'text-white underline' : 'text-indigo-600 hover:text-indigo-500'}>
                {footer_promo.line2}
              </a>
            </p>
          )}
          {footer_promo.line2 && !footer_promo.signup_url && <p className="mt-1">{footer_promo.line2}</p>}
        </footer>
      )}

      {/* Share via email modal */}
      {shareModalOpen && share_email_url && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" aria-modal="true" role="dialog">
          <div className="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
            <h3 className="text-lg font-semibold text-gray-900">Share via email</h3>
            <form onSubmit={handleShareEmail} className="mt-4 space-y-4">
              <div>
                <label htmlFor="share-to" className="block text-sm font-medium text-gray-700">To</label>
                <input
                  id="share-to"
                  type="email"
                  required
                  value={shareForm.data.to}
                  onChange={(e) => shareForm.setData('to', e.target.value)}
                  className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                  placeholder="email@example.com"
                />
                {(shareForm.errors?.to || pageErrors?.to) && (
                  <p className="mt-1 text-sm text-red-600">{shareForm.errors.to || pageErrors.to}</p>
                )}
              </div>
              <div>
                <label htmlFor="share-message" className="block text-sm font-medium text-gray-700">Optional message</label>
                <textarea
                  id="share-message"
                  rows={3}
                  value={shareForm.data.message}
                  onChange={(e) => shareForm.setData('message', e.target.value)}
                  className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                  placeholder="Add a personal note..."
                />
              </div>
              <div className="flex gap-3 justify-end">
                <button
                  type="button"
                  onClick={() => setShareModalOpen(false)}
                  className="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={shareForm.processing}
                  className="rounded-md border border-transparent px-4 py-2 text-sm font-medium text-white shadow-sm"
                  style={{ backgroundColor: accentColor || '#4F46E5' }}
                >
                  {shareForm.processing ? 'Sending…' : 'Send'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
