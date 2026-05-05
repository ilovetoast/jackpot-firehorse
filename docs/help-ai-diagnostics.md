# Help AI diagnostics (internal)

In-app **Ask AI** answers are **grounded only** in entries from `config/help_actions.php` (retrieved and scored in `HelpActionService`, then optionally summarized by the LLM in `HelpAiAskService`). This document explains how operators use persisted asks to improve the help map over time.

## Where data lives

- **Table**: `help_ai_questions`
- **Model**: `App\Models\HelpAiQuestion`
- **Written on**: every `POST /app/help/ask` that runs `HelpAiAskService` (success paths and fallbacks). **Not** written for **`workspace_required`** (no tenant in session — see [help-actions](./help-actions.md) Phase 2).
- **`response_kind`** (internal diagnostics label; client `kind` may differ slightly):
  - `no_strong_match` — best score below threshold; no LLM call (client `kind`: `fallback`).
  - `ai` — LLM returned a sanitized answer (client `kind`: `ai`).
  - `ai_failed` — strong match but provider/parse failure; user saw primary topic fallback (client `kind`: `fallback_action`).
  - `ai_disabled` — tenant turned off AI in settings (client `kind`: `ai_disabled`).
  - `feature_disabled` — platform flag `config('ai.help_ask.enabled')` is false (client `kind`: `feature_disabled`).

User thumbs and optional notes update the same row (`feedback_rating`, `feedback_note`, `feedback_submitted_at`).

## Admin UI

Site staff with **`ai.dashboard.view`** can open **AI Control Center → Help AI** (`/app/admin/ai/help-diagnostics`).

The page surfaces:

- **Recent asks** — raw stream with tenant, kind, score, feedback.
- **No strong match (sample)** — questions that never reached the LLM; primary signal for **new aliases, tags, or help_actions entries**.
- **AI failures** — strong match but LLM path failed; check provider health and whether the retrieved JSON was malformed.
- **Top matched action keys** — demand-weighted view of what retrieval surfaced most often (from `matched_action_keys`).
- **Repeated unanswered questions** — exact duplicate question text among `no_strong_match` and `ai_failed` rows (quick wins for documentation).
- **Estimated cost** — sum of `cost` on successful `ai` rows in the selected window (not a vendor invoice).

## Improving the help map (no vector search)

1. **Sort by “no strong match” and repeated patterns**  
   If users ask the same thing multiple times and scoring stays low, add or extend a help action:
   - New `key` with `title`, `short_answer`, `steps`, `aliases`, and `tags` that mirror real language.
   - Or extend an existing action’s `aliases` / `tags` so `HelpActionService` ranking picks it up.

2. **Use “top matched keys”**  
   High counts show where people already land; ensure those entries are accurate and cross-link `related` actions for adjacent questions.

3. **Read “not helpful” feedback**  
   Optional notes in `help_ai_questions` explain gaps; pair with the stored `question` and `matched_action_keys` to decide whether retrieval or copy needs work.

4. **AI failures**  
   If failures cluster on one tenant or time window, treat as operations; if they correlate with specific actions, check that serialized JSON (steps, URLs) is valid and not overwhelming the prompt.

5. **Keep grounding**  
   Do not point the model at arbitrary docs or the open web in this flow. Improvements should flow through **more and better `help_actions` entries** so retrieval + prompt stay the single source of truth.

## Product constraints (current phase)

- **No vector search** — matching is config-driven scoring only.
- **No screenshots or tours** — deep links and `highlight` selectors only where already modeled in help actions.
- **No free-form AI knowledge** — answers must come from retrieved help actions; the prompt enforces this and the sanitizer drops unknown keys/URLs.
