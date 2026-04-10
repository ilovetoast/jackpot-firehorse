/**
 * Amber notice when pipeline analysis / thumbnails / embeddings are still in progress,
 * or when thumbnails exist but visual metadata failed validation.
 */
export default function MetadataAnalysisRunningBanner({
    metadataHealth,
    analysisStatus,
    thumbnailStatus,
    className = '',
}) {
    if (!metadataHealth || metadataHealth.is_complete || analysisStatus === 'complete') {
        return null
    }

    return (
        <div className={`rounded-md border border-amber-200 bg-amber-50 p-4 ${className}`.trim()}>
            {thumbnailStatus === 'completed' && metadataHealth?.visual_metadata_ready === false ? (
                <>
                    <div className="font-medium text-amber-800">Visual metadata invalid</div>
                    <div className="mt-1 text-sm text-amber-700">
                        Thumbnail exists but dimensions or metadata are missing or invalid. Re-run analysis or contact
                        support.
                    </div>
                </>
            ) : (
                <>
                    <div className="font-medium text-amber-800">System analysis still running</div>
                    <div className="mt-1 text-sm text-amber-700">
                        Dominant colors, embeddings, or thumbnails may not have completed. Re-run analysis will be
                        available once the pipeline finishes.
                    </div>
                </>
            )}
        </div>
    )
}
