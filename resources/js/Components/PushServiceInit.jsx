import { usePage, router } from '@inertiajs/react'
import { useEffect, useRef } from 'react'
import { initPush } from '../services/pushService'

/**
 * Runs after login: OneSignal init + at-most-once permission prompt (server tracks push_prompted_at).
 */
export default function PushServiceInit() {
    const page = usePage()
    const { auth, oneSignal } = page.props
    const user = auth?.user
    const enabled = oneSignal?.client_enabled
    const cancelledRef = useRef(false)

    useEffect(() => {
        cancelledRef.current = false
        if (!enabled || !user?.id) {
            return
        }

        initPush(user).then((result) => {
            if (cancelledRef.current) {
                return
            }
            if (result?.didPrompt) {
                router.reload({ only: ['auth'] })
            }
        })

        return () => {
            cancelledRef.current = true
        }
    }, [enabled, user?.id, user?.push_prompted_at, user?.push_enabled])

    return null
}
