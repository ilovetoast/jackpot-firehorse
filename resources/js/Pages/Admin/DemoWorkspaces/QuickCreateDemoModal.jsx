import { useEffect, useMemo, useState } from 'react'
import { router } from '@inertiajs/react'

/**
 * @typedef {{ id: number, name: string, slug: string }} QuickTemplate
 * @typedef {{
 *   cloning_enabled: boolean,
 *   templates: QuickTemplate[],
 *   default_template_id: number | null,
 *   allowed_expiration_days: number[],
 *   default_plan_key: string,
 *   default_expiration_days: number,
 * }} QuickCreateConfig
 */

function parseEmailsFromText(text) {
    return Array.from(
        new Set(
            String(text || '')
                .split(/[\s,;]+/)
                .map((s) => s.trim())
                .filter(Boolean),
        ),
    )
}

function flattenErrors(errors) {
    if (!errors || typeof errors !== 'object') {
        return []
    }
    const lines = []
    for (const v of Object.values(errors)) {
        if (Array.isArray(v)) {
            lines.push(...v.map(String))
        } else if (typeof v === 'string') {
            lines.push(v)
        }
    }
    return lines
}

/** @param {{ quick_create: QuickCreateConfig, plan_options: { value: string, label: string }[] }} props */
export default function QuickCreateDemoModal({ quick_create: quickCreate, plan_options: planOptions = [] }) {
    const [open, setOpen] = useState(false)
    const [phase, setPhase] = useState('form')
    const [templateId, setTemplateId] = useState(() => quickCreate?.default_template_id ?? '')
    const [planKey, setPlanKey] = useState(() => quickCreate?.default_plan_key ?? 'pro')
    const [expirationDays, setExpirationDays] = useState(() => Number(quickCreate?.default_expiration_days ?? 7))
    const [emailsText, setEmailsText] = useState('')
    const [demoLabel, setDemoLabel] = useState('')
    const [serverMessage, setServerMessage] = useState('')
    const [fieldErrors, setFieldErrors] = useState([])
    const [successPayload, setSuccessPayload] = useState(null)

    const templates = quickCreate?.templates ?? []
    const canSubmit = quickCreate?.cloning_enabled && templates.length > 0

    useEffect(() => {
        if (!open) {
            return
        }
        setPhase('form')
        setSuccessPayload(null)
        setServerMessage('')
        setFieldErrors([])
        setPlanKey(quickCreate?.default_plan_key ?? 'pro')
        setExpirationDays(Number(quickCreate?.default_expiration_days ?? 7))
        setEmailsText('')
        setDemoLabel('')
        const tpl = quickCreate?.templates ?? []
        const tid = quickCreate?.default_template_id ?? tpl[0]?.id ?? ''
        setTemplateId(tid === '' || tid === undefined ? '' : tid)
    }, [open, quickCreate])

    useEffect(() => {
        if (!open) {
            return undefined
        }
        const onKey = (e) => {
            if (e.key === 'Escape' && phase === 'form') {
                setOpen(false)
            }
        }
        window.addEventListener('keydown', onKey)
        return () => window.removeEventListener('keydown', onKey)
    }, [open, phase])

    const expirationOptions = useMemo(() => {
        const raw = quickCreate?.allowed_expiration_days ?? [7, 14]
        return raw.map((d) => ({ value: Number(d), label: `${d} days` }))
    }, [quickCreate])

    const submit = async () => {
        setServerMessage('')
        setFieldErrors([])
        setPhase('submitting')

        const invitedEmails = parseEmailsFromText(emailsText)
        const body = {
            template_id: Number(templateId),
            plan_key: planKey,
            expiration_days: Number(expirationDays),
            invited_emails: invitedEmails,
        }
        const trimmedLabel = demoLabel.trim()
        if (trimmedLabel !== '') {
            body.target_demo_label = trimmedLabel
        }

        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
            const res = await fetch(route('admin.demo-workspaces.quick-create'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(body),
            })
            const data = await res.json().catch(() => ({}))

            if (!res.ok) {
                setPhase('form')
                setServerMessage(data.message || 'Request failed.')
                const flat = flattenErrors(data.errors)
                if (data.errors?.blockers?.length) {
                    setFieldErrors(data.errors.blockers.map(String))
                } else if (flat.length) {
                    setFieldErrors(flat)
                }
                return
            }

            setSuccessPayload(data)
            setPhase('success')
        } catch {
            setPhase('form')
            setServerMessage('Network error. Try again.')
        }
    }

    const copyGateway = () => {
        if (!successPayload?.gateway_url) {
            return
        }
        void navigator.clipboard.writeText(successPayload.gateway_url)
    }

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                disabled={!canSubmit}
                title={
                    !quickCreate?.cloning_enabled
                        ? 'Turn on DEMO_CLONING_ENABLED to create demos.'
                        : templates.length === 0
                          ? 'Create at least one demo template tenant first.'
                          : ''
                }
                className="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-600"
            >
                Create Demo
            </button>

            {open ? (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4"
                    role="presentation"
                    onClick={(e) => {
                        if (e.target === e.currentTarget && phase === 'form') {
                            setOpen(false)
                        }
                    }}
                >
                    <div
                        className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-6 shadow-xl"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="quick-create-demo-title"
                    >
                        <div className="mb-4 flex items-start justify-between gap-3">
                            <h2 id="quick-create-demo-title" className="text-lg font-semibold text-slate-900">
                                Create demo workspace
                            </h2>
                            {phase === 'form' ? (
                                <button
                                    type="button"
                                    onClick={() => setOpen(false)}
                                    className="rounded-md p-1 text-slate-500 hover:bg-slate-100 hover:text-slate-800"
                                    aria-label="Close"
                                >
                                    ✕
                                </button>
                            ) : null}
                        </div>

                        {phase === 'submitting' ? (
                            <div className="py-10 text-center">
                                <div className="mx-auto mb-4 h-10 w-10 animate-spin rounded-full border-2 border-indigo-600 border-t-transparent" />
                                <p className="text-sm font-medium text-slate-800">Preparing demo workspace…</p>
                                <p className="mt-2 text-xs text-slate-500">Running checks and queueing the clone job.</p>
                            </div>
                        ) : null}

                        {phase === 'form' ? (
                            <div className="space-y-4">
                                {!quickCreate?.cloning_enabled ? (
                                    <p className="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200">
                                        Demo cloning is off. Set <code className="rounded bg-amber-100 px-1">DEMO_CLONING_ENABLED=true</code> in
                                        the environment, then refresh.
                                    </p>
                                ) : null}
                                {templates.length === 0 ? (
                                    <p className="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-700 ring-1 ring-slate-200">
                                        No demo templates exist yet. Mark a tenant as a demo template in the database before using this flow.
                                    </p>
                                ) : null}

                                {serverMessage ? (
                                    <p className="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-900 ring-1 ring-rose-200">{serverMessage}</p>
                                ) : null}
                                {fieldErrors.length > 0 ? (
                                    <ul className="list-inside list-disc text-sm text-rose-800">
                                        {fieldErrors.map((line, i) => (
                                            <li key={`${i}-${line}`}>{line}</li>
                                        ))}
                                    </ul>
                                ) : null}

                                <label className="block text-sm font-medium text-slate-700">
                                    Template
                                    <select
                                        className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900"
                                        value={templateId}
                                        onChange={(e) => setTemplateId(e.target.value === '' ? '' : Number(e.target.value))}
                                    >
                                        {templates.map((t) => (
                                            <option key={t.id} value={t.id}>
                                                {t.name} (#{t.id})
                                            </option>
                                        ))}
                                    </select>
                                </label>

                                <label className="block text-sm font-medium text-slate-700">
                                    Plan
                                    <select
                                        className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900"
                                        value={planKey}
                                        onChange={(e) => setPlanKey(e.target.value)}
                                        required
                                    >
                                        {planOptions.map((o) => (
                                            <option key={o.value} value={o.value}>
                                                {o.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>

                                <label className="block text-sm font-medium text-slate-700">
                                    Expiration
                                    <select
                                        className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900"
                                        value={expirationDays}
                                        onChange={(e) => setExpirationDays(Number(e.target.value))}
                                    >
                                        {expirationOptions.map((o) => (
                                            <option key={o.value} value={o.value}>
                                                {o.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>

                                <label className="block text-sm font-medium text-slate-700">
                                    Invitee emails
                                    <textarea
                                        className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900"
                                        rows={3}
                                        placeholder="Comma or line separated. If empty, your account email is used."
                                        value={emailsText}
                                        onChange={(e) => setEmailsText(e.target.value)}
                                    />
                                </label>

                                <label className="block text-sm font-medium text-slate-700">
                                    Demo label <span className="font-normal text-slate-500">(optional)</span>
                                    <input
                                        type="text"
                                        className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900"
                                        placeholder="Shown as the company name for the new workspace"
                                        value={demoLabel}
                                        onChange={(e) => setDemoLabel(e.target.value)}
                                        maxLength={120}
                                    />
                                </label>

                                <div className="flex justify-end gap-2 pt-2">
                                    <button
                                        type="button"
                                        onClick={() => setOpen(false)}
                                        className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => void submit()}
                                        disabled={!canSubmit || !templateId}
                                        className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:bg-slate-300"
                                    >
                                        Create
                                    </button>
                                </div>
                            </div>
                        ) : null}

                        {phase === 'success' && successPayload ? (
                            <div className="space-y-4">
                                <p className="text-sm font-medium text-emerald-800">Demo workspace queued.</p>
                                {successPayload.note ? <p className="text-xs text-slate-600">{successPayload.note}</p> : null}
                                <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-800">
                                    <span className="text-slate-500">Tenant</span> #{successPayload.tenant?.id} ·{' '}
                                    <code className="rounded bg-white px-1">{successPayload.tenant?.slug}</code>
                                </div>
                                <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                                    <button
                                        type="button"
                                        onClick={() => copyGateway()}
                                        className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-800 hover:bg-slate-50"
                                    >
                                        Copy access URL
                                    </button>
                                    <a
                                        href={successPayload.gateway_url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="inline-flex justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500"
                                    >
                                        Open demo
                                    </a>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setOpen(false)
                                            router.visit(successPayload.view_details_url)
                                        }}
                                        className="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-medium text-indigo-900 hover:bg-indigo-100"
                                    >
                                        View details
                                    </button>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setOpen(false)}
                                    className="w-full rounded-lg border border-slate-300 bg-white py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                                >
                                    Done
                                </button>
                            </div>
                        ) : null}
                    </div>
                </div>
            ) : null}
        </>
    )
}
