import { Link } from '@inertiajs/react'
import { motion } from 'framer-motion'
import { ArrowRightIcon, BuildingOffice2Icon } from '@heroicons/react/24/outline'

/**
 * Overview: compact entry to agency dashboard (managed clients + partner metrics).
 */
export default function ManagedCompaniesTeaser({
    count = 0,
    brandColor = '#6366f1',
}) {
    if (count < 1) {
        return null
    }

    return (
        <motion.div
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.4, delay: 0.12 }}
            className="w-full"
        >
            <div className="mb-3 flex items-center gap-2">
                <span className="text-[10px] font-medium uppercase tracking-wider text-white/35">
                    Agency dashboard
                </span>
            </div>

            <div className="rounded-2xl bg-gradient-to-br from-white/[0.07] to-white/[0.02] p-6 ring-1 ring-white/[0.1] backdrop-blur-sm sm:p-7">
                <div className="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between sm:gap-8">
                    <div className="flex min-w-0 flex-1 items-start gap-4">
                        <div
                            className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white/10"
                            style={{ boxShadow: `0 0 0 1px ${brandColor}33` }}
                        >
                            <BuildingOffice2Icon className="h-6 w-6 text-white/85" aria-hidden />
                        </div>
                        <div className="min-w-0 flex-1 space-y-2">
                            <p className="text-base font-semibold leading-snug tracking-tight text-white sm:text-lg">
                                {count} client {count === 1 ? 'company' : 'companies'}
                            </p>
                            <p className="max-w-xl text-sm leading-relaxed text-white/50">
                                Open client workspaces, partner tier, and program details.
                            </p>
                        </div>
                    </div>

                    <div className="shrink-0 sm:pl-2">
                        <Link
                            href="/app/agency/dashboard"
                            className="inline-flex w-full items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-white/10 px-4 py-3 text-xs font-semibold text-white ring-1 ring-white/15 transition hover:bg-white/15 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/40 sm:w-auto sm:px-5 sm:text-sm"
                        >
                            Open agency dashboard
                            <ArrowRightIcon className="h-4 w-4 shrink-0" aria-hidden />
                        </Link>
                    </div>
                </div>
            </div>
        </motion.div>
    )
}
