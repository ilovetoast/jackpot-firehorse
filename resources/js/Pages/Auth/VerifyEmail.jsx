import { useState, useCallback } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { motion } from 'framer-motion'
import { EnvelopeIcon, CheckCircleIcon, ArrowRightOnRectangleIcon } from '@heroicons/react/24/outline'
import FilmGrainOverlay from '../../Components/FilmGrainOverlay'

export default function VerifyEmail({ email }) {
    const { flash } = usePage().props
    const [resending, setResending] = useState(false)
    const [sent, setSent] = useState(false)

    const handleResend = useCallback(() => {
        if (resending) return
        setResending(true)
        setSent(false)
        router.post('/email/resend', {}, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                setSent(true)
                setResending(false)
                setTimeout(() => setSent(false), 5000)
            },
            onError: () => setResending(false),
        })
    }, [resending])

    const handleLogout = useCallback(() => {
        router.post('/app/logout')
    }, [])

    return (
        <div className="min-h-screen bg-[#0B0B0D] text-white relative overflow-hidden">
            <Head title="Verify Your Email" />

            {/* Background */}
            <div
                className="fixed inset-0 pointer-events-none"
                style={{
                    background: `
                        radial-gradient(ellipse 80% 50% at 50% 0%, rgba(124, 58, 237, 0.1) 0%, transparent 50%),
                        #0B0B0D
                    `,
                }}
            />
            <div className="fixed inset-0 pointer-events-none">
                <div className="absolute inset-0 bg-black/20" />
                <div className="absolute inset-0 bg-gradient-to-b from-black/10 via-transparent to-black/30" />
            </div>

            {/* Content */}
            <div className="relative z-10 min-h-screen flex flex-col">
                {/* Header */}
                <div className="absolute top-6 left-6 right-6 flex justify-between items-center z-20">
                    <img
                        src="/jp-wordmark-inverted.svg"
                        alt="Jackpot"
                        className="h-7 w-auto opacity-60"
                        decoding="async"
                    />
                    <button
                        type="button"
                        onClick={handleLogout}
                        className="flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-full bg-white/[0.06] hover:bg-white/[0.1] text-white/50 hover:text-white/80 transition-all duration-300 tracking-wide"
                    >
                        <ArrowRightOnRectangleIcon className="h-3.5 w-3.5" />
                        Sign out
                    </button>
                </div>

                {/* Main */}
                <main className="flex-1 flex flex-col items-center justify-center px-6 pb-16 pt-24">
                    <motion.div
                        initial={{ opacity: 0, y: 12 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.5 }}
                        className="w-full max-w-md text-center"
                    >
                        {/* Icon */}
                        <div className="flex justify-center mb-8">
                            <div
                                className="h-20 w-20 rounded-2xl flex items-center justify-center"
                                style={{
                                    background: 'linear-gradient(135deg, rgba(124, 58, 237, 0.22), rgba(124, 58, 237, 0.08))',
                                    boxShadow: '0 0 40px rgba(124, 58, 237, 0.14)',
                                }}
                            >
                                <EnvelopeIcon className="h-9 w-9 text-violet-400" />
                            </div>
                        </div>

                        {/* Copy */}
                        <h1 className="text-3xl md:text-4xl font-semibold tracking-tight text-white/95 mb-3">
                            Verify your email
                        </h1>
                        <p className="text-base text-white/50 leading-relaxed max-w-sm mx-auto mb-2">
                            One more step before your workspace opens.
                        </p>
                        <p className="text-sm text-white/40 leading-relaxed max-w-sm mx-auto">
                            We sent a verification link to{' '}
                            <span className="text-white/70 font-medium">{email}</span>.
                            Check your inbox and click the link to continue setting up your brand hub.
                        </p>

                        {/* Status messages */}
                        {(flash?.status || sent) && (
                            <motion.div
                                initial={{ opacity: 0, y: 4 }}
                                animate={{ opacity: 1, y: 0 }}
                                className="mt-6 flex items-center justify-center gap-2 text-sm text-emerald-400"
                            >
                                <CheckCircleIcon className="h-5 w-5 shrink-0" />
                                <span>{flash?.status || 'Verification email sent!'}</span>
                            </motion.div>
                        )}

                        {/* Actions */}
                        <div className="mt-8 space-y-3">
                            <button
                                type="button"
                                onClick={handleResend}
                                disabled={resending}
                                className="w-full py-3.5 px-6 rounded-xl font-semibold text-white transition-all duration-300 disabled:opacity-40 hover:brightness-110 active:brightness-95"
                                style={{
                                    background: 'linear-gradient(135deg, #8b5cf6, #6d28d9)',
                                    boxShadow: '0 4px 24px rgba(124, 58, 237, 0.35)',
                                }}
                            >
                                {resending ? 'Sending…' : 'Resend verification email'}
                            </button>

                            <p className="text-xs text-white/30 leading-relaxed">
                                Didn't receive it? Check your spam folder, or resend the link above.
                            </p>
                        </div>
                    </motion.div>
                </main>

                {/* Footer */}
                <footer className="px-8 py-6 text-center">
                    <p className="text-[11px] text-white/20 tracking-widest uppercase">
                        Jackpot &middot; Brand asset manager
                    </p>
                </footer>
            </div>

            <FilmGrainOverlay />
        </div>
    )
}
