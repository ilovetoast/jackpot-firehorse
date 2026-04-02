/**
 * Minimal feature cards: title + description, optional icon (SVG path d=).
 */
export default function FeatureGrid({ items, columns = 3, className = '' }) {
    const grid =
        columns === 2
            ? 'sm:grid-cols-2'
            : columns === 4
              ? 'sm:grid-cols-2 lg:grid-cols-4'
              : 'sm:grid-cols-2 lg:grid-cols-3'

    return (
        <div className={`grid grid-cols-1 gap-6 lg:gap-8 ${grid} ${className}`}>
            {items.map((item) => (
                <div
                    key={item.title}
                    className="rounded-2xl bg-white/[0.03] p-8 ring-1 ring-white/[0.06] hover:ring-white/[0.1] transition-[box-shadow,ring-color] shadow-sm shadow-black/20"
                >
                    {item.iconPath && (
                        <div className="mb-5 flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500/20 to-violet-500/10 ring-1 ring-indigo-500/20">
                            <svg className="h-5 w-5 text-indigo-300" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" d={item.iconPath} />
                            </svg>
                        </div>
                    )}
                    <h3 className="text-lg font-semibold text-white tracking-tight">{item.title}</h3>
                    <p className="mt-3 text-sm leading-relaxed text-white/50">{item.description}</p>
                </div>
            ))}
        </div>
    )
}
