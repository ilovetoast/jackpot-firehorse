import { useEffect, useMemo, useState } from 'react'
import { useForm } from '@inertiajs/react'
import SalesInquiryProgressRail, { SALES_INQUIRY_STEPS } from './SalesInquiryProgressRail'
import { JACKPOT_WORDMARK_INVERTED_SRC } from '../Brand/LogoMark'
import { SITE_PRIMARY_HEX } from '../../utils/colorUtils'

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

const FIELD_TO_STEP = {
    first_name: 0,
    last_name: 0,
    email: 0,
    phone: 0,
    job_title: 0,
    company: 1,
    company_website: 1,
    company_size: 1,
    industry: 1,
    use_case: 2,
    brand_count: 2,
    timeline: 2,
    heard_from: 2,
    message: 2,
}

const STEP_SIDE_COPY = [
    {
        title: 'Start with the basics',
        body: 'How we can reach you and your role—so the right person follows up.',
        imageSrc: '/img/presentation-wall-texture.jpg',
    },
    {
        title: 'Your organization',
        body: 'Context on company size and industry helps us speak your language.',
        imageSrc: '/img/presentation-desk-texture.jpg',
    },
    {
        title: 'What you need',
        body: 'Goals, timing, and brands—so we can tailor the conversation.',
        imageSrc: '/img/presentation-neutral-texture.jpg',
    },
    {
        title: "You're set",
        body: 'Review your details and send. We’ll respond within one business day.',
        imageSrc: '/img/presentation-wall-texture.jpg',
    },
]

