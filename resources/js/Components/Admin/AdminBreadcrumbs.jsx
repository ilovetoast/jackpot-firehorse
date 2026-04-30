import { Link } from '@inertiajs/react'

/**
 * @param {{ label: string, href?: string }[]} items
 */
export default function AdminBreadcrumbs({ items = [] }) {
    if (!items.length) {
        return null
    }
    return (
        <nav className="mb-4 text-sm text-slate-500" aria-label="Breadcrumb">
            <ol className="flex flex-wrap items-center gap-1.5">
                {items.map((item, i) => (
                    <li key={i} className="flex items-center gap-1.5">
                        {i > 0 && <span className="text-slate-300" aria-hidden="true">/</span>}
                        {item.href && i < items.length - 1 ? (
                            <Link href={item.href} className="font-medium text-indigo-600 hover:text-indigo-800">
                                {item.label}
                            </Link>
                        ) : (
                            <span className={i === items.length - 1 ? 'font-semibold text-slate-800' : ''}>{item.label}</span>
                        )}
                    </li>
                ))}
            </ol>
        </nav>
    )
}
