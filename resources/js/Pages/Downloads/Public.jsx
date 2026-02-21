/**
 * Phase D4 — Public download page (trust signals).
 * D7: Password-protected view + branded skinning.
 * D10.1: Public layout pulls design from Brand Settings > Public Pages (logo, accent color, background).
 * Clean design: white background, big typography, brand name/logo, CTAs in brand colors.
 */
import { useForm } from '@inertiajs/react'
import { usePage } from '@inertiajs/react'
import { useCdn403Recovery } from '../../hooks/useCdn403Recovery'

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
  show_jackpot_promo = false,
  footer_promo = {},
  cdn_domain = null,
}) {
  useCdn403Recovery(cdn_domain)
  const { errors: pageErrors = {}, flash = {} } = usePage().props
  const isProcessing = state === 'processing'
  const isReady = state === 'ready'
  const isPasswordRequired = password_required && unlock_url && download_id

  const accentColor = branding_options?.accent_color || '#4F46E5'
  const logoUrl = branding_options?.logo_url || null
  const brandName = branding_options?.brand_name || 'Jackpot'
  const backgroundImageUrl = branding_options?.background_image_url || null
  const useWhiteBackground = branding_options?.use_white_background !== false

  const { data, setData, post, processing, error: formError } = useForm({
    password: '',
  })

  const handleUnlock = (e) => {
    e.preventDefault()
    post(unlock_url)
  }

  // Clean public layout: 50/50 split on md+, left = content, right = accent panel with radiant animation
  const hasBackgroundImage = !!backgroundImageUrl && !useWhiteBackground

  const textClass = 'text-gray-900'
  const textMutedClass = 'text-gray-500'

  return (
    <div className="min-h-screen flex flex-col bg-white">
      <div className="flex-1 flex flex-col md:flex-row">
      {/* Left: download content — full width on mobile (centered), 50% on md+ (block centered, text left-aligned) */}
      <div className="flex-1 flex flex-col items-center justify-center px-6 py-12 md:px-12 lg:px-16">
        <div className="w-full max-w-md md:max-w-xl text-center md:text-left">
        {/* Brand: logo or name */}
        <div className="mb-10 text-center md:text-left">
          {logoUrl ? (
            <img
              src={logoUrl}
              alt=""
              className="mx-auto md:mx-0 h-14 w-auto max-h-20 object-contain object-center"
            />
          ) : (
            <span className="text-xl font-semibold tracking-tight text-gray-900 font-sans">{brandName}</span>
          )}
        </div>

        {isPasswordRequired && (
          <>
            <h1 className="mt-2 text-2xl font-semibold text-gray-900 font-sans">
              This download is protected
            </h1>
            <p className="mt-2 text-sm text-gray-500">{message || 'Enter the password to continue.'}</p>
            <form onSubmit={handleUnlock} className="mt-8 max-w-xs mx-auto md:mx-0 text-left">
              <label htmlFor="password" className="block text-sm font-medium text-gray-700">
                Password
              </label>
              <input
                id="password"
                type="password"
                value={data.password}
                onChange={(e) => setData('password', e.target.value)}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2.5 shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 sm:text-sm"
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
                className="mt-4 w-full rounded-lg px-4 py-3 text-sm font-semibold text-white shadow-sm focus:ring-2 focus:ring-offset-2"
                style={{ backgroundColor: accentColor }}
              >
                {processing ? 'Checking…' : 'Unlock'}
              </button>
            </form>
          </>
        )}

        {isReady && (
          <>
            <h1 className="mt-3 text-2xl font-medium tracking-tight text-gray-900 sm:text-3xl font-sans">
              {download_title || 'Your download is ready'}
            </h1>
            <div className="mt-4 flex flex-wrap items-center justify-center md:justify-start gap-x-3 gap-y-1 text-xs text-gray-500 font-sans">
              {asset_count != null && asset_count > 0 && (
                <span>{asset_count} {asset_count === 1 ? 'file' : 'files'}</span>
              )}
              {zip_size_bytes != null && zip_size_bytes > 0 && (
                <>
                  {(asset_count != null && asset_count > 0) && <span className="text-gray-300">·</span>}
                  <span>{formatBytes(zip_size_bytes)}</span>
                </>
              )}
              {expires_at && (
                <>
                  {((asset_count != null && asset_count > 0) || (zip_size_bytes != null && zip_size_bytes > 0)) && <span className="text-gray-300">·</span>}
                  <span>Expires {formatExpiration(expires_at)}</span>
                </>
              )}
            </div>
            <div className="mt-8">
              <a
                href={file_url}
                className="inline-flex w-full items-center justify-center rounded-lg px-4 py-3 text-sm font-semibold text-white shadow-sm focus:ring-2 focus:ring-offset-2"
                style={{ backgroundColor: accentColor }}
              >
                Download
              </a>
            </div>
          </>
        )}

        {!isPasswordRequired && !isReady && (
          <>
            {isProcessing && (
              <div className="flex justify-center md:justify-start mb-6">
                <svg
                  className="animate-spin h-12 w-12"
                  xmlns="http://www.w3.org/2000/svg"
                  fill="none"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                  style={{ color: accentColor }}
                >
                  <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                  <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                </svg>
              </div>
            )}
            <h1 className={`text-2xl font-semibold font-sans ${textClass}`}>
              {isProcessing ? "We're preparing your download"
                : state === 'not_found' ? 'Download not found'
                : state === 'expired' ? 'This download has expired'
                : state === 'revoked' ? 'This download has been revoked'
                : state === 'access_denied' ? 'Access denied'
                : state === 'failed' ? 'Download not available'
                : 'Download not available'}
            </h1>
            <p className={`mt-2 text-sm ${textMutedClass}`}>{message}</p>
            {isProcessing && (
              <p className="mt-4 text-xs text-gray-500">
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
      </div>

      {/* Right: accent panel — background visual (overlay blend) + fluid blobs + shimmer, hidden on mobile */}
      <div
        className="hidden md:flex md:w-1/2 lg:w-1/2 relative overflow-hidden"
        style={{ backgroundColor: accentColor }}
        aria-hidden
      >
        {/* Background visual — covers accent area, blend mode overlay */}
        {hasBackgroundImage && (
          <div
            className="absolute inset-0 bg-cover bg-center bg-no-repeat mix-blend-overlay opacity-60"
            style={{ backgroundImage: `url(${backgroundImageUrl})` }}
          />
        )}
        {/* Fluid morphing blobs — organic, abstract shapes */}
        <div
          className="absolute w-[70%] h-[70%] top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 download-accent-blob-1 pointer-events-none"
          style={{ background: 'radial-gradient(circle, rgba(255,255,255,0.2) 0%, transparent 70%)' }}
        />
        <div
          className="absolute w-[55%] h-[55%] top-1/3 right-1/4 download-accent-blob-2 pointer-events-none"
          style={{ background: 'radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 65%)' }}
        />
        {/* Subtle jackpot shimmer — soft sweep across */}
        <div
          className="absolute inset-0 w-1/3 download-accent-shimmer pointer-events-none"
          style={{
            background: 'linear-gradient(105deg, transparent 0%, rgba(255,255,255,0.12) 45%, rgba(255,255,255,0.08) 55%, transparent 100%)',
          }}
        />
      </div>
      </div>

      {/* Footer: plan-based promo (FREE only) */}
      {show_jackpot_promo && footer_promo && (footer_promo.line1 || footer_promo.line2) && (
        <footer className="mt-auto py-6 text-center text-sm text-gray-500">
          {footer_promo.line1 && <p>{footer_promo.line1}</p>}
          {footer_promo.line2 && footer_promo.signup_url && (
            <p className="mt-1">
              <a href={footer_promo.signup_url} className="text-indigo-600 hover:text-indigo-500 underline">
                {footer_promo.line2}
              </a>
            </p>
          )}
          {footer_promo.line2 && !footer_promo.signup_url && <p className="mt-1">{footer_promo.line2}</p>}
        </footer>
      )}
    </div>
  )
}
