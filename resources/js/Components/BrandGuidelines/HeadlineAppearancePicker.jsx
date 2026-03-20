/**
 * Multi-select chips for predefined headline appearance options (config/headline_appearance.php).
 * Catalog is shared via Inertia (headlineAppearanceCatalog).
 */
export default function HeadlineAppearancePicker({ catalog = [], value = [], onChange, variant = 'dark' }) {
    const selected = Array.isArray(value) ? value : []
    const isDark = variant === 'dark'

    const toggle = (id) => {
        if (!id) return
        if (selected.includes(id)) {
            onChange?.(selected.filter((x) => x !== id))
        } else {
            onChange?.([...selected, id])
        }
    }

    if (!catalog.length) {
        return (
            <p className={`text-xs ${isDark ? 'text-white/40' : 'text-gray-500'}`}>
                Appearance options unavailable — refresh the page.
            </p>
        )
    }

    return (
        <div className="flex flex-wrap gap-2">
            {catalog.map((opt) => {
                const on = selected.includes(opt.id)
                return (
                    <button
                        key={opt.id}
                        type="button"
                        title={opt.description || opt.label}
                        onClick={() => toggle(opt.id)}
                        className={`px-3 py-1.5 rounded-lg text-xs font-medium transition border ${
                            on
                                ? isDark
                                    ? 'bg-white/15 border-white/30 text-white'
                                    : 'bg-indigo-50 border-indigo-200 text-indigo-900'
                                : isDark
                                  ? 'bg-white/[0.04] border-white/10 text-white/60 hover:bg-white/10'
                                  : 'bg-white border-gray-200 text-gray-700 hover:bg-gray-50'
                        }`}
                    >
                        {opt.label}
                    </button>
                )
            })}
        </div>
    )
}
