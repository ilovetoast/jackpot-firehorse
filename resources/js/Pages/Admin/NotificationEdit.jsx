import { Link, useForm, usePage } from '@inertiajs/react'
import { useState, useMemo } from 'react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'

export default function NotificationEdit({ template, app_name, app_url }) {
    const { auth } = usePage().props
    
    // Get variables from template or default list
    const templateVariables = template.variables || []
    
    // Initialize sample data with defaults
    const getDefaultSampleData = () => {
        const defaults = {
            tenant_name: 'Example Company',
            inviter_name: 'John Doe',
            invite_url: `${window.location.origin}/invite/abc123`,
            app_name: app_name || 'Jackpot',
            app_url: app_url || window.location.origin,
            user_name: 'Jane Smith',
            user_email: 'jane@example.com',
        }
        const sampleData = {}
        templateVariables.forEach(variable => {
            sampleData[variable] = defaults[variable] || `Sample ${variable.replace(/_/g, ' ')}`
        })
        return sampleData
    }
    
    const [sampleData, setSampleData] = useState(getDefaultSampleData())
    
    const { data, setData, put, processing, errors } = useForm({
        name: template.name || '',
        subject: template.subject || '',
        body_html: template.body_html || '',
        body_text: template.body_text || '',
        is_active: template.is_active ?? true,
    })
    
    // Function to replace variables in text
    // Handles both {{variable}} and {{{variable}}} syntax
    const replaceVariables = (text, variables, data) => {
        let result = text || ''
        variables.forEach(variable => {
            const value = data[variable] || `{{${variable}}}`
            // Replace triple braces first (more specific), then double braces
            result = result.replace(new RegExp(`\\{\\{\\{${variable}\\}\\}\\}`, 'g'), value)
            result = result.replace(new RegExp(`\\{\\{${variable}\\}\\}`, 'g'), value)
        })
        return result
    }
    
    // Generate preview
    const preview = useMemo(() => {
        return {
            subject: replaceVariables(data.subject, templateVariables, sampleData),
            body_html: replaceVariables(data.body_html, templateVariables, sampleData),
            body_text: replaceVariables(data.body_text, templateVariables, sampleData),
        }
    }, [data.subject, data.body_html, data.body_text, sampleData, templateVariables])

    const handleSubmit = (e) => {
        e.preventDefault()
        put(`/app/admin/notifications/${template.id}`, {
            preserveScroll: true,
        })
    }

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    <div className="mb-8">
                        <Link href="/app/admin/notifications" className="text-sm font-medium text-gray-500 hover:text-gray-700 mb-4 inline-block">
                            ← Back to Notifications
                        </Link>
                        <div>
                            <h1 className="text-3xl font-bold tracking-tight text-gray-900">Edit Notification Template</h1>
                            <p className="mt-2 text-sm text-gray-700">{template.name}</p>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        {/* Left Column: Edit Form */}
                        <div className="space-y-6">
                            <form onSubmit={handleSubmit} className="space-y-6">
                                <div className="rounded-lg bg-white shadow-sm ring-1 ring-gray-200 p-6">
                                    <div className="space-y-6">
                                <div>
                                    <label htmlFor="name" className="block text-sm font-medium text-gray-700 mb-2">
                                        Template Name
                                    </label>
                                    <input
                                        type="text"
                                        id="name"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                        required
                                    />
                                    {errors.name && <p className="mt-1 text-sm text-red-600">{errors.name}</p>}
                                </div>

                                <div>
                                    <label htmlFor="subject" className="block text-sm font-medium text-gray-700 mb-2">
                                        Email Subject
                                    </label>
                                    <input
                                        type="text"
                                        id="subject"
                                        value={data.subject}
                                        onChange={(e) => setData('subject', e.target.value)}
                                        className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                        required
                                    />
                                    {errors.subject && <p className="mt-1 text-sm text-red-600">{errors.subject}</p>}
                                </div>

                                <div>
                                    <label htmlFor="body_html" className="block text-sm font-medium text-gray-700 mb-2">
                                        HTML Body
                                    </label>
                                    <textarea
                                        id="body_html"
                                        rows={12}
                                        value={data.body_html}
                                        onChange={(e) => setData('body_html', e.target.value)}
                                        className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6 font-mono"
                                        required
                                    />
                                    <p className="mt-1 text-xs text-gray-500">
                                        Available variables: {template.variables?.join(', ') || 'None'}
                                    </p>
                                    {errors.body_html && <p className="mt-1 text-sm text-red-600">{errors.body_html}</p>}
                                </div>

                                <div>
                                    <label htmlFor="body_text" className="block text-sm font-medium text-gray-700 mb-2">
                                        Plain Text Body (optional)
                                    </label>
                                    <textarea
                                        id="body_text"
                                        rows={8}
                                        value={data.body_text}
                                        onChange={(e) => setData('body_text', e.target.value)}
                                        className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6 font-mono"
                                    />
                                    {errors.body_text && <p className="mt-1 text-sm text-red-600">{errors.body_text}</p>}
                                </div>

                                <div className="flex items-center">
                                    <input
                                        id="is_active"
                                        type="checkbox"
                                        checked={data.is_active}
                                        onChange={(e) => setData('is_active', e.target.checked)}
                                        className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                    />
                                    <label htmlFor="is_active" className="ml-2 block text-sm text-gray-900">
                                        Active
                                    </label>
                                </div>
                                    </div>
                                </div>

                                <div className="flex justify-end gap-3">
                                    <Link
                                        href="/app/admin/notifications"
                                        className="inline-flex items-center rounded-md bg-white px-4 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                    >
                                        Cancel
                                    </Link>
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                                    >
                                        {processing ? 'Saving...' : 'Save Changes'}
                                    </button>
                                </div>
                            </form>
                        </div>

                        {/* Right Column: Preview */}
                        <div className="space-y-6">
                            <div className="rounded-lg bg-white shadow-sm ring-1 ring-gray-200 p-6">
                                <h2 className="text-lg font-semibold text-gray-900 mb-4">Preview</h2>
                                
                                {/* Sample Data Inputs */}
                                {templateVariables.length > 0 && (
                                    <div className="mb-6 pb-6 border-b border-gray-200">
                                        <h3 className="text-sm font-medium text-gray-700 mb-3">Sample Data</h3>
                                        <div className="space-y-3">
                                            {templateVariables.map(variable => (
                                                <div key={variable}>
                                                    <label htmlFor={`sample_${variable}`} className="block text-xs font-medium text-gray-600 mb-1">
                                                        {variable.replace(/_/g, ' ')}
                                                    </label>
                                                    <input
                                                        type="text"
                                                        id={`sample_${variable}`}
                                                        value={sampleData[variable] || ''}
                                                        onChange={(e) => setSampleData({ ...sampleData, [variable]: e.target.value })}
                                                        className="block w-full rounded-md border-0 py-1.5 px-3 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600"
                                                        placeholder={`Enter ${variable}`}
                                                    />
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {/* Email Subject Preview */}
                                <div className="mb-6">
                                    <h3 className="text-sm font-medium text-gray-700 mb-2">Subject</h3>
                                    <div className="rounded-md bg-gray-50 p-3 border border-gray-200">
                                        <p className="text-sm text-gray-900">{preview.subject || '(No subject)'}</p>
                                    </div>
                                </div>

                                {/* HTML Body Preview */}
                                <div>
                                    <h3 className="text-sm font-medium text-gray-700 mb-2">Email Preview</h3>
                                    <div className="rounded-md border border-gray-200 overflow-hidden">
                                        <iframe
                                            srcDoc={preview.body_html}
                                            className="w-full h-[600px] border-0"
                                            title="Email Preview"
                                            sandbox="allow-same-origin"
                                        />
                                    </div>
                                </div>

                                {/* Plain Text Preview (Collapsible) */}
                                {preview.body_text && (
                                    <details className="mt-4">
                                        <summary className="text-sm font-medium text-gray-700 cursor-pointer hover:text-gray-900">
                                            Plain Text Preview
                                        </summary>
                                        <div className="mt-2 rounded-md bg-gray-50 p-3 border border-gray-200">
                                            <pre className="text-xs text-gray-900 whitespace-pre-wrap font-mono">{preview.body_text}</pre>
                                        </div>
                                    </details>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
