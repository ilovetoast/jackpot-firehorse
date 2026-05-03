/**
 * Thumbnail area for upload queue rows: local blob preview, progressive server URLs, overlays, or file-type fallback.
 */

import { useEffect, useState } from 'react'
import { ArrowPathIcon } from '@heroicons/react/24/outline'
import FileTypeIcon from '../FileTypeIcon'

/**
 * @param {Object} props
 * @param {string|null} props.localPreviewUrl — blob URL (registry); stays until server raster fades in
 * @param {string|null} props.serverPreviewUrl
 * @param {string|null} props.serverFinalThumbUrl
 * @param {boolean} props.previewLoadError
 * @param {boolean} props.trayPreviewUnsupported
 * @param {string} props.extension
 * @param {string} props.mimeType
 * @param {string} props.badgeKey
 * @param {string} props.lifecycle
 * @param {string|null|undefined} props.pipelineThumbStatus
 * @param {string} props.statusIconClass
 * @param {function} props.onImageError
 * @param {function} [props.onReleaseLocalBlob]
 * @param {'none'|'upload'|'saving'|'server_preview'} props.overlayMode
 */
export default function UploadTrayThumbnail({
    localPreviewUrl,
    serverPreviewUrl,
    serverFinalThumbUrl,
    previewLoadError,
    trayPreviewUnsupported,
    extension,
    mimeType,
    badgeKey,
    lifecycle = '',
    pipelineThumbStatus,
    statusIconClass,
    onImageError,
    onReleaseLocalBlob,
    overlayMode = 'none',
}) {
    const serverRasterUrl = serverFinalThumbUrl || serverPreviewUrl || null
    const [serverDecoded, setServerDecoded] = useState(false)

    useEffect(() => {
        setServerDecoded(false)
    }, [serverRasterUrl])

    const serverVisible = Boolean(serverRasterUrl && serverDecoded)
    const showLocal = Boolean(localPreviewUrl && !previewLoadError)

    const waitingUnsupportedServer =
        trayPreviewUnsupported &&
        !previewLoadError &&
        lifecycle === 'finalized' &&
        (pipelineThumbStatus === 'pending' ||
            pipelineThumbStatus === 'processing' ||
            pipelineThumbStatus === 'skipped')

    const extensionBadge = (extension || '')
        .replace(/^\./, '')
        .slice(0, 8)
        .toUpperCase()

    if (trayPreviewUnsupported && !previewLoadError) {
        const ariaLabel = waitingUnsupportedServer
            ? `Generating library preview for ${extensionBadge || 'file'}`
            : `No live preview for ${extensionBadge || 'this file type'}; a library thumbnail will appear when processing finishes`

        return (
            <div
                className="relative flex h-11 w-11 shrink-0 flex-col items-center justify-center gap-0.5 overflow-hidden rounded-lg border border-slate-200 bg-slate-50 px-0.5 shadow-sm"
                role="img"
                aria-label={ariaLabel}
                title={
                    waitingUnsupportedServer
                        ? 'Generating a preview in the library for this file type.'
                        : 'This type cannot be previewed in the browser here. A thumbnail will appear in the library when ready.'
                }
            >
                <FileTypeIcon
                    fileExtension={extension}
                    mimeType={mimeType}
                    size="sm"
                    iconClassName="shrink-0 text-slate-500"
                />
                {waitingUnsupportedServer ? (
                    <ArrowPathIcon className="h-3 w-3 shrink-0 animate-spin text-slate-400" aria-hidden />
                ) : (
                    <span className="max-w-[2.75rem] truncate text-center font-mono text-[8px] font-semibold leading-none tracking-tight text-slate-500">
                        {extensionBadge || 'FILE'}
                    </span>
                )}
            </div>
        )
    }

    const showRasterStack = showLocal || serverRasterUrl || (previewLoadError && serverRasterUrl)

    if (showRasterStack) {
        return (
            <div className="relative h-11 w-11 shrink-0 overflow-hidden rounded-lg border border-gray-200 bg-gray-100 shadow-sm">
                {showLocal && (
                    <img
                        src={localPreviewUrl}
                        alt=""
                        loading="lazy"
                        decoding="async"
                        className={`absolute inset-0 h-full w-full object-contain transition-opacity duration-200 ${
                            serverVisible ? 'opacity-0' : 'opacity-100'
                        }`}
                        onError={onImageError}
                    />
                )}

                {serverRasterUrl && (
                    <img
                        src={serverRasterUrl}
                        alt=""
                        loading="lazy"
                        decoding="async"
                        className={`absolute inset-0 h-full w-full object-contain transition-opacity duration-300 ${
                            serverDecoded ? 'opacity-100' : 'opacity-0'
                        }`}
                        onLoad={() => {
                            setServerDecoded(true)
                            onReleaseLocalBlob?.()
                        }}
                        onError={() => {
                            /* keep local; next poll may fix */
                        }}
                    />
                )}

                {!showLocal && serverRasterUrl && !serverDecoded && (
                    <div className="absolute inset-0 flex items-center justify-center bg-gray-100">
                        <ArrowPathIcon className="h-4 w-4 animate-spin text-gray-400" aria-hidden />
                    </div>
                )}

                {previewLoadError && !serverRasterUrl && (
                    <div
                        className="absolute inset-0 flex flex-col items-center justify-center bg-amber-50/95 px-0.5 text-center"
                        role="img"
                        aria-label="Waiting for library thumbnail after a preview error"
                        title="Could not load a quick preview; a library thumbnail will appear when ready."
                    >
                        <FileTypeIcon
                            fileExtension={extension}
                            mimeType={mimeType}
                            size="sm"
                            iconClassName="text-amber-700"
                        />
                        <span className="mt-0.5 max-w-full truncate font-mono text-[8px] font-semibold uppercase leading-tight text-amber-950">
                            {(extension || '').replace(/^\./, '').slice(0, 8) || '…'}
                        </span>
                    </div>
                )}

                {overlayMode !== 'none' && (showLocal || serverRasterUrl) && (
                    <div
                        className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-0.5 bg-black/35 px-0.5 text-center backdrop-blur-[0.5px]"
                        title={
                            overlayMode === 'upload'
                                ? 'Uploading file data'
                                : overlayMode === 'server_preview'
                                  ? 'Generating library preview'
                                  : 'Saving to library'
                        }
                    >
                        <ArrowPathIcon className="h-3.5 w-3.5 shrink-0 animate-spin text-white drop-shadow" aria-hidden />
                        <span className="text-[9px] font-semibold leading-tight text-white drop-shadow">
                            {overlayMode === 'upload'
                                ? 'Uploading…'
                                : overlayMode === 'server_preview'
                                  ? 'Preview…'
                                  : 'Saving…'}
                        </span>
                    </div>
                )}
            </div>
        )
    }

    if (previewLoadError) {
        return (
            <div
                className="flex h-11 w-11 shrink-0 flex-col items-center justify-center rounded-lg border border-amber-100 bg-amber-50/90 px-1.5 text-center shadow-sm"
                title="A library preview will appear after upload is processed."
            >
                <FileTypeIcon
                    fileExtension={extension}
                    mimeType={mimeType}
                    size="sm"
                    iconClassName="text-amber-700"
                />
                <span className="mt-1 text-[10px] font-semibold leading-tight text-amber-950">Preview soon</span>
            </div>
        )
    }

    return (
        <div className="relative flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-gray-200 bg-gray-50 shadow-sm">
            <FileTypeIcon fileExtension={extension} mimeType={mimeType} size="sm" iconClassName={statusIconClass} />
            {badgeKey === 'processing_preview' && (
                <div
                    className="absolute inset-0 flex flex-col items-center justify-center gap-0.5 bg-white/90 px-0.5 text-center"
                    title="Saving to library and generating previews"
                >
                    <ArrowPathIcon className="h-4 w-4 shrink-0 animate-spin text-violet-600" aria-hidden />
                    <span className="text-[9px] font-medium leading-tight text-violet-900">Saving…</span>
                </div>
            )}
        </div>
    )
}
