/**
 * Single-asset view page. Used when opening an asset in a new tab (e.g. from CollectionOnlyView).
 * Supports collection_only back link and download.
 */
import { usePage } from '@inertiajs/react'
import { Link } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'

export default function AssetView({ asset }) {
    const { auth } = usePage().props
    const isImage = asset?.mime_type?.startsWith('image/')
    const isVideo = asset?.mime_type?.startsWith('video/')

    return (
        <div className="min-h-screen bg-gray-100">
            <AppNav brand={auth?.activeBrand} tenant={null} />

            <div className="max-w-5xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                {asset?.collection_only && asset?.collection && (
                    <Link
                        href={route('collection-invite.landing', { collection: asset.collection.id })}
                        className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 mb-4"
                    >
                        ← Back to collection
                    </Link>
                )}

                <div className="bg-white rounded-lg shadow overflow-hidden">
                    <div className="p-4 border-b border-gray-200 flex items-center justify-between flex-wrap gap-2">
                        <h1 className="text-lg font-semibold text-gray-900 truncate">
                            {asset?.title || asset?.original_filename || 'Asset'}
                        </h1>
                        {asset?.download_url && (
                            <a
                                href={asset.download_url}
                                className="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"
                            >
                                Download
                            </a>
                        )}
                    </div>

                    <div className="p-4 flex flex-col justify-center items-center min-h-[400px] bg-gray-50 gap-4">
                        {!asset ? (
                            <p className="text-gray-500">Asset not found.</p>
                        ) : (
                            <>
                                {/* Always show thumbnail when available (any file type) */}
                                {asset.thumbnail_url && (
                                    <div className="flex-shrink-0 max-w-full max-h-[60vh] flex justify-center">
                                        <img
                                            src={asset.thumbnail_url}
                                            alt={asset.title || asset.original_filename || 'Asset thumbnail'}
                                            className="max-w-full max-h-[60vh] w-auto h-auto object-contain rounded border border-gray-200 shadow-sm"
                                        />
                                    </div>
                                )}
                                {/* Full preview for images (thumbnail already shown above if no larger preview); message for others */}
                                {isImage && asset.thumbnail_url ? null : isVideo ? (
                                    <p className="text-gray-500 text-center">
                                        Video preview not available in this view. Use Download to get the file.
                                    </p>
                                ) : !asset.thumbnail_url ? (
                                    <div className="text-center text-gray-500">
                                        <p className="mb-2">No thumbnail available.</p>
                                        {asset.download_url && (
                                            <a
                                                href={asset.download_url}
                                                className="text-indigo-600 hover:text-indigo-800 font-medium"
                                            >
                                                Download file
                                            </a>
                                        )}
                                    </div>
                                ) : (
                                    <div className="text-center text-gray-500">
                                        <p className="mb-2">Preview not available for this file type.</p>
                                        {asset.download_url && (
                                            <a
                                                href={asset.download_url}
                                                className="text-indigo-600 hover:text-indigo-800 font-medium"
                                            >
                                                Download file
                                            </a>
                                        )}
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                </div>
            </div>
        </div>
    )
}
