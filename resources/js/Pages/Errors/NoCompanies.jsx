import { Link } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import JackpotLogo from '../../Components/JackpotLogo'

function pendingInviteLabel(inv) {
    if (inv.company_name) return inv.company_name
    if (inv.brand_name) return inv.brand_name
    return null
}

export default function NoCompanies({ user, pending_workspace_invites: pendingInvitesProp = [] }) {
    const pendingInvites = Array.isArray(pendingInvitesProp) ? pendingInvitesProp : []
    const hasPendingInvite = pendingInvites.length > 0
    const names = pendingInvites.map(pendingInviteLabel).filter(Boolean)
    const invitePhrase =
        names.length === 0
            ? null
            : names.length === 1
              ? names[0]
              : names.length === 2
                ? `${names[0]} and ${names[1]}`
                : `${names.slice(0, -1).join(', ')}, and ${names[names.length - 1]}`

    return (
        <div className="min-h-screen bg-gradient-to-b from-slate-50 via-white to-indigo-50/30">
            <AppNav />
            <main className="mx-auto flex max-w-7xl flex-col items-center px-4 py-16 sm:px-6 lg:px-8">
                <div className="w-full max-w-lg rounded-2xl border border-slate-200/90 bg-white/95 px-8 py-10 text-center shadow-sm ring-1 ring-slate-900/5 backdrop-blur-sm">
                    <Link href="/" className="inline-flex justify-center focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 rounded-lg">
                        <JackpotLogo className="h-9 w-auto sm:h-10" textClassName="text-lg font-semibold tracking-tight text-slate-900 sm:text-xl" />
                    </Link>

                    <div className="mx-auto mt-6 h-1 w-10 rounded-full bg-indigo-600" aria-hidden="true" />

                    <h1 className="mt-6 text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">
                        No company access
                    </h1>

                    <div className="mt-5 space-y-4 text-base leading-relaxed text-slate-600">
                        {hasPendingInvite ? (
                            <>
                                <p>
                                    An invitation is waiting in your email. You don&apos;t have access to any companies yet — open the
                                    message we sent to{' '}
                                    <span className="font-medium text-slate-800">{user?.email}</span> and accept the invite to connect
                                    your account.
                                </p>
                                {invitePhrase ? (
                                    <p className="text-sm text-slate-500">
                                        Pending invite{names.length > 1 ? 's' : ''} include{' '}
                                        <span className="font-medium text-slate-700">{invitePhrase}</span>.
                                    </p>
                                ) : null}
                            </>
                        ) : (
                            <p>
                                You don&apos;t have access to any companies yet. If you were invited, check your email — or ask an admin
                                to send an invite to{' '}
                                <span className="font-medium text-slate-800">{user?.email}</span>.
                            </p>
                        )}
                    </div>

                    <div className="mt-10 flex flex-col gap-3 sm:flex-row sm:justify-center">
                        <Link
                            href="/app/profile"
                            className="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                        >
                            Profile
                        </Link>
                        <Link
                            href="/app/companies"
                            className="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-900 shadow-sm transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                        >
                            Companies
                        </Link>
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
