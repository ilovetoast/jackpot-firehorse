import { productFocusInput } from './brandWorkspaceTokens'

/** Neutral surfaces + violet for interaction — workbench / product only */
export const workbenchPanelClass =
    'rounded-xl border border-slate-200/90 bg-white shadow-sm'
export const workbenchPanelPadded = `${workbenchPanelClass} p-4 sm:p-5`
export const workbenchTableWrapClass = 'overflow-x-auto rounded-xl border border-slate-200/90 bg-white shadow-sm'
export const workbenchSectionTitleClass = 'text-base font-semibold text-slate-900 sm:text-lg'
export const workbenchSectionDescClass = 'text-sm text-slate-500'
export const workbenchLabelClass = 'text-[11px] font-semibold uppercase tracking-wide text-slate-500'
export const workbenchTableHeadRowClass =
    'border-b border-slate-200 bg-slate-50/80 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500'

/** Toolbar: time range, filters, search — wraps on small screens */
export const workbenchToolbarClass =
    'flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between sm:gap-3'

/** Text inputs & selects in workbench panels */
export const workbenchControlClass = `w-full rounded-lg border border-slate-200 bg-white py-2 pl-2.5 pr-2.5 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 ${productFocusInput} focus:outline-none focus:ring-1`
export const workbenchSelectClass = workbenchControlClass
export const workbenchInputClass = workbenchControlClass

/**
 * @param {object} props
 * @param {string} [props.title]
 * @param {string} [props.description] — one line, muted
 * @param {import('react').ReactNode} [props.actions]
 * @param {import('react').ReactNode} props.children
 * @param {string} [props.className]
 */
export function WorkbenchPageIntro({ title, description, actions = null, children = null, className = '' }) {
    return (
        <div className={`min-w-0 space-y-2 ${className}`.trim()}>
            {title ? <h2 className="text-lg font-semibold text-slate-900 sm:text-xl">{title}</h2> : null}
            {description ? <p className="max-w-3xl text-sm text-slate-500">{description}</p> : null}
            {actions}
            {children}
        </div>
    )
}

/**
 * @param {object} props
 * @param {string} [props.title] — card / panel title
 * @param {import('react').ReactNode} [props.headerRight]
 * @param {import('react').ReactNode} props.children
 * @param {string} [props.className] — on outer panel
 * @param {string} [props.bodyClassName] — inner padding area
 * @param {boolean} [props.flushed] — no default bottom padding in header
 */
export function WorkbenchPanel({ title, headerRight, children, className = '', bodyClassName = '' }) {
    const hasHeader = Boolean(title || headerRight)
    return (
        <section className={`${workbenchPanelClass} ${className}`.trim()}>
            {hasHeader ? (
                <div className="flex flex-col gap-2 border-b border-slate-100/90 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                    {title ? <h3 className="text-sm font-semibold text-slate-900">{title}</h3> : <span />}
                    {headerRight ? <div className="flex shrink-0 flex-wrap items-center gap-2">{headerRight}</div> : null}
                </div>
            ) : null}
            <div className={bodyClassName || (hasHeader ? 'p-4 sm:p-5' : 'p-4 sm:p-5')}>{children}</div>
        </section>
    )
}

/**
 * @param {object} props
 * @param {string} props.title
 * @param {string} [props.description]
 * @param {import('react').ReactNode} [props.action]
 * @param {import('react').ReactNode} [props.icon]
 */
export function WorkbenchEmptyState({ title, description, action = null, icon = null }) {
    return (
        <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-200/90 bg-slate-50/40 px-5 py-10 text-center sm:py-12">
            {icon ? <div className="mb-3 text-slate-300">{icon}</div> : null}
            <p className="text-sm font-medium text-slate-800">{title}</p>
            {description ? <p className="mt-1 max-w-md text-sm text-slate-500">{description}</p> : null}
            {action ? <div className="mt-4">{action}</div> : null}
        </div>
    )
}
