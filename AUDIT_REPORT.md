# HiRa AI Agent — Code Audit Report (PHP Codebase)
**Date:** 2026-06-05  
**Audited By:** Principal Software Architect  
**Codebase:** PHP 8 / PDO / OpenAI PHP Client  
**Audit Framework:** See [docs/AUDIT_FRAMEWORK.md](docs/AUDIT_FRAMEWORK.md)

---

## Executive Summary

This is a PHP-based AI agent backend for a residential society app, orchestrating OpenAI (gpt-4.1) to handle bookings, complaints, maintenance, and resident queries. The architecture is understandable and the intent routing pattern is clean, but the system is **not production-ready**. There are zero authentication checks on all endpoints — any caller who knows a user's `code` can impersonate them, read their history, or wipe their memory. Internally, a `saveMemory()` SQL bug silently overwrites nested context data on every update. Several stubs (`getAvailableSlots`, `bookAmenity`, `generateImage`) are unimplemented. Dead code, hardcoded URLs and data, and redundant DB round-trips are widespread. The minimum viable path to production requires fixing the auth gap, the memory corruption bug, the debug endpoint, and plugging the exception leak.

---

## Critical Issues — P0 (Must Fix Before Any Production Deployment)

---

### P0-1 — No Authentication on Any Endpoint
**File:** `index.php:98–146`

Every route (`/chat`, `/history`, `/clearhistory`, `/profile`) accepts a bare `code` value from the request body as the user identity with zero server-side validation. Any attacker who knows or guesses a `code` can read, write, and wipe any user's chat history and AI memory.

```php
// No auth check — code comes from untrusted request body
if ($path === 'chat' && ($method === 'GET' || $method === 'POST')) {
    $body = request_body();
    $response = runAgent($body);
```

**Fix:** Add JWT validation middleware. Validate the Bearer token signature and expiry on every request. Verify the `code` in the request body matches the `sub` claim in the JWT.

---

### P0-2 — `/decode` Debug Endpoint Exposed in Production
**File:** `index.php:100–105`

The `/decode` route echoes the entire parsed request body back to the caller with no auth check. Fully reachable from the public internet.

```php
if ($path === 'decode' && ($method === 'GET' || $method === 'POST')) {
    $body = request_body();
    response_ok($body, 200, $body);  // mirrors entire request back
    return;
}
```

**Fix:** Remove entirely. If needed for local debugging, gate behind `APP_ENV === 'dev'`.

---

### P0-3 — Internal Exception Message Leaked to Clients
**File:** `index.php:94–96`

The global catch block sends `$e->getMessage()` directly in the JSON response, exposing file paths, table names, and DB connection details on any error.

```php
} catch (Throwable $e) {
    response_error(500, 'Server error', ['detail' => $e->getMessage()]);
}
```

**Fix:** Log internally with `error_log()`, return only `"An unexpected error occurred"` to the client.

---

### P0-4 — `saveMemory()` JSONB `||` Merge Silently Destroys Nested Context
**File:** `core/memory.php:44–54`

The PostgreSQL `||` operator merges only at the top level. Saving `{"context": {"unit_id": "E2204"}}` when existing memory contains `{"context": {"club_id": "1205", "unit_id": null}}` results in `{"context": {"unit_id": "E2204"}}` — `club_id` is permanently lost.

```sql
-- Only top-level merge — nested keys are replaced, not merged
context = ai_memory.context || EXCLUDED.context,
```

This is the root cause of the 4–6 redundant `getMemory()` calls scattered across `runAgent()` — they are compensating for this data loss without fixing it.

**Fix:** Always read current memory in PHP first, deep-merge using `mergeMemory()`, then save the complete merged object as a full replace.

---

## High Priority Issues — P1

---

### P1-1 — SQL Schema Prefix Inconsistency
`getMemory()`, `saveMemory()`, `getMessages()` query without schema prefix (`ai_memory`, `ai_messages`). But `clearhistory()` and `historynext()` use `aiagent.ai_messages`. If `search_path` is not set to `aiagent`, half the queries fail at runtime.

