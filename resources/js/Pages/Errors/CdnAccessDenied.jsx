import { Head } from '@inertiajs/react'

/**
 * Branded 403 page for CloudFront access denied.
 *
 * Configure CloudFront custom error response:
 * - HTTP error code: 403
 * - Response page path: /cdn-access-denied
 * - HTTP response code: 403
 * - TTL: 0
 *
 * Do not expose S3/AWS error details.
 */
export default function CdnAccessDenied({ logoUrl = null }) {
    const handleReload = () => {
        window.location.reload()
    }

    return (
        <>
            <Head title="Access Expired" />
            <div className="min-h-screen bg-gray-50 flex flex-col items-center justify-center px-4">
                <div className="max-w-md w-full text-center">
                    {logoUrl && (
                        <img
                            src={logoUrl}
                            alt="Logo"
                            className="mx-auto h-12 w-auto object-contain mb-8"
                            onError={(e) => { e.target.style.display = 'none' }}
                        />
                    )}
                    <div className="rounded-lg bg-white p-8 shadow-sm ring-1 ring-gray-200">
                        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-amber-100 mb-4">
                            <svg
                                className="h-6 w-6 text-amber-600"
                                fill="none"
                                viewBox="0 0 24 24"
                                strokeWidth="1.5"
                                stroke="currentColor"
                            >
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"
                                />
                            </svg>
                        </div>
                        <h1 className="text-xl font-semibold text-gray-900">
                            Access expired
                        </h1>
                        <p className="mt-2 text-sm text-gray-600">
                            Your access to this content has expired. Please reload the page to continue.
                        </p>
                        <button
                            type="button"
                            onClick={handleReload}
                            className="mt-6 inline-flex items-center justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                        >
                            Reload page
                        </button>
                    </div>
                </div>
            </div>
        </>
    )
}
