/**
 * Avatar Component
 * Displays user avatar image or initials fallback
 * 
 * @param {string} avatarUrl - URL to the user's avatar image
 * @param {string} firstName - User's first name
 * @param {string} lastName - User's last name
 * @param {string} email - User's email (fallback for initials)
 * @param {string} size - Size variant: 'sm', 'md', 'lg', 'xl' or custom className
 * @param {string} className - Additional CSS classes
 */
export default function Avatar({ 
    avatarUrl, 
    firstName, 
    lastName, 
    email, 
    size = 'md',
    className = '' 
}) {
    // Get initials from first/last name or email
    const getInitials = () => {
        if (firstName && lastName) {
            return (firstName.charAt(0) + lastName.charAt(0)).toUpperCase()
        }
        if (firstName) {
            return firstName.charAt(0).toUpperCase()
        }
        if (email) {
            return email.charAt(0).toUpperCase()
        }
        return '?'
    }

    // Size classes mapping
    const sizeClasses = {
        sm: 'h-8 w-8 text-xs',
        md: 'h-10 w-10 text-sm',
        lg: 'h-12 w-12 text-base',
        xl: 'h-16 w-16 text-lg',
    }

    // If size is a custom className, use it; otherwise use size mapping
    const sizeClass = sizeClasses[size] || size

    const baseClasses = 'flex items-center justify-center rounded-full bg-indigo-600 text-white font-medium flex-shrink-0'

    if (avatarUrl) {
        return (
            <img
                src={avatarUrl}
                alt={`${firstName || ''} ${lastName || ''}`.trim() || email || 'User'}
                className={`${baseClasses} ${sizeClass} ${className} object-cover`}
            />
        )
    }

    return (
        <div className={`${baseClasses} ${sizeClass} ${className}`}>
            {getInitials()}
        </div>
    )
}
