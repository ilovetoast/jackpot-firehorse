import { useForm } from '@inertiajs/react'

export default function CollectionInviteRegistration({ token, collection, email, inviter }) {
    const { data, setData, post, processing, errors } = useForm({
        first_name: '',
        last_name: '',
        password: '',
        password_confirmation: '',
    })

    const handleSubmit = (e) => {
        e.preventDefault()
        post(route('collection-invite.complete', { token }))
    }

    return (
        <div className="flex min-h-full flex-1 flex-col justify-center bg-gray-50 px-6 py-12 lg:px-8">
            <div className="sm:mx-auto sm:w-full sm:max-w-md">
                <div className="mb-6 rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 className="text-2xl font-bold text-gray-900">Complete your registration</h2>
                    <p className="mt-2 text-sm text-gray-600">
                        You&apos;ve been invited to view the collection <strong>{collection?.name}</strong>.
                    </p>
                    {inviter?.name && (
                        <p className="mt-1 text-sm text-gray-500">Invited by {inviter.name}</p>
                    )}
                    <p className="mt-2 text-sm text-gray-500">Email: {email}</p>
                </div>
                <div className="rounded-lg bg-white p-8 shadow-sm ring-1 ring-gray-200">
                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div>
                            <label htmlFor="first_name" className="block text-sm font-medium text-gray-900">
                                First name
                            </label>
                            <input
                                id="first_name"
                                type="text"
                                value={data.first_name}
                                onChange={(e) => setData('first_name', e.target.value)}
                                required
                                className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                            {errors.first_name && (
                                <p className="mt-1 text-sm text-red-600">{errors.first_name}</p>
                            )}
                        </div>
                        <div>
                            <label htmlFor="last_name" className="block text-sm font-medium text-gray-900">
                                Last name
                            </label>
                            <input
                                id="last_name"
                                type="text"
                                value={data.last_name}
                                onChange={(e) => setData('last_name', e.target.value)}
                                required
                                className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                            {errors.last_name && (
                                <p className="mt-1 text-sm text-red-600">{errors.last_name}</p>
                            )}
                        </div>
                        <div>
                            <label htmlFor="password" className="block text-sm font-medium text-gray-900">
                                Password
                            </label>
                            <input
                                id="password"
                                type="password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                required
                                className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                            {errors.password && (
                                <p className="mt-1 text-sm text-red-600">{errors.password}</p>
                            )}
                        </div>
                        <div>
                            <label htmlFor="password_confirmation" className="block text-sm font-medium text-gray-900">
                                Confirm password
                            </label>
                            <input
                                id="password_confirmation"
                                type="password"
                                value={data.password_confirmation}
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                                required
                                className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                            {errors.password_confirmation && (
                                <p className="mt-1 text-sm text-red-600">{errors.password_confirmation}</p>
                            )}
                        </div>
                        <button
                            type="submit"
                            disabled={processing}
                            className="w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                        >
                            {processing ? 'Creating account…' : 'Create account and view collection'}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    )
}
