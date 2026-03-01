import { Link, usePage } from '@inertiajs/react'
import JackpotLogo from '../Components/JackpotLogo'

export default function Contact({ plan }) {
    const { auth, flash, signup_enabled } = usePage().props
    const isEnterprise = plan === 'enterprise'

    return (
        <div className="bg-white min-h-screen">
            <nav className="bg-white shadow-sm relative z-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex h-16 justify-between">
                        <div className="flex items-center">
                            <Link href="/" className="flex items-center">
                                <JackpotLogo className="h-8 w-auto" />
                            </Link>
                        </div>
                        <div className="flex items-center gap-4">
                            {auth?.user ? (
                                <Link
                                    href="/app/dashboard"
                                    className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                >
                                    Dashboard
                                </Link>
                            ) : (
                                <>
                                    <Link href="/login" className="text-sm font-semibold text-gray-900 hover:text-gray-700">
                                        Login
                                    </Link>
                                    {signup_enabled !== false && (
                                        <Link
                                            href="/signup"
                                            className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                        >
                                            Sign up
                                        </Link>
                                    )}
                                </>
                            )}
                        </div>
                    </div>
                </div>
            </nav>

            <main className="mx-auto max-w-2xl px-4 py-16 sm:px-6 lg:px-8">
                {flash?.info && (
                    <div className="mb-6 rounded-md bg-blue-50 p-4 text-sm text-blue-700">
                        {flash.info}
                    </div>
                )}

                <h1 className="text-3xl font-bold text-gray-900">
                    {isEnterprise ? 'Contact Sales' : 'Contact Us'}
                </h1>
                <p className="mt-4 text-gray-600">
                    {isEnterprise
                        ? 'Enterprise is a custom plan with dedicated infrastructure. Our team will reach out to discuss your needs and provide a tailored quote.'
                        : 'Have questions? We\'d love to hear from you.'}
                </p>

                <div className="mt-8 rounded-lg border border-gray-200 bg-gray-50 p-6">
                    <p className="text-sm text-gray-600">
                        Email us at{' '}
                        <a
                            href={`mailto:sales@${typeof window !== 'undefined' ? window.location.hostname : 'jackpot.local'}?subject=${encodeURIComponent(isEnterprise ? 'Enterprise Plan Inquiry' : 'Contact Request')}`}
                            className="font-medium text-indigo-600 hover:text-indigo-500"
                        >
                            sales@{typeof window !== 'undefined' ? window.location.hostname : 'jackpot.local'}
                        </a>
                        {' '}or reach out through your account manager.
                    </p>
                </div>

                <div className="mt-8">
                    <Link
                        href={auth?.user ? '/app/billing' : '/'}
                        className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                    >
                        ← Back to {auth?.user ? 'Billing' : 'Home'}
                    </Link>
                </div>
            </main>
        </div>
    )
}
