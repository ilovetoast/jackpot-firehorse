import { useForm, Link } from '@inertiajs/react'

/**
 * Art. 15 / 17 / 20 — export + erasure request (see Privacy Policy).
 */
export default function DataSubjectRights() {
    const { data, setData, post, processing, errors, reset, wasSuccessful } = useForm({
        message: '',
    })

    const submitErasure = (e) => {
        e.preventDefault()
        post('/app/profile/erasure-request', {
            preserveScroll: true,
            onSuccess: () => reset('message'),
        })
    }

    return (
        <div className="space-y-6">
            <p className="text-sm text-gray-600">
                Download a copy of personal data we hold about your account, or ask us to erase it after review.
                Full details are in our{' '}
                <Link href="/privacy" className="font-medium text-indigo-600 hover:text-indigo-500">
                    Privacy Policy
                </Link>
                .
            </p>

            <div className="rounded-lg border border-gray-200 bg-gray-50 p-4">
                <h3 className="text-sm font-semibold text-gray-900">Export your data (Art. 15 &amp; 20)</h3>
                <p className="mt-1 text-sm text-gray-600">
                    You will get a ZIP file containing <code className="text-xs">jackpot-user-data.json</code> with
                    account, memberships, activity, and related records. Large workspace files stay with your
                    organization — this export is account-level metadata and logs.
                </p>
                <a
                    href="/app/profile/export"
                    className="mt-3 inline-flex rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                >
                    Download ZIP
                </a>
            </div>

            <div className="rounded-lg border border-amber-200 bg-amber-50 p-4">
                <h3 className="text-sm font-semibold text-amber-950">Request erasure (Art. 17)</h3>
                <p className="mt-1 text-sm text-amber-900/90">
                    Submit a request for our team to review. Approved requests run automated scrubbing of logs and
                    identifiers tied to your account, and suspend the account. Some data may be retained where the law
                    requires (for example billing records).
                </p>
                <form onSubmit={submitErasure} className="mt-4 space-y-3">
                    <div>
                        <label htmlFor="dsr_message" className="block text-sm font-medium text-gray-700">
                            Optional message
                        </label>
                        <textarea
                            id="dsr_message"
                            rows={3}
                            value={data.message}
                            onChange={(e) => setData('message', e.target.value)}
                            className="mt-1 block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                            placeholder="Anything that helps us verify or process your request…"
                        />
                        {errors.message && <p className="mt-1 text-sm text-red-600">{errors.message}</p>}
                    </div>
                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-md bg-amber-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-700 disabled:opacity-50"
                    >
                        {processing ? 'Submitting…' : 'Submit erasure request'}
                    </button>
                    {wasSuccessful && (
                        <p className="text-sm text-emerald-800">Request received.</p>
                    )}
                </form>
            </div>
        </div>
    )
}
