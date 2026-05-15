import { useMemo } from 'react'
import { Link } from '@inertiajs/react'
import {
    getWorkspacePrimaryActionButtonColors,
    getSolidFillButtonForegroundHex,
} from '../../utils/colorUtils'

/**
 * C12: Collection-only access landing.
 * TODO (future): Brand these pages with the collection's brand (logo, primary color, nav theme)
 * so the experience feels on-brand even for collection-only users.
 */
export default function AccessLanding({ collection, brand }) {
    const viewUrl = route('collection-invite.view', { collection: collection.id })

    const cta = useMemo(() => {
        if (!brand) {
            const resting = '#334155'
            const hover = '#1e293b'
            return { resting, hover, fg: getSolidFillButtonForegroundHex(resting) }
        }
        const { resting, hover } = getWorkspacePrimaryActionButtonColors(brand)
        return { resting, hover, fg: getSolidFillButtonForegroundHex(resting) }
    }, [brand])

    return (
        <div className="flex min-h-full flex-1 flex-col justify-center bg-gray-50 px-6 py-12 lg:px-8">
            <div className="sm:mx-auto sm:w-full sm:max-w-md">
                <div className="rounded-lg bg-white p-8 shadow-sm ring-1 ring-gray-200">
                    <h2 className="text-2xl font-bold text-gray-900">You have access to this collection</h2>
                    <p className="mt-2 text-sm text-gray-600">
                        <strong>{collection?.name}</strong>
                        {brand?.name && (
                            <span className="text-gray-500 font-normal"> from {brand.name}</span>
                        )}
                    </p>
                    <div className="mt-6">
                        <Link
                            href={viewUrl}
                            className="inline-flex w-full justify-center rounded-md px-3 py-2 text-sm font-semibold shadow-sm transition-colors bg-[var(--al-cta)] text-[var(--al-cta-fg)] hover:bg-[var(--al-cta-hover)]"
                            style={{
                                ['--al-cta']: cta.resting,
                                ['--al-cta-hover']: cta.hover,
                                ['--al-cta-fg']: cta.fg,
                            }}
                        >
                            View collection
                        </Link>
                    </div>
                    <p className="mt-4 text-xs text-gray-500">
                        You have collection-only access. You can view and download assets in this collection.
                    </p>
                </div>
            </div>
        </div>
    )
}
