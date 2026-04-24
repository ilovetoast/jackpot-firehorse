import type { InputHTMLAttributes, ReactNode } from 'react'

export function StudioCompactField({
    label,
    children,
    className = '',
}: {
    label: string
    children: ReactNode
    className?: string
}) {
    return (
        <div className={className}>
            <label className="mb-0.5 block text-[10px] font-medium text-gray-400">{label}</label>
            {children}
        </div>
    )
}

export function StudioNumberInput(props: InputHTMLAttributes<HTMLInputElement>) {
    return (
        <input
            {...props}
            className={`w-full rounded-md border border-gray-700/90 bg-gray-900/55 px-2 py-1 text-[11px] text-gray-200 ${
                props.className ?? ''
            }`}
        />
    )
}
