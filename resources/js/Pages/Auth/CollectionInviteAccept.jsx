import { useForm } from '@inertiajs/react'

export default function CollectionInviteAccept({ token, collection, email, inviter }) {
    const { post, processing } = useForm({})

    const handleAccept = (e) => {
        e.preventDefault()
        post(route('collection-invite.accept.submit', { token }))
    }

    return (
        <div className="flex min-h-full flex-1 flex-col justify-center bg-gray-50 px-6 py-12 lg:px-8">
            <div className="sm:mx-auto sm:w-full sm:max-w-md">
                <div className="rounded-lg bg-white p-8 shadow-sm ring-1 ring-gray-200">
                    <h2 className="text-2xl font-bold text-gray-900">Collection invitation</h2>
                    <p className="mt-2 text-sm text-gray-600">
                        You have been invited to view the collection <strong>{collection?.name}</strong>.
                    </p>
                    {inviter?.name && (
                        <p className="mt-1 text-sm text-gray-500">Invited by {inviter.name}</p>
                    )}
                    <p className="mt-2 text-sm text-gray-500">Signed in as {email}</p>
                    <form onSubmit={handleAccept} className="mt-6">
                        <button
                            type="submit"
                            disabled={processing}
                            className="w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                        >
                            {processing ? 'Accepting…' : 'Accept invitation'}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    )
}
