/**
 * Notice when pipeline analysis / thumbnails / embeddings are still in progress,
 * or when thumbnails exist but visual metadata failed validation.
 *
 * Typography matches drawer callouts (slate); “in progress” uses a light sky tint.
 */
import { CpuChipIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline'
import DrawerReviewCallout from './DrawerReviewCallout'

export default function MetadataAnalysisRunningBanner({
    metadataHealth,
    analysisStatus,
    thumbnailStatus,
    className = '',
}) {
    if (!metadataHealth || metadataHealth.is_complete || analysisStatus === 'complete') {
        return null
    }

    const isVisualInvalid = thumbnailStatus === 'completed' && metadataHealth?.visual_metadata_ready === false

    return (
        <DrawerReviewCallout
            variant={isVisualInvalid ? 'neutral' : 'in_progress'}
            title={isVisualInvalid ? 'Visual metadata invalid' : 'System analysis still running'}
            titleIcon={isVisualInvalid ? ExclamationTriangleIcon : CpuChipIcon}
            className={className}
        >
            {isVisualInvalid ? (
                <p className="text-sm leading-relaxed text-slate-600">
                    Thumbnail exists but dimensions or metadata are missing or invalid. Re-run analysis or contact
                    support.
                </p>
            ) : (
                <p className="text-sm leading-relaxed text-slate-600">
                    Dominant colors, embeddings, or thumbnails may not have completed. Re-run analysis will be available
                    once the pipeline finishes.
                </p>
            )}
        </DrawerReviewCallout>
    )
}
