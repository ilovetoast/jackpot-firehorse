import { useCallback, useRef, useState } from 'react'
import { XMarkIcon, ExclamationTriangleIcon, InformationCircleIcon } from '@heroicons/react/24/outline'

type ConfirmVariant = 'info' | 'warning' | 'danger'

interface ConfirmOptions {
    title: string
    message: string
    confirmText?: string
    cancelText?: string
    /** When false, only the primary action is shown (e.g. informational OK). */
    showCancel?: boolean
    variant?: ConfirmVariant
    icon?: React.ComponentType<React.SVGProps<SVGSVGElement>>
}

interface ConfirmState extends ConfirmOptions {
    open: boolean
}

const VARIANT_STYLES = {
    info: {
        iconBg: 'bg-indigo-500/20',
        iconColor: 'text-indigo-400',
        btnBg: 'bg-indigo-600 hover:bg-indigo-500',
        DefaultIcon: InformationCircleIcon,
    },
    warning: {
        iconBg: 'bg-amber-500/20',
        iconColor: 'text-amber-400',
        btnBg: 'bg-amber-600 hover:bg-amber-500',
        DefaultIcon: ExclamationTriangleIcon,
    },
    danger: {
        iconBg: 'bg-red-500/20',
        iconColor: 'text-red-400',
        btnBg: 'bg-red-600 hover:bg-red-500',
        DefaultIcon: ExclamationTriangleIcon,
    },
} as const

export function useEditorConfirm() {
    const [state, setState] = useState<ConfirmState>({
        open: false,
        title: '',
        message: '',
    })

    const resolveRef = useRef<((value: boolean) => void) | null>(null)

    const confirm = useCallback((opts: ConfirmOptions): Promise<boolean> => {
        return new Promise<boolean>((resolve) => {
            resolveRef.current = resolve
            setState({ ...opts, open: true })
        })
    }, [])

    const handleClose = useCallback((result: boolean) => {
        setState((prev) => ({ ...prev, open: false }))
        resolveRef.current?.(result)
        resolveRef.current = null
    }, [])

    return { confirmState: state, confirm, handleClose }
}

interface EditorConfirmDialogProps {
    state: ConfirmState
    onClose: (result: boolean) => void
}

export default function EditorConfirmDialog({ state, onClose }: EditorConfirmDialogProps) {
    if (!state.open) return null

    const variant = state.variant ?? 'info'
    const styles = VARIANT_STYLES[variant]
    const Icon = state.icon ?? styles.DefaultIcon
    const confirmText = state.confirmText ?? 'Confirm'
    const cancelText = state.cancelText ?? 'Cancel'
    const showCancel = state.showCancel !== false

    return (
        <div
            className="fixed inset-0 z-[60] flex items-center justify-center"
            onKeyDown={(e) => { if (e.key === 'Escape') onClose(false) }}
        >
            {/* Backdrop */}
            <div
                className="absolute inset-0 bg-black/60 backdrop-blur-sm"
                onClick={() => onClose(false)}
            />

            {/* Panel */}
            <div className="relative mx-4 w-full max-w-md animate-[jp-dialog-enter_0.15s_ease-out] overflow-hidden rounded-xl border border-gray-700 bg-gray-900 shadow-2xl ring-1 ring-white/5">
                <style>{`@keyframes jp-dialog-enter { from { opacity: 0; transform: scale(0.96) translateY(8px); } to { opacity: 1; transform: scale(1) translateY(0); } }`}</style>

                {/* Header strip */}
                <div className="flex items-start gap-3 px-5 pt-5 pb-0">
                    <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${styles.iconBg}`}>
                        <Icon className={`h-5 w-5 ${styles.iconColor}`} />
                    </div>
                    <div className="min-w-0 flex-1 pt-0.5">
                        <h3 className="text-sm font-semibold text-white">{state.title}</h3>
                        <p className="mt-1 text-[13px] leading-relaxed text-gray-400">{state.message}</p>
                    </div>
                    <button
                        type="button"
                        onClick={() => onClose(false)}
                        className="shrink-0 rounded-md p-1 text-gray-500 transition-colors hover:bg-gray-800 hover:text-gray-300"
                    >
                        <XMarkIcon className="h-4 w-4" />
                    </button>
                </div>

                {/* Actions */}
                <div className="flex items-center justify-end gap-2 px-5 pb-4 pt-5">
                    {showCancel && (
                        <button
                            type="button"
                            onClick={() => onClose(false)}
                            className="rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2 text-xs font-semibold text-gray-300 transition-colors hover:bg-gray-700 hover:text-white"
                        >
                            {cancelText}
                        </button>
                    )}
                    <button
                        type="button"
                        onClick={() => onClose(true)}
                        autoFocus
                        className={`rounded-lg px-3.5 py-2 text-xs font-semibold text-white shadow-sm transition-colors ${styles.btnBg}`}
                    >
                        {confirmText}
                    </button>
                </div>
            </div>
        </div>
    )
}
