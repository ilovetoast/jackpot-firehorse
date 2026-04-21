import { Head } from '@inertiajs/react'
import MarketingLayout from '../Marketing/MarketingLayout'

/**
 * Shared chrome for legal documents: Terms, Privacy, DPA, Accessibility, Subprocessors.
 * Keeps styling consistent and provides the standard "prose" typography we want for
 * long-form legal copy on the dark marketing shell.
 */
export default function LegalPage({ title, effectiveDate, lastUpdated, children }) {
    const effective = effectiveDate || lastUpdated
    return (
        <MarketingLayout cinematicBackdrop>
            <Head title={`${title} — Jackpot`} />
            <section className="relative">
                <div className="mx-auto max-w-3xl px-6 lg:px-8 py-16 sm:py-24">
                    <header className="mb-12 border-b border-white/[0.06] pb-8">
                        <p className="text-xs font-semibold uppercase tracking-[0.2em] text-white/40">Legal</p>
                        <h1 className="mt-3 font-display text-4xl font-bold tracking-tight text-white sm:text-5xl">
                            {title}
                        </h1>
                        {effective && (
                            <p className="mt-4 text-sm text-white/45">
                                {effectiveDate ? 'Effective' : 'Last updated'}: {effective}
                            </p>
                        )}
                    </header>
                    <article className="legal-prose text-[15px] leading-relaxed text-white/75">
                        {children}
                    </article>
                    <footer className="mt-16 border-t border-white/[0.06] pt-8 text-xs text-white/40 space-y-1">
                        <p>Jackpot Brand Asset Management, LLC ("Jackpot LLC")</p>
                        <p>Questions: <a href="mailto:support@jackpotbam.com" className="text-white/60 hover:text-white">support@jackpotbam.com</a></p>
                        <p>Legal notices: <a href="mailto:legal@jackpotbam.com" className="text-white/60 hover:text-white">legal@jackpotbam.com</a></p>
                    </footer>
                </div>
            </section>
        </MarketingLayout>
    )
}

/**
 * Styled section wrapper inside a legal document. Renders a numbered H2 anchored for linking.
 */
export function LegalSection({ id, number, title, children }) {
    return (
        <section id={id} className="mt-12 first:mt-0 scroll-mt-24">
            <h2 className="text-white font-display text-xl sm:text-2xl font-semibold tracking-tight">
                {number ? (
                    <>
                        <span className="text-white/40 mr-3 tabular-nums">{number}.</span>
                        {title}
                    </>
                ) : (
                    title
                )}
            </h2>
            <div className="mt-4 space-y-4">{children}</div>
        </section>
    )
}

/**
 * Styled <p> that matches the legal-prose rhythm and avoids clashing with Tailwind reset.
 */
export function LegalParagraph({ children, className = '' }) {
    return <p className={`text-white/75 ${className}`}>{children}</p>
}

/**
 * Styled ordered/unordered list for enumerating obligations, rights, or definitions.
 */
export function LegalList({ items, ordered = false, className = '' }) {
    const Tag = ordered ? 'ol' : 'ul'
    return (
        <Tag className={`ml-5 space-y-2 text-white/70 ${ordered ? 'list-decimal' : 'list-disc'} marker:text-white/30 ${className}`}>
            {items.map((item, i) => (
                <li key={i} className="pl-1">
                    {item}
                </li>
            ))}
        </Tag>
    )
}
