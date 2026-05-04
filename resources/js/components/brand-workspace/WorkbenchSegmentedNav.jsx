import { Link } from '@inertiajs/react'
import { hexToRgba } from '../../utils/colorUtils'
import { useBrandWorkbenchChrome } from '../../contexts/BrandWorkbenchChromeContext'
import { JACKPOT_VIOLET } from './brandWorkspaceTokens'

/**
 * Horizontal segment control for workbench local nav on small viewports.
 * Active tint follows brand workbench chrome when wrapped in BrandWorkbenchChrome.
 */
export default function WorkbenchSegmentedNav({ items = [], activeId, ariaLabel = 'Section' }) {
    const chrome = useBrandWorkbenchChrome()
    const accent = chrome?.linkHex || JACKPOT_VIOLET

    return (
        <div className="w-full min-w-0 shrink-0 lg:hidden -mx-1" role="navigation" aria-label={ariaLabel}>
            <div className="overflow-x-auto pb-0.5 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                <ul className="inline-flex min-w-0 flex-nowrap gap-0.5 rounded-lg border border-slate-200/90 bg-slate-50/90 p-0.5">
                    {items.map((item) => {
                        const isActive = activeId === item.id
                        if (item.disabled) {
                            return (
                                <li key={item.id} className="shrink-0">
                                    <span className="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm text-slate-400">
                                        {item.label}
                                    </span>
                                </li>
                            )
                        }
                        return (
                            <li key={item.id} className="shrink-0">
                                <Link
                                    href={item.href}
                                    className={`inline-flex min-h-[2.5rem] max-w-[11rem] items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium transition-colors ${
                                        isActive
                                            ? 'text-slate-900'
                                            : 'text-slate-600 hover:bg-white/90 hover:text-slate-900'
                                    }`}
                                    style={
                                        isActive
                                            ? {
                                                  backgroundColor: hexToRgba(accent, 0.1),
                                                  boxShadow: `inset 0 -2px 0 0 ${accent}`,
                                              }
                                            : undefined
                                    }
                                    aria-current={isActive ? 'page' : undefined}
                                >
                                    <span className="truncate">{item.label}</span>
                                    {item.suffix}
                                </Link>
                            </li>
                        )
                    })}
                </ul>
            </div>
        </div>
    )
}