**Fix:** Add `SET search_path TO aiagent, public` after DB connection, or prefix every table consistently.

---

### P1-2 — No Rate Limiting on Any Endpoint
A single client can hammer `/chat` indefinitely, driving up OpenAI costs and DB load with no throttle.

**Fix:** Redis-backed sliding window counter, 60 req/min per user, 200 req/min per IP.

---

### P1-3 — External API Base URL Hardcoded
`https://app.hcomm.in/api/v1` is hardcoded in `shared/api.php:71` and `shared/profile.php:40`. Switching environments requires code changes.

**Fix:** `define('HCOMM_API_BASE', getenv('HCOMM_API_BASE') ?: 'https://app.hcomm.in/api/v1');`

---

### P1-4 — `callApi()` Failures Are Silent
When `curl_exec()` fails or upstream returns 5xx, the function returns a generic message with zero log entry. Ops has no visibility into downstream failures.

**Fix:** Add `error_log()` call before returning the error response.

---

### P1-5 — `response_error()` Double-Faults on DB Errors
`response_error()` calls `api_log_response()` which calls `db()`. If the original error was a DB exception, this triggers another DB call, throws again uncaught, and the client receives no response at all.

**Fix:** Wrap the logging call inside `response_error()` in its own `try-catch`.

---

### P1-6 — `processAI()` Can Return `null` Implicitly
If 3 full tool-call rounds complete without producing a text output or a result with `cards`/`ui`, the function falls off the end and returns `null`. This produces an empty message to the user.

**Fix:** Add explicit fallback return at end of function with a user-friendly message.

---

### P1-7 — OFFSET-Based Pagination on Message History
`historynext()` uses `LIMIT 10 OFFSET :limit` — O(n) on large tables. Degrades linearly as history grows.

**Fix:** Cursor-based pagination using `WHERE id < :cursor ORDER BY id DESC LIMIT 10`.

---

### P1-8 — No CORS Headers
No CORS headers set. Cross-origin requests are uncontrolled.

**Fix:** Set `Access-Control-Allow-Origin` restricted to known frontend origins.

---

## Medium Priority Issues — P2

---

### P2-1 — Massive Dead Code Throughout
The following should be deleted, not commented out:

| Location | Dead Code |
|---|---|
| `index.php:148–161` | Entire commented-out request block |
| `index.php:101,108,116,121,131,141` | `$result = array()` assigned but never read |
| `agent/agent.php:920–972` | Entire commented-out unit selection handler |
| `agent/agent.php:827–845` | Commented-out body normalization block |
| `shared/profile.php:77–196` | Entire mock `getUserProfile()` function |
| `shared/api.php:27–66` | `generateImage()` — builds a prompt, never calls OpenAI, returns `$params` |
| `intents/booking/booking_functions.php:89–94` | `bookAmenity()` stub — always returns `success: true` |
| `intents/booking/booking_functions.php:70–83` | `getAvailableSlots()` stub — hardcoded slots |

---

### P2-2 — `getMemory()` Called 4–6 Times Per Request
In `agent/agent.php`, memory is re-fetched from DB after every `saveMemory()` call — 4 to 6 round-trips per single user request. All caused by the P0-4 JSONB merge bug.

**Fix:** Fix P0-4. Memory is then loaded once and mutated in-process.

---

### P2-3 — `getMessages()` Called Twice Per Request
`buildConversation()` fetches history, then `buildRelevantHistorySnippet()` fetches it again internally. Two DB round-trips for the same data.

**Fix:** Fetch once, pass the result to both functions.

---

### P2-4 — Timezone Set Twice
`date_default_timezone_set('Asia/Kolkata')` appears in both `index.php:9` and `config.php:10`.

---

### P2-5 — `openai()` Creates a New Client Instance on Every Call
`processAI()` and `updateConversationSummaryIfNeeded()` both call `openai()`, creating two HTTP client instances per request.

**Fix:** Use a static singleton inside the `openai()` function.

