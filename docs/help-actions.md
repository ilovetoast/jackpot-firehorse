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
  - `contextual`: topics that match the request’s `route_name` and/or `page_context` (**permission- and workspace-visibility–filtered**). Sorted by `priority` (desc), then `common_sort`, then title. Omitted from duplicate listings in `common` when there is no active search. With an active search, the same contextual rows may still appear in `results` if they score; `contextual` is still the route/page slice for the panel’s “Suggested for this page” band.
  - Each item in `results` / `common` / `contextual` exposes: `key`, `title`, `category`, `short_answer`, `steps` (list of strings), `page_label`, `route_name` (nullable), `url` (nullable), `deep_link` (nullable object), `highlight` (nullable object), `requires_context` (nullable object), `tags` (list of strings), `related` (list of the same shape, with empty `related` on nested entries).
- **`deep_link`** (optional in config): when present and valid, the API includes `{ "route_name": string, "params": { … }, "query": { … } }` with **resolved** route parameters (same binding rules as `route_bindings`) and a sanitized query map (string keys `^[a-zA-Z0-9_-]{1,64}$`, string values capped at 256 chars, max 24 entries). If the deep-link route is missing or cannot be generated, `deep_link` is `null`.
- **`highlight`** (optional in config): when valid, an object whose **`selector`** is either that same token regex or a literal **`[data-help="token"]`** (normalized server-side to `token`). Optional: **`label`**, **`fallback_selector`**, **`fallback_label`** (try parent target if the primary `data-help` is missing), **`missing_title`**, **`missing_message`**, **`missing_cta_label`**, **`missing_cta_route`** (Laravel route name), **`missing_cta_route_bindings`** (same shape as `route_bindings`, values `active_brand` only). The API adds **`missing_cta_url`** when the route resolves. Invalid or non-array values yield `highlight: null` (never a 500). String fields are length-capped server-side. **Show me** passes `highlight`, optional `highlight_fb` (fallback token), and `highlight_label`; if neither target exists, the app shows a short system-styled notice using **`missing_*`** copy (see `HelpShowMeMissingNotice.jsx`).
- **`requires_context`** (optional): `{ "type": string, "message"?: string }` — advisory only (`asset_open`, `assets_selected`, `collection_open`, `brand_selected`, etc.). Omitted or invalid → `null` in JSON.
- **URLs**: `url` is built by **preferring `deep_link`** (`route_name` + `params` + `query`) when that resolves; otherwise it falls back to `route_name` + `route_bindings`. Generation uses `Route::has()` and the same `active_brand` binding rules. Missing routes or unresolved bindings yield `url: null` without throwing.
- **Permissions**: Actions are filtered with **AND** semantics on `permissions` before search ranking. Invalid `permissions` values in config (non-array) are treated as **no restriction** (public) so a bad deploy does not 500; fix the config to an array as soon as you notice.

### Workspace visibility (tenant + brand bound)

When `app('tenant')` is bound and the controller has an authenticated user, `HelpActionService` applies **additional** filters so help matches what the user can actually open:

| Config field | Meaning |
|--------------|---------|
| `permissions` | AND — must have every listed permission (unchanged). |
| `requires_owner` | Tenant pivot role must be `owner`. |
| `requires_admin` | Tenant pivot role must be `owner`, `admin`, or `agency_admin`. |
| `required_modules` | Currently: `creator_module` → `FeatureGate::creatorModuleEnabled($tenant)`. |
| `required_features` + `hidden_when_features_disabled` | **Merged**: every listed key must be **on**. Keys: `generative` / `studio` (tenant `generative_enabled`), `ai` (`ai_enabled`), `creator_module`, `workspace_insights` (`User::canViewBrandWorkspaceInsights` — needs active brand), `agency_workspace` (agency tenant or user belongs to an agency tenant). |
| `required_plan_features` | **AND** — every listed plan capability must be on. Known keys include `public_collections_enabled`, `download_password_protection` (packaged download passwords via `FeatureGate::downloadPasswordProtectionEnabled` / `PlanService::canPasswordProtectDownload`), plus approval-style keys resolved through `FeatureGate::allows()` (e.g. `approvals.enabled`). |
| `required_plan_features_any` | **OR** — at least one listed plan capability must be on (e.g. `downloads.password_protect` when either download passwords **or** public collection links are available). |
| `required_disabled_plan_features` | **AND NOT** — every listed plan capability must be **off** (e.g. `downloads.password_protection_unavailable` when the workspace has neither download passwords nor public collection sharing). |
| `requires_brand_approver` | Active brand membership exists and `PermissionMap::canApproveAssets` for that brand role (e.g. Approvals queue). |
| `required_disabled_features` | Topic is shown **only** when every listed feature is **off** (for safe “feature unavailable” explainers like `studio.disabled`). |

