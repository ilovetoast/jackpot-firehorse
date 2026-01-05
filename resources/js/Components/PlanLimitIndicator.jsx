import { Link } from '@inertiajs/react'

export default function PlanLimitIndicator({ current, max, label, className = '' }) {
    const isLimitReached = current >= max
    const isUnlimited = max === Number.MAX_SAFE_INTEGER || max === 2147483647

    if (isUnlimited) {
        return null // Don't show indicator for unlimited plans
    }

    return (
        <div className={`rounded-lg border-2 border-indigo-600 bg-indigo-600 shadow-lg ${className}`}>
            <div className="flex items-center justify-between p-5">
                <div className="flex items-center">
                    <div className="flex-shrink-0">
                        <svg className="h-6 w-6 text-white" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fillRule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
                        </svg>
                    </div>
                    <div className="ml-4">
                        <p className="text-base font-semibold text-white">
                            {isLimitReached ? (
                                <>
                                    {label} limit reached ({current}/{max})
                                </>
                            ) : (
                                <>
                                    {label}: {current}/{max}
                                </>
                            )}
                        </p>
                    </div>
                </div>
                {isLimitReached && (
                    <Link 
                        href="/app/billing" 
                        className="ml-4 rounded-md bg-white px-4 py-2 text-sm font-semibold text-indigo-600 shadow-sm hover:bg-indigo-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white transition-colors"
                    >
                        Upgrade →
                    </Link>
                )}
            </div>
        </div>
    )
}
