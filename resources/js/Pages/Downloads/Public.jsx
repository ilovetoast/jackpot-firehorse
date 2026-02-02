/**
 * Phase D4 — Public download page (trust signals).
 * D7: Password-protected view + branded skinning (logo, accent, headline, subtext).
 * D10.1: When uses_landing_page — full-screen background (random image or solid), overlay, content stack (logo 64–96px, headline, subtext, CTA).
 * When uses_landing_page is true, the same branded layout is used for ALL states: landing, 404, 403, password entry, waiting for ZIP.
 */
import { useForm } from '@inertiajs/react'
import { usePage } from '@inertiajs/react'

export default function DownloadsPublic({
  state = 'processing',
  message = '',
  password_required = false,
  download_id = null,
  unlock_url = '',
  uses_landing_page = false,
  show_landing_layout = false,
  branding_options = {},
}) {
  const { errors: pageErrors = {} } = usePage().props
  const isProcessing = state === 'processing'
  const isPasswordRequired = password_required && unlock_url && download_id

  const accentColor = branding_options?.accent_color || '#4F46E5'
  const overlayColor = branding_options?.overlay_color || accentColor
  const logoUrl = branding_options?.logo_url || null
  const headline = branding_options?.headline || null
  const subtext = branding_options?.subtext || null
  const backgroundImageUrl = branding_options?.background_image_url || null
  const hasBranding = logoUrl || headline || subtext || accentColor

  const { data, setData, post, processing, error: formError } = useForm({
    password: '',
  })

  const handleUnlock = (e) => {
    e.preventDefault()
    post(unlock_url)
  }

  // D10.1: Landing page layout — full-screen background, 60–75% overlay, centered content stack.
  // show_landing_layout is true when download has "Use landing page" OR brand has landing settings (so 404/revoked/etc. stay on-brand).
  const isLandingLayout = show_landing_layout
  const containerClass = isLandingLayout
    ? 'min-h-screen flex flex-col items-center justify-center px-4 py-12 relative'
    : hasBranding
      ? 'min-h-screen flex flex-col items-center justify-center px-4 py-12'
      : 'min-h-screen bg-gray-50 flex flex-col items-center justify-center px-4 py-12'

  const accentStyle = hasBranding ? { '--accent': accentColor } : {}

  // Full-page background: fixed viewport layer when image is selected, so it stays full-screen behind content
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

  return (
    <div className={containerClass} style={accentStyle}>
      {backgroundSection}
      <div className={`max-w-md w-full text-center ${isLandingLayout ? 'relative z-10' : ''}`}>
        {hasBranding && logoUrl && (
          <div className={`flex justify-center ${isLandingLayout ? 'mb-6' : 'mb-6'}`}>
            <img
              src={logoUrl}
              alt=""
              className={`object-contain object-center ${isLandingLayout ? 'max-h-24 w-auto' : 'h-12'}`}
              style={isLandingLayout ? { maxHeight: '96px' } : {}}
            />
          </div>
        )}
        {hasBranding && headline && (
          <h2 className={`font-medium text-gray-900 mb-1 ${isLandingLayout ? 'text-xl text-white drop-shadow-md' : 'text-lg'}`}>{headline}</h2>
        )}
        {hasBranding && subtext && (
          <p className={`mb-6 ${isLandingLayout ? 'text-sm text-white/95 drop-shadow' : 'text-sm text-gray-600'}`}>{subtext}</p>
        )}

        {isPasswordRequired && (
          <>
            <h1 className={`font-semibold ${isLandingLayout ? 'text-xl text-white drop-shadow-md' : 'text-xl text-gray-900'}`}>This download is protected.</h1>
            <p className={`mt-2 text-sm ${isLandingLayout ? 'text-white/95 drop-shadow' : 'text-gray-600'}`}>{message || 'Enter the password to continue.'}</p>
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
                style={accentColor ? { backgroundColor: accentColor } : { backgroundColor: '#4F46E5' }}
              >
                {processing ? 'Checking…' : 'Unlock'}
              </button>
            </form>
          </>
        )}

        {!isPasswordRequired && (
          <>
            {isProcessing && (
              <div className="flex justify-center mb-6">
                <svg
                  className="animate-spin h-12 w-12"
                  xmlns="http://www.w3.org/2000/svg"
                  fill="none"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                  style={isLandingLayout ? { color: 'rgba(255,255,255,0.9)' } : (accentColor ? { color: accentColor } : { color: '#4F46E5' })}
                >
                  <circle
                    className="opacity-25"
                    cx="12"
                    cy="12"
                    r="10"
                    stroke="currentColor"
                    strokeWidth="4"
                  />
                  <path
                    className="opacity-75"
                    fill="currentColor"
                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                  />
                </svg>
              </div>
            )}
            <h1 className={`text-xl font-semibold ${isLandingLayout ? 'text-white drop-shadow-md' : 'text-gray-900'}`}>
              {isProcessing
                ? "We're preparing your download"
                : state === 'not_found'
                  ? 'Download not found'
                  : state === 'expired'
                    ? 'This download has expired'
                    : state === 'revoked'
                      ? 'This download has been revoked'
                      : state === 'access_denied'
                        ? 'Access denied'
                        : state === 'failed'
                          ? 'Download not available'
                          : 'Download not available'}
            </h1>
            <p className={`mt-2 text-sm ${isLandingLayout ? 'text-white/95 drop-shadow' : 'text-gray-600'}`}>{message}</p>
            {isProcessing && (
              <p className={`mt-4 text-xs ${isLandingLayout ? 'text-white/80' : 'text-gray-500'}`}>
                Large downloads may take a few moments to prepare.
              </p>
            )}
          </>
        )}
      </div>
    </div>
  )
}