When the help routes run **without** `ResolveTenant`, `HelpActionController` still builds visibility from **`tenant_id` / `brand_id` in session** when present, so plan gates apply after the user picks a workspace. With **no** session workspace and no container-bound tenant, only **`permissions`** filtering runs (same as tests that omit workspace context).

**`related`**: targets are omitted unless they pass the **same** visibility rules as top-level actions (permissions + workspace gates).

## Phase 2 — `POST /app/help/ask` (grounded AI)

- **Body**: `{ "question": string }` — required, max **2000** characters (trimmed server-side in matching).
- **Middleware**: **Not public.** Registered under the `/app/*` group with **`auth`** and **`ensure.account.active`**. This route is **outside** `ResolveTenant` so the help panel works before a workspace is chosen. The route itself adds **`verified`** (email verification when the user model enforces it) and **`throttle:20,1`** (20 requests per minute per throttle key). `GET /app/help/actions` stays on the same group but does not add `verified` on the route (only `POST` ask does).
- **No workspace in session**: Before any ranking or persistence, if neither `app('tenant')` nor session `tenant_id` resolves a tenant, the handler returns **200 JSON** with **`kind: "workspace_required"`**, a **`message`**, empty matches/suggestions as documented in the payload, **`help_ai_question_id`: null**. It does **not** call `HelpAiAskService::ask()`, **`AIService`**, or insert into **`help_ai_questions`**.
- **Flow** (only when a tenant is bound):
  1. Build **visible** actions with the same rules as `GET /app/help/actions` (permissions + workspace visibility). **Common** suggestions are taken only from this visible set.
  2. If `tenant.settings.ai_enabled === false` → **`kind: "ai_disabled"`** with suggestions (no LLM).
  3. If `config('ai.help_ask.enabled')` is false → **`kind: "feature_disabled"`** with suggestions (no LLM).
  4. If the question clearly targets **Studio / Generative** but `generative_enabled` is false for the tenant → **`kind: "feature_unavailable"`** with a safe message and suggestions — **no LLM**, no Studio actions in context. Similar guard for **Creator module** when it is not entitled.
  5. `HelpActionService::rankForNaturalLanguageQuestion()` scores **visible** actions only (same ranking as search).
  6. If the best score is below `config('ai.help_ask.strong_match_min_score')` (default **12**), the response is **`kind: "fallback"`** with suggested common topics — **no LLM call**.
  7. Otherwise `AIService::executeAgent()` runs agent **`in_app_help_assistant`** (`config('ai.help_ask.agent_id')`) with task type **`in_app_help_action_answer`**, model **`gpt-4o-mini`** (registry key `gpt-4o-mini`), tenant + user + `brand_id` attribution. Only the top **`ai.help_ask.max_actions_for_prompt`** serialized **visible** actions are sent as JSON in the prompt.
  8. On provider/model failure → **`kind: "fallback_action"`** with the best matching serialized action as `primary` (no throw to the client).
- **Persistence**: When the tenant is bound and the ask is processed, a row is normally stored in **`help_ai_questions`**. The JSON body includes **`help_ai_question_id`** (the new row id) or **`null`** if persistence failed (logged server-side). **`workspace_required`** responses never persist. **`help_ai_question_id`** is always **`null`** for **`workspace_required`**.
- **User feedback**: `POST /app/help/ask/{help_ai_question}/feedback` with `{ "feedback_rating": "helpful"|"not_helpful", "feedback_note"?: string }` (same tenant + same user as the ask). Throttled. See [Help AI diagnostics](./help-ai-diagnostics.md).
- **Logging**: `Log::info` / `Log::warning` lines `help.ask.*` include `user_id`, `tenant_id`, `brand_id`, query snippet, matched keys, best score; on success also `model`, `agent_run_id`, tokens, `cost` from the agent run.
- **Response shapes** (all 200 JSON):
  - **`kind: "workspace_required"`**: User is authenticated but no company/brand workspace is selected in session — **`message`**, **`matched_keys`**: `[]`, **`best_score`**: `0`, **`suggested`**: `[]`, **`usage`**: `null`, **`help_ai_question_id`**: `null`. Client should nudge the user to pick a workspace; the help panel’s topic list still works via `GET /app/help/actions`.
  - **`kind: "ai"`**: `answer` = `{ direct_answer, numbered_steps, recommended_page?, related_actions, confidence }` (sanitized to retrieved keys/URLs only), `usage` = `{ agent_run_id, model, tokens_in, tokens_out, cost }`.
  - **`kind: "fallback"`** | **`"ai_disabled"`** | **`"feature_disabled"`** | **`"feature_unavailable"`**: `message`, `suggested` (slice of common topics). **`feature_unavailable`** also includes **`feature`**: short key such as `studio` or `creators`.
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
   Object with required `selector` (token or `[data-help="token"]`), optional `label`, optional **fallback** + **missing** fields for Show me when the control is not mounted (drawer closed, nothing selected, etc.). Use **`missing_cta_route`** (+ bindings when the route needs `{brand}`) so the client can offer a neutral CTA.

