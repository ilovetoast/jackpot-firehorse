import type { ButtonHTMLAttributes, ReactNode } from 'react'

export function StudioIconButton({
    children,
    active,
    danger,
    subtleDanger,
    size = 'md',
    className = '',
    ...rest
}: ButtonHTMLAttributes<HTMLButtonElement> & {
    children: ReactNode
    active?: boolean
    danger?: boolean
    /** Destructive affordance without a loud red tile — gray until hover/focus */
    subtleDanger?: boolean
    /** sm = compact row, md = default, lg = properties / touch-friendly */
    size?: 'sm' | 'md' | 'lg'
}) {
    const dim = size === 'sm' ? 'h-7 w-7' : size === 'lg' ? 'h-10 w-10' : 'h-8 w-8'
    const base = `inline-flex ${dim} shrink-0 items-center justify-center rounded-md border text-gray-300 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400/45 disabled:cursor-not-allowed disabled:opacity-40`
    const tone = subtleDanger
        ? 'border-gray-800/90 bg-gray-900/30 text-gray-400 hover:border-red-900/40 hover:bg-red-950/20 hover:text-red-300/95'
        : danger
          ? 'border-red-900/45 bg-red-950/25 text-red-300 hover:border-red-800/55 hover:bg-red-950/40'
          : active
            ? 'border-indigo-400/50 bg-indigo-950/55 text-indigo-100 shadow-sm'
            : 'border-gray-800/90 bg-gray-900/35 hover:border-gray-700 hover:bg-gray-800/50 hover:text-gray-100'
    return (
        <button type="button" className={`${base} ${tone} ${className}`} {...rest}>
            {children}
        </button>
    )
}
