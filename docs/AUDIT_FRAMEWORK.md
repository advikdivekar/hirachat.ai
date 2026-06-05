AUDIT FRAMEWORK
Audit the code across every dimension listed below. For each issue found, provide: the exact location or pattern, why it is a problem, and a concrete fix or refactored version.

1. HIGH-LEVEL DESIGN (HLD)

Does the overall architecture make sense for the stated purpose?
Is the system designed for horizontal scalability? Can it scale to 10x, 100x, 1000x traffic without rearchitecting?
Are there any single points of failure?
Is there a clear separation of concerns at the system level — API layer, business logic layer, data layer, infra layer?
Is the communication pattern (REST, gRPC, event-driven, pub/sub) the right choice for this use case?
Are there caching strategies at the right layers (CDN, application cache, DB query cache)?
Is the data flow clean and traceable end-to-end?
Are async and sync boundaries drawn correctly?
Is there a clear strategy for service boundaries if this grows into microservices?
Is the system observable — does it support distributed tracing, metrics, and centralized logging by design?


2. LOW-LEVEL DESIGN (LLD)

Are classes, modules, and functions designed with SOLID principles?
Is there proper use of design patterns (Factory, Strategy, Repository, Observer, etc.) where applicable — and are patterns avoided where they add unnecessary complexity?
Is the data model normalized correctly? Are there any N+1 query risks or schema design flaws?
Are interfaces and contracts well-defined and stable?
Is business logic leaking into controllers, resolvers, or route handlers?
Are there god objects or god functions that do too many things?
Is dependency injection used correctly to decouple components?
Are abstractions at the right level — not too leaky, not too over-engineered?
Are edge cases handled at every function boundary?


3. CODE QUALITY & ENGINEERING PRACTICES

Is the code DRY (Don't Repeat Yourself) without becoming over-abstracted?
Are naming conventions consistent, descriptive, and intention-revealing?
Are magic numbers, magic strings, and hardcoded values eliminated in favor of constants or config?
Is the cyclomatic complexity of functions within acceptable limits (ideally under 10)?
Are functions doing exactly one thing and doing it well?
Is there dead code, commented-out code, or TODO debt that needs resolution?
Are imports and dependencies clean with no circular dependencies?
Is the code self-documenting? Where it isn't, is there clear, meaningful documentation?
Is there consistent use of language-level idioms and best practices?


4. BACKEND ENGINEERING

Are all API endpoints idempotent where they should be?
Is there proper input validation and sanitization at every entry point?
Is authentication and authorization handled correctly — no privilege escalation risks, no auth logic in the wrong layer?
Are secrets, credentials, and config externalized and never hardcoded?
Is rate limiting, throttling, and backpressure handled?
Are database transactions used correctly — are there any risks of partial writes, dirty reads, or phantom reads?
Are database queries optimized — proper indexing, avoiding full table scans, pagination on all list endpoints?
Is connection pooling configured and are connections properly released?
Is there retry logic with exponential backoff and jitter for all external calls?
Are timeouts set on every external call — HTTP clients, DB connections, queue consumers?
Are there any blocking I/O calls in async contexts?
Is concurrency handled correctly — any race conditions, deadlocks, or thread-safety issues?
Are background jobs and async tasks fault-tolerant with proper failure queuing?


5. ERROR HANDLING & RESILIENCE

Are errors caught at the right level and not swallowed silently?
Is there a consistent error taxonomy — domain errors, validation errors, infrastructure errors?
Are error messages meaningful to developers but not leaking internals to end users?
Is there a global error handler or fallback that prevents unhandled exceptions from crashing the process?
Are circuit breakers in place for downstream dependencies?
Is the system designed to degrade gracefully under partial failures?
Are retries bounded — no infinite retry loops?


6. PERFORMANCE

Are there any obvious O(n²) or worse algorithmic complexities that could be reduced?
Are expensive operations (heavy computation, large DB queries, external API calls) cached appropriately?
Is pagination or cursor-based navigation used on all list/collection endpoints?
Is there lazy loading or deferred execution where data is not immediately needed?
Are memory allocations in hot paths minimized?
Is serialization/deserialization happening unnecessarily in tight loops?
Are there any synchronous operations that should be made async or offloaded to a queue?


7. SECURITY

Is there protection against OWASP Top 10 vulnerabilities — SQLi, XSS, CSRF, IDOR, SSRF, etc.?
Are all user-supplied inputs treated as untrusted by default?
Is sensitive data (PII, passwords, tokens) encrypted at rest and in transit?
Are passwords hashed with a proper algorithm (bcrypt, argon2) — never MD5 or SHA1?
Are JWTs validated correctly — signature, expiry, and audience claims checked?
Are file uploads validated for type, size, and scanned before processing?
Are there any dependency vulnerabilities in third-party packages?
Is the principle of least privilege applied to all service accounts, DB users, and IAM roles?


8. OBSERVABILITY & DEBUGGING

Are there structured logs at every meaningful system boundary with consistent fields (request ID, user ID, timestamp, severity)?
Are distributed trace IDs propagated across all service calls?
Are application-level metrics emitted (latency p50/p95/p99, error rates, queue depths, cache hit rates)?
Are health check endpoints implemented correctly — liveness and readiness distinct?
Is there enough context in error logs to reconstruct what happened without needing to reproduce the bug?


9. TESTABILITY

Is the code written in a way that makes unit testing straightforward — no hidden dependencies, no global state?
Are there meaningful unit tests, integration tests, and contract tests?
Is test coverage on critical paths (auth, payments, data mutations) above 90%?
Are mocks and stubs used correctly — testing behavior, not implementation?
Are there any tests that are testing the framework rather than the actual business logic?
Is there a clear testing pyramid — many unit, some integration, few E2E?


10. DEVEX, MAINTAINABILITY & SCALABILITY FOR THE FUTURE

Can a new engineer onboard and understand this codebase in under a day?
Is the folder and module structure intuitive and does it reflect domain boundaries?
Are environment-specific configs handled through env vars with documented defaults?
Is there a migration strategy for DB schema changes with no-downtime deploys?
Is versioning in place for APIs so consumers aren't broken by changes?
Is there feature flag infrastructure to safely roll out changes?
Can any part of this be extracted into a standalone service without major surgery?
Is the deployment model containerized, reproducible, and environment-agnostic?


OUTPUT FORMAT
Structure your audit as follows:
Executive Summary — 3–5 sentence overall assessment of the code's production-readiness.
Critical Issues — Must fix before any production deployment. Include severity: P0.
High Priority Issues — Should fix soon. Severity: P1.
Medium Priority Issues — Important for long-term health. Severity: P2.
Low Priority / Nice-to-Have — Improvements that would elevate the code further. Severity: P3.
Refactored Code Snippets — For every critical or high priority issue, provide the corrected version of the code inline.
Architecture Recommendations — Any HLD or structural changes recommended beyond code-level fixes.
Verdict — Is this code production-ready? What is the minimum work required to make it so?

RULES

Never give vague feedback like "improve error handling." Always show the exact problem and the exact fix.
If a pattern is repeated across multiple places, flag it once with a note that it applies broadly — do not repeat the same finding ten times.
Prioritize ruthlessly. Not everything is a P0. Reserve critical severity for things that would cause data loss, security breaches, or production outages.
Treat performance and security as first-class citizens, not afterthoughts.
Assume this code will be read, modified, and extended by 10 different engineers over 5 years. Evaluate accordingly.