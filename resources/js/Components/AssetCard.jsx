/**
 * AssetCard Component
 * 
 * Displays a single asset in the grid view.
 * Shows thumbnail preview, title, and file type badge.
 * 
 * @param {Object} props
 * @param {Object} props.asset - Asset object with title, thumbnail_url, file_extension, etc.
 * @param {Function} props.onClick - Optional click handler to open asset detail drawer
 */
export default function AssetCard({ asset, onClick = null }) {
    // Extract file extension from original_filename, file_extension, or mime_type
    const getFileExtension = () => {
        // First try explicit file_extension field
        if (asset.file_extension && asset.file_extension.trim()) {
            return asset.file_extension.toUpperCase()
        }
        
        // Then try to extract from original_filename
        if (asset.original_filename) {
            const parts = asset.original_filename.split('.')
            if (parts.length > 1) {
                const ext = parts[parts.length - 1].trim()
                if (ext) {
                    return ext.toUpperCase()
                }
            }
        }
        
        // Fallback: derive from mime_type
        if (asset.mime_type) {
            // Extract from mime types like "image/jpeg" -> "JPEG", "application/pdf" -> "PDF"
            const mimeParts = asset.mime_type.split('/')
            if (mimeParts.length === 2) {
                const mimeSubtype = mimeParts[1].toLowerCase()
                // Map common mime types to extensions
                const mimeToExt = {
                    'jpeg': 'JPG',
                    'jpg': 'JPG',
                    'png': 'PNG',
                    'gif': 'GIF',
                    'webp': 'WEBP',
                    'svg+xml': 'SVG',
                    'tiff': 'TIF',
                    'bmp': 'BMP',
                    'pdf': 'PDF',
                    'zip': 'ZIP',
                    'x-zip-compressed': 'ZIP',
                    'mpeg': 'MPG',
                    'mp4': 'MP4',
                    'quicktime': 'MOV',
                    'x-msvideo': 'AVI',
                    'vnd.adobe.photoshop': 'PSD',
                    'vnd.adobe.illustrator': 'AI',
                }
                if (mimeToExt[mimeSubtype]) {
                    return mimeToExt[mimeSubtype]
                }
                // Fallback: use mime subtype uppercase
                return mimeSubtype.split('+')[0].toUpperCase()
            }
        }
        
        // Last resort: return generic "FILE" (should rarely happen)
        return 'FILE'
    }
    
    const fileExtension = getFileExtension()
    
    // Determine if asset is an image based on mime_type or extension
    const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'tiff', 'tif']
    const extLower = fileExtension.toLowerCase()
    const isImage = asset.mime_type?.startsWith('image/') || imageExtensions.includes(extLower)
    
    // Get thumbnail URL or preview URL
    const thumbnailUrl = asset.thumbnail_url || asset.preview_url || asset.url || asset.storage_url
    
    // Get appropriate icon for non-image files
    const getFileIcon = () => {
        if (extLower === 'pdf') {
            return (
                <svg className="h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
            )
        } else if (['psd', 'psb', 'ai', 'eps', 'sketch'].includes(extLower)) {
            return (
                <svg className="h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h11.25c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />
                </svg>
            )
        } else {
            // Generic document icon
            return (
                <svg className="h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
            )
        }
    }
    
    const handleClick = () => {
        if (onClick) {
            onClick(asset)
        }
    }

    return (
        <div
            onClick={handleClick}
            className="group relative bg-white rounded-lg border border-gray-200 overflow-hidden hover:shadow-md hover:border-gray-300 transition-all duration-200 cursor-pointer"
        >
            {/* Thumbnail container - fixed aspect ratio (4:3) */}
            <div className="aspect-[4/3] bg-gray-100 relative overflow-hidden">
                {isImage && thumbnailUrl ? (
                    <img
                        src={thumbnailUrl}
                        alt={asset.title || asset.original_filename || 'Asset'}
                        className="w-full h-full object-cover"
                        loading="lazy"
                    />
                ) : (
                    // Fallback icon for non-image files
                    <div className="w-full h-full flex items-center justify-center bg-gray-50">
                        <div className="text-center">
                            <div className="mx-auto mb-2">
                                {getFileIcon()}
                            </div>
                            <span className="text-xs font-medium text-gray-500 uppercase">
                                {fileExtension}
                            </span>
                        </div>
                    </div>
                )}
                
                {/* File type badge overlay - top right */}
                <div className="absolute top-2 right-2">
                    <span className="inline-flex items-center rounded-md bg-black/60 backdrop-blur-sm px-2 py-1 text-xs font-medium text-white uppercase tracking-wide">
                        {fileExtension}
                    </span>
                </div>
            </div>
            
            {/* Title section */}
            <div className="p-3 border-t border-gray-100">
                <h3 className="text-sm font-medium text-gray-900 truncate group-hover:text-indigo-600 transition-colors">
                    {asset.title || asset.original_filename || 'Untitled Asset'}
                </h3>
            </div>
        </div>
    )
}
