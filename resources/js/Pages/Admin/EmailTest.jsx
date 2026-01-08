import { Link, useForm, usePage } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'

export default function EmailTest({ mail_config, recent_emails, laravel_log_url, is_local, dev_email_location }) {
    const { auth } = usePage().props
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        template: 'invite_member',
    })

    const handleSubmit = (e) => {
        e.preventDefault()
        post('/app/admin/email-test/send', {
            preserveScroll: true,
        })
    }

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    <div className="mb-8">
                        <Link href="/app/admin" className="text-sm font-medium text-gray-500 hover:text-gray-700 mb-4 inline-block">
                            ← Back to Admin Dashboard
                        </Link>
                        <div className="flex items-center justify-between">
                            <div>
                                <h1 className="text-3xl font-bold tracking-tight text-gray-900">Email Testing</h1>
                                <p className="mt-2 text-sm text-gray-700">Test email sending and view email logs</p>
                            </div>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        {/* Send Test Email */}
                        <div className="rounded-lg bg-white shadow-sm ring-1 ring-gray-200 p-6">
                            <h2 className="text-lg font-semibold text-gray-900 mb-4">Send Test Email</h2>
                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div>
                                    <label htmlFor="email" className="block text-sm font-medium text-gray-700 mb-2">
                                        Recipient Email
                                    </label>
                                    <input
                                        type="email"
                                        id="email"
                                        value={data.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                        className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                        placeholder="test@example.com"
                                        required
                                    />
                                    {errors.email && <p className="mt-1 text-sm text-red-600">{errors.email}</p>}
                                </div>
                                <div>
                                    <label htmlFor="template" className="block text-sm font-medium text-gray-700 mb-2">
                                        Template
                                    </label>
                                    <select
                                        id="template"
                                        value={data.template}
                                        onChange={(e) => setData('template', e.target.value)}
                                        className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                    >
                                        <option value="invite_member">Invite Member</option>
                                    </select>
                                </div>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="w-full inline-flex justify-center items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                                >
                                    {processing ? 'Sending...' : 'Send Test Email'}
                                </button>
                            </form>
                        </div>

                        {/* Mail Configuration */}
                        <div className="rounded-lg bg-white shadow-sm ring-1 ring-gray-200 p-6">
                            <h2 className="text-lg font-semibold text-gray-900 mb-4">Mail Configuration</h2>
                            <dl className="space-y-2">
                                <div>
                                    <dt className="text-sm font-medium text-gray-500">Driver</dt>
                                    <dd className="mt-1 text-sm text-gray-900">{mail_config.driver}</dd>
                                </div>
                                <div>
                                    <dt className="text-sm font-medium text-gray-500">From Address</dt>
                                    <dd className="mt-1 text-sm text-gray-900">{mail_config.from_address}</dd>
                                </div>
                                <div>
                                    <dt className="text-sm font-medium text-gray-500">From Name</dt>
                                    <dd className="mt-1 text-sm text-gray-900">{mail_config.from_name}</dd>
                                </div>
                            </dl>
                            {mail_config.driver === 'log' && (
                                <div className="mt-4">
                                    <a
                                        href={laravel_log_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                    >
                                        View Laravel Log
                                    </a>
                                </div>
                            )}
                            {is_local && dev_email_location && (
                                <div className="mt-4 p-4 bg-blue-50 border border-blue-200 rounded-md">
                                    <div className="flex items-start">
                                        <svg className="h-5 w-5 text-blue-400 mt-0.5 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418" />
                                        </svg>
                                        <div className="flex-1">
                                            <h3 className="text-sm font-medium text-blue-900 mb-1">Local Development Email Viewer</h3>
                                            <p className="text-sm text-blue-700 mb-2">
                                                Since you're in a local environment, emails are being captured by Mailpit. View them in your browser:
                                            </p>
                                            <a
                                                href={dev_email_location}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex items-center rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500"
                                            >
                                                <svg className="h-4 w-4 mr-1.5" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                                </svg>
                                                Open Mailpit Dashboard
                                            </a>
                                            <p className="mt-2 text-xs text-blue-600">
                                                {dev_email_location}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
