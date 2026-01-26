import { useForm, usePage } from '@inertiajs/react'
import { Link } from '@inertiajs/react'

export default function ForgotPassword({ status }) {
    const { flash } = usePage().props
    // Use status prop if provided, otherwise check flash.status
    const displayStatus = status || flash?.status
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    })

    const submit = (e) => {
        e.preventDefault()
        post('/forgot-password')
    }

    return (
        <div className="relative isolate bg-white min-h-screen flex items-center justify-center px-6 py-12 lg:px-8">
            {/* Abstract background pattern */}
            <div
                className="absolute inset-x-0 -top-40 -z-10 transform-gpu overflow-hidden blur-3xl sm:-top-80"
                aria-hidden="true"
            >
                <div
                    className="relative left-[calc(50%-11rem)] aspect-[1155/678] w-[36.125rem] -translate-x-1/2 rotate-[30deg] bg-gradient-to-tr from-[#ff80b5] to-[#9089fc] opacity-30 sm:left-[calc(50%-30rem)] sm:w-[72.1875rem]"
                    style={{
                        clipPath:
                            'polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.5% 76.7%, 76.1% 97.7%, 74.1% 44.1%)',
                    }}
                />
            </div>
            <div
                className="absolute inset-x-0 top-[calc(100%-13rem)] -z-10 transform-gpu overflow-hidden blur-3xl sm:top-[calc(100%-30rem)]"
                aria-hidden="true"
            >
                <div
                    className="relative left-[calc(50%+3rem)] aspect-[1155/678] w-[36.125rem] -translate-x-1/2 bg-gradient-to-tr from-[#ff80b5] to-[#9089fc] opacity-30 sm:left-[calc(50%+36rem)] sm:w-[72.1875rem]"
                    style={{
                        clipPath:
                            'polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.5% 76.7%, 76.1% 97.7%, 74.1% 44.1%)',
                    }}
                />
            </div>

            {/* Forgot password card */}
            <div className="w-full max-w-md">
                <div className="bg-white rounded-lg shadow-xl border border-gray-200 p-8">
                    <div className="text-center mb-8">
                        <Link href="/" className="text-2xl font-bold text-gray-900 inline-block mb-2">
                            Jackpot
                        </Link>
                        <h2 className="text-2xl font-bold leading-9 tracking-tight text-gray-900">
                            Forgot your password?
                        </h2>
                        <p className="mt-2 text-sm text-gray-600">
                            No worries! Enter your email address and we'll send you a link to reset your password.
                        </p>
                    </div>

                    {displayStatus && (
                        <div className="mb-4 p-4 bg-green-50 border border-green-200 rounded-md">
                            <p className="text-sm text-green-800">{displayStatus}</p>
                        </div>
                    )}

                    <form className="space-y-6" onSubmit={submit}>
                        <div>
                            <label htmlFor="email" className="block text-sm font-medium leading-6 text-gray-900">
                                Email address
                            </label>
                            <div className="mt-2">
                                <input
                                    id="email"
                                    name="email"
                                    type="email"
                                    autoComplete="email"
                                    required
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                    className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                />
                                {errors.email && (
                                    <p className="mt-2 text-sm text-red-600">{errors.email}</p>
                                )}
                            </div>
                        </div>

                        <div>
                            <button
                                type="submit"
                                disabled={processing}
                                className="flex w-full justify-center rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold leading-6 text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                {processing ? 'Sending...' : 'Send reset link'}
                            </button>
                        </div>
                    </form>

                    <p className="mt-6 text-center text-sm text-gray-500">
                        Remember your password?{' '}
                        <Link href="/login" className="font-semibold text-indigo-600 hover:text-indigo-500">
                            Sign in
                        </Link>
                    </p>

                    <p className="mt-4 text-center text-sm text-gray-500">
                        <Link href="/" className="font-semibold text-indigo-600 hover:text-indigo-500">
                            ← Back to home
                        </Link>
                    </p>
                </div>
            </div>
        </div>
    )
}
