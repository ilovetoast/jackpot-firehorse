import { useState, useEffect, useCallback } from 'react'

export default function AssetLightbox({ asset, theme, onClose }) {
    const [visible, setVisible] = useState(false)
    const [loaded, setLoaded] = useState(false)

    useEffect(() => {
        requestAnimationFrame(() => setVisible(true))
    }, [])

    const handleClose = useCallback(() => {
        setVisible(false)
        setTimeout(onClose, 200)
    }, [onClose])

    useEffect(() => {
        const handleKey = (e) => {
            if (e.key === 'Escape') handleClose()
        }
        document.addEventListener('keydown', handleKey)
        return () => document.removeEventListener('keydown', handleKey)
    }, [handleClose])

    const isImage = asset.mime_type?.startsWith('image/')
    const isVideo = asset.mime_type?.startsWith('video/')
    const previewUrl = asset.preview_url || asset.thumbnail_url

    return (
        <div
            className={`fixed inset-0 z-50 flex items-center justify-center transition-all duration-200 ${
                visible ? 'opacity-100' : 'opacity-0'
            }`}
            onClick={handleClose}
        >
            {/* Backdrop */}
            <div className="absolute inset-0 bg-black/80 backdrop-blur-sm" />

            {/* Content */}
            <div
                className={`relative z-10 max-w-4xl w-full mx-4 transition-all duration-300 ${
                    visible ? 'scale-100 translate-y-0' : 'scale-95 translate-y-4'
                }`}
                onClick={(e) => e.stopPropagation()}
            >
                {/* Close button */}
                <button
                    onClick={handleClose}
                    className="absolute -top-12 right-0 text-white/50 hover:text-white/80 transition-colors"
                >
                    <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>

                {/* Preview */}
                <div className="rounded-xl overflow-hidden bg-white/[0.03] border border-white/[0.08]">
                    <div className="relative flex items-center justify-center min-h-[300px] max-h-[70vh] bg-black/40">
                        {!loaded && previewUrl && (
                            <div className="absolute inset-0 flex items-center justify-center">
                                <div
                                    className="h-8 w-8 rounded-full border-2 border-white/20 border-t-white/60 animate-spin"
                                />
                            </div>
                        )}

                        {previewUrl && isImage && (
                            <img
                                src={previewUrl}
                                alt={asset.title}
                                className={`max-w-full max-h-[70vh] object-contain transition-opacity duration-300 ${
                                    loaded ? 'opacity-100' : 'opacity-0'
                                }`}
                                onLoad={() => setLoaded(true)}
                            />
                        )}

                        {previewUrl && isVideo && (
                            <div className="flex items-center justify-center p-12">
                                <svg className="w-16 h-16 text-white/30" fill="none" viewBox="0 0 24 24" strokeWidth="1" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 010 1.972l-11.54 6.347a1.125 1.125 0 01-1.667-.986V5.653z" />
                                </svg>
                            </div>
                        )}

                        {previewUrl && !isImage && !isVideo && (
                            <div className="flex items-center justify-center p-12">
                                <svg className="w-16 h-16 text-white/20" fill="none" viewBox="0 0 24 24" strokeWidth="1" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                </svg>
                            </div>
                        )}
                    </div>

                    {/* Info bar */}
                    <div className="px-5 py-4 flex items-center justify-between border-t border-white/[0.06]">
                        <div className="min-w-0">
                            <p className="text-sm font-medium text-white/90 truncate">
                                {asset.title}
                            </p>
                            <p className="text-xs text-white/40 mt-0.5 truncate">
                                {asset.original_filename}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    )
}
