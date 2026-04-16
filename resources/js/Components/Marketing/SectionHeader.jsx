/**
 * Section title block: optional eyebrow, headline, description.
 */
export default function SectionHeader({ eyebrow, title, description, align = 'center', className = '' }) {
    const wrap = align === 'left' ? 'text-left max-w-3xl' : 'text-center mx-auto max-w-3xl'

    return (
        <div className={`${wrap} ${className}`}>
            {eyebrow && (
                <p className="text-sm font-semibold uppercase tracking-[0.2em] text-indigo-400/90">{eyebrow}</p>
            )}
            <h2 className={`mt-2 font-display text-3xl font-bold tracking-tight text-white sm:text-4xl lg:text-5xl text-balance`}>
                {title}
            </h2>
            {description && <p className="mt-5 text-lg leading-relaxed text-white/50 text-pretty">{description}</p>}
        </div>
    )
}
