import { Link } from '@inertiajs/react'
import { hexToRgba } from '../../utils/colorUtils'
import { useBrandWorkbenchChrome } from '../../contexts/BrandWorkbenchChromeContext'
import { JACKPOT_VIOLET } from './brandWorkspaceTokens'

/**
 * Local sidebar for brand workbench (Manage, Insights). Active state uses brand workbench chrome when available.
 */
export default function WorkbenchLocalNav({ items = [], activeId, ariaLabel = 'Section navigation' }) {
    const chrome = useBrandWorkbenchChrome()
    const accent = chrome?.linkHex || JACKPOT_VIOLET

    return (
        <nav className="sticky top-8 space-y-0.5" aria-label={ariaLabel}>
            {items.map((item) => {
                const Icon = item.icon
                const isActive = activeId === item.id
                const disabled = item.disabled
                if (disabled) {
                    return (
                        <div
                            key={item.id}
                            className="flex cursor-not-allowed items-center gap-2.5 rounded-md px-2.5 py-1.5 text-sm text-zinc-400"
                        >
                            {Icon ? <Icon className="h-[1.125rem] w-[1.125rem] shrink-0 opacity-50" /> : null}
                            <span>{item.label}</span>
                            {item.suffix}
                        </div>
                    )
                }
                return (
                    <Link
                        key={item.id}
                        href={item.href}
                        className={`flex w-full min-w-0 items-center gap-2 rounded-md px-2.5 py-1.5 text-sm transition-colors ${
                            isActive
                                ? 'font-medium text-slate-900'
                                : 'text-zinc-600 hover:bg-zinc-100/80 hover:text-zinc-900'
                        }`}
                        style={
                            isActive
                                ? {
                                      backgroundColor: hexToRgba(accent, 0.08),
                                      boxShadow: `inset 2px 0 0 0 ${accent}`,
                                  }
                                : undefined
                        }
                    >
                        {Icon ? (
                            <Icon
                                className={`h-[1.125rem] w-[1.125rem] shrink-0 ${isActive ? '' : 'text-zinc-400'}`}
                                style={isActive ? { color: accent } : undefined}
                            />
                        ) : null}
                        <span className="min-w-0 flex-1">{item.label}</span>
                        {item.suffix}
                    </Link>
                )
            })}
        </nav>
    )
}
