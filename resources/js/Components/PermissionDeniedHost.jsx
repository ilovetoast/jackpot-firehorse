import { useEffect, useRef, useState, useCallback } from 'react'
import { usePage } from '@inertiajs/react'
import PermissionDeniedModal from './PermissionDeniedModal'
import { resolvePermissionTheme } from '../utils/resolvePermissionTheme'

/**
 * Listens for jackpot:permission-denied and axios 403; shows themed modal when user is logged in.
 */
export default function PermissionDeniedHost() {
    const page = usePage()
    const pageRef = useRef(page)
    pageRef.current = page

    const [open, setOpen] = useState(false)
    const [title, setTitle] = useState('Access denied')
    const [message, setMessage] = useState('')
    const [theme, setTheme] = useState('jackpot')

    const show = useCallback((t, m, explicitTheme) => {
        setTitle(t || 'Access denied')
        setMessage(m || 'You do not have permission to perform this action.')
        setTheme(explicitTheme || resolvePermissionTheme(pageRef.current.url, pageRef.current.props.auth?.activeBrand))
        setOpen(true)
    }, [])

    useEffect(() => {
        const onDenied = (e) => {
            const d = e.detail || {}
            if (!pageRef.current.props.auth?.user) return
            show(d.title, d.message, d.theme)
        }
        window.addEventListener('jackpot:permission-denied', onDenied)
        return () => window.removeEventListener('jackpot:permission-denied', onDenied)
    }, [show])

    useEffect(() => {
        if (typeof window === 'undefined' || !window.axios) return undefined

        const id = window.axios.interceptors.response.use(
            (response) => response,
            (error) => {
                if (error.response?.status !== 403) {
                    return Promise.reject(error)
                }
                if (!pageRef.current.props.auth?.user) {
                    return Promise.reject(error)
                }
                const data = error.response.data
                let msg = 'You do not have permission to perform this action.'
                let ttl = 'Access denied'
                if (data && typeof data === 'object') {
                    if (data.message) msg = String(data.message)
                    if (data.error && typeof data.error === 'string') msg = data.error
                    if (data.title) ttl = String(data.title)
                } else if (typeof data === 'string' && data.length && data.length < 2000 && !data.trim().startsWith('<')) {
                    msg = data
                }
                window.dispatchEvent(
                    new CustomEvent('jackpot:permission-denied', {
                        detail: { title: ttl, message: msg, source: 'axios' },
                    })
                )
                return Promise.reject(error)
            }
        )

        return () => {
            window.axios.interceptors.response.eject(id)
        }
    }, [])

    return (
        <PermissionDeniedModal
            open={open}
            onClose={() => setOpen(false)}
            title={title}
            message={message}
            theme={theme}
        />
    )
}
