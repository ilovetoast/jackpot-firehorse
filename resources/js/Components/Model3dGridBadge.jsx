import { usePage } from '@inertiajs/react'
import { CubeIcon } from '@heroicons/react/24/outline'
import { isRegistryModel3dAsset } from '../utils/resolveAsset3dPreviewImage'

/**
 * Small non-interfering label for registry `model_*` assets (Phase 5A poster path).
 */
export default function Model3dGridBadge({ asset, className = '' }) {
    const { dam_file_types: damFileTypes } = usePage().props
    if (!isRegistryModel3dAsset(asset, damFileTypes)) {
        return null
    }
    return (
        <span
            className={`pointer-events-none absolute right-2 top-2 z-30 inline-flex items-center gap-0.5 rounded-md bg-slate-900/70 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white shadow-sm ring-1 ring-white/15 backdrop-blur-sm dark:bg-slate-950/80 ${className}`}
            aria-hidden
        >
            <CubeIcon className="h-3 w-3 shrink-0 opacity-90" />
            3D
        </span>
    )
}
