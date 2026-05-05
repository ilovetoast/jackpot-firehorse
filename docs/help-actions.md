# Help actions (in-app help / future AI)

## Purpose of `config/help_actions.php`

`config/help_actions.php` is the **single structured source of truth** for first-party help topics in Jackpot. Each entry describes one user-facing “help action”: title, short answer, step-by-step text, category, search hooks (aliases, tags), optional permission gates, a primary route to send the user to, and related actions.

V1 is **file-driven** (no database). The backend loads it through `App\Services\HelpActionService`, and the app shell queries `GET /app/help/actions` for the help panel.

## Tags, fields, and metadata: three separate help workflows

Help separates **configuration of the metadata system** from **editing values on assets** so search, contextual suggestions, and Ask AI (which only sees `help_actions`) do not steer users to the wrong screen.

1. **Configure tags, fields, and metadata structure (admin / operations)**  
   Use **`manage.tags_fields_metadata`** and related Manage / Company topics (`manage.categories_fields`, `manage.tags`, `manage.values`, `manage.structure`, **`tenant.metadata_registry`**, etc.). These cover **taxonomy**, **field definitions**, **allowed values**, **categories**, and **registry** behavior. They **do not** change metadata on one specific asset.

2. **Edit metadata on one asset**  
   Use **`assets.edit_single_metadata`** and **`assets.view_details`**: open an asset from the library and work in the **asset drawer** / details panel (`data-help="asset-metadata-panel"`).

3. **Bulk edit metadata on multiple selected assets**  
   Use **`assets.bulk_edit_metadata`** and **`assets.select_multiple`**: select assets with **checkboxes** (`data-help="asset-selection-checkbox"`), then use the **floating toolbar** (`data-help="asset-bulk-edit"`). The toolbar is only meaningful once something is selected.

**Manage surfaces** use **`data-help="manage-metadata-structure"`** on the Manage layout main content. `short_answer` / `steps` for these topics include an explicit line where confusion was common: changing the tag list or field structure is **Manage**; changing values on files is **drawer or bulk**.

## API contract (`GET /app/help/actions`)

- **Query `q`**: optional string. Non-strings (e.g. `q[]=x`) are ignored and treated as no search. Values are trimmed and capped at **256 characters** server-side.
- **Query `route_name`**: optional string (trimmed). Current Laravel route name; used with `page_context` to pick **contextual** topics (see Phase 3 below). Empty strings are ignored.
- **Query `page_context`**: optional string (trimmed), e.g. a section label like `Assets` that matches `page_context` entries in config. Case-insensitive on the server. Empty strings are ignored.
- **Response** (always JSON): `{ "query": string|null, "contextual": [...], "results": [...], "common": [...] }`
  - `query` is `null` when there is no active search; otherwise the normalized search string.
  - `contextual`: topics that match the request’s `route_name` and/or `page_context` (permission-filtered). Sorted by `priority` (desc), then `common_sort`, then title. Omitted from duplicate listings in `common` when there is no active search. With an active search, the same contextual rows may still appear in `results` if they score; `contextual` is still the route/page slice for the panel’s “Suggested for this page” band.
  - Each item in `results` / `common` / `contextual` exposes: `key`, `title`, `category`, `short_answer`, `steps` (list of strings), `page_label`, `route_name` (nullable), `url` (nullable), `deep_link` (nullable object), `highlight` (nullable object), `tags` (list of strings), `related` (list of the same shape, with empty `related` on nested entries).
- **`deep_link`** (optional in config): when present and valid, the API includes `{ "route_name": string, "params": { … }, "query": { … } }` with **resolved** route parameters (same binding rules as `route_bindings`) and a sanitized query map (string keys `^[a-zA-Z0-9_-]{1,64}$`, string values capped at 256 chars, max 24 entries). If the deep-link route is missing or cannot be generated, `deep_link` is `null`.
- **`highlight`** (optional in config): when valid, `{ "selector": string, "label"?: string }`. `selector` must match `^[a-z0-9][a-z0-9_.-]{0,63}$` — this is the value used in `[data-help="selector"]` on the frontend and in the `highlight=` query param for **Show me**. Invalid or non-array values yield `highlight: null` (never a 500). Labels longer than 200 characters are truncated server-side. When **Show me** runs, optional copy is passed as **`highlight_label`** in the query string (client-trimmed to 120 characters); the guided overlay shows it in a small floating callout.
- **URLs**: `url` is built by **preferring `deep_link`** (`route_name` + `params` + `query`) when that resolves; otherwise it falls back to `route_name` + `route_bindings`. Generation uses `Route::has()` and the same `active_brand` binding rules. Missing routes or unresolved bindings yield `url: null` without throwing.
- **Permissions**: Actions are filtered with **AND** semantics on `permissions` before search ranking. Invalid `permissions` values in config (non-array) are treated as **no restriction** (public) so a bad deploy does not 500; fix the config to an array as soon as you notice.

