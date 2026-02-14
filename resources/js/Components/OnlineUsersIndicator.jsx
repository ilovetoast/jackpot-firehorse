import { useState, useRef, useEffect } from 'react'
import { usePermission } from '../hooks/usePermission'
import { usePresencePolling } from '../hooks/usePresencePolling'

function formatActiveAgo(lastSeen) {
  if (!lastSeen || typeof lastSeen !== 'number') return ''
  const sec = Math.floor(Date.now() / 1000 - lastSeen)
  if (sec < 5) return 'Active now'
  if (sec < 60) return `Active ${sec} sec ago`
  const min = Math.floor(sec / 60)
  if (min < 60) return `Active ${min} min ago`
  return `Active ${Math.floor(min / 60)} hr ago`
}

export default function OnlineUsersIndicator({ textColor = '#6b7280', className = '' }) {
  const { can } = usePermission()
  const canSeePresence = can('team.manage') || can('brand_settings.manage')
  const { online } = usePresencePolling(canSeePresence)
  const [popoverOpen, setPopoverOpen] = useState(false)
  const popoverRef = useRef(null)
  const triggerRef = useRef(null)

  useEffect(() => {
    if (!popoverOpen) return
    const handleClickOutside = (e) => {
      if (
        popoverRef.current &&
        !popoverRef.current.contains(e.target) &&
        triggerRef.current &&
        !triggerRef.current.contains(e.target)
      ) {
        setPopoverOpen(false)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [popoverOpen])

  if (!canSeePresence) return null
  if (online.length === 0) return null

  return (
    <div className={`relative ${className}`}>
      <button
        ref={triggerRef}
        type="button"
        onClick={() => setPopoverOpen((v) => !v)}
        className="flex items-center gap-1.5 w-full px-3 py-2 text-xs rounded-md cursor-pointer hover:opacity-80 transition-opacity"
        style={{ color: textColor }}
        aria-label={`${online.length} online`}
      >
        <span
          className="w-2 h-2 rounded-full flex-shrink-0"
          style={{ backgroundColor: '#22c55e' }}
          aria-hidden
        />
        <span>{online.length} online</span>
      </button>

      {popoverOpen && (
        <div
          ref={popoverRef}
          className="absolute left-0 bottom-full mb-2 w-56 rounded-lg shadow-lg bg-white border border-gray-200 py-2 z-50"
          role="dialog"
          aria-label="Online users"
        >
          <div className="px-3 py-2 border-b border-gray-100">
            <span className="text-xs font-medium text-gray-500">Online</span>
          </div>
          <div className="max-h-48 overflow-y-auto">
            {online.map((u) => (
              <div
                key={u.id}
                className="px-3 py-2 text-sm text-gray-700"
              >
                <div className="font-medium">{u.name || 'Unknown'}</div>
                <div className="text-xs text-gray-500 mt-0.5">
                  {u.role ? `${u.role} • ` : ''}
                  {u.page ? `${u.page} • ` : ''}
                  {formatActiveAgo(u.last_seen)}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
