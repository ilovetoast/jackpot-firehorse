/**
 * Shared brand options used by Brand Builder wizard and Brand Settings UI.
 */

export const ARCHETYPES = [
    { id: 'Creator', desc: 'Innovation, imagination, self-expression' },
    { id: 'Caregiver', desc: 'Compassion, nurturing, protection' },
    { id: 'Ruler', desc: 'Leadership, control, responsibility' },
    { id: 'Jester', desc: 'Joy, humor, playfulness' },
    { id: 'Everyman', desc: 'Belonging, realism, connection' },
    { id: 'Lover', desc: 'Passion, intimacy, appreciation' },
    { id: 'Hero', desc: 'Courage, mastery, triumph' },
    { id: 'Outlaw', desc: 'Rebellion, liberation, disruption' },
    { id: 'Magician', desc: 'Transformation, vision, catalyst' },
    { id: 'Innocent', desc: 'Purity, optimism, simplicity' },
    { id: 'Sage', desc: 'Wisdom, truth, clarity' },
    { id: 'Explorer', desc: 'Freedom, discovery, authenticity' },
] as const

export const ARCHETYPE_RECOMMENDED_TRAITS: Record<string, string[]> = {
    Ruler: ['decisive', 'authoritative', 'precise', 'commanding', 'confident'],
    Creator: ['imaginative', 'innovative', 'expressive', 'artistic', 'original'],
    Caregiver: ['warm', 'supportive', 'nurturing', 'compassionate', 'gentle'],
    Jester: ['playful', 'humorous', 'witty', 'fun', 'lighthearted'],
    Everyman: ['friendly', 'relatable', 'down-to-earth', 'approachable', 'honest'],
    Lover: ['passionate', 'sensual', 'romantic', 'intimate', 'devoted'],
    Hero: ['courageous', 'determined', 'inspiring', 'bold', 'strong'],
    Outlaw: ['rebellious', 'edgy', 'disruptive', 'bold', 'unconventional'],
    Magician: ['transformative', 'visionary', 'charismatic', 'mysterious', 'innovative'],
    Innocent: ['pure', 'optimistic', 'simple', 'trustworthy', 'hopeful'],
    Sage: ['wise', 'knowledgeable', 'thoughtful', 'analytical', 'insightful'],
    Explorer: ['adventurous', 'independent', 'pioneering', 'curious', 'free'],
}