## Phase 2 — `POST /app/help/ask` (grounded AI)

- **Body**: `{ "question": string }` — required, max **2000** characters (trimmed server-side in matching).
- **Middleware**: **Not public.** Registered under the `/app/*` group with **`auth`** and **`ensure.account.active`**. This route is **outside** `ResolveTenant` so the help panel works before a workspace is chosen. The route itself adds **`verified`** (email verification when the user model enforces it) and **`throttle:20,1`** (20 requests per minute per throttle key). `GET /app/help/actions` stays on the same group but does not add `verified` on the route (only `POST` ask does).
- **No workspace in session**: Before any ranking or persistence, if `app('tenant')` is not bound, the handler returns **200 JSON** with **`kind: "workspace_required"`**, a **`message`**, empty matches/suggestions as documented in the payload, **`help_ai_question_id`: null**. It does **not** call `HelpAiAskService::ask()`, **`AIService`**, or insert into **`help_ai_questions`**.
- **Flow** (only when a tenant is bound):
  1. `HelpActionService::rankForNaturalLanguageQuestion()` scores visible actions (same ranking as search).
  2. If the best score is below `config('ai.help_ask.strong_match_min_score')` (default **12**), the response is **`kind: "fallback"`** with suggested common topics — **no LLM call**.
  3. If `tenant.settings.ai_enabled === false` → **`kind: "ai_disabled"`** with suggestions (no LLM).
  4. If `config('ai.help_ask.enabled')` is false → **`kind: "feature_disabled"`** with suggestions (no LLM).
  5. Otherwise `AIService::executeAgent()` runs agent **`in_app_help_assistant`** (`config('ai.help_ask.agent_id')`) with task type **`in_app_help_action_answer`**, model **`gpt-4o-mini`** (registry key `gpt-4o-mini`), tenant + user + `brand_id` attribution. Only the top **`ai.help_ask.max_actions_for_prompt`** serialized actions are sent as JSON in the prompt.
  6. On provider/model failure → **`kind: "fallback_action"`** with the best matching serialized action as `primary` (no throw to the client).
- **Persistence**: When the tenant is bound and the ask is processed, a row is normally stored in **`help_ai_questions`**. The JSON body includes **`help_ai_question_id`** (the new row id) or **`null`** if persistence failed (logged server-side). **`workspace_required`** responses never persist. **`help_ai_question_id`** is always **`null`** for **`workspace_required`**.
- **User feedback**: `POST /app/help/ask/{help_ai_question}/feedback` with `{ "feedback_rating": "helpful"|"not_helpful", "feedback_note"?: string }` (same tenant + same user as the ask). Throttled. See [Help AI diagnostics](./help-ai-diagnostics.md).
- **Logging**: `Log::info` / `Log::warning` lines `help.ask.*` include `user_id`, `tenant_id`, `brand_id`, query snippet, matched keys, best score; on success also `model`, `agent_run_id`, tokens, `cost` from the agent run.
- **Response shapes** (all 200 JSON):
  - **`kind: "workspace_required"`**: User is authenticated but no company/brand workspace is selected in session — **`message`**, **`matched_keys`**: `[]`, **`best_score`**: `0`, **`suggested`**: `[]`, **`usage`**: `null`, **`help_ai_question_id`**: `null`. Client should nudge the user to pick a workspace; the help panel’s topic list still works via `GET /app/help/actions`.
  - **`kind: "ai"`**: `answer` = `{ direct_answer, numbered_steps, recommended_page?, related_actions, confidence }` (sanitized to retrieved keys/URLs only), `usage` = `{ agent_run_id, model, tokens_in, tokens_out, cost }`.
  - **`kind: "fallback"`** | **`"ai_disabled"`** | **`"feature_disabled"`**: `message`, `suggested` (slice of common topics).
  - **`kind: "fallback_action"`**: `message`, `primary` (one full serialized action), optional `suggested`.
