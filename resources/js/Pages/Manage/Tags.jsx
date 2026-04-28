import { Link } from '@inertiajs/react'
import ManageLayout from '../../layouts/ManageLayout'
import ManageTagsWorkspace from '../../Components/Manage/ManageTagsWorkspace'
import { WorkbenchPageIntro } from '../../components/brand-workspace/workbenchPatterns'
import { productButtonPrimary } from '../../components/brand-workspace/brandWorkspaceTokens'

export default function ManageTags({
    brand,
    tag_filter,
    assets_missing_tags_count,
    can_view_assets,
    can_purge_tags,
}) {
    const libraryHref =
        typeof route === 'function'
            ? route('assets.index', { missing_tags: 1 })
            : '/app/assets?missing_tags=1'

    const clearFilterHref =
        typeof route === 'function' ? route('manage.tags') : '/app/manage/tags'

    return (
        <ManageLayout title="Tags — Manage" activeSection="tags">
            <div>
                <WorkbenchPageIntro
                    title="Tags"
                    description={`Canonical tags on assets in ${brand?.name ?? 'this brand'}. Remove a tag everywhere it appears when you retire or fix a label.`}
                />

                {tag_filter === 'missing' && (
                    <div className="mt-6 space-y-4">
                        <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                            <p className="font-medium text-amber-950">Assets missing tags</p>
                            <p className="mt-1 text-amber-900/90">
                                {assets_missing_tags_count !== null ? (
                                    <>
                                        <span className="font-semibold tabular-nums">{assets_missing_tags_count}</span>{' '}
                                        visible library {assets_missing_tags_count === 1 ? 'asset has' : 'assets have'} no
                                        tags yet.
                                    </>
                                ) : (
                                    'Count unavailable.'
                                )}
                            </p>
                        </div>
                        {can_view_assets ? (
                            <div className="flex flex-wrap items-center gap-3">
                                <Link href={libraryHref} className={`${productButtonPrimary} no-underline`}>
                                    Open library — missing tags
                                </Link>
                                <Link
                                    href={clearFilterHref}
                                    className="text-sm font-medium text-slate-600 hover:text-slate-900"
                                >
                                    Clear filter
                                </Link>
                            </div>
                        ) : (
                            <p className="text-sm text-gray-600">
                                You do not have permission to open the asset library. Ask an admin for asset access.
                            </p>
                        )}
                    </div>
                )}

                {brand?.id != null && (
                    <ManageTagsWorkspace brandId={brand.id} canPurge={Boolean(can_purge_tags)} />
                )}
            </div>
        </ManageLayout>
    )
}
