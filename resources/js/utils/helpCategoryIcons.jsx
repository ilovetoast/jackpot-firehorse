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
 * Compact help topic row: neutral icon, title, category/page line, optional short line.
 * @param {{ item: { key?: string, title?: string, category?: string, page_label?: string, short_answer?: string }, onPick?: () => void, showDescription?: boolean, asStatic?: boolean }} props
 */
export function HelpTopicListRow({ item, onPick, showDescription = true, asStatic = false }) {
    const Icon = getHelpCategoryIcon(item?.category)
    const cat = String(item?.category || '').trim()
    const page = String(item?.page_label || '').trim()
    const metaLine = (() => {
        if (!cat && !page) {
            return ''
        }
        if (cat && page && cat !== page) {
            return `${cat} · ${page}`
        }
        return cat || page
    })()
    const short = showDescription && item?.short_answer ? String(item.short_answer).trim() : ''

    const className =
        'flex w-full min-h-[44px] gap-2.5 rounded-md px-2 py-2 text-left' +
        (asStatic ? ' text-gray-800' : ' hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-inset')

    const body = (
        <>
            <span className="mt-0.5 shrink-0" aria-hidden>
                <Icon className="h-5 w-5 text-slate-500" />
            </span>
            <span className="min-w-0 flex-1">
                <span className="block font-medium text-gray-900">{item?.title || ''}</span>
                {metaLine ? <span className="mt-0.5 block text-xs text-slate-600">{metaLine}</span> : null}
                {short ? (
                    <span className="mt-1 block line-clamp-2 text-xs text-slate-500">{short}</span>
                ) : null}
            </span>
        </>
    )

    if (asStatic) {
        return (
            <div className={className} role="note">
                {body}
            </div>
        )
    }

    return (
        <button type="button" onClick={onPick} className={className}>
            {body}
        </button>
    )
}