- **Client CSRF**: The help panel uses `fetch` for `POST /app/help/ask`. On **419 Page Expired**, it refreshes the token via **`GET /csrf-token`** and retries the ask **once** (see `HelpLauncher.jsx`). There is no dedicated JS unit test for this path; exercise it manually or via E2E if you add coverage later.

Guardrails are enforced in `HelpAiAskService` (prompt contract + `sanitizeAiPayload`). The model must not invent routes; `recommended_page.url` is re-bound from the retrieved payload when possible.

## Adding or editing actions safely

1. **Use a stable `key`**  
   Dot-separated, lower snake case (e.g. `billing.plan`). Never rename casually: analytics and `related` references depend on it.

2. **Verify `route_name`**  
   Run `php artisan route:list --name=…` or search `routes/web.php`. Wrong names still return JSON with `url: null` but confuse users—prefer fixing immediately.

3. **`route_bindings`**  
   Only `active_brand` is supported today. It maps to the resolved `app('brand')` id. If there is no active brand, `url` is `null` and the UI explains context.

4. **`deep_link`** (optional)  
   Object with `route_name` (string), optional `params` (same shape as `route_bindings`: values `active_brand` only), and optional `query` (plain key → scalar for query string). Used for contextual URLs (different page than `route_name`, or same page with extra query e.g. a tab). If `deep_link` cannot be resolved, the service falls back to `route_name` for `url` and sets `deep_link` to `null` in JSON.

5. **`highlight`** (optional)  
   Object with required `selector` (stable id for `data-help` and `?highlight=`) and optional `label` (short UI hint). Coordinate with frontend: add matching `data-help="{selector}"` on the control you want to pulse.

6. **`permissions`**  
   Use permission strings from `App\Support\Roles\PermissionMap` (same as the rest of RBAC). Empty array = visible to all authenticated users who can hit the endpoint.

7. **`related`**  
   List other **`key` values**. Related targets are only included if that target is **also visible** to the user (same permission filter). Do not use `related` to point at “admin-only” topics from a public parent—the child will be omitted automatically.

8. **`steps` / `tags` / `aliases`**  
   Use arrays of strings. Non-string entries are coerced or dropped in the API so clients never see odd types.

9. **Ship with the route change**  
   If you rename a Laravel route, update `help_actions.php` in the **same PR** as `routes/web.php`.

10. **Phase 3 — contextual fields (optional)**  
    - **`routes`**: list of route names where this topic should appear under “Suggested for this page”. If omitted or empty, the action’s primary **`route_name`** is used as the only route hint (when it matches the request).  
    - **`page_context`**: list of labels (e.g. `Assets`, `Collections`) matched case-insensitively against the client-supplied `page_context` query param (the app maps known routes to these labels in `HelpLauncher`).  
    - **`priority`**: integer; higher values sort earlier within contextual results and add a small boost when **search** / Ask-AI-style ranking scores actions for the same query.

Shared Inertia props include `help_panel_context.route_name` so the help panel can pass the current route to `GET /app/help/actions` without expanding AI grounding beyond `help_actions`.

## How future AI should use this

1. **Retrieval first**  
   When building an AI help or copilot experience, treat `help_actions` as a **retrieval index**: given a user question (or the current page context), search or embed-match against title, `aliases`, `category`, `tags`, and `short_answer` using the same fields the service already uses for ranking.

2. **Ground answers in these records**  
   Model answers should be **grounded in the matching help action(s)**. Prefer quoting or paraphrasing `short_answer` and `steps` from the config rather than inventing new product behavior. If nothing matches, the model should say it does not have a covered topic and point to support or the help search UI—not guess.

3. **Respect `permissions` and `url` resolution**  
   The service filters actions by the user’s effective permissions (same idea as the rest of the app). AI assistants calling APIs on behalf of the user must still respect RBAC; **never** surface steps that imply access the user does not have. URLs that depend on `active_brand` may be null until context exists—the UI already handles that.

