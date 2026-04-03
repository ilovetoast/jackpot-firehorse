import { Link, usePage } from '@inertiajs/react'
import { useMemo, useState } from 'react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import {
    BookOpenIcon,
    ExclamationTriangleIcon,
    TrashIcon,
    InformationCircleIcon,
    TagIcon,
    KeyIcon,
    DocumentTextIcon,
    Squares2X2Icon,
    MagnifyingGlassIcon,
} from '@heroicons/react/24/outline'

function docHref(path) {
    return `/app/admin/documentation?doc=${encodeURIComponent(path)}`
}

/** Human-readable label; path stays in title tooltip */
function labelForPath(path) {
    const base = path.replace(/\.md$/i, '').split('/').pop() || path
    return base.replace(/_/g, ' ').replace(/-/g, ' ')
}

function folderLabel(segment) {
    if (segment === '') {
        return 'Root'
    }
    return segment.replace(/-/g, ' ')
}

export default function Documentation() {
    const { auth, docPath, docTitle, bodyHtml, docIndex } = usePage().props
    const [mainTab, setMainTab] = useState('repository')
    const [search, setSearch] = useState('')

    const sortedIndex = useMemo(() => {
        const list = Array.isArray(docIndex) ? [...docIndex] : []
        return list.sort((a, b) => {
            if (a === 'README.md') {
                return -1
            }
            if (b === 'README.md') {
                return 1
            }
            return a.localeCompare(b, undefined, { sensitivity: 'base' })
        })
    }, [docIndex])

    const filteredIndex = useMemo(() => {
        const q = search.trim().toLowerCase()
        if (!q) {
            return sortedIndex
        }
        return sortedIndex.filter((p) => p.toLowerCase().includes(q))
    }, [sortedIndex, search])

    const grouped = useMemo(() => {
        const groups = {}
        for (const path of filteredIndex) {
            const slash = path.indexOf('/')
            const key = slash === -1 ? '' : path.slice(0, slash)
            if (!groups[key]) {
                groups[key] = []
            }
            groups[key].push(path)
        }
        const order = Object.keys(groups).sort((a, b) => {
            if (a === '') {
                return -1
            }
            if (b === '') {
                return 1
            }
            return a.localeCompare(b)
        })
        return order.map((k) => ({ folder: k, paths: groups[k] }))
    }, [filteredIndex])

    return (
        <div className="min-h-full">
            <AppNav brand={auth?.activeBrand} tenant={null} />
            <main className="bg-slate-100/80">
                <div className="mx-auto max-w-[90rem] px-4 sm:px-6 lg:px-8 py-8">
                    <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <div className="flex items-center gap-3 mb-2">
                                <BookOpenIcon className="h-8 w-8 text-indigo-600" />
                                <h1 className="text-3xl font-bold tracking-tight text-slate-900">Admin Documentation</h1>
                            </div>
                            <p className="text-sm text-slate-600 max-w-2xl">
                                Engineering docs from <code className="text-xs bg-white px-1.5 py-0.5 rounded border border-slate-200">/docs</code>.
                                Open <span className="font-medium text-slate-800">README.md</span> for the index, then follow links or use the file list.
                            </p>
                        </div>
                        <div className="flex items-center gap-2 rounded-lg bg-white p-1 shadow-sm ring-1 ring-slate-200/80">
                            <button
                                type="button"
                                onClick={() => setMainTab('repository')}
                                className={`inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors ${
                                    mainTab === 'repository'
                                        ? 'bg-indigo-50 text-indigo-900 shadow-sm'
                                        : 'text-slate-600 hover:bg-slate-50'
                                }`}
                            >
                                <DocumentTextIcon className="h-5 w-5 shrink-0" />
                                Repository docs
                            </button>
                            <button
                                type="button"
                                onClick={() => setMainTab('platform')}
                                className={`inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors ${
                                    mainTab === 'platform'
                                        ? 'bg-indigo-50 text-indigo-900 shadow-sm'
                                        : 'text-slate-600 hover:bg-slate-50'
                                }`}
                            >
                                <Squares2X2Icon className="h-5 w-5 shrink-0" />
                                Platform procedures
                            </button>
                        </div>
                    </div>

                    {mainTab === 'repository' && (
                        <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
                            <aside className="w-full shrink-0 lg:w-80">
                                <div className="rounded-xl bg-white shadow-sm ring-1 ring-slate-200/80 overflow-hidden">
                                    <div className="px-4 py-3 border-b border-slate-100 bg-slate-50/90">
                                        <h2 className="text-sm font-semibold text-slate-900">Browse files</h2>
                                        <p className="text-xs text-slate-500 mt-0.5">
                                            {filteredIndex.length} of {sortedIndex.length} shown
                                        </p>
                                        <div className="mt-3 relative">
                                            <MagnifyingGlassIcon className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                                            <input
                                                type="search"
                                                value={search}
                                                onChange={(e) => setSearch(e.target.value)}
                                                placeholder="Filter by path…"
                                                className="block w-full rounded-md border border-slate-200 bg-white py-2 pl-8 pr-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                            />
                                        </div>
                                    </div>
                                    <nav className="max-h-[calc(100vh-14rem)] overflow-y-auto p-2 text-sm">
                                        {grouped.map(({ folder, paths }) => (
                                            <div key={folder || 'root'} className="mb-4 last:mb-0">
                                                <h3 className="px-2 mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                                    {folderLabel(folder)}
                                                </h3>
                                                <ul className="space-y-0.5">
                                                    {paths.map((path) => (
                                                        <li key={path}>
                                                            <Link
                                                                href={docHref(path)}
                                                                preserveScroll
                                                                title={path}
                                                                className={`block rounded-lg px-2 py-2 ${
                                                                    path === docPath
                                                                        ? 'bg-indigo-50 text-indigo-950 ring-1 ring-indigo-100'
                                                                        : 'text-slate-700 hover:bg-slate-50'
                                                                }`}
                                                            >
                                                                <span className="block text-sm font-medium text-slate-900 leading-snug">
                                                                    {labelForPath(path)}
                                                                </span>
                                                                <span className="block font-mono text-[0.65rem] text-slate-500 mt-0.5 break-all leading-tight">
                                                                    {path}
                                                                </span>
                                                            </Link>
                                                        </li>
                                                    ))}
                                                </ul>
                                            </div>
                                        ))}
                                        {filteredIndex.length === 0 && (
                                            <p className="px-2 py-4 text-sm text-slate-500">No files match your filter.</p>
                                        )}
                                    </nav>
                                </div>
                            </aside>

                            <div className="min-w-0 flex-1">
                                <div className="rounded-xl bg-white shadow-sm ring-1 ring-slate-200/80 overflow-hidden">
                                    <div className="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-indigo-50/90 to-white">
                                        <h2 className="text-xl font-semibold text-slate-900 tracking-tight" title={docPath}>
                                            {docTitle}
                                        </h2>
                                        <p className="text-xs font-mono text-slate-500 mt-1.5 break-all">{docPath}</p>
                                    </div>
                                    <div className="px-4 py-8 sm:px-10 bg-slate-50/40">
                                        <article
                                            className="documentation-html prose prose-slate prose-lg max-w-none
                                                prose-headings:scroll-mt-24 prose-headings:font-semibold prose-headings:tracking-tight
                                                prose-h1:text-2xl prose-h2:text-xl prose-h3:text-lg
                                                prose-a:text-indigo-600 prose-a:font-medium prose-a:no-underline hover:prose-a:underline
                                                prose-strong:text-slate-900
                                                prose-code:text-indigo-800 prose-code:bg-white prose-code:px-1 prose-code:py-0.5 prose-code:rounded prose-code:text-[0.9em] prose-code:font-normal prose-code:before:content-none prose-code:after:content-none
                                                prose-pre:bg-slate-900 prose-pre:text-slate-100 prose-pre:shadow-inner prose-pre:rounded-lg
                                                prose-table:text-sm prose-th:bg-slate-100 prose-th:font-semibold prose-td:border-slate-200
                                                prose-li:marker:text-slate-400
                                                rounded-xl bg-white px-5 py-8 sm:px-10 sm:py-10 shadow ring-1 ring-slate-100"
                                            // eslint-disable-next-line react/no-danger
                                            dangerouslySetInnerHTML={{ __html: bodyHtml }}
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {mainTab === 'platform' && <PlatformProceduresContent />}

                    <div className="mt-8 flex justify-end">
                        <Link
                            href="/app/admin"
                            className="rounded-md bg-white px-3 py-2 text-sm font-semibold text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50"
                        >
                            Back to Admin Dashboard
                        </Link>
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}

/** Static platform procedures (not from repo markdown). */
function PlatformProceduresContent() {
    return (
        <div className="max-w-4xl space-y-6">
            <div className="rounded-xl bg-white shadow-sm ring-1 ring-slate-200/80 overflow-hidden">
                <div className="px-6 py-4 border-b border-slate-100 bg-slate-50/90">
                    <div className="flex items-center gap-2">
                        <TagIcon className="h-5 w-5 text-indigo-600" />
                        <h2 className="text-lg font-semibold text-slate-900">System Categories</h2>
                    </div>
                </div>
                <div className="px-6 py-6">
                    <div className="prose prose-slate prose-sm max-w-none">
                        <h3 className="text-base font-semibold text-slate-900 mb-3">What happens when you update a system category template?</h3>
                        <div className="space-y-4">
                            <div className="rounded-lg bg-indigo-50/80 p-4 ring-1 ring-indigo-100">
                                <div className="flex gap-3">
                                    <InformationCircleIcon className="h-5 w-5 text-indigo-500 shrink-0 mt-0.5" />
                                    <p className="text-sm text-indigo-950">
                                        Template changes save in place. Existing brand categories keep their own names and icons
                                        until a tenant edits them; we do not push template renames to every brand.
                                    </p>
                                </div>
                            </div>
                            <div>
                                <h4 className="text-sm font-semibold text-slate-900 mb-2">Catalog &amp; new brands</h4>
                                <ul className="list-disc list-inside space-y-1 text-sm text-slate-700">
                                    <li>
                                        <strong>Auto-add to new brands:</strong> Only templates marked for auto-provision are
                                        copied when a brand is created; others stay in the catalog until a tenant adds them.
                                    </li>
                                    <li>
                                        <strong>Tenant control:</strong> Brands can rename, change icons, and hide system
                                        categories locally (slug stays fixed for stability).
                                    </li>
                                    <li>
                                        <strong>Visibility:</strong> Each brand has a cap on how many non-hidden categories
                                        they can show per asset type (assets and deliverables).
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div className="rounded-xl bg-white shadow-sm ring-1 ring-slate-200/80 overflow-hidden">
                <div className="px-6 py-4 border-b border-slate-100 bg-slate-50/90">
                    <div className="flex items-center gap-2">
                        <KeyIcon className="h-5 w-5 text-indigo-600" />
                        <h2 className="text-lg font-semibold text-slate-900">Tenant Ownership Transfer Process</h2>
                    </div>
                </div>
                <div className="px-6 py-6 prose prose-slate prose-sm max-w-none">
                    <p className="text-sm text-slate-700">
                        Tenant ownership transfer is a secure, multi-step workflow. Only the current tenant owner can initiate;
                        site admins cannot initiate transfers. Platform super-owner may force a transfer in emergencies only.
                        All steps are logged (initiated, confirmed, accepted, completed).
                    </p>
                </div>
            </div>

            <div className="rounded-xl bg-white shadow-sm ring-1 ring-slate-200/80 overflow-hidden">
                <div className="px-6 py-4 border-b border-slate-100 bg-slate-50/90">
                    <div className="flex items-center gap-2">
                        <TrashIcon className="h-5 w-5 text-indigo-600" />
                        <h2 className="text-lg font-semibold text-slate-900">Brand Deletion</h2>
                    </div>
                </div>
                <div className="px-6 py-6 prose prose-slate prose-sm max-w-none">
                    <div className="rounded-lg bg-red-50 border border-red-100 p-4 mb-4">
                        <p className="text-sm text-red-900 font-medium">Warning: irreversible</p>
                        <p className="text-sm text-red-800 mt-1">
                            Deleting a brand permanently removes associated S3 assets, categories, and invitations. Users are
                            detached from the brand but remain tenant members.
                        </p>
                    </div>
                    <p className="text-sm text-slate-700">
                        You cannot delete the default brand without choosing another default first, or delete the only brand
                        for a tenant.
                    </p>
                </div>
            </div>
        </div>
    )
}
