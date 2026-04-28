import { Link } from '@inertiajs/react'
import { hexToRgba } from '../../utils/colorUtils'
import { JACKPOT_VIOLET } from './brandWorkspaceTokens'

/**
 * Local sidebar for brand workbench (Manage, Insights, …). Active state = **product violet**, not customer brand.
 */
export default function WorkbenchLocalNav({ items = [], activeId, ariaLabel = 'Section navigation' }) {
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
                                ? 'font-medium text-violet-950'
                                : 'text-zinc-600 hover:bg-zinc-100/80 hover:text-zinc-900'
                        }`}
                        style={
                            isActive
                                ? {
                                      backgroundColor: hexToRgba(JACKPOT_VIOLET, 0.08),
                                      boxShadow: `inset 2px 0 0 0 ${JACKPOT_VIOLET}`,
                                  }
                                : undefined
                        }
                    >
                        {Icon ? (
                            <Icon
                                className={`h-[1.125rem] w-[1.125rem] shrink-0 ${isActive ? 'text-violet-600' : 'text-zinc-400'}`}
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