4. **Stable keys**  
   The `key` field (e.g. `assets.upload`) is the stable identifier for analytics, A/B tests, and linking related content. Prefer referencing keys in code and prompts rather than titles.

## Example prompt (hypothetical AI) using only retrieved actions

Use this pattern so the model cannot invent navigation:

```
You are Jackpot in-app help. You must ONLY use the JSON array HELP_ACTIONS below as factual ground truth.
Rules:
- Answer in short paragraphs or bullet steps taken from short_answer and steps fields.
- If the user question is not covered by any entry, say you do not have a documented topic and suggest they open Help in the app or contact support. Do not guess URLs or menu paths.
- Never claim the user can perform an action that requires a permission they do not have; the list is already filtered for their role.

HELP_ACTIONS:
{{paste JSON from GET /app/help/actions?q=... or from config for an offline tool}}

User question: {{user_question}}
```

In production, replace `{{paste…}}` with the live HTTP response body or a server-side slice of the same structure.

## Why AI answers should stay tied to predefined actions

- **Safety**: Reduces hallucinated workflows (wrong clicks, imaginary menus).
- **Consistency**: Same wording as the product UI and routing (`route_name`).
- **Compliance**: Permissions are explicit per topic.
- **Governance**: Legal/support can review YAML-style additions in version control.

## Adding screenshots later

For each help action you can extend the config with optional fields (implementation when ready), for example:

- `screenshots`: list of URLs or asset IDs and captions  
- `video_url`: short loom-style walkthrough  

The frontend panel can then render a media block under **Steps**. Until then, keep copy concise so steps remain usable without images.

## Contextual “Show me” (deep link + highlight)

The help panel’s **Show me** control navigates to the resolved `url` and appends:

`?help={action.key}&highlight={highlight.selector}`  
Optional: `&highlight_label={…}` when `highlight.label` is set (URL-encoded text for the floating callout).

**Show me highlights are system-guided:** the dimmed overlay, ring, and callout chrome always use **Jackpot UI violet** (fixed design tokens in `app.css`, e.g. `--jp-help-guided-violet`). They **do not** use the active brand’s `--primary` / theme accent, so guided help reads as product chrome rather than branded chrome.

`HelpHighlightController` + `useHelpHighlightFromUrl` + `HelpGuidedHighlightOverlay`:

1. On load, find `[data-help="…"]`, scroll it into view (respecting reduced motion).
2. Add a dark full-page **backdrop** (click to dismiss); keep the target **above** the backdrop so it **stays clickable**.
3. Apply a **subtle violet ring / pulse** on the target (disabled under `prefers-reduced-motion: reduce`).
4. Show a small **floating label** when `highlight_label` is present.
5. **Dismiss** when the user clicks the backdrop, presses **Escape**, or after ~5.5s.
6. Strip `help`, `highlight`, and `highlight_label` from the URL immediately via an Inertia **`replace`** visit after activation (failed lookup: strip silently, no overlay).

Example (after deploying matching `data-help` attributes):

- Config: `key` => `collections.add_assets`, `highlight.selector` => `collections-add-assets`, `url` => `/app/collections` (via routes).
- User clicks **Show me** → `/app/collections?help=collections.add_assets&highlight=collections-add-assets` (plus `highlight_label` when configured).
- On load, the target control is spotlighted with the system overlay.

Keep `selector` names stable; they are part of the public contract alongside `key`.

## Moving from config to database / admin-managed content

When editorial teams need to edit help without deploys:

1. Mirror the same schema as rows in a `help_actions` table (key unique, JSON for arrays).
2. Replace `config('help_actions.actions')` with an `HelpActionRepository` that reads from DB with caching (Redis/config cache TTL).
3. Add an admin UI with validation, preview, and permission checks.
4. Optionally keep **seed data** from config for new environments and migrations.

Until then, PRs that change copy or routes should update `config/help_actions.php` alongside real route changes.

## Frontend testing

Jackpot’s JS tests today use **Node’s built-in test runner** on small `.mjs` utilities (`npm run test:js`). There is **no React Testing Library / Vitest** harness for components yet, so `HelpLauncher` is covered via manual QA, Headless UI behavior, and backend/API tests. When a component test runner is adopted, add tests for: open/close, Escape vs back stack, retry on fetch failure, and focus order.
