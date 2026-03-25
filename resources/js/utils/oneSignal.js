/**
 * Optional: call from a settings "Enable notifications" button after OneSignal is loaded.
 * Does not run if push is disabled (no SDK on the page).
 */
export async function requestOneSignalPushPermission() {
    if (typeof window === 'undefined') {
        return false
    }

    window.OneSignalDeferred = window.OneSignalDeferred || []

    return new Promise((resolve) => {
        window.OneSignalDeferred.push(async function (OneSignal) {
            try {
                await OneSignal.Notifications.requestPermission()
                resolve(true)
            } catch {
                resolve(false)
            }
        })
    })
}