---

### P2-6 — `enrichAmenityCards()` Calls `getAmenities()` Instead of `getAmenitiesCached()`
Minor but unnecessary repeated array construction. Swap to the cached version.

---

### P2-7 — Magic Strings for Action Names Across 10+ Files
Strings like `"servicemaintainanceadd"`, `"clubsbooking"`, `"select_unit"` appear in at least 6 different files without constants. A single typo causes silent routing failures.

**Fix:** Define once in `core/constants.php`, use everywhere.

---

### P2-8 — No PDO Connection Timeout
PDO will block indefinitely if the DB is unreachable, tying up PHP-FPM workers.

**Fix:** Add `PDO::ATTR_TIMEOUT => 5` to PDO options.

---

### P2-9 — `getProfile()` Caches Empty Array on API Failure
If hcomm.in is temporarily down, `getUserProfile()` caches `[]` for the rest of the request. The agent makes incorrect decisions (no name, no units, no clubs).

**Fix:** Only cache non-empty profile responses.

---

### P2-10 — Typo in External API URL
**File:** `shared/profile.php:40`

```php
$url = "https://app.hcomm.in/api/v1/default/profile/summery"; // "summery" → should be "summary"
```

If the real endpoint is `/summary`, this call silently always fails and returns `[]`.

---

## Low Priority / Nice-to-Have — P3

| # | Issue |
|---|---|
| P3-1 | No health check endpoint for load balancer liveness/readiness probes |
| P3-2 | No request correlation ID — impossible to trace a single request across logs |
| P3-3 | Inconsistent naming: `clearhistory`, `historynext`, `defaultmsg` vs `getUserProfile`, `getFirstName` |
| P3-4 | `route()` has no final 404 fallback — execution falls off the end silently |
| P3-5 | `bookAmenity()` and `getAvailableSlots()` are unreachable stubs — delete them |
| P3-6 | Zero unit or integration tests |
| P3-7 | `getAmenities()` returns a hardcoded PHP array — should be DB-driven or configurable |
| P3-8 | Booking rules engine entirely absent — no user type checks, no quotas, no advance window |

---

## Booking Rules Gap (Critical Missing Feature)

The entire booking rule layer is absent. Specifically:

| Rule Category | Status |
|---|---|
| User type eligibility (owner / tenant / family / guest) | ❌ Not implemented |
| Daily / weekly booking quota per user | ❌ Not implemented |
| Advance booking window (how many days ahead) | ❌ Not implemented |
| Guest pass / sponsor requirement | ❌ Not implemented |
| Cancellation policy | ❌ Not implemented |
| Real-time slot availability | ❌ Hardcoded stub |
| Court / lane capacity | ❌ Not implemented |
| Club membership check before booking | ❌ Not implemented |
| Blackout dates / maintenance windows | ❌ Not implemented |
| Concurrent booking prevention | ❌ Not implemented |

The hcomm.in API likely enforces some of these server-side, but HiRa has no knowledge of them and cannot surface booking eligibility errors intelligently to the user.

---

## Verdict

**Not production-ready.** Minimum work to ship:

| # | Fix | Effort |
|---|---|---|
| 1 | Add Bearer JWT validation server-side (P0-1) | 1–2 hours |
| 2 | Remove `/decode` endpoint (P0-2) | 5 minutes |
| 3 | Stop leaking exception messages to clients (P0-3) | 15 minutes |
| 4 | Fix `saveMemory()` JSONB merge bug (P0-4) | 1 hour |
| 5 | Unify schema prefixes in all SQL (P1-1) | 30 minutes |
| 6 | Wrap `api_log_response` in try-catch in `response_error` (P1-5) | 10 minutes |
| 7 | Add CORS headers (P1-8) | 20 minutes |
| 8 | Log all `callApi` failures (P1-4) | 15 minutes |

**Total minimum: ~1 day of engineering.**

All P0 and P1 issues are resolved by design in the Python FastAPI rewrite. See [README.md](README.md) for the full technical design.
