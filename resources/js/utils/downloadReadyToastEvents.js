/** Window events for global “download ready” toast (no React imports — safe from circular deps). */

export const DOWNLOAD_PROCESSING_EVENT = 'jackpot:download-processing'
export const DOWNLOAD_SUPPRESS_READY_TOAST_EVENT = 'jackpot:suppress-download-ready-toast'

export function emitDownloadProcessingStarted(downloadId) {
  if (typeof window === 'undefined' || !downloadId) return
  window.dispatchEvent(new CustomEvent(DOWNLOAD_PROCESSING_EVENT, { detail: { id: downloadId } }))
}

export function emitSuppressDownloadReadyToast(downloadId) {
  if (typeof window === 'undefined' || !downloadId) return
  window.dispatchEvent(new CustomEvent(DOWNLOAD_SUPPRESS_READY_TOAST_EVENT, { detail: { id: downloadId } }))
}
