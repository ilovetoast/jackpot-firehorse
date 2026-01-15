import { Link, useForm, usePage } from '@inertiajs/react'
import { useState, useMemo, useEffect } from 'react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'

export default function NotificationEdit({ template, app_name, app_url, tenants, saas_primary_color }) {
    const { auth } = usePage().props
    
    // Get variables from template or default list
    const templateVariables = template.variables || []
    
    // Calculate color variations helper
    const getColorVariations = (color) => {
        // Convert hex to RGB
        const hex = color.replace('#', '')
        const r = parseInt(hex.substr(0, 2), 16)
        const g = parseInt(hex.substr(2, 2), 16)
        const b = parseInt(hex.substr(4, 2), 16)
        
        // Darken for gradient end
        const darken = (val) => Math.max(0, Math.floor(val * 0.85))
        const primaryColorDark = `#${darken(r).toString(16).padStart(2, '0')}${darken(g).toString(16).padStart(2, '0')}${darken(b).toString(16).padStart(2, '0')}`
        
        // Light background (10% opacity)
        const primaryColorLight = `rgba(${r}, ${g}, ${b}, 0.1)`
        
        return {
            primary_color: color,
            primary_color_dark: primaryColorDark,
            primary_color_light: primaryColorLight,
        }
    }
    
    // Get selected tenant and brand colors
    const [selectedTenantId, setSelectedTenantId] = useState(tenants && tenants.length > 0 ? tenants[0].id : null)
    const selectedTenant = tenants?.find(t => t.id === selectedTenantId)
    const primaryColor = selectedTenant?.first_brand?.primary_color || saas_primary_color || '#6366f1'
    const colorVars = getColorVariations(primaryColor)
    
    // Initialize sample data with defaults
    const getDefaultSampleData = () => {
        const defaults = {
            tenant_name: selectedTenant?.name || 'Example Company',
            inviter_name: 'John Doe',
            invite_url: `${window.location.origin}/invite/abc123`,
            app_name: app_name || 'Jackpot',
            app_url: app_url || window.location.origin,
            user_name: 'Jane Smith',
            user_email: 'jane@example.com',
            ...colorVars, // Add color variables
        }
        const sampleData = {}
        templateVariables.forEach(variable => {
            sampleData[variable] = defaults[variable] || `Sample ${variable.replace(/_/g, ' ')}`
        })
        // Always include color variables for tenant emails
        if (template.category === 'tenant' || !template.category) {
            Object.assign(sampleData, colorVars)
        }
        return sampleData
    }
    
    const [sampleData, setSampleData] = useState(getDefaultSampleData())
    
    // Update sample data when tenant changes
    useEffect(() => {
        const isTenantEmail = template.category === 'tenant' || !template.category
        if (isTenantEmail && selectedTenant) {
            const newColorVars = getColorVariations(selectedTenant.first_brand?.primary_color || saas_primary_color || '#6366f1')
            setSampleData(prev => ({
                ...prev,
                tenant_name: selectedTenant.name,
                ...newColorVars,
            }))
        }
    }, [selectedTenantId, template.category, selectedTenant, saas_primary_color])
    
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
    
    // Generate preview with color replacements
    const preview = useMemo(() => {
        let bodyHtml = replaceVariables(data.body_html, templateVariables, sampleData)
        
        // Replace color variables with actual colors (for tenant emails)
        const isTenantEmail = template.category === 'tenant' || !template.category
        if (isTenantEmail) {
            bodyHtml = bodyHtml.replace(/\{\{primary_color\}\}/g, colorVars.primary_color)
            bodyHtml = bodyHtml.replace(/\{\{primary_color_dark\}\}/g, colorVars.primary_color_dark)
            bodyHtml = bodyHtml.replace(/\{\{primary_color_light\}\}/g, colorVars.primary_color_light)
        } else {
            // For system emails, use SaaS primary color
            bodyHtml = bodyHtml.replace(/\{\{primary_color\}\}/g, saas_primary_color || '#6366f1')
            bodyHtml = bodyHtml.replace(/\{\{primary_color_dark\}\}/g, '#4f46e5')
            bodyHtml = bodyHtml.replace(/\{\{primary_color_light\}\}/g, 'rgba(99, 102, 241, 0.1)')
        }
        
        return {
            subject: replaceVariables(data.subject, templateVariables, sampleData),
            body_html: bodyHtml,
            body_text: replaceVariables(data.body_text, templateVariables, sampleData),
        }
    }, [data.subject, data.body_html, data.body_text, sampleData, templateVariables, template.category, colorVars, saas_primary_color])

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
                                
                                {/* Company Selector for Tenant Emails */}
                                {(template.category === 'tenant' || !template.category) && tenants && tenants.length > 0 && (
                                    <div className="mb-6 pb-6 border-b border-gray-200">
                                        <label htmlFor="tenant_select" className="block text-sm font-medium text-gray-700 mb-2">
                                            Preview as Company
                                        </label>
                                        <select
                                            id="tenant_select"
                                            value={selectedTenantId || ''}
                                            onChange={(e) => {
                                                const tenantId = parseInt(e.target.value)
                                                setSelectedTenantId(tenantId)
                                                const tenant = tenants.find(t => t.id === tenantId)
                                                if (tenant) {
                                                    setSampleData(prev => ({
                                                        ...prev,
                                                        tenant_name: tenant.name,
                                                        ...getColorVariations(tenant.first_brand?.primary_color || saas_primary_color || '#6366f1'),
                                                    }))
                                                }
                                            }}
                                            className="block w-full rounded-md border-0 py-1.5 px-3 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600"
                                        >
                                            {tenants.map(tenant => (
                                                <option key={tenant.id} value={tenant.id}>
                                                    {tenant.name}
                                                </option>
                                            ))}
                                        </select>
                                        <p className="mt-1 text-xs text-gray-500">
                                            {selectedTenant?.first_brand 
                                                ? `Using ${selectedTenant.first_brand.name} brand colors (${selectedTenant.first_brand.primary_color})`
                                                : 'Using default SaaS colors'}
                                        </p>
                                    </div>
                                )}
                                
                                {/* Sample Data Inputs */}
                                {templateVariables.length > 0 && (
                                    <div className="mb-6 pb-6 border-b border-gray-200">
                                        <h3 className="text-sm font-medium text-gray-700 mb-3">Sample Data</h3>
                                        <div className="space-y-3">
                                            {templateVariables.filter(v => !v.startsWith('primary_color')).map(variable => (
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