6. **`requires_context`** (optional)  
   `{ type, message? }` for documentation and future UX; does not auto-drive state.

7. **`permissions`**  
   Use permission strings from `App\Support\Roles\PermissionMap` (same as the rest of RBAC). Empty array = visible to all authenticated users who can hit the endpoint.

8. **`related`**  
   List other **`key` values**. Related targets are only included if that target is **also visible** to the user (permissions **and** workspace visibility when a tenant is bound). Do not use `related` to point at “admin-only” topics from a public parent—the child will be omitted automatically.

9. **`steps` / `tags` / `aliases`**  
   Use arrays of strings. Non-string entries are coerced or dropped in the API so clients never see odd types.

   **Aliases and Ask AI:** If users ask in natural language and Help AI falls back (`kind: "fallback"`) or matches the wrong topic, add **aliases** and **tags** that mirror real phrases (see `downloads.password_protect`: “add password to download”, “password protect share link”, etc.). Prefer a **dedicated** topic with focused aliases over overloading a broad action so ranking stays stable. When behavior is **plan-gated**, add a matching **unavailable** topic (`required_disabled_plan_features`) so searches and Ask AI never imply a feature exists on the wrong tier.

10. **Ship with the route change**  
   If you rename a Laravel route, update `help_actions.php` in the **same PR** as `routes/web.php`.

11. **Phase 3 — contextual fields (optional)**  
    - **`routes`**: list of route names where this topic should appear under “Suggested for this page”. If omitted or empty, the action’s primary **`route_name`** is used as the only route hint (when it matches the request).  
    - **`page_context`**: list of labels (e.g. `Assets`, `Collections`) matched case-insensitively against the client-supplied `page_context` query param (the app maps known routes to these labels in `HelpLauncher`).  
    - **`priority`**: integer; higher values sort earlier within contextual results and add a small boost when **search** / Ask-AI-style ranking scores actions for the same query.

Shared Inertia props include `help_panel_context.route_name` so the help panel can pass the current route to `GET /app/help/actions` without expanding AI grounding beyond `help_actions`.

## How future AI should use this

1. **Retrieval first**  
   When building an AI help or copilot experience, treat `help_actions` as a **retrieval index**: given a user question (or the current page context), search or embed-match against title, `aliases`, `category`, `tags`, and `short_answer` using the same fields the service already uses for ranking.

2. **Ground answers in these records**  
   Model answers should be **grounded in the matching help action(s)**. Prefer quoting or paraphrasing `short_answer` and `steps` from the config rather than inventing new product behavior. If nothing matches, the model should say it does not have a covered topic and point to support or the help search UI—not guess.

3. **Respect `permissions`, workspace visibility, and `url` resolution**  
   The service filters actions by effective permissions **and** (when a tenant is bound) plan/module/feature flags aligned with `FeatureGate`, tenant settings, and Insights access. **Ask AI only receives serialized actions from this visible set.** Never surface steps that imply access the user does not have. URLs that depend on `active_brand` may be null until context exists—the UI already handles that.

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
Optional: `&highlight_fb={fallback_selector}` when a fallback target is configured.

`HelpLauncher` also writes a short-lived **`sessionStorage`** payload (`jp_help_showme_v1`) with `missing_*` and CTA URL so “couldn’t find control” copy survives the URL strip.

**Show me highlights are system-guided:** the dimmed overlay, ring, and callout chrome always use **Jackpot UI violet** (fixed design tokens in `app.css`, e.g. `--jp-help-guided-violet`). They **do not** use the active brand’s `--primary` / theme accent, so guided help reads as product chrome rather than branded chrome.

`HelpHighlightController` + `useHelpHighlightFromUrl` + `HelpGuidedHighlightOverlay` + `HelpShowMeMissingNotice`:

1. Strip `help`, `highlight`, `highlight_label`, and `highlight_fb` from the URL via an Inertia **`replace`** visit (same tick as lookup).
2. Find `[data-help="…"]` for the **primary** selector; if missing, try **`highlight_fb`** / config **`fallback_selector`**.
3. On success: scroll into view (respecting reduced motion), full-page **backdrop**, target stays clickable, **violet ring / pulse**, optional **floating label**, auto-dismiss ~5.5s or Escape / backdrop click.
4. If **both** targets are missing: show **`HelpShowMeMissingNotice`** (concise slate copy + optional indigo CTA) using **`missing_title`**, **`missing_message`**, **`missing_cta_url`** when the API resolved them — not silent.

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
