import { useForm } from '@inertiajs/react'

/**
 * Qualified sales / demo-request form. Posts to /contact/sales.
 *
 * Kept in lockstep with the enum constants in App\Models\ContactLead — when
 * you add or remove an option here, update the PHP constants + validation.
 */

const COMPANY_SIZES = [
    { value: '1-10', label: '1–10' },
    { value: '11-50', label: '11–50' },
    { value: '51-200', label: '51–200' },
    { value: '201-1000', label: '201–1,000' },
    { value: '1001-5000', label: '1,001–5,000' },
    { value: '5000+', label: '5,000+' },
]

const INDUSTRIES = [
    { value: 'agency', label: 'Agency / Creative services' },
    { value: 'saas', label: 'Software / SaaS' },
    { value: 'retail_cpg', label: 'Retail / CPG' },
    { value: 'finance', label: 'Financial services' },
    { value: 'healthcare', label: 'Healthcare' },
    { value: 'media_entertainment', label: 'Media & Entertainment' },
    { value: 'education', label: 'Education' },
    { value: 'nonprofit', label: 'Nonprofit' },
    { value: 'manufacturing', label: 'Manufacturing' },
    { value: 'other', label: 'Other' },
]

const USE_CASES = [
    { value: 'brand_management', label: 'Brand management' },
    { value: 'asset_management', label: 'Creative / asset management' },
    { value: 'agency_client_work', label: 'Agency client work' },
    { value: 'creator_program', label: 'Creator / partner program' },
    { value: 'compliance_governance', label: 'Brand compliance & governance' },
    { value: 'other', label: 'Other' },
]

const BRAND_COUNTS = [
    { value: '1', label: '1 brand' },
    { value: '2-5', label: '2–5 brands' },
    { value: '6-20', label: '6–20 brands' },
    { value: '21-50', label: '21–50 brands' },
    { value: '50+', label: '50+ brands' },
]

const TIMELINES = [
    { value: 'exploring', label: 'Just exploring' },
    { value: '0-3mo', label: 'Within 3 months' },
    { value: '3-6mo', label: '3–6 months' },
    { value: '6-12mo', label: '6–12 months' },
    { value: '12+mo', label: '12+ months' },
]

