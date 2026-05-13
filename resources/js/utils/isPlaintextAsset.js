/**
 * True when the asset is a registry plain-text / CSV row (.txt, .csv and canonical MIME types).
 *
 * @param {{ id?: string|number, mime_type?: string|null, original_filename?: string|null }|null|undefined} asset
 * @returns {boolean}
 */
export function isPlaintextRegistryAsset(asset) {
    if (!asset?.id) {
        return false
    }
    const ext = (asset.original_filename || '').split('.').pop()?.toLowerCase() || ''
    if (ext === 'txt' || ext === 'csv') {
        return true
    }
    const m = String(asset.mime_type || '').toLowerCase()
    return (
        m === 'text/plain' ||
        m === 'text/csv' ||
        m === 'application/csv' ||
        m === 'text/comma-separated-values'
    )
}
