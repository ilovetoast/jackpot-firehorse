# Suggestion Confidence Tiers

When generating suggestions (snapshot, archetype, future AI), use these tiers to avoid conflicts and maintain consistency.

## Tier 1: Structural Mismatch (0.85–0.95)

**Definition:** Snapshot vs draft comparison — website reality differs from configured standards.

| Example | Key | Confidence |
|---------|-----|-------------|
| Color palette mismatch | SUG:standards.allowed_color_palette | 0.9 |
| Font mismatch | SUG:standards.primary_font | 0.9 |

**Use when:** Detected website data (colors, fonts) does not match draft configuration.

---

## Tier 2: Identity-Derived Suggestion (0.7–0.85)

**Definition:** Derived from draft identity (archetype, personality) — not from website detection.

| Example | Key | Confidence |
|---------|-----|-------------|
| Archetype traits | SUG:expression.traits | 0.8 |
| Archetype tone keywords | SUG:expression.tone_keywords | 0.7 |

**Use when:** Draft has archetype but traits/tone are incomplete; suggestion comes from canonical archetype map.

---

## Tier 3: Informational Detection (0.5–0.7)

**Definition:** Informational only — detected data to surface, not a mismatch to fix.

| Example | Key | Confidence |
|---------|-----|-------------|
| Logo detected | SUG:standards.logo | 0.6 |

**Use when:** Snapshot detected something (logo, bio) worth surfacing; user may or may not act.

---

## Suggestion Type Semantics (Future)

- **update** = replace — full replacement of the target field
- **merge** = union without duplication — add suggested values to existing
- **informational** = display only — no apply action
