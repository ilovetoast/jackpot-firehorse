# Help actions (in-app help / future AI)

## Purpose of `config/help_actions.php`

`config/help_actions.php` is the **single structured source of truth** for first-party help topics in Jackpot. Each entry describes one user-facing “help action”: title, short answer, step-by-step text, category, search hooks (aliases, tags), optional permission gates, a primary route to send the user to, and related actions.

V1 is **file-driven** (no database). The backend loads it through `App\Services\HelpActionService`, and the app shell queries `GET /app/help/actions` for the help panel.

## How future AI should use this

1. **Retrieval first**  
   When building an AI help or copilot experience, treat `help_actions` as a **retrieval index**: given a user question (or the current page context), search or embed-match against title, `aliases`, `category`, `tags`, and `short_answer` using the same fields the service already uses for ranking.

2. **Ground answers in these records**  
   Model answers should be **grounded in the matching help action(s)**. Prefer quoting or paraphrasing `short_answer` and `steps` from the config rather than inventing new product behavior. If nothing matches, the model should say it does not have a covered topic and point to support or the help search UI—not guess.

3. **Respect `permissions` and `url` resolution**  
   The service filters actions by the user’s effective permissions (same idea as the rest of the app). AI assistants calling APIs on behalf of the user must still respect RBAC; **never** Surface steps that imply access the user does not have. URLs that depend on `active_brand` may be null until context exists—the UI already handles that.

4. **Stable keys**  
   The `key` field (e.g. `assets.upload`) is the stable identifier for analytics, A/B tests, and linking related content. Prefer referencing keys in code and prompts rather than titles.

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

## Page-specific contextual help later

Two complementary approaches:

1. **Route mapping**: Maintain a map `route_name → list of help keys` (config or small PHP array). When the panel opens, pre-filter or boost topics for the current Inertia page’s named route.

2. **Query hints**: Pass `?context=assets.index` or POST body from the client so `HelpActionService` ranks topics that declare `context_routes: [...]` (future field).

Keep context hints **non-sensitive** (route names only, not IDs).

## Moving from config to database / admin-managed content

When editorial teams need to edit help without deploys:

1. Mirror the same schema as rows in a `help_actions` table (key unique, JSON for arrays).
2. Replace `config('help_actions.actions')` with an `HelpActionRepository` that reads from DB with caching (Redis/config cache TTL).
3. Add an admin UI with validation, preview, and permission checks.
4. Optionally keep **seed data** from config for new environments and migrations.

Until then, PRs that change copy or routes should update `config/help_actions.php` alongside real route changes.
