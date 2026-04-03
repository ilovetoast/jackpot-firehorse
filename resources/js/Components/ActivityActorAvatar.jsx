import { useState } from 'react'
import { UserIcon } from '@heroicons/react/24/outline'

export function initialsFromActor(actor) {
    if (actor?.first_name || actor?.last_name) {
        const a = (actor.first_name?.[0] || '').toUpperCase()
        const b = (actor.last_name?.[0] || '').toUpperCase()
        const pair = `${a}${b}`.trim()
        if (pair) return pair
    }
    const n = (actor?.name || '').trim()
    if (!n) return '?'
    const parts = n.split(/\s+/).filter(Boolean)
    if (parts.length >= 2) {
        return `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase()
    }
    return n.slice(0, 2).toUpperCase()
}

/**
 * Renders the actor (user) for activity rows — not the asset preview (which can 403 / fail for PSD, etc.).
 */
export default function ActivityActorAvatar({ actor, size = 'md' }) {
    const [imgFailed, setImgFailed] = useState(false)
    const type = actor?.type
    const url = actor?.avatar_url

    const box = size === 'sm' ? 'h-8 w-8 text-xs' : 'h-10 w-10 text-sm'
    const iconClass = size === 'sm' ? 'h-4 w-4' : 'h-5 w-5'

    if (type && type !== 'user') {
        return (
            <div
                className={`flex shrink-0 items-center justify-center rounded-full bg-gray-100 ring-1 ring-gray-200/80 ${box}`}
                aria-hidden
            >
                <UserIcon className={`${iconClass} text-gray-400`} />
            </div>
        )
    }

    if (url && !imgFailed) {
        return (
            <img
                src={url}
                alt=""
                className={`shrink-0 rounded-full object-cover ring-1 ring-gray-200/80 ${box}`}
                onError={() => setImgFailed(true)}
            />
        )
    }

    const initials = initialsFromActor(actor)
    return (
        <div
            className={`flex shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-indigo-500 to-violet-600 font-semibold text-white shadow-sm ring-1 ring-white/10 ${box}`}
            aria-hidden
        >
            {initials}
        </div>
    )
}