function labelFor(options, value) {
    if (!value) return '—'
    return options.find((o) => o.value === value)?.label ?? value
}

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

    const [stepIndex, setStepIndex] = useState(0)
    const [stepHint, setStepHint] = useState('')

    const currentStepKey = SALES_INQUIRY_STEPS[stepIndex]

    useEffect(() => {
        if (!errors || typeof errors !== 'object') return
        const keys = Object.keys(errors).filter((k) => k in FIELD_TO_STEP)
        if (keys.length === 0) return
        const minStep = Math.min(...keys.map((k) => FIELD_TO_STEP[k]))
        setStepIndex(minStep)
    }, [errors])

    const validateStep = (idx) => {
        if (idx === 0) {
            if (!data.first_name?.trim() || !data.last_name?.trim() || !data.email?.trim()) {
                return 'Please add your first name, last name, and work email.'
            }
            const email = data.email.trim()
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                return 'Please enter a valid work email address.'
            }
        }
        if (idx === 1) {
            if (!data.company?.trim()) return 'Please add your company name.'
            if (!data.company_size) return 'Please select a company size.'
        }
        if (idx === 2) {
            if (!data.use_case) return 'Please select a primary use case.'
        }
        return ''
    }

    const goNext = () => {
        const err = validateStep(stepIndex)
        if (err) {
            setStepHint(err)
            return
        }
        setStepHint('')
        setStepIndex((i) => Math.min(i + 1, SALES_INQUIRY_STEPS.length - 1))
    }

    const goBack = () => {
        setStepHint('')
        setStepIndex((i) => Math.max(0, i - 1))
    }

    const submit = (e) => {
        e.preventDefault()
        const err = validateStep(0) || validateStep(1) || validateStep(2)
        if (err) {
            setStepHint(err)
            const fix = validateStep(0) ? 0 : validateStep(1) ? 1 : 2
            setStepIndex(fix)
            return
        }
        setStepHint('')
        post('/contact/sales', {
            preserveScroll: true,
            onSuccess: () => {
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
                )
                setStepIndex(0)
            },
        })
    }

    const side = STEP_SIDE_COPY[stepIndex]

    const gradientStyle = useMemo(
        () => ({
            background: `
                radial-gradient(ellipse 120% 80% at 100% 0%, ${SITE_PRIMARY_HEX}22 0%, transparent 55%),
                radial-gradient(ellipse 90% 70% at 0% 100%, ${SITE_PRIMARY_HEX}12 0%, transparent 50%),
                linear-gradient(165deg, rgba(255,255,255,0.04) 0%, rgba(255,255,255,0.01) 100%)
            `,
        }),
        [],
    )

    return (
        <div
            className="rounded-2xl sm:rounded-3xl ring-1 ring-white/[0.08] overflow-hidden"
            style={gradientStyle}
        >
            <div className="grid grid-cols-1 lg:grid-cols-12 gap-0 lg:gap-0">
                {/* Left: step imagery (placeholder — swap for product screenshots per step) */}
                <div className="lg:col-span-5 relative min-h-[200px] lg:min-h-[420px] border-b lg:border-b-0 lg:border-r border-white/[0.06] bg-black/20">
                    <div
                        className="absolute inset-0 opacity-40"
                        style={{
                            background: `radial-gradient(circle at 30% 20%, ${SITE_PRIMARY_HEX}35, transparent 65%)`,
                        }}
                        aria-hidden
                    />
                    <div className="relative z-10 flex flex-col h-full p-6 sm:p-8 lg:p-10">
                        <div className="flex-1 flex flex-col justify-center">
                            <div className="relative rounded-xl overflow-hidden ring-1 ring-white/10 bg-white/[0.03] aspect-[4/3] max-h-[220px] lg:max-h-none lg:aspect-auto lg:flex-1 lg:min-h-[200px]">
                                <img
                                    src={side.imageSrc}
                                    alt=""
                                    className="absolute inset-0 w-full h-full object-cover opacity-90"
                                />
                                <div
                                    className="absolute inset-x-0 top-0 h-28 bg-gradient-to-b from-black/70 via-black/25 to-transparent pointer-events-none"
                                    aria-hidden
                                />
                                <div className="absolute inset-0 bg-gradient-to-t from-[#0B0B0D]/90 via-[#0B0B0D]/20 to-transparent" />
                                <div className="absolute top-0 left-0 right-0 p-4 sm:p-5 z-[1]">
                                    <img
                                        src={JACKPOT_WORDMARK_INVERTED_SRC}
                                        alt="Jackpot"
                                        className="h-8 sm:h-9 w-auto max-w-[min(100%,14rem)] drop-shadow-[0_2px_14px_rgba(0,0,0,0.5)]"
                                        decoding="async"
                                    />
                                </div>
                                <div className="absolute bottom-0 left-0 right-0 p-4 sm:p-5">
                                    <p className="text-[10px] font-semibold uppercase tracking-[0.2em] text-white/45 mb-1">
                                        Preview
                                    </p>
                                    <p className="text-sm font-medium text-white/90 leading-snug">{side.title}</p>
                                </div>
                            </div>
                        </div>
                        <div className="mt-6 hidden lg:block">
                            <p className="text-sm text-white/50 leading-relaxed">{side.body}</p>
                        </div>
                    </div>
                </div>

                {/* Right: form steps */}
                <div className="lg:col-span-7 p-6 sm:p-8 lg:p-10 flex flex-col">
                    <div className="mb-6">
                        <SalesInquiryProgressRail currentStep={currentStepKey} />
                    </div>

                    <p className="lg:hidden text-sm text-white/45 mb-4 leading-relaxed">{side.body}</p>

                    {stepHint && (
                        <p className="mb-4 text-sm text-amber-200/90" role="status">
                            {stepHint}
                        </p>
                    )}

                    <form
                        onSubmit={stepIndex === SALES_INQUIRY_STEPS.length - 1 ? submit : (e) => e.preventDefault()}
                        noValidate
                        className="relative space-y-5 flex-1 flex flex-col"
                    >
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

                        {stepIndex === 0 && (
                            <fieldset className="space-y-4">
                                <legend className="sr-only">About you</legend>
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
                            </fieldset>
                        )}

                        {stepIndex === 1 && (
                            <fieldset className="space-y-4">
                                <legend className="sr-only">About your company</legend>
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
                            </fieldset>
                        )}

                        {stepIndex === 2 && (
                            <fieldset className="space-y-4">
                                <legend className="sr-only">What you are looking for</legend>
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
                            </fieldset>
                        )}

                        {stepIndex === 3 && (
                            <div className="space-y-5">
                                <div className="rounded-xl bg-white/[0.03] ring-1 ring-white/[0.06] p-5 space-y-4 text-sm">
                                    <h3 className="text-xs font-semibold uppercase tracking-wider text-white/40">Summary</h3>
                                    <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                                        <div>
                                            <dt className="text-white/35 text-xs uppercase tracking-wider">Name</dt>
                                            <dd className="text-white/85 mt-0.5">
                                                {data.first_name} {data.last_name}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-white/35 text-xs uppercase tracking-wider">Email</dt>
                                            <dd className="text-white/85 mt-0.5 break-all">{data.email}</dd>
                                        </div>
                                        {data.phone && (
                                            <div>
                                                <dt className="text-white/35 text-xs uppercase tracking-wider">Phone</dt>
                                                <dd className="text-white/85 mt-0.5">{data.phone}</dd>
                                            </div>
                                        )}
                                        {data.job_title && (
                                            <div>
                                                <dt className="text-white/35 text-xs uppercase tracking-wider">Title</dt>
                                                <dd className="text-white/85 mt-0.5">{data.job_title}</dd>
                                            </div>
                                        )}
                                        <div className="sm:col-span-2">
                                            <dt className="text-white/35 text-xs uppercase tracking-wider">Company</dt>
                                            <dd className="text-white/85 mt-0.5">{data.company}</dd>
                                        </div>
                                        {data.company_website?.trim() && (
                                            <div className="sm:col-span-2">
                                                <dt className="text-white/35 text-xs uppercase tracking-wider">Website</dt>
                                                <dd className="text-white/85 mt-0.5 break-all">{data.company_website}</dd>
                                            </div>
                                        )}
                                        <div>
                                            <dt className="text-white/35 text-xs uppercase tracking-wider">Size</dt>
                                            <dd className="text-white/85 mt-0.5">{labelFor(COMPANY_SIZES, data.company_size)}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-white/35 text-xs uppercase tracking-wider">Industry</dt>
                                            <dd className="text-white/85 mt-0.5">{labelFor(INDUSTRIES, data.industry)}</dd>
                                        </div>
                                        <div className="sm:col-span-2">
                                            <dt className="text-white/35 text-xs uppercase tracking-wider">Primary use case</dt>
                                            <dd className="text-white/85 mt-0.5">{labelFor(USE_CASES, data.use_case)}</dd>
                                        </div>
                                        {(data.brand_count || data.timeline) && (
                                            <div className="sm:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                {data.brand_count && (
                                                    <div>
                                                        <dt className="text-white/35 text-xs uppercase tracking-wider">Brands</dt>
                                                        <dd className="text-white/85 mt-0.5">{labelFor(BRAND_COUNTS, data.brand_count)}</dd>
                                                    </div>
                                                )}
                                                {data.timeline && (
                                                    <div>
                                                        <dt className="text-white/35 text-xs uppercase tracking-wider">Timeline</dt>
                                                        <dd className="text-white/85 mt-0.5">{labelFor(TIMELINES, data.timeline)}</dd>
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                        {data.heard_from?.trim() && (
                                            <div className="sm:col-span-2">
                                                <dt className="text-white/35 text-xs uppercase tracking-wider">Referral</dt>
                                                <dd className="text-white/85 mt-0.5">{data.heard_from}</dd>
                                            </div>
                                        )}
                                    </dl>
                                    {data.message?.trim() && (
                                        <div>
                                            <dt className="text-white/35 text-xs uppercase tracking-wider">Notes</dt>
                                            <dd className="text-white/70 mt-1 whitespace-pre-wrap">{data.message}</dd>
                                        </div>
                                    )}
                                </div>

                                <label className="flex items-start gap-2 text-xs text-white/45 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        checked={data.consent_marketing}
                                        onChange={(e) => setData('consent_marketing', e.target.checked)}
                                        className="mt-0.5 h-3.5 w-3.5 rounded border-white/20 bg-white/[0.03] text-violet-500 focus:ring-violet-400/60"
                                    />
                                    <span>
                                        I&apos;d also like to receive occasional product updates. (Optional — we&apos;ll follow up
                                        regardless.)
                                    </span>
                                </label>
                            </div>
                        )}

                        <div className="pt-4 mt-auto flex flex-col-reverse sm:flex-row sm:items-center sm:justify-between gap-3">
                            <div className="flex gap-2">
                                {stepIndex > 0 && (
                                    <button
                                        type="button"
                                        onClick={goBack}
                                        className="inline-flex items-center justify-center rounded-xl border border-white/[0.12] bg-white/[0.03] px-5 py-2.5 text-sm font-semibold text-white/80 hover:bg-white/[0.06] transition-colors"
                                    >
                                        Back
                                    </button>
                                )}
                            </div>
                            <div className="flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-4">
                                {stepIndex < SALES_INQUIRY_STEPS.length - 1 ? (
                                    <button
                                        type="button"
                                        onClick={goNext}
                                        className="inline-flex items-center justify-center rounded-xl bg-white px-6 py-3 text-sm font-semibold text-gray-900 shadow-lg shadow-black/20 hover:bg-gray-100 transition-colors"
                                    >
                                        Continue
                                    </button>
                                ) : (
                                    <>
                                        <span className="text-xs text-white/35 sm:order-first">
                                            We&apos;ll reply within one business day.
                                        </span>
                                        <button
                                            type="submit"
                                            disabled={processing}
                                            className="inline-flex items-center justify-center rounded-xl bg-white px-6 py-3 text-sm font-semibold text-gray-900 shadow-lg shadow-black/20 hover:bg-gray-100 transition-colors disabled:cursor-not-allowed disabled:opacity-40"
                                        >
                                            {processing ? 'Sending…' : 'Send request'}
                                        </button>
                                    </>
                                )}
                            </div>
                        </div>

                        {wasSuccessful && (
                            <p className="text-sm text-emerald-300/90" role="status">
                                Thanks — we&apos;ll be in touch shortly.
                            </p>
                        )}
                    </form>
                </div>
            </div>
        </div>
    )
}

const inputClass =
    'w-full rounded-xl bg-white/[0.03] px-4 py-3 text-sm text-white placeholder:text-white/30 ring-1 ring-inset ring-white/[0.08] focus:ring-2 focus:ring-violet-400/60 focus:outline-none transition-shadow'

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
