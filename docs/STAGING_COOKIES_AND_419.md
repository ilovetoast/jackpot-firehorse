# Staging: 419, 502, and CloudFront / Session Cookies

If staging shows **419 Page Expired** (e.g. on `presence/heartbeat` or API calls), **502 Bad Gateway**, or **401 Unauthorized**, the cause is often session or cookie configuration shared across environments or hosts.

## Root cause: session cookie domain

- Laravel’s session cookie is used for auth and CSRF. If **SESSION_DOMAIN** is set to a **broad** value (e.g. `.velvetysoft.com`), then:
  - **Staging** (`staging-jackpot.velvetysoft.com`) and **production** (`jackpot.velvetysoft.com` or similar) share the same cookie name and domain.
  - One environment can overwrite the other’s session cookie.
  - The browser then sends the “wrong” session (e.g. production session to staging), so CSRF token doesn’t match → **419 Page Expired**, and the app can misbehave or crash → **502**.

## Fix on staging

1. **Keep the Laravel session cookie host-only**
   - In staging `.env`, set:
     ```env
     SESSION_DOMAIN=null
     ```
   - Or omit `SESSION_DOMAIN` so it defaults to `null`. The session cookie will then be scoped to the exact host (e.g. `staging-jackpot.velvetysoft.com`) and will **not** be sent to or overwritten by production.

2. **CloudFront signed cookies**
   - Use **CLOUDFRONT_COOKIE_DOMAIN** only for the CDN (e.g. your CloudFront custom domain). That’s independent of the Laravel session.
   - Do **not** “fix” session issues by setting `SESSION_DOMAIN=.velvetysoft.com`; that will cause cross-environment 419/502.

3. **Optional: different cookie name per environment**
   - You can force separation with a different session cookie name on staging, e.g.:
     ```env
     SESSION_COOKIE=jackpot-staging-session
     ```
   - This is optional if `SESSION_DOMAIN` is null.

## 502 Bad Gateway (nginx)

- **502** means nginx did not get a valid response from the upstream (e.g. PHP-FPM / Laravel). Common causes:
  - App exception (e.g. tenant without UUID in CDN cookie middleware) — we now skip cookie issuance when tenant has no UUID to avoid that.
  - Session/cookie issues leading to bad state and then an exception.
  - PHP-FPM down, timeout, or misconfiguration.

After fixing `SESSION_DOMAIN` and redeploying, clear browser cookies for the staging host (or use an incognito window) and log in again so the session and CSRF token are correct.

## Quick checklist

| Item | Staging recommendation |
|------|-------------------------|
| SESSION_DOMAIN | `null` or unset (host-only cookie) |
| SESSION_COOKIE | Optional: e.g. `jackpot-staging-session` |
| CLOUDFRONT_COOKIE_DOMAIN | Only if CDN uses a custom domain; keep session domain separate |
| After changing .env | Restart PHP-FPM / workers; clear browser cookies for staging and re-login |
