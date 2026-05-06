import {
    AdjustmentsHorizontalIcon,
    ArrowDownTrayIcon,
    BookOpenIcon,
    BuildingOffice2Icon,
    ChartBarIcon,
    ChatBubbleLeftRightIcon,
    ClipboardDocumentListIcon,
    Cog6ToothIcon,
    CreditCardIcon,
    DocumentTextIcon,
    FolderIcon,
    HomeIcon,
    PhotoIcon,
    RectangleGroupIcon,
    ShieldCheckIcon,
    SparklesIcon,
    Squares2X2Icon,
    UserCircleIcon,
    UserGroupIcon,
} from '@heroicons/react/24/outline'

/**
 * Monochrome nav-aligned icon for help topic category (Heroicons outline, same family as AppNav).
 * @param {string|null|undefined} category
 * @returns {import('@heroicons/react/24/outline').ForwardRefExoticComponent<React.SVGProps<SVGSVGElement>>}
 */
export function getHelpCategoryIcon(category) {
    const c = String(category || '')
        .trim()
        .toLowerCase()
    if (!c) {
        return DocumentTextIcon
    }
    if (c === 'concepts' || c.includes('concept')) {
        return BookOpenIcon
    }
    if (c === 'assets') {
        return PhotoIcon
    }
    if (c === 'executions') {
        return Squares2X2Icon
    }
    if (c === 'collections') {
        return FolderIcon
    }
    if (c === 'studio') {
        return RectangleGroupIcon
    }
    if (c.includes('ai & insights')) {
        return SparklesIcon
    }
    if (c === 'insights') {
        return ChartBarIcon
    }
    if (c === 'manage') {
        return AdjustmentsHorizontalIcon
    }
    if (c === 'company' || c === 'workspace') {
        return BuildingOffice2Icon
    }
    if (c === 'billing') {
        return CreditCardIcon
    }
    if (c === 'support') {
        return ChatBubbleLeftRightIcon
    }
    if (c === 'admin') {
        return ShieldCheckIcon
    }
    if (c === 'approvals') {
        return ClipboardDocumentListIcon
    }
    if (c === 'downloads') {
        return ArrowDownTrayIcon
    }
    if (c === 'brand guidelines' || c.includes('guidelines')) {
        return BookOpenIcon
    }
    if (c === 'brands' || c.includes('brand settings')) {
        return Cog6ToothIcon
    }
    if (c === 'team' || c === 'creators' || c === 'agency') {
        return UserGroupIcon
    }
    if (c === 'overview') {
        return HomeIcon
    }
    if (c === 'account') {
        return UserCircleIcon
    }
    return DocumentTextIcon
}

/**
 * Card-style help topic (Bulk Actions design language): icon well, title, optional description, category pill.
 * Icons stay slate by default; purple on hover / focus-within. Use `highlighted` for contextual suggestions.
 *
 * @param {{ item: { key?: string, title?: string, category?: string, page_label?: string, short_answer?: string }, onPick?: () => void, showDescription?: boolean, asStatic?: boolean, highlighted?: boolean, compact?: boolean }} props
 */
export function HelpTopicCard({
    item,
    onPick,
    showDescription = true,
    asStatic = false,
    highlighted = false,
    compact = false,
}) {
    const Icon = getHelpCategoryIcon(item?.category)
    const cat = String(item?.category || '').trim()
    const page = String(item?.page_label || '').trim()
    const categoryLabel = (() => {
        if (!cat && !page) {
            return ''
        }
        if (cat && page && cat.toLowerCase() !== page.toLowerCase()) {
            return `${cat} · ${page}`
        }
        return cat || page
    })()
    const short = showDescription && item?.short_answer ? String(item.short_answer).trim() : ''

    const pad = compact ? 'p-2.5' : 'p-3'
    const iconWell = compact ? 'h-8 w-8' : 'h-9 w-9'
    const iconCls = compact ? 'h-4 w-4' : 'h-5 w-5'
    const titleCls = compact ? 'text-xs font-semibold text-gray-900' : 'text-sm font-semibold text-gray-900'
    const descCls = compact ? 'text-[11px] leading-snug text-gray-500' : 'text-[11px] leading-snug text-gray-500'

    const surface = (() => {
        if (asStatic) {
            return highlighted
                ? 'border-violet-200/90 bg-violet-50/50 ring-1 ring-violet-100/80'
                : 'border-gray-100 bg-white'
        }
        return highlighted
            ? 'border-violet-200 bg-violet-50/60 shadow-sm ring-1 ring-violet-100/90 hover:border-violet-300 hover:bg-violet-50/90'
            : 'border-gray-100 bg-white hover:border-gray-200 hover:bg-gray-50/90'
    })()

    const motion = asStatic
        ? ''
        : 'transition-all duration-150 ease-out hover:-translate-y-px hover:shadow-md motion-reduce:transform-none motion-reduce:hover:shadow-sm'

    const focusRing =
        !asStatic &&
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-50 active:scale-[0.99] active:duration-75 motion-reduce:active:scale-100'

    const cardClass = `group w-full rounded-xl border text-left shadow-sm ${pad} ${surface} ${motion} ${!asStatic ? focusRing : ''}`

    const iconWellClass = asStatic
        ? `flex ${iconWell} shrink-0 items-center justify-center rounded-lg bg-gray-100`
        : `flex ${iconWell} shrink-0 items-center justify-center rounded-lg bg-gray-100 transition-colors duration-150 group-hover:bg-violet-50`

    const iconClass = asStatic
        ? `${iconCls} text-slate-500`
        : `${iconCls} text-slate-500 transition-colors duration-150 group-hover:text-violet-600`

    const body = (
        <div className="flex gap-3">
            <span className={iconWellClass} aria-hidden>
                <Icon className={iconClass} />
            </span>
            <div className="min-w-0 flex-1 pt-0.5">
                <span className={`block line-clamp-2 ${titleCls}`}>{item?.title || ''}</span>
                {short ? <p className={`mt-1 line-clamp-2 ${descCls}`}>{short}</p> : null}
                {categoryLabel ? (
                    <span className="mt-2 inline-flex max-w-full rounded-md bg-slate-100 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-slate-600 truncate">
                        {categoryLabel}
                    </span>
                ) : null}
            </div>
        </div>
    )

    if (asStatic) {
        return (
            <div className={cardClass} role="note">
                {body}
            </div>
        )
    }

    return (
        <button type="button" onClick={onPick} className={cardClass}>
            {body}
        </button>
    )
}

/**
 * Dense list row — implemented as a compact card for backwards compatibility.
 * @param {{ item: object, onPick?: () => void, showDescription?: boolean, asStatic?: boolean, highlighted?: boolean }} props
 */
export function HelpTopicListRow(props) {
    return <HelpTopicCard compact {...props} />
}