export default function SalesInquiryForm({ planInterest = 'default' }) {
    const { data, setData, post, processing, errors, wasSuccessful, reset } = useForm({
        first_name: '',
        last_name: '',
        email: '',
        phone: '',
        job_title: '',
        company: '',
        company_website: '',
        plan_interest: planInterest,
        company_size: '',
        industry: '',
        use_case: '',
        brand_count: '',
        timeline: '',
        heard_from: '',
        message: '',
        consent_marketing: false,
        source: typeof window !== 'undefined' ? window.location.pathname : '/contact',
        website: '',
    })

    const submit = (e) => {
        e.preventDefault()
        post('/contact/sales', {
            preserveScroll: true,
            onSuccess: () =>
                reset(
                    'first_name',
                    'last_name',
                    'email',
                    'phone',
                    'job_title',
                    'company',
                    'company_website',
                    'company_size',
                    'industry',
                    'use_case',
                    'brand_count',
                    'timeline',
                    'heard_from',
                    'message',
                    'consent_marketing',
                ),
        })
    }

    return (
        <form onSubmit={submit} noValidate className="space-y-5">
            {/* Honeypot */}
            <div className="absolute left-[-9999px] top-auto h-px w-px overflow-hidden" aria-hidden="true">
                <label>
                    Website
                    <input
                        type="text"
                        tabIndex={-1}
                        autoComplete="off"
                        value={data.website}
                        onChange={(e) => setData('website', e.target.value)}
                    />
                </label>
            </div>

            <FieldGroup title="About you">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <Field label="First name" required error={errors.first_name}>
                        <input
                            type="text"
                            required
                            autoComplete="given-name"
                            value={data.first_name}
                            onChange={(e) => setData('first_name', e.target.value)}
                            className={inputClass}
                        />
                    </Field>
                    <Field label="Last name" required error={errors.last_name}>
                        <input
                            type="text"
                            required
                            autoComplete="family-name"
                            value={data.last_name}
                            onChange={(e) => setData('last_name', e.target.value)}
                            className={inputClass}
                        />
                    </Field>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <Field label="Work email" required error={errors.email}>
                        <input
                            type="email"
                            required
                            autoComplete="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            className={inputClass}
                        />
                    </Field>
                    <Field label="Phone" error={errors.phone} hint="Optional">
                        <input
                            type="tel"
                            autoComplete="tel"
                            value={data.phone}
                            onChange={(e) => setData('phone', e.target.value)}
                            className={inputClass}
                        />
                    </Field>
                </div>

                <Field label="Job title" error={errors.job_title} hint="Optional">
                    <input
                        type="text"
                        autoComplete="organization-title"
                        value={data.job_title}
                        onChange={(e) => setData('job_title', e.target.value)}
                        className={inputClass}
                    />
                </Field>
            </FieldGroup>

            <FieldGroup title="About your company">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <Field label="Company" required error={errors.company}>
                        <input
                            type="text"
                            required
                            autoComplete="organization"
                            value={data.company}
                            onChange={(e) => setData('company', e.target.value)}
                            className={inputClass}
                        />
                    </Field>
                    <Field label="Company website" error={errors.company_website} hint="Optional">
                        <input
                            type="url"
                            placeholder="https://"
                            autoComplete="url"
                            value={data.company_website}
                            onChange={(e) => setData('company_website', e.target.value)}
                            className={inputClass}
                        />
                    </Field>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <Field label="Company size" required error={errors.company_size}>
                        <Select
                            value={data.company_size}
                            onChange={(v) => setData('company_size', v)}
                            placeholder="Select size"
                            options={COMPANY_SIZES}
                        />
                    </Field>
                    <Field label="Industry" error={errors.industry} hint="Optional">
                        <Select
                            value={data.industry}
                            onChange={(v) => setData('industry', v)}
                            placeholder="Select industry"
                            options={INDUSTRIES}
                        />
                    </Field>
                </div>
            </FieldGroup>

            <FieldGroup title="What you're looking for">
                <Field label="Primary use case" required error={errors.use_case}>
                    <Select
                        value={data.use_case}
                        onChange={(v) => setData('use_case', v)}
                        placeholder="What are you hoping to accomplish?"
                        options={USE_CASES}
                    />
                </Field>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <Field label="Number of brands" error={errors.brand_count} hint="Optional">
                        <Select
                            value={data.brand_count}
                            onChange={(v) => setData('brand_count', v)}
                            placeholder="How many brands?"
                            options={BRAND_COUNTS}
                        />
                    </Field>
                    <Field label="Timeline" error={errors.timeline} hint="Optional">
                        <Select
                            value={data.timeline}
                            onChange={(v) => setData('timeline', v)}
                            placeholder="When are you looking to start?"
                            options={TIMELINES}
                        />
                    </Field>
                </div>

                <Field
                    label="Anything else we should know?"
                    error={errors.message}
                    hint="Current tools, pain points, or specific questions"
                >
                    <textarea
                        rows={4}
                        value={data.message}
                        onChange={(e) => setData('message', e.target.value)}
                        className={`${inputClass} resize-y`}
                    />
                </Field>

                <Field label="How did you hear about Jackpot?" error={errors.heard_from} hint="Optional">
                    <input
                        type="text"
                        value={data.heard_from}
                        onChange={(e) => setData('heard_from', e.target.value)}
                        className={inputClass}
                    />
                </Field>
            </FieldGroup>

            <label className="flex items-start gap-2 text-xs text-white/45 cursor-pointer">
                <input
                    type="checkbox"
                    checked={data.consent_marketing}
                    onChange={(e) => setData('consent_marketing', e.target.checked)}
                    className="mt-0.5 h-3.5 w-3.5 rounded border-white/20 bg-white/[0.03] text-indigo-500 focus:ring-indigo-400/60"
                />
                <span>I'd also like to receive occasional product updates. (Optional — a sales rep will follow up regardless.)</span>
            </label>

            <div className="pt-2 flex flex-col sm:flex-row sm:items-center gap-3">
                <button
                    type="submit"
                    disabled={processing}
                    className="inline-flex items-center justify-center rounded-xl bg-white px-6 py-3 text-sm font-semibold text-gray-900 shadow-lg shadow-black/20 hover:bg-gray-100 transition-colors disabled:cursor-not-allowed disabled:opacity-40"
                >
                    {processing ? 'Sending…' : 'Request a demo'}
                </button>
                <span className="text-xs text-white/35">A sales rep will reach out within one business day.</span>
            </div>

            {wasSuccessful && (
                <p className="text-sm text-emerald-300/90" role="status">
                    Thanks — we'll be in touch shortly.
                </p>
            )}
        </form>
    )
}

const inputClass =
    'w-full rounded-xl bg-white/[0.03] px-4 py-3 text-sm text-white placeholder:text-white/30 ring-1 ring-inset ring-white/[0.08] focus:ring-2 focus:ring-indigo-400/60 focus:outline-none transition-shadow'

function FieldGroup({ title, children }) {
    return (
        <fieldset className="space-y-4">
            <legend className="text-xs font-semibold uppercase tracking-wider text-white/35 mb-1">
                {title}
            </legend>
            <div className="space-y-4">{children}</div>
        </fieldset>
    )
}

function Field({ label, required, error, hint, children }) {
    return (
        <label className="block">
            <div className="flex items-baseline justify-between mb-1.5">
                <span className="text-xs font-semibold uppercase tracking-wider text-white/50">
                    {label}
                    {required && <span className="text-rose-300/80 ml-1">*</span>}
                </span>
                {hint && <span className="text-xs text-white/30">{hint}</span>}
            </div>
            {children}
            {error && <p className="mt-1 text-xs text-rose-300/90">{error}</p>}
        </label>
    )
}

function Select({ value, onChange, options, placeholder }) {
    return (
        <select
            value={value}
            onChange={(e) => onChange(e.target.value)}
            className={`${inputClass} appearance-none bg-[url('data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20width%3D%2212%22%20height%3D%2212%22%20fill%3D%22none%22%20stroke%3D%22%23ffffff88%22%20stroke-width%3D%222%22%20viewBox%3D%220%200%2024%2024%22%3E%3Cpath%20d%3D%22M6%209l6%206%206-6%22/%3E%3C/svg%3E')] bg-[length:12px] bg-no-repeat bg-[position:right_1rem_center] pr-10`}
        >
            <option value="" disabled className="bg-[#0B0B0D]">
                {placeholder}
            </option>
            {options.map((o) => (
                <option key={o.value} value={o.value} className="bg-[#0B0B0D]">
                    {o.label}
                </option>
            ))}
        </select>
    )
}
