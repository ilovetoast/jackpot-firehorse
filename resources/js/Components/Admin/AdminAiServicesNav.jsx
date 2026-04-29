import { Link } from '@inertiajs/react'

/**
 * Shared cross-links for AI observability admin pages (hub, generative audit, layer extraction, BI, ops).
 */
export default function AdminAiServicesNav() {
    const link = 'text-sm font-medium text-slate-500 hover:text-slate-800'
    const sep = <span className="text-slate-300" aria-hidden="true">|</span>

    return (
        <nav
            className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm border-b border-slate-200 pb-3 mb-2 text-slate-600"
            aria-label="AI admin navigation"
        >
            <Link href="/app/admin" className={link}>
                ← Admin
            </Link>
            {sep}
            <Link href="/app/admin/ai" className={link}>
                AI Dashboard
            </Link>
            {sep}
            <Link href="/app/admin/ai/activity" className={link}>
                All AI activity
            </Link>
            {sep}
            <Link href="/app/admin/ai/analyzed-content" className={link}>
                AI services hub
            </Link>
            {sep}
            <Link href="/app/admin/brand-intelligence" className={link}>
                Brand Intelligence
            </Link>
            {sep}
            <Link href="/app/admin/ai/editor-image-audit" className={link}>
                Generative audit
            </Link>
            {sep}
            <Link href="/app/admin/ai/studio-layer-extraction" className={link}>
                Layer extraction
            </Link>
            {sep}
            <Link href="/app/admin/operations-center?tab=studio-exports" className={link}>
                Studio video exports
            </Link>
        </nav>
    )
}
