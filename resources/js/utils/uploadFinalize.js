/**
 * POST /app/assets/upload/finalize always returns 200; each manifest item has its own status.
 * @param {object} finalData - Parsed JSON body
 * @returns {{ asset_id: number|string, thumbnail_url?: string|null, final_thumbnail_url?: string|null }}
 */
export function parseUploadFinalizeResult(finalData) {
  const r = finalData?.results?.[0]
  if (!r) {
    throw new Error('No response from upload server')
  }
  const id = r.asset_id ?? r.id
  if (r.status === 'success' && id != null) {
    return {
      asset_id: id,
      thumbnail_url: r.thumbnail_url ?? r.final_thumbnail_url ?? null,
      final_thumbnail_url: r.final_thumbnail_url ?? null,
    }
  }

  const err = r.error
  let message = 'Upload could not be completed'
  if (typeof err === 'string') {
    message = err
  } else if (err && typeof err === 'object') {
    message = err.message ?? err.error ?? message
    if (typeof message !== 'string') {
      message = JSON.stringify(err)
    }
  } else if (r.status === 'error' && typeof r.error === 'string') {
    message = r.error
  }
  throw new Error(message)
}

/**
 * Browsers sometimes omit `file.type` for SVG; S3/metadata need a correct Content-Type for processing.
 * @param {File} file
 * @returns {string}
 */
export function uploadPutContentType(file) {
  if (file?.type) return file.type
  const name = file?.name?.toLowerCase() ?? ''
  if (name.endsWith('.svg')) return 'image/svg+xml'
  return 'application/octet-stream'
}
