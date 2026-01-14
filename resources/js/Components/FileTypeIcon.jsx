/**
 * Phase 3.0C: FileTypeIcon Component
 * 
 * Displays a consistent file-type icon based on file extension or MIME type.
 * Replaces green placeholders with standard file-type icons.
 * 
 * @param {Object} props
 * @param {string} props.fileExtension - File extension (e.g., 'pdf', 'jpg')
 * @param {string} props.mimeType - MIME type (e.g., 'application/pdf')
 * @param {string} props.className - CSS classes for the icon container
 * @param {string} props.iconClassName - CSS classes for the SVG icon
 * @param {string} props.size - Icon size ('sm', 'md', 'lg', or number in pixels)
 */
export default function FileTypeIcon({ 
    fileExtension, 
    mimeType, 
    className = '',
    iconClassName = 'text-gray-400',
    size = 'md'
}) {
    // Normalize extension
    const ext = (fileExtension || '').toLowerCase().replace(/^\./, '')
    
    // Normalize MIME type
    const mime = (mimeType || '').toLowerCase()
    
    // Size mapping
    const sizeMap = {
        sm: 'h-6 w-6',
        md: 'h-10 w-10',
        lg: 'h-16 w-16',
    }
    const sizeClass = typeof size === 'number' ? `h-${size} w-${size}` : sizeMap[size] || sizeMap.md
    
    // Determine icon based on extension or MIME type
    const getIcon = () => {
        // PDF
        if (ext === 'pdf' || mime === 'application/pdf') {
            return (
                <svg className={`${sizeClass} ${iconClassName}`} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
            )
        }
        
        // Design files (PSD, AI, EPS, Sketch)
        if (['psd', 'psb', 'ai', 'eps', 'sketch'].includes(ext) || 
            mime.includes('photoshop') || mime.includes('illustrator')) {
            return (
                <svg className={`${sizeClass} ${iconClassName}`} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h11.25c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />
                </svg>
            )
        }
        
        // Video files
        if (['mp4', 'mov', 'avi', 'webm', 'mkv'].includes(ext) || mime.startsWith('video/')) {
            return (
                <svg className={`${sizeClass} ${iconClassName}`} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M15.91 11.672a.375.375 0 010 .656l-5.603 3.113a.375.375 0 01-.557-.328V8.887c0-.286.307-.466.557-.327l5.603 3.112z" />
                </svg>
            )
        }
        
        // Audio files
        if (['mp3', 'wav', 'ogg', 'flac', 'aac'].includes(ext) || mime.startsWith('audio/')) {
            return (
                <svg className={`${sizeClass} ${iconClassName}`} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 9V4.5M9 9H4.5M9 9l3-3m0 0v11.25m0-11.25l-3 3M9 20.25v-5.25m0 5.25h4.5M9 15h4.5m-4.5 0l3-3m4.5 3l3-3m0 0v-.375c0-.621-.504-1.125-1.125-1.125H18.75m-1.5 0H17.25m-1.5 0c-.621 0-1.125.504-1.125 1.125v.375m0 0H21m-3.75 0H21" />
                </svg>
            )
        }
        
        // Archive files
        if (['zip', 'rar', '7z', 'tar', 'gz'].includes(ext) || mime.includes('zip') || mime.includes('archive')) {
            return (
                <svg className={`${sizeClass} ${iconClassName}`} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
            )
        }
        
        // Image files (fallback - should use thumbnail if available)
        if (mime.startsWith('image/')) {
            return (
                <svg className={`${sizeClass} ${iconClassName}`} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-6.364-8.318l6.364-6.364m2.25 0l2.25 2.25M9.75 9.75l4.5-4.5M12 12.75l-3-3" />
                </svg>
            )
        }
        
        // Generic document icon (default)
        return (
            <svg className={`${sizeClass} ${iconClassName}`} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
            </svg>
        )
    }
    
    return (
        <div className={className}>
            {getIcon()}
        </div>
    )
}
