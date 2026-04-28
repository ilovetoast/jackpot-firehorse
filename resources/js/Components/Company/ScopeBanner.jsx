/**
 * Company vs brand scope callout. Keeps account-level changes distinct from brand settings.
 */
export default function ScopeBanner({ scope, name, className = '' }) {
    const isCompany = scope === 'company'
    return (
        <div
            className={[
                'rounded-lg border px-4 py-3.5 text-sm',
                isCompany
                    ? 'border-slate-200/90 bg-slate-50/95 text-slate-700'
                    : 'border-slate-200/80 bg-white/90 text-slate-600',
                className,
            ].join(' ')}
            role="note"
        >
            <p className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <span
                    className={[
                        'shrink-0 text-[10px] font-bold uppercase tracking-[0.2em] text-slate-500',
                        isCompany ? '' : 'text-violet-800/90',
                    ].join(' ')}
                >
                    Scope: {isCompany ? 'Company' : 'Brand'}
                </span>
                <span className="hidden min-[380px]:inline text-slate-300" aria-hidden>
                    |
                </span>
                <span>
                    {isCompany ? (
                        <>Changes here affect all brands in {name}.</>
                    ) : (
                        <>Changes here affect only {name}.</>
                    )}
                </span>
            </p>
        </div>
    )
}
