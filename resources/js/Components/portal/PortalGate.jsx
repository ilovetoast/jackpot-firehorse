import { useState } from 'react'
import { Link } from '@inertiajs/react'

/**
 * Wraps a portal section that is plan-gated. When locked:
 *   - Controls are visible but non-interactive (users see what they'd get)
 *   - A persistent lock banner replaces pointer-events-none
 *   - Clicking any control inside opens an upsell modal
 */
export default function PortalGate({ allowed, planName = 'Pro', feature, children }) {
    const [showUpsell, setShowUpsell] = useState(false)

    if (allowed) {
        return <>{children}</>
    }

    return (
        <>
            {/* Gate overlay — intercepts clicks on locked controls */}
            <div
                className="relative"
                onClick={(e) => {
                    if (e.target.closest('a[href]')) return
                    e.preventDefault()
                    e.stopPropagation()
                    setShowUpsell(true)
                }}
            >
                {/* Persistent lock banner */}
                <div className="mb-4 flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
                    <svg className="h-4 w-4 text-gray-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" strokeWidth="2" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                    </svg>
                    <span className="text-sm text-gray-600">
                        Available on <span className="font-semibold">{planName}</span> plan
                    </span>
                    <button
                        type="button"
                        onClick={(e) => { e.stopPropagation(); setShowUpsell(true) }}
                        className="ml-auto text-xs font-medium text-indigo-600 hover:text-indigo-500"
                    >
                        See plans
                    </button>
                </div>

                {/* Visible but muted controls */}
                <div className="opacity-50 pointer-events-none select-none" aria-disabled="true">
                    {children}
                </div>
            </div>

            {/* Upsell Modal */}
            {showUpsell && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div className="fixed inset-0 bg-black/50" onClick={() => setShowUpsell(false)} />
                    <div className="relative bg-white rounded-2xl shadow-2xl max-w-sm w-full p-8 text-center">
                        <div className="mx-auto mb-4 h-12 w-12 rounded-full bg-indigo-50 flex items-center justify-center">
                            <svg className="h-6 w-6 text-indigo-600" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z" />
                            </svg>
                        </div>
                        <h3 className="text-lg font-semibold text-gray-900 mb-2">
                            Unlock {feature || 'this feature'}
                        </h3>
                        <p className="text-sm text-gray-500 mb-6">
                            Upgrade to {planName} to access {feature ? feature.toLowerCase() : 'this feature'} and more advanced brand controls.
                        </p>
                        <div className="flex flex-col gap-2">
                            <Link
                                href="/app/billing"
                                className="inline-flex justify-center items-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-gray-800 transition-colors"
                            >
                                View Plans
                            </Link>
                            <button
                                type="button"
                                onClick={() => setShowUpsell(false)}
                                className="text-sm text-gray-500 hover:text-gray-700"
                            >
                                Maybe later
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </>
    )
}
