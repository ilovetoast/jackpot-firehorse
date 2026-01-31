import { Link } from '@inertiajs/react'

/**
 * C12: Collection-only access landing.
 * TODO (future): Brand these pages with the collection's brand (logo, primary color, nav theme)
 * so the experience feels on-brand even for collection-only users.
 */
export default function AccessLanding({ collection, brand }) {
    const viewUrl = route('collection-invite.view', { collection: collection.id })

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
                            className="inline-flex w-full justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
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
