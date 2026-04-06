import { Link } from '@inertiajs/react'

/**
 * @param {{ open: boolean, onClose: () => void }} props
 */
export default function CreatorModuleRequiredModal({ open, onClose }) {
    if (!open) return null

    return (
        <div className="fixed inset-0 z-[220] flex items-end justify-center p-4 sm:items-center">
            <button type="button" className="absolute inset-0 bg-black/75 backdrop-blur-sm" aria-label="Close" onClick={onClose} />
            <div
                className="relative w-full max-w-md overflow-hidden rounded-2xl border border-white/10 bg-[#12141a]/95 p-6 shadow-2xl backdrop-blur-2xl"
                role="dialog"
                aria-modal="true"
            >
                <h2 className="text-lg font-semibold text-white">Creator module required</h2>
                <p className="mt-3 text-sm text-white/60">
                    Enable the Creator add-on for your workspace to invite and track creators.
                </p>
                <div className="mt-6 flex flex-wrap justify-end gap-2">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-xl border border-white/15 px-4 py-2.5 text-sm font-medium text-white/80 transition hover:bg-white/5"
                    >
                        Close
                    </button>
                    <Link
                        href={typeof route === 'function' ? route('companies.settings') : '/app/companies/settings'}
                        className="inline-flex rounded-xl bg-white/90 px-4 py-2.5 text-sm font-semibold text-gray-900 transition hover:bg-white"
                    >
                        Open company settings
                    </Link>
                </div>
            </div>
        </div>
    )
}
