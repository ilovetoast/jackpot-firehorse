# Grid Entrance Animation — Intent & Behavior

## Intent

The grid entrance animation provides **perceived dimensionality** when new content appears. It should feel like tiles are gently settling into place, not like a loading spinner or busy effect.

**Goals:**
- Acknowledge that new content has loaded
- Add subtle depth (not literal rotation)
- Complete quickly — users should feel the app is responsive
- Cascade like a waterfall, but end within best-practice timing

---

## When Does It Run?

| Trigger | Current behavior |
|--------|------------------|
| **First page load** | ✅ Animates all |
| **Category / filter change** | ✅ Animates all (may revert later) |
| **Infinite scroll (load more)** | ✅ Animates new items only |

**Note:** Category/filter change currently triggers the cascade. To disable, remove the `else` branch that calls `setAnimatedIds(currIds)` on filter change.

---

## UX Best Practices (34 tiles)

| Metric | Target | Notes |
|--------|--------|------|
| Per-tile duration | 180–220ms | Feels snappy, not sluggish |
| Stagger delay | 10–15ms | 34 tiles × 15ms ≈ 500ms for last tile to start |
| Total cascade end | ~600–700ms | User perceives the cascade but it finishes quickly |
| Perceived depth | Subtle translate-y + scale | No rotation — illusion of depth |

**Current:** 40ms stagger + 300ms duration → 34 tiles × 40ms = 1.36s for last to start → ~1.6s total (too slow)

**Improved:** 12ms stagger + 200ms duration → 34 × 12 ≈ 400ms for last to start → ~600ms total for cascade to complete

---

## Depth Without Rotation

- **translate-y** (e.g. 4–6px): Slight lift from below
- **scale** (e.g. 0.98 → 1): Subtle “pop in”
- **opacity** (0 → 1): Standard fade

Avoid literal rotation for a more refined, professional feel.

---

## Configuration

The current implementation uses `animatedIds` and `prevIdsRef` to decide when to animate:

- **Initial load:** `prevIds.size === 0` → animate all
- **Append:** `isAppend` (previous IDs ⊆ current IDs, size increased) → animate only new items
- **Filter change:** Full replace → no animation (by design)

To animate on **every** content change (including filter), add `setAnimatedIds(currIds)` in the filter-change branch. Not recommended for typical UX.

---

## Placeholder / Future

If you want to disable the animation temporarily:

```jsx
// In AssetGrid.jsx: set isVisible = true for all items (skip animation)
const isVisible = true
```

Or gate via a prop:

```jsx
// AssetGrid: animateEntrance={true}
```
