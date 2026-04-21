# Runbook: Data explosions & cascade cost control

**Purpose:** When error spikes, automation bugs, or noisy clients cause **runaway row growth**, **API/AI spend**, or **queue backlog**, use this alongside GDPR **Art. 5(1)(e)** retention planning.  
**Tracking doc:** `docs/compliance/PATH_TO_GDPR.md` → section *Go Live — Explosions, operational data & cost control*.

**Related (deep dive):** `docs/RUNBOOK_ALERTS_AND_TICKETS.md` — alert pipeline, `alert_candidates`, auto-tickets, rate caps (`config/alerts.php`).

---

## 1. Symptoms

- DB CPU or storage growth **step-changes** (not gradual).
- `ai_agent_runs`, `activity_events`, `frontend_errors`, or `alert_candidates` **insert rate** jumps in metrics.
- AI provider bills or rate limits spike; queue workers fall behind.
- Thousands of **support/internal tickets** or **AI ticket suggestions** in a short window.

---

## 2. Immediate triage (first 30 minutes)

1. **Identify the hot table** — Use §4 below or your DB monitoring (slow queries, top tables by size).
2. **Confirm caps are active** — `ALERTS_MAX_PER_TENANT_PER_HOUR`, `TICKETS_MAX_AUTO_CREATE_PER_HOUR` in production `.env` (see `config/alerts.php`). Check logs for `[AutoTicketCreationService]` / suppression messages.
3. **Stop the cascade if possible** — For alert/ticket storms: see `RUNBOOK_ALERTS_AND_TICKETS.md` (pause or adjust rules, acknowledge alerts, avoid re-running heavy aggregations blindly until the upstream noise stops).
4. **AI spend** — If ticket classification or agent runs are the cost driver: temporarily restrict or disable non-critical automations (feature flags, queue workers for low-priority jobs) per your deployment process; document who approved.
5. **Communicate** — Note incident time range for later retention/prune analysis.

---

## 3. After stabilisation

- Root-cause: bad deploy, client bug, missing index, rule misconfiguration, or DDoS-like client error loop.
- Add or tune **rate limits**, **deduplication**, or **sampling** so the path cannot repeat unbounded.
- Schedule or run **retention pruning** when the backlog is understood (see `PATH_TO_GDPR.md` — *Retention (Art. 5(1)(e))*).
- Update the **codebase audit table** in `PATH_TO_GDPR.md` if a new high-volume store was involved.

---

## 4. Monitoring queries (examples — adjust for your DB)

Run against **read replica** or off-peak. Replace table names if your schema differs.

### Row counts (current snapshot)

```sql
SELECT 'ai_agent_runs' AS tbl, COUNT(*) AS cnt FROM ai_agent_runs
UNION ALL SELECT 'activity_events', COUNT(*) FROM activity_events
UNION ALL SELECT 'frontend_errors', COUNT(*) FROM frontend_errors
UNION ALL SELECT 'alert_candidates', COUNT(*) FROM alert_candidates
UNION ALL SELECT 'application_error_events', COUNT(*) FROM application_error_events;
```

### Approximate rows last 24 hours (requires `created_at` or equivalent)

```sql
SELECT COUNT(*) AS cnt_24h FROM ai_agent_runs
WHERE created_at >= NOW() - INTERVAL 1 DAY;

SELECT COUNT(*) AS cnt_24h FROM activity_events
WHERE created_at >= NOW() - INTERVAL 1 DAY;

SELECT COUNT(*) AS cnt_24h FROM frontend_errors
WHERE created_at >= NOW() - INTERVAL 1 DAY;

SELECT COUNT(*) AS cnt_24h FROM alert_candidates
WHERE created_at >= NOW() - INTERVAL 1 DAY;
```

### Compare today vs yesterday (daily growth signal)

Repeat the 24h query on a schedule and alert if **cnt_24h** exceeds a baseline (e.g. 3× trailing 7-day average). Implement in Datadog / CloudWatch / custom job as appropriate.

---

## 5. Configuration reference

| Env / config | Role |
|--------------|------|
| `ALERTS_MAX_PER_TENANT_PER_HOUR` | Caps new `alert_candidates` per tenant per hour (`config/alerts.php`). |
| `TICKETS_MAX_AUTO_CREATE_PER_HOUR` | Caps auto-created support tickets per hour globally (`config/alerts.php` → `tickets.max_auto_create_per_hour`). |

Set `0` only if you **disable** that cap (rare; document why).

---

## 6. Ownership

- **Engineering:** incident response, code/config fixes, pruning jobs.
- **On-call:** first response; escalate if AI or DB capacity is impacted.

---

*This file is operational guidance, not legal advice. Retention periods and lawful bases remain in the Privacy Policy and DPA.*
