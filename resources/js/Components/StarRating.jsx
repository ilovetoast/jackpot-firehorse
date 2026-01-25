/**
 * Star Rating Component
 * 
 * Displays and allows editing of a star rating (1-5 stars).
 * Used for quality_rating and other rating-type metadata fields.
 */

import { useState } from 'react'
import { StarIcon } from '@heroicons/react/24/solid'
import { StarIcon as StarIconOutline } from '@heroicons/react/24/outline'

export default function StarRating({ value, onChange, editable = true, maxStars = 5, size = 'md' }) {
    const [hoveredStar, setHoveredStar] = useState(null)
    
    const currentRating = value ? parseInt(value, 10) : 0
    
    const sizeClasses = {
        sm: 'w-4 h-4',
        md: 'w-5 h-5',
        lg: 'w-6 h-6',
    }
    
    const starSize = sizeClasses[size] || sizeClasses.md
    
    const handleStarClick = (rating) => {
        if (editable && onChange) {
            // If clicking the same star, clear the rating (set to 0)
            const newRating = rating === currentRating ? 0 : rating
            onChange(newRating)
        }
    }
    
    const handleStarHover = (rating) => {
        if (editable) {
            setHoveredStar(rating)
        }
    }
    
    const handleMouseLeave = () => {
        if (editable) {
            setHoveredStar(null)
        }
    }
    
    const displayRating = hoveredStar !== null ? hoveredStar : currentRating
    
    return (
        <div 
            className="flex items-center gap-0.5"
            onMouseLeave={handleMouseLeave}
        >
            {Array.from({ length: maxStars }, (_, index) => {
                const starNumber = index + 1
                const isFilled = starNumber <= displayRating
                const isInteractive = editable && onChange
                
                return (
                    <button
                        key={starNumber}
                        type="button"
                        onClick={() => handleStarClick(starNumber)}
                        onMouseEnter={() => handleStarHover(starNumber)}
                        disabled={!isInteractive}
                        className={`
                            ${starSize} 
                            ${isInteractive ? 'cursor-pointer hover:scale-110 transition-transform' : 'cursor-default'}
                            ${isInteractive ? 'text-yellow-400 hover:text-yellow-500' : 'text-yellow-400'}
                            focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-1 rounded
                        `}
                        aria-label={`Rate ${starNumber} out of ${maxStars}`}
                        title={editable ? `Click to rate ${starNumber} out of ${maxStars}` : `${currentRating} out of ${maxStars} stars`}
                    >
                        {isFilled ? (
                            <StarIcon className="w-full h-full" />
                        ) : (
                            <StarIconOutline className="w-full h-full" />
                        )}
                    </button>
                )
            })}
            {currentRating > 0 && (
                <span className="ml-2 text-sm text-gray-600">
                    {currentRating}/{maxStars}
                </span>
            )}
        </div>
    )
}
