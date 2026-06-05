# HiRa AI Agent — Complete Technical Design Document
### Python FastAPI Rewrite 
**Version:** 1.0  
**Date:** 2026-06-05  
**Author:** Advik Divekar
**Status:** Pre-Implementation — Pending Sign-Off  

---

## Table of Contents

1. [Executive Summary](#executive-summary) ← *Start here for stakeholders*
2. [System Overview](#system-overview)
3. [Phase 1 — Requirements Decomposition](#phase-1--requirements-decomposition)
4. [Phase 2 — Architecture Documents](#phase-2--architecture-documents)
5. [Phase 3 — Technical Decision Log (ADRs)](#phase-3--technical-decision-log-adrs)
6. [Phase 4 — Engineering Standards](#phase-4--engineering-standards)
7. [Phase 5 — Code Implementation Standards](#phase-5--code-implementation-standards)
8. [Phase 6 — Runbook & Operational Documentation](#phase-6--runbook--operational-documentation)
9. [Pre-Implementation Sign-Off Checklist](#pre-implementation-sign-off-checklist)

---

# Executive Summary

> *This section is written for stakeholders, product managers, and leadership. No technical background required.*

## What Are We Building?

**HiRa** (Hiranandani Resident Assistant) is an AI-powered conversational assistant embedded inside a residential society mobile app. Residents interact with it via a chat interface to:

- Book sports and recreation facilities (Badminton, Gym, Squash, Carrom)
- Raise and track maintenance/repair requests
- Manage family members and tenants
- View complaint history and close/reopen complaints
- Get answers about their units, clubs, and community

The assistant is powered by OpenAI's GPT-4.1 model and maintains memory of each resident's context across conversations.

## What Problem Does This Document Solve?

The current system is built in PHP. Before we rewrite it in Python (FastAPI), this document defines **every engineering decision, every data model, every API contract, and every operational procedure** — so that:

- Engineering teams can build without ambiguity
- Stakeholders can understand what is being built and why
- The new system avoids all the critical security and reliability issues found in the audit
- Any engineer in the world can pick this up and contribute on day one

## Why Rewrite in Python FastAPI?

| Dimension | Current PHP | New Python FastAPI |
|---|---|---|
| **Concurrency** | Blocking — one DB call holds the thread | Async — all DB/API calls run concurrently |
| **Type Safety** | None — runtime bugs from untyped params | Full Pydantic validation — bugs caught at startup |
| **AI SDK** | Third-party PHP OpenAI client | Official Python SDK, async-native, always up to date |
| **Authentication** | ❌ None — any user can impersonate another | ✅ Bearer JWT validated server-side on every request |
| **Rate Limiting** | ❌ None | ✅ Redis-backed, per-user and per-IP |
| **Error Handling** | Exception messages leak to clients | Structured error taxonomy, internals never exposed |
| **Observability** | Basic DB logging only | Structured JSON logs + trace IDs on every request |
| **Test Coverage** | 0% | Unit + integration test suite from day one |

## What the Frontend Sees

**Nothing changes.** The JSON response contract is identical. The frontend continues sending:
```json
{ "code": "HC2526Y61", "message": "Book badminton tomorrow", "selection": null }
```
And receiving:
```json
{ "success": true, "data": { "message": "...", "cards": [...], "ui": {} } }
```
The rewrite is a pure backend swap. No frontend work required.

## Timeline Estimate

| Phase | Duration |
|---|---|
| Pre-engineering (this document) | 3 days |
| Core infrastructure (auth, DB, logging, config) | 3 days |
| Domain logic port (agent, memory, sessions) | 4 days |
| Intent handlers (booking, complaints, family, directory) | 3 days |
| Testing + hardening | 3 days |
| Staging deploy + validation | 2 days |
| **Total** | **~18 working days** |

---

# System Overview

**HiRa** is a stateful AI chat agent for residential society residents. Each conversation turn:

1. Authenticates the resident via Bearer JWT
2. Loads or creates a daily session
3. Fetches resident's persistent memory (unit, club, last intent)
4. Optionally handles a card selection (booking confirmation, complaint action) **before** calling the AI
5. Builds a conversation context and calls OpenAI with tool definitions
6. Processes tool calls in a loop (max 3 iterations) to fetch booking slots, etc.
7. Validates and enriches the AI's card output
8. Saves the turn to message history and updates memory
9. Returns a structured JSON response with message, cards, and UI metadata

The system is **intent-driven**: booking, complaints, maintenance, family management, and directory are separate intent modules with their own prompt sections and handlers.

---

# Phase 1 — Requirements Decomposition

## 1.1 Functional Requirements

### Actors

| Actor | Description |
|---|---|
| **Resident** | Authenticated society member using the mobile app chat |
| **AI Agent** | GPT-4.1 model processing resident messages |
| **System** | Automated processes (session rotation, memory summarisation) |
| **Admin** | Society admin (out of scope for this phase) |

### Use Cases

#### UC-01: Start / Continue a Conversation
- **Actor:** Resident
- **Action:** Sends a text message via the chat UI
- **Outcome:** AI processes the message, responds with text + optional action cards
- **Edge cases:**
  - Empty message with no selection → return welcome screen with suggestions
  - Message is a simple greeting (hi/hello/hey) → return lightweight greeting, skip AI call
  - Code is missing or invalid → return 401
  - OpenAI API is down → return graceful degradation message, log error

#### UC-02: Select an Action Card
- **Actor:** Resident
- **Action:** Taps a card in the chat (e.g. "Book Badminton", "Raise Complaint")
- **Outcome:** System handles the selection — either confirms an action or navigates to a screen
- **Edge cases:**
  - Selection is a booking confirmation → call booking API directly, skip AI
  - Selection is a complaint close/reopen → call complaints API directly, skip AI
  - Selection has missing context (no unit_id) → show unit selection cards
  - Selection has missing club context → show club selection cards

#### UC-03: Book a Facility
- **Actor:** Resident
- **Action:** Requests to book a sports facility for a date and time
- **Outcome:** AI presents available slots as cards; resident taps one; booking API is called; confirmation shown
- **Happy path:** message → slots shown → slot selected → booking confirmed
- **Edge cases:**
  - No slots available → friendly message with alternative suggestions
  - Booking API fails → error card with retry option
  - Missing amenity_id or date → AI prompts for missing info
  - User has no club selected → club selection prompt shown first
  - User changes date mid-flow → slots refreshed for new date

#### UC-04: Raise a Maintenance Request
- **Actor:** Resident
- **Action:** Reports an issue (e.g. "AC not working", "sink is leaking")
- **Outcome:** System detects the issue, matches to a device if possible, confirms with resident, calls service request API
- **Happy path:** message → issue detected → unit confirmed → confirmation card → API called → success
- **Edge cases:**
  - Multiple units → show unit selection cards
  - Device not registered for unit → block complaint, show device directory
  - User cancels → reset flow state
  - API call fails → error card with retry

#### UC-05: View and Manage Complaints
- **Actor:** Resident
- **Action:** Asks "show my complaints" or "close complaint #C1256"
- **Outcome:** AI reads from resident profile and returns complaint cards with close/reopen actions
- **Edge cases:**
  - No complaints → friendly message
  - Close API fails → error card
  - Complaint already closed → inform resident

#### UC-06: View Chat History
- **Actor:** Resident
- **Action:** Scrolls up in chat / taps "load more"
- **Outcome:** Paginated list of previous messages returned
- **Edge cases:**
  - No history → return welcome message
  - Invalid cursor → return 400

#### UC-07: Clear Chat History
- **Actor:** Resident
- **Action:** Taps "Clear Chat"
- **Outcome:** All messages and memory for that user are deleted; fresh welcome screen shown
- **Edge cases:**
  - Code missing → 400 error

#### UC-08: View Resident Profile (via chat)
- **Actor:** Resident
- **Action:** Asks "what is my name" or "which units do I have"
- **Outcome:** AI answers directly from profile data without returning cards

#### UC-09: Manage Family Members
- **Actor:** Resident
- **Action:** Asks to add/view family or tenants
- **Outcome:** Navigation card to the family management screen returned

#### UC-10: View Community Directory
- **Actor:** Resident
- **Action:** Asks for a directory or contacts
- **Outcome:** Navigation card or list returned

---

## 1.2 Non-Functional Requirements

### Availability
- **Target:** 99.9% uptime (≤ 8.7 hours downtime/year)
- **Justification:** Chat is a convenience feature, not life-critical; 99.9% is appropriate and achievable without multi-region active-active

### Latency Targets

| Endpoint | p50 | p95 | p99 |
|---|---|---|---|
| `POST /v1/chat` (with AI call) | 1.5s | 4s | 8s |
| `POST /v1/chat` (greeting shortcut) | 50ms | 150ms | 300ms |
| `POST /v1/chat` (confirmation card, no AI) | 200ms | 500ms | 1s |
| `GET /v1/history` | 80ms | 200ms | 500ms |
| `POST /v1/clearhistory` | 100ms | 300ms | 600ms |

> Note: AI call latency (GPT-4.1) is the dominant factor. p99 of 8s is acceptable for a chat assistant; users see a typing indicator.

### Throughput
- **Baseline:** 50 concurrent residents, 200 requests/minute
- **Peak:** 500 concurrent residents, 2,000 requests/minute (society events)
- **Scale target:** System must handle 10x baseline (500 concurrent) with horizontal pod scaling and no rearchitecting

### Data Volume
- **Messages:** ~30 messages/resident/day × 10,000 residents = 300,000 rows/day = ~9M rows/month
- **Memory:** 1 row per resident, JSON blob ~5KB = 50MB total, grows slowly
- **API logs:** 1 row per request, ~300,000 rows/day
- **Retention:** Messages — 90 days rolling; API logs — 30 days rolling; Memory — indefinite

### Consistency Requirements

| Operation | Consistency Model | Justification |
|---|---|---|
| Save message | Strong (synchronous) | Message must be saved before response sent |
| Save memory | Strong (synchronous) | Next request depends on memory state |
| API logging | Eventual (async, fire-and-forget) | Logging must not block user response |
| Profile fetch | Cache-aside (5 min TTL) | Profile changes infrequently |
| Amenity catalogue | Cache-aside (60 min TTL) | Static data |

### Security Requirements
- All endpoints authenticated via Bearer JWT
- No internal error details ever returned to clients
- All user-supplied inputs validated and sanitized via Pydantic before use
- Rate limiting: 60 requests/minute per user, 200 requests/minute per IP
- Sensitive fields (tokens, passwords) masked in logs
- HTTPS enforced at the load balancer level (TLS 1.2+)
- CORS restricted to known frontend origins
- DB queries via parameterized statements only (no string interpolation)
- Secrets via environment variables, never in code

### Disaster Recovery
- **RTO (Recovery Time Objective):** 15 minutes
- **RPO (Recovery Point Objective):** 5 minutes (DB WAL replication lag)

---

## 1.3 Assumptions & Constraints

| # | Assumption |
|---|---|
| [A-01] | The Bearer JWT issued by `hcomm.in` can be validated server-side (either via shared secret or JWKS endpoint) |
| [A-02] | The PostgreSQL schema (`aiagent.*`) already exists in the target DB; migrations are additive only |
| [A-03] | Redis is available in the deployment environment for caching and rate limiting |
| [A-04] | The `hcomm.in` external API is available and accepts the same Bearer token the frontend sends |
| [A-05] | The amenity catalogue will eventually move to DB; for now it is seeded from config |
| [A-06] | A single OpenAI API key is sufficient; no per-tenant key rotation required in this phase |
| [A-07] | The frontend does not need any changes; JSON contract is fully backward compatible |
| [A-08] | Deployment is to a Linux container environment (Docker / Kubernetes) |


---

## 1.4 Out of Scope

- Admin portal / society management interface
- Push notifications to residents
- Real-time WebSocket streaming (typing indicators are client-side only)
- Payment processing of any kind
- Multi-language / i18n support
- Analytics dashboards
- PDF/file upload in chat
- Multi-tenant isolation (all data is in one PostgreSQL schema)

---

# Phase 2 — Architecture Documents

## 2.1 — Context Diagram (C4 Level 1)

```mermaid
C4Context
    title HiRa AI Agent — System Context

    Person(resident, "Resident", "Society member using the mobile app")
    
    System(hira, "HiRa AI Agent", "Conversational AI assistant for society residents. Handles bookings, complaints, maintenance, and community queries.")
    
    System_Ext(hcomm_api, "hcomm.in API", "Society management platform. Source of truth for profiles, bookings, complaints, and service requests.")
    System_Ext(openai, "OpenAI API", "GPT-4.1 model for natural language understanding and response generation.")
    System_Ext(cloudinary, "Cloudinary CDN", "Image delivery for amenity icons and resident photos.")
    
    Rel(resident, hira, "Sends messages, taps cards", "HTTPS/JSON")
    Rel(hira, hcomm_api, "Fetches profile, creates bookings, raises service requests", "HTTPS/REST")
    Rel(hira, openai, "Sends conversation context, receives structured responses", "HTTPS/REST")
    Rel(hira, cloudinary, "Generates image URLs", "URL construction only")
    Rel(hcomm_api, resident, "Auth tokens issued at login", "HTTPS")
```

**Narrative:** HiRa sits between the resident's app and three external systems. The resident's Bearer token (issued by hcomm.in at login) is passed through to HiRa and used both to authenticate the request and to authorize downstream calls to the society platform. OpenAI is the only AI provider; the system is designed so the provider can be swapped by changing one service class. Cloudinary URLs are constructed locally — no API call required. The key trust boundary is the Bearer token: HiRa must validate it before trusting any user-supplied `code` value.

---

## 2.2 — Container Diagram (C4 Level 2)

```mermaid
C4Container
    title HiRa AI Agent — Container Diagram

    Person(resident, "Resident", "Mobile app user")

    System_Boundary(hira, "HiRa AI Agent System") {
        Container(api, "FastAPI Application", "Python 3.12 / FastAPI / uvicorn", "Handles all HTTP requests. Auth middleware, routing, request/response validation.")
        Container(redis, "Redis", "Redis 7", "Rate limiting counters, in-request profile cache, amenity cache, session locks.")
        ContainerDb(postgres, "PostgreSQL 15", "PostgreSQL", "Stores messages, memory, sessions, api_logs. Schema: aiagent.*")
        Container(worker, "Background Worker", "Python / asyncio task", "Async API log writes. Runs inside the FastAPI process as background tasks.")
    }

    System_Ext(hcomm_api, "hcomm.in API", "External society platform")
    System_Ext(openai, "OpenAI API", "GPT-4.1")

    Rel(resident, api, "POST /v1/chat, GET /v1/history", "HTTPS JSON")
    Rel(api, redis, "Rate limit check, cache read/write", "Redis protocol")
    Rel(api, postgres, "Read/write messages, memory, sessions", "asyncpg / SQL")
    Rel(api, hcomm_api, "Fetch profile, create booking, raise service request", "HTTPS REST")
    Rel(api, openai, "Chat completions with tool calling", "HTTPS REST")
    Rel(api, worker, "Queue api_log write", "In-process async queue")
    Rel(worker, postgres, "Write api_log rows", "asyncpg / SQL")
```

**Narrative:** The system is intentionally a single deployable unit (monolith) for this phase — the right choice given the team size and request volume. The only true async boundary is the API log write, which is offloaded to a background task so it never adds latency to the user response. Redis serves dual purpose: rate limiting and short-lived caching of profile and amenity data. If Redis becomes unavailable, the system degrades gracefully — rate limiting is bypassed (fail open) and profile fetches fall back to the external API directly.

---

## 2.3 — Component Diagram (C4 Level 3)

```mermaid
graph TB
    subgraph "API Layer (app/api/v1/)"
        R[Router<br/>router.py] --> CH[Chat Handler<br/>chat.py]
        R --> HH[History Handler<br/>history.py]
        R --> PH[Profile Handler<br/>profile.py]
    end

    subgraph "Middleware (app/middleware/)"
        AM[Auth Middleware<br/>auth.py] --> RLM[Rate Limit Middleware<br/>rate_limit.py]
        RLM --> LM[Logging Middleware<br/>logging.py]
        LM --> CM[CORS Middleware<br/>cors.py]
    end

    subgraph "Domain Layer (app/domain/)"
        AG[Agent Orchestrator<br/>agent/agent.py]
        PR[System Prompt Builder<br/>agent/prompt.py]
        MM[Memory Manager<br/>agent/memory_manager.py]
        
        subgraph "Intents"
            BI[Booking Intent<br/>intents/booking/]
            CI[Complaints Intent<br/>intents/complaints/]
            GI[Greeting Intent<br/>intents/greeting/]
            FI[Family Intent<br/>intents/family/]
            DI[Directory Intent<br/>intents/directory/]
        end
        
        CV[Card Validator<br/>cards/validator.py]
        CE[Card Enricher<br/>cards/enricher.py]
        CR[Context Resolver<br/>cards/resolver.py]
    end

    subgraph "Service Layer (app/services/)"
        OAS[OpenAI Service<br/>openai_service.py]
        EAS[External API Service<br/>external_api.py]
        PS[Profile Service<br/>profile_service.py]
    end

    subgraph "Repository Layer (app/repositories/)"
        MR[Memory Repository<br/>memory_repo.py]
        MSR[Message Repository<br/>message_repo.py]
        SR[Session Repository<br/>session_repo.py]
        LR[Log Repository<br/>log_repo.py]
    end

    subgraph "Infrastructure (app/core/)"
        DB[(asyncpg Pool<br/>database.py)]
        RD[(Redis Client<br/>redis.py)]
        LOG[Structured Logger<br/>logger.py]
        EX[Exception Taxonomy<br/>exceptions.py]
    end

    CH --> AG
    AG --> PR
    AG --> MM
    AG --> BI
    AG --> CI
    AG --> GI
    AG --> CV
    AG --> CE
    AG --> CR
    AG --> OAS
    AG --> EAS
    MM --> MR
    AG --> MSR
    AG --> SR
    PS --> EAS
    MR --> DB
    MSR --> DB
    SR --> DB
    LR --> DB
    AM --> RD
    RLM --> RD
    PS --> RD
```

**Narrative:** The architecture enforces a strict dependency rule: the domain layer (Agent, Intents, Cards) depends on service interfaces and repository interfaces — never on infrastructure directly. This means the OpenAI client, PostgreSQL pool, and Redis client are never imported inside business logic. All external I/O flows through the service and repository layers. This makes every business logic unit independently testable without a real database or real API. The middleware stack runs in order: Auth first (blocks unauthenticated), then Rate Limit (blocks abusers), then Logging (captures the full request/response cycle).

---

## 2.4 — High-Level Architecture Diagram (HLD)

```mermaid
graph TB
    subgraph "Client Layer"
        MOB[Mobile App<br/>iOS / Android]
        WEB[Web App<br/>Browser]
    end

    subgraph "Edge Layer"
        LB[Load Balancer / Nginx<br/>TLS Termination, CORS headers]
    end

    subgraph "Application Tier"
        subgraph "FastAPI Pods (horizontally scalable)"
            POD1[uvicorn worker 1]
            POD2[uvicorn worker 2]
            PODN[uvicorn worker N]
        end
    end

    subgraph "Caching Layer"
        RC[Redis Cluster<br/>Rate limits · Profile cache<br/>Amenity cache · Session locks]
    end

    subgraph "Data Tier"
        PG_PRIMARY[(PostgreSQL Primary<br/>aiagent.ai_messages<br/>aiagent.ai_memory<br/>aiagent.ai_sessions<br/>aiagent.api_logs)]
        PG_REPLICA[(PostgreSQL Read Replica<br/>history queries)]
    end

    subgraph "External Services"
        OAI[OpenAI API<br/>gpt-4.1<br/>Tool calling]
        HCOMM[hcomm.in API<br/>Profile · Booking<br/>Complaints · Service Req]
        CDN[Cloudinary CDN<br/>Images]
    end

    subgraph "Observability"
        LOG[Structured Logs<br/>stdout → log aggregator]
        MET[Metrics<br/>Prometheus / StatsD]
        TR[Traces<br/>OpenTelemetry]
    end

    MOB -->|HTTPS| LB
    WEB -->|HTTPS| LB
    LB -->|HTTP/1.1 or HTTP/2| POD1
    LB -->|HTTP/1.1 or HTTP/2| POD2
    LB -->|HTTP/1.1 or HTTP/2| PODN
    POD1 <-->|Redis protocol| RC
    POD2 <-->|Redis protocol| RC
    PODN <-->|Redis protocol| RC
    POD1 -->|asyncpg| PG_PRIMARY
    POD2 -->|asyncpg| PG_PRIMARY
    PODN -->|asyncpg| PG_PRIMARY
    POD1 -->|asyncpg| PG_REPLICA
    POD1 -->|HTTPS| OAI
    POD1 -->|HTTPS| HCOMM
    POD1 -.->|URL construction| CDN
    PG_PRIMARY -->|WAL streaming| PG_REPLICA
    POD1 --> LOG
    POD1 --> MET
    POD1 --> TR
```

**Narrative:** Each FastAPI pod runs multiple uvicorn async workers. Because all I/O is non-blocking (asyncpg, httpx), a single pod can handle hundreds of concurrent in-flight requests. The Load Balancer terminates TLS and handles CORS preflight. Read-heavy queries (history) can be directed to the read replica to offload the primary. Redis is the shared state layer across pods — rate limit counters and caches are consistent regardless of which pod handles a request. The observability stack is pluggable: structured JSON logs to stdout, Prometheus metrics via a `/metrics` endpoint, and OpenTelemetry traces for distributed tracing across the OpenAI and hcomm.in calls.

---

## 2.5 — Data Flow Diagram

### DFD Level 0 — Overall System

```mermaid
graph LR
    RESIDENT([Resident]) -->|message + selection + auth token| HIRA[HiRa AI Agent]
    HIRA -->|response: message + cards + ui| RESIDENT
    HIRA -->|conversation context + tools| OPENAI([OpenAI API])
    OPENAI -->|structured response| HIRA
    HIRA -->|profile/booking/complaint requests| HCOMM([hcomm.in API])
    HCOMM -->|profile data, action results| HIRA
    HIRA -->|read/write| DB([PostgreSQL])
    HIRA -->|cache reads/writes, rate limit| CACHE([Redis])
```

### DFD Level 1 — Chat Request Flow

```mermaid
flowchart TD
    A[HTTP Request\nPOST /v1/chat] --> B{Auth Middleware\nValidate Bearer JWT}
    B -->|Invalid| B1[Return 401]
    B -->|Valid| C{Rate Limit\nCheck Redis}
    C -->|Exceeded| C1[Return 429]
    C -->|OK| D[Parse + Validate\nPydantic ChatRequest]
    D -->|Invalid| D1[Return 400]
    D -->|Valid| E[Get/Create Session\nfrom PostgreSQL]
    E --> F[Load Memory\nfrom PostgreSQL]
    F --> G[Load Profile\nfrom Redis cache\nor hcomm.in API]
    G --> H{Has Selection?}
    H -->|Yes| I{Is Confirmation\nAction?}
    I -->|Yes| J[Call hcomm.in API\nbooking/complaint]
    J --> K[Build Response\nNo AI needed]
    I -->|No| L[Handle Selection\nContext Save to DB]
    L --> M[Pre-process\nMaintenance Flow]
    H -->|No| M
    M -->|Handled| N[Return Pre-built Response]
    M -->|Continue| O[Build Conversation\nSystem prompt + history + memory]
    O --> P[Call OpenAI\ngpt-4.1 + tools]
    P --> Q{Tool Calls?}
    Q -->|Yes - max 3 loops| R[Execute Tool\ngetAvailableSlots]
    R --> P
    Q -->|No| S[Parse AI Response\nJSON extraction]
    S --> T[Validate Cards]
    T --> U[Resolve Context Cards\nunit/club injection]
    U --> V[Enrich Cards\nicons, buttons, amenity data]
    V --> W[Highlight Message\nMarkdown wrap]
    W --> X[Save Message\nto PostgreSQL async]
    X --> Y[Update Memory\nto PostgreSQL]
    Y --> Z[Log Request\nbackground task]
    Z --> AA[Return JSON Response]
```

---

## 2.6 — Entity Relationship Diagram (ERD)

```mermaid
erDiagram
    USERS {
        varchar code PK "Resident identifier e.g. HC2526Y61"
        varchar type "owner | tenant | family"
        varchar username
        varchar fullname
        varchar countrycode
        varchar gender
        varchar mobile
        varchar email
        varchar photo
        boolean active
        varchar propertyid FK
        timestamptz createdat
    }

    AI_SESSIONS {
        uuid id PK "UUID v4"
        varchar code FK "→ users.code"
        timestamptz created_at "default now()"
        timestamptz updated_at "default now()"
    }

    AI_MESSAGES {
        bigserial id PK
        varchar code FK "→ users.code"
        uuid session_id FK "→ ai_sessions.id"
        varchar role "user | assistant | system"
        varchar type "text | response | card | array"
        text message "Plain text or stringified JSON"
        jsonb meta "cards, ui metadata"
        timestamptz created_at "default now()"
    }

    AI_MEMORY {
        varchar code PK "→ users.code"
        jsonb context "Persistent context: unit_id, club_id, society_id"
        jsonb booking "Last booking details: amenity, date, timeslot"
        jsonb issue "Current maintenance issue being raised"
        jsonb flow "Current multi-step flow state"
        text last_intent "Last matched intent action string"
        text last_screen "Last screen the user was on"
        text summary "LLM-generated conversation summary"
        int summary_message_count "Message count when summary was last generated"
        timestamptz updated_at "default now()"
    }

    API_LOGS {
        bigserial id PK
        varchar api_name "Script/service name"
        text endpoint "Full URI path"
        varchar request_method
        jsonb request_headers "Sensitive fields masked"
        text request_body "Sensitive fields masked, truncated at 100KB"
        jsonb query_params
        int response_status
        jsonb response_body
        inet ip_address
        text user_agent
        varchar user_id FK "→ users.code, nullable"
        int execution_time_ms
        timestamptz created_at "default now()"
    }

    USERS ||--o{ AI_SESSIONS : "has many"
    USERS ||--o{ AI_MESSAGES : "has many"
    USERS ||--|| AI_MEMORY : "has one"
    USERS ||--o{ API_LOGS : "has many (nullable)"
    AI_SESSIONS ||--o{ AI_MESSAGES : "contains"
```

### Index Design

```sql
-- Primary access patterns:

-- 1. Load latest session for a user (getOrCreateSession)
CREATE INDEX idx_ai_sessions_code_updated
    ON aiagent.ai_sessions (code, updated_at DESC);

-- 2. Load N most recent messages for a session (getMessages)
CREATE INDEX idx_ai_messages_session_id_id
    ON aiagent.ai_messages (session_id, id DESC);

-- 3. Load messages by user for history endpoint (paginated)
CREATE INDEX idx_ai_messages_code_id
    ON aiagent.ai_messages (code, id DESC);

-- 4. Memory lookup is always by primary key (code) — no extra index needed

-- 5. API log queries (by user or by date range for cleanup)
CREATE INDEX idx_api_logs_user_id_created
    ON aiagent.api_logs (user_id, created_at DESC)
    WHERE user_id IS NOT NULL;
CREATE INDEX idx_api_logs_created_at
    ON aiagent.api_logs (created_at DESC);
```

**ERD Decisions:**

- **`ai_memory` is one row per user** (upsert pattern). Context is split into typed JSONB columns (`context`, `booking`, `issue`, `flow`) rather than one blob. This allows the PostgreSQL `||` merge to work correctly at the column level — each column merges independently. This directly fixes the P0-4 audit bug.
- **`ai_sessions.id` is a UUID**, not an integer. Session IDs are passed around in conversation context; using UUIDs prevents enumeration attacks.
- **`ai_messages.id` is a `bigserial`** for cursor-based pagination. The cursor is the last seen `id`, making history queries O(log n) rather than O(n).
- **`api_logs` is append-only.** No updates, no deletes except via scheduled cleanup job.

---

## 2.7 — API Contract Design

### Authentication
All endpoints (except `/health`) require:
```
Authorization: Bearer <token>
```
The token is the JWT issued by hcomm.in at resident login. On every request, HiRa validates the JWT signature and expiry. The `code` in the request body must match the `sub` claim in the JWT.

---

### POST /v1/chat

```
POST   /v1/chat
Auth:  Bearer JWT (required)
Rate:  60 requests/minute per user_id, 200/minute per IP
Idempotency: NOT idempotent — each call creates a new message

Headers:
  Content-Type: application/json
  Authorization: Bearer <token>
  X-Request-ID: <uuid>  (generated by client or middleware if absent)

Request Body:
{
  "code":      string   — Resident identifier — required — must match JWT sub claim
  "message":   string   — User's chat message — optional (empty string allowed)
  "selection": object | null — Card selection payload — optional
}

Selection object (when resident taps a card):
{
  "action":       string  — Action name e.g. "clubsbooking", "select_unit"
  "type":         string  — "unit" | "club" | null
  "id":           string  — Entity ID
  "title":        string  — Display title
  "confirm":      boolean — True when resident confirms an action
  "autocomplete": object  — Pre-filled form data for the action
  "data":         object  — Additional action-specific payload
}

Success Response 200:
{
  "success": true,
  "data": {
    "type":    string   — "response" | "card" | "array"
    "message": string | object  — Text response or structured welcome message
    "cards":   array    — Action cards for the frontend
    "ui":      object   — UI component hints e.g. {"component": "booking_card"}
    "suggestions": array — Suggested actions (welcome screen only)
  }
}

Card object in response:
{
  "id":     string  — Optional card identifier
  "title":  string  — Button label
  "action": string  — Frontend action name
  "icon":   string  — CDN image URL (optional)
  "button": object  — Navigate target {"title": string, "action": string}
  "data":   object  — Payload passed back as selection on tap
}

Error Responses:
  400 — {"success": false, "error": {"code": 400, "message": "Validation error", "field": "code"}}
  401 — {"success": false, "error": {"code": 401, "message": "Unauthorized"}}
  403 — {"success": false, "error": {"code": 403, "message": "Forbidden — code does not match token"}}
  429 — {"success": false, "error": {"code": 429, "message": "Rate limit exceeded"}}
        Header: Retry-After: 30
  500 — {"success": false, "error": {"code": 500, "message": "An unexpected error occurred"}}
        (Internal details NEVER included)
```

---

### GET /v1/history

```
GET    /v1/history
Auth:  Bearer JWT (required)
Rate:  120 requests/minute per user
Idempotency: Idempotent (read-only)
Cacheability: Not cached (user-specific, changes frequently)

Query Params:
  code:   string — Resident identifier — required
  cursor: integer — Last seen message id for pagination — optional (0 = latest)
  limit:  integer — Number of messages — optional — default 10 — max 50

Success Response 200:
{
  "success": true,
  "data": {
    "messages": [
      {
        "id":        integer
        "code":      string
        "role":      string  — "user" | "assistant"
        "type":      string
        "message":   string
        "photo":     string | null
        "data":      object  — card/ui metadata
        "shortdate": string  — "Jun 05, 2026 10:30 AM"
        "date":      string  — ISO 8601 timestamp
      }
    ],
    "next_cursor": integer | null  — null means no more history
    "has_more":    boolean
  }
}
```

---

### POST /v1/clearhistory

```
POST   /v1/clearhistory
Auth:  Bearer JWT (required)
Rate:  10 requests/minute per user
Idempotency: Idempotent (double-clear is safe)

Request Body:
{
  "code": string — required
}

Success Response 200:
{
  "success": true,
  "data": { ...welcome screen response identical to empty chat... }
}
```

---

### GET /v1/profile

```
GET    /v1/profile
Auth:  Bearer JWT (required)
Rate:  30 requests/minute per user
Idempotency: Idempotent
Cacheability: Cache 5 minutes in Redis, key: profile:{code}

Query Params:
  code: string — required

Success Response 200:
{
  "success": true,
  "data": { ...profile object from hcomm.in... }
}
```

---

### GET /health

```
GET /health
Auth: None required
Rate: None

Success Response 200:
{
  "status":  "ok",
  "db":      "ok" | "degraded",
  "redis":   "ok" | "degraded",
  "version": "1.0.0"
}
```

---

## 2.8 — Sequence Diagrams

### Sequence 1: Full Chat Turn (AI Path)

```mermaid
sequenceDiagram
    participant App as Mobile App
    participant LB as Load Balancer
    participant API as FastAPI
    participant Auth as Auth Middleware
    participant RL as Rate Limiter
    participant Redis as Redis
    participant DB as PostgreSQL
    participant OAI as OpenAI
    participant HC as hcomm.in

    App->>LB: POST /v1/chat {code, message}
    LB->>API: Forward request
    API->>Auth: Validate Bearer JWT
    Auth->>Auth: Verify signature + expiry
    Auth->>Auth: Verify code == JWT.sub
    alt JWT invalid
        Auth-->>App: 401 Unauthorized
    end
    
    API->>RL: Check rate limit (Redis)
    Redis-->>RL: count=5 (under limit)
    RL->>Redis: Increment counter (TTL 60s)
    
    API->>Redis: GET profile:{code}
    alt Cache miss
        Redis-->>API: nil
        API->>HC: POST /profile {code} + Bearer token
        HC-->>API: Profile data
        API->>Redis: SET profile:{code} TTL=300
    else Cache hit
        Redis-->>API: Profile data
    end
    
    API->>DB: SELECT ai_sessions WHERE code=? ORDER BY updated_at DESC LIMIT 1
    DB-->>API: session_id
    
    API->>DB: SELECT context,booking,issue,flow,last_intent FROM ai_memory WHERE code=?
    DB-->>API: memory object
    
    API->>DB: SELECT role,message FROM ai_messages WHERE session_id=? ORDER BY id DESC LIMIT 12
    DB-->>API: recent messages
    
    API->>API: buildConversation(history + memory + profile + selection)
    
    API->>OAI: POST /responses {model, input, tools}
    Note over API,OAI: Async HTTP call — does not block other requests
    OAI-->>API: Response with tool_call: get_available_slots
    
    API->>API: handleTool("get_available_slots", args)
    Note over API: Returns hardcoded slots for now (stub)
    
    API->>OAI: POST /responses {input + tool_result}
    OAI-->>API: Final text response (JSON)
    
    API->>API: parseResponse(output)
    API->>API: validateCards(cards)
    API->>API: resolveContextCards(cards, profile, memory)
    API->>API: enrichPredefinedCards(cards)
    API->>API: enrichAmenityCards(cards)
    API->>API: highlightMessageMarkdown(message)
    
    API->>DB: INSERT ai_messages (user message)
    API->>DB: INSERT ai_messages (assistant response)
    API->>DB: UPSERT ai_memory SET context=?, last_intent=?
    
    API-->>App: 200 {success: true, data: {message, cards, ui}}
    
    Note over API,DB: Background task (does not delay response)
    API->>DB: INSERT api_logs (async)
```

---

### Sequence 2: Booking Confirmation (No AI Path)

```mermaid
sequenceDiagram
    participant App as Mobile App
    participant API as FastAPI
    participant HC as hcomm.in

    App->>API: POST /v1/chat {code, message:"", selection:{action:"clubsbooking", autocomplete:{...}}}
    API->>API: Auth + Rate limit (same as above)
    API->>API: detectConfirmationAction(selection)
    Note over API: selection.action == "clubsbooking" AND autocomplete.timeslot present
    API->>HC: POST /mcp/clubs/booking/create {amenity_id, date, timeslot, court, unit_id, club_id}
    HC-->>API: {success: true}
    API->>API: Build success response (no OpenAI call)
    API->>DB: INSERT ai_messages (assistant confirmation)
    API-->>App: 200 {message: "✅ Booking confirmed!", cards: [{View My Bookings}, {Book Another}]}
```

---

### Sequence 3: Missing Unit Context (Context Resolution)

```mermaid
sequenceDiagram
    participant App as Mobile App
    participant API as FastAPI
    participant DB as PostgreSQL

    App->>API: POST /v1/chat {code, message:"My sink is leaking"}
    API->>API: Auth + Rate limit
    API->>DB: Load memory — context.unit_id is null
    API->>DB: Load profile — units: [E2204, 801, A1308]
    API->>API: detectIssue("sink is leaking") → {title, category: "Plumbing"}
    API->>DB: UPSERT ai_memory {issue: {...}, flow: {step: "maintenance_confirm"}}
    API->>API: buildConversation(...)
    API->>OAI: POST /responses
    OAI-->>API: cards: [{action: "servicemaintainanceadd"}]
    API->>API: resolveContextCards — unit required, unit_id missing, 3 units
    Note over API: Override AI cards with unit selection cards
    API-->>App: 200 {message:"Select your unit...", cards:[{E2204},{801},{A1308}]}
```

---

## 2.9 — State Machine Diagram

### Agent Memory Flow State Machine

```mermaid
stateDiagram-v2
    [*] --> IDLE : User sends first message

    IDLE --> BOOKING_INTENT : User mentions booking/sport/play
    IDLE --> MAINTENANCE_INTENT : User reports an issue
    IDLE --> COMPLAINT_VIEW : User asks about complaints
    IDLE --> GENERAL : Any other message

    BOOKING_INTENT --> AWAITING_CLUB : User has multiple clubs, none selected
    BOOKING_INTENT --> SHOWING_SLOTS : Club context exists
    AWAITING_CLUB --> SHOWING_SLOTS : User selects club card
    SHOWING_SLOTS --> CONFIRMING_BOOKING : User selects a time slot card
    CONFIRMING_BOOKING --> BOOKING_CONFIRMED : hcomm.in booking API returns success
    CONFIRMING_BOOKING --> BOOKING_FAILED : hcomm.in booking API returns error
    BOOKING_CONFIRMED --> IDLE : New session starts
    BOOKING_FAILED --> SHOWING_SLOTS : User taps "Try Again"

    MAINTENANCE_INTENT --> AWAITING_UNIT : User has multiple units, none selected
    MAINTENANCE_INTENT --> MAINTENANCE_CONFIRM : Unit context exists
    AWAITING_UNIT --> MAINTENANCE_CONFIRM : User selects unit card
    MAINTENANCE_CONFIRM --> SERVICE_REQUEST_SENT : User taps "Yes, Raise Request", API succeeds
    MAINTENANCE_CONFIRM --> SERVICE_REQUEST_FAILED : API fails
    MAINTENANCE_CONFIRM --> IDLE : User taps "Cancel" → session rotated
    SERVICE_REQUEST_SENT --> IDLE
    SERVICE_REQUEST_FAILED --> MAINTENANCE_CONFIRM : User taps "Try Again"

    COMPLAINT_VIEW --> COMPLAINT_CLOSING : User taps "Mark as Closed"
    COMPLAINT_VIEW --> COMPLAINT_REOPENING : User taps "Reopen"
    COMPLAINT_CLOSING --> COMPLAINT_CLOSED : API success
    COMPLAINT_CLOSING --> COMPLAINT_VIEW : API failure
    COMPLAINT_REOPENING --> COMPLAINT_OPEN : API success
    COMPLAINT_REOPENING --> COMPLAINT_VIEW : API failure

    GENERAL --> IDLE
    COMPLAINT_CLOSED --> IDLE
    COMPLAINT_OPEN --> IDLE
```

### Session Lifecycle State Machine

```mermaid
stateDiagram-v2
    [*] --> SESSION_CREATED : First request for user

    SESSION_CREATED --> SESSION_ACTIVE : Subsequent requests same day
    SESSION_ACTIVE --> SESSION_ACTIVE : Message saved
    SESSION_ACTIVE --> SESSION_ROTATED : New day detected (updated_at.date != today)
    SESSION_ACTIVE --> SESSION_CANCELLED : User taps "Cancel" action
    SESSION_ROTATED --> SESSION_CREATED : New session inserted
    SESSION_CANCELLED --> SESSION_CREATED : New session created, memory reset
```

---

## 2.10 — Infrastructure Diagram

```mermaid
graph TB
    subgraph "Internet"
        CLIENT[Mobile / Web Client]
    end

    subgraph "Edge"
        DNS[DNS\nCloudflare / Route53]
        CDN_EDGE[CDN Edge\nStatic assets]
        LB[Nginx / Cloud LB\nTLS 1.3 termination\nHTTP/2]
    end

    subgraph "VPC — Production"
        subgraph "AZ-1 (Primary)"
            APP1[FastAPI Pod 1\n2 vCPU / 2GB RAM]
            APP2[FastAPI Pod 2\n2 vCPU / 2GB RAM]
            PG_MAIN[(PostgreSQL Primary\n4 vCPU / 16GB RAM\n500GB SSD)]
            REDIS_1[Redis Primary\n2GB]
        end
        subgraph "AZ-2 (Standby)"
            APP3[FastAPI Pod 3]
            PG_REP[(PostgreSQL Replica\nread-only)]
            REDIS_2[Redis Replica]
        end
        subgraph "Secrets"
            SM[Secrets Manager\nOPENAI_API_KEY\nDB credentials\nJWT secrets]
        end
    end

    subgraph "Observability"
        LOGS[Log Aggregator\nLoki / CloudWatch]
        METRICS[Metrics\nPrometheus + Grafana]
        ALERTS[Alerting\nPagerDuty / OpsGenie]
    end

    CLIENT --> DNS
    DNS --> LB
    LB --> APP1
    LB --> APP2
    LB --> APP3
    APP1 --> PG_MAIN
    APP2 --> PG_MAIN
    APP3 --> PG_REP
    PG_MAIN -->|WAL streaming| PG_REP
    APP1 --> REDIS_1
    REDIS_1 --> REDIS_2
    APP1 --> SM
    APP1 --> LOGS
    APP1 --> METRICS
    METRICS --> ALERTS
```

---

## 2.11 — Deployment Pipeline Diagram

```mermaid
flowchart LR
    A[Git Push\nto main] --> B[CI: Lint\nruff + mypy]
    B --> C[CI: Unit Tests\npytest -unit]
    C --> D[CI: Integration Tests\npytest -integration\nwith test DB]
    D --> E[CI: Security Scan\nbandit + safety]
    E --> F[Build Docker Image\nmulti-stage build]
    F --> G[Push to Registry\nGHCR / ECR]
    G --> H[Deploy to Staging\nkubectl rollout]
    H --> I[Smoke Tests\nautomated curl suite]
    I --> J{All tests pass?}
    J -->|No| K[Notify team\nDeploy blocked]
    J -->|Yes| L[Manual approval\nStage gate]
    L --> M[Deploy to Production\nRolling update\n25% → 50% → 100%]
    M --> N[Health check gate\n/health 200 OK]
    N --> O{Healthy?}
    O -->|No| P[Auto rollback\nkubectl rollout undo]
    O -->|Yes| Q[Deploy complete\nNotify team]
```

---

## 2.12 — Failure Mode Analysis (FMEA)

| Component | Failure Mode | Probability | Impact | Detection | Mitigation | Recovery Time |
|---|---|---|---|---|---|---|
| PostgreSQL Primary | DB goes down | Low | Critical — no chat, no history | Health check fails | Read replica auto-promote (RDS failover), circuit breaker in app | 30–60s (automated failover) |
| Redis | Redis goes down | Medium | Medium — rate limiting bypassed, cache miss | Redis ping in health check | Fail open on rate limit; fall back to direct API calls for profile | Immediate — stateless fallback |
| OpenAI API | API down / degraded | Medium | High — AI responses unavailable | HTTP 5xx from client | Exponential backoff 3× retry; graceful degradation message to user | Minutes to hours (OpenAI SLA) |
| hcomm.in API | Profile API down | Medium | High — cannot authenticate user context | HTTP 5xx from callApi | Return cached profile (stale ok); block mutations, allow reads | Minutes |
| hcomm.in API | Booking API down | Low | Medium — booking fails | HTTP error in callApi | Return error card with retry option | Minutes |
| Bad Deploy | New code raises exception on startup | Medium | High — all pods crash | Pod liveness probe fails | Rollback triggered automatically; previous image re-deployed | 2–5 minutes |
| Bad Deploy | New code has data corruption bug | Very Low | Critical | Monitoring alerts on error rate spike | Feature flag to bypass new code path; rollback deploy | 10–30 minutes |
| DDoS | Request flood from single IP | Medium | Medium — rate limit Redis, LB overwhelmed | Spike in request rate metrics | Rate limiting at LB + app level; IP block at CDN edge | Immediate |
| Memory Growth | Memory store unbounded | Low | Medium — DB storage exhausted | DB size monitoring alert | Implement 90-day rolling delete job on api_logs, messages | Scheduled maintenance |
| OpenAI Token Limit | Conversation too long for context window | Medium | Low — single request fails | OpenAI 400 error | Summariser runs more aggressively; trim oldest messages | Per-request |
| Background Worker | API log queue backs up | Low | Low — logs delayed, not lost | Queue depth metric | Background task is fire-and-forget with retry; not user-facing | Minutes |

---

# Phase 3 — Technical Decision Log (ADRs)

## ADR-001: Python 3.12 + FastAPI over PHP 8

**Date:** 2026-06-05  
**Status:** Accepted

**Context:** The current system is in PHP. We are evaluating whether to stay in PHP or migrate to Python.

**Decision:** Migrate to Python 3.12 with FastAPI.

**Rationale:**
- OpenAI's Python SDK is the reference implementation, maintained by Anthropic/OpenAI, async-native, and feature-complete. The PHP client lags months behind.
- FastAPI's Pydantic-based validation eliminates the entire class of input coercion bugs found in the audit.
- Python's `asyncio` allows all DB and HTTP calls to run concurrently without blocking workers.
- Python's ecosystem for ML/AI tooling is unmatched and future-proofs the stack for model switching.

**Alternatives Considered:**
- **Stay in PHP 8** — Pros: no migration cost; Cons: blocking I/O, inferior AI SDK, no type safety, audit issues are hard to fix structurally.
- **Node.js / TypeScript** — Pros: async, typed; Cons: team has no Node experience; OpenAI SDK parity is similar.

**Consequences:** All engineers need Python familiarity. The deployment artifact changes from PHP-FPM to a Docker container running uvicorn.

**Review Date:** 2027-06-05

---

## ADR-002: asyncpg over SQLAlchemy ORM

**Date:** 2026-06-05  
**Status:** Accepted

**Context:** We need an async PostgreSQL driver. Options are raw asyncpg, SQLAlchemy Core (async), or SQLAlchemy ORM (async).

**Decision:** asyncpg with raw parameterized SQL, wrapped in repository classes.

**Rationale:**
- All existing queries are straightforward CRUD — no ORM benefit for this complexity level.
- asyncpg is the fastest Python PostgreSQL driver; SQLAlchemy ORM adds 2–3× query overhead for object mapping.
- Raw SQL inside repository classes is readable, debuggable, and gives full query control.
- The repository pattern provides the same abstraction boundary as an ORM — business logic never touches SQL.

**Alternatives Considered:**
- **SQLAlchemy ORM** — Pros: migrations, model validation; Cons: overhead, complexity, slower queries.
- **SQLAlchemy Core** — Good middle ground, but asyncpg is simpler for this use case.

**Consequences:** Migrations must be managed manually (or via Alembic with raw SQL). This is acceptable and already the case with the PHP codebase.

**Review Date:** 2027-06-05

---

## ADR-003: JWT Validation via hcomm.in JWKS Endpoint

**Date:** 2026-06-05  
**Status:** Proposed — Requires OQ-01 answer

**Context:** Every request carries a Bearer token from hcomm.in. We must validate it without coupling to the hcomm.in service on every request.

**Decision:** Validate JWT locally using hcomm.in's JWKS (JSON Web Key Set) endpoint, cached in Redis for 1 hour.

**Rationale:**
- Local validation adds ~0ms latency vs. ~100ms for a remote verification call per request.
- JWKS is the industry standard (used by Auth0, Okta, Google, Stripe).
- If hcomm.in rotates keys, the cache auto-refreshes when a validation fails.
- The `sub` claim in the JWT becomes the authoritative user identifier; the `code` in the request body must match it — preventing horizontal auth escalation.

**Alternatives Considered:**
- **Remote verify call on every request** — Pros: always fresh; Cons: adds ~100ms latency, creates hard dependency on hcomm.in being up.
- **Shared HMAC secret** — Pros: simple; Cons: secret rotation is operationally painful; requires out-of-band secret sharing.

**Consequences:** hcomm.in must expose a JWKS endpoint. If it does not, fallback is option B (shared secret).

**Review Date:** On first key rotation incident.

---

## ADR-004: Redis for Rate Limiting and Caching

**Date:** 2026-06-05  
**Status:** Accepted

**Context:** Rate limiting must be consistent across multiple FastAPI pods. Profile data is fetched on every request.

**Decision:** Redis for both rate limiting (sliding window counter) and application-level caching (profile, amenities).

**Rationale:**
- Redis sliding window counters are the standard for distributed rate limiting.
- Profile data changes infrequently (5-minute TTL is appropriate); Redis prevents thundering herd on the hcomm.in API.
- Redis is already assumed available in the deployment environment.
- Fail-open strategy on Redis unavailability: rate limiting skipped, caching skipped — system degrades gracefully without going down.

**Alternatives Considered:**
- **In-process cache (per pod)** — Fails with multiple pods; inconsistent rate limits.
- **DB-based rate limiting** — Works but adds DB load for a non-critical feature.

**Review Date:** 2027-06-05

---

## ADR-005: Monolith First, Microservices Later

**Date:** 2026-06-05  
**Status:** Accepted

**Context:** Should HiRa be built as microservices (one per intent) or a monolith?

**Decision:** Monolith. One FastAPI application. One deployment artifact.

**Rationale:**
- Current traffic (50–500 concurrent users) does not justify the operational overhead of microservices.
- The domain boundaries (intents) are internal Python modules — they can be extracted into services later without a rewrite.
- A monolith with strict internal boundaries (repository pattern, service layer, domain modules) gives the same code separation as microservices without the distributed systems complexity.

**Alternatives Considered:**
- **One service per intent (booking-service, complaints-service, etc.)** — Pros: independent scaling; Cons: inter-service latency, distributed tracing complexity, 5× the infrastructure for 1/5th the users.

**Review Date:** When single-pod CPU/memory consistently exceeds 70% at peak.

---

## ADR-006: Structured JSON Logging to stdout

**Date:** 2026-06-05  
**Status:** Accepted

**Context:** The current system logs to PostgreSQL synchronously, adding latency to every response and creating a single point of failure.

**Decision:** Structured JSON logs to stdout. API logs written asynchronously as background tasks. Log aggregation handled by the infrastructure layer (CloudWatch, Loki, etc.).

**Rationale:**
- stdout is the 12-factor app standard for logging in containers.
- Decoupling log writes from the request path means a DB slowdown never affects user response time.
- Structured JSON (with `trace_id`, `user_id`, `endpoint`, `duration_ms`) makes logs machine-queryable.

**Consequences:** Developers must check the log aggregator, not the DB, for request logs. The `api_logs` DB table still exists for the searchable audit trail but is written async.

---

# Phase 4 — Engineering Standards

## 4.1 Folder Structure

```
hira_agent/
├── app/
│   ├── main.py                     # FastAPI app factory, lifespan, middleware wiring
│   ├── config.py                   # Pydantic BaseSettings — all env vars declared here
│   ├── dependencies.py             # FastAPI Depends() — db pool, redis, auth user
│   │
│   ├── api/                        # HTTP layer — no business logic here
│   │   └── v1/
│   │       ├── router.py           # Aggregates all v1 routes
│   │       ├── chat.py             # POST /chat
│   │       ├── history.py          # GET /history, POST /clearhistory
│   │       └── profile.py          # GET /profile
│   │
│   ├── middleware/                 # ASGI middleware stack
│   │   ├── auth.py                 # JWT validation
│   │   ├── rate_limit.py           # Redis sliding window
│   │   ├── request_logging.py      # Structured request/response logging
│   │   └── error_handler.py        # Global exception → structured error response
│   │
│   ├── domain/                     # Pure business logic — no I/O imports
│   │   ├── agent/
│   │   │   ├── agent.py            # runAgent orchestrator
│   │   │   ├── prompt.py           # SystemPrompt builder
│   │   │   ├── memory_manager.py   # Memory load/merge/save logic
│   │   │   ├── recall.py           # Token-based history relevance scoring
│   │   │   └── summariser.py       # Conversation summarisation
│   │   ├── intents/
│   │   │   ├── booking/
│   │   │   │   ├── functions.py    # Slot fetching, booking execution
│   │   │   │   ├── tools.py        # Tool definitions + dispatcher
│   │   │   │   └── prompt.py       # Booking prompt section
│   │   │   ├── complaints/
│   │   │   │   ├── handler.py      # detectIssue, preProcessAI
│   │   │   │   └── prompt.py
│   │   │   ├── greeting/
│   │   │   │   └── prompt.py
│   │   │   ├── family/
│   │   │   │   └── prompt.py
│   │   │   └── directory/
│   │   │       └── prompt.py
│   │   └── cards/
│   │       ├── validator.py        # validateCards — allowedActions list
│   │       ├── enricher.py         # enrichPredefinedCards, enrichAmenityCards
│   │       └── resolver.py         # resolveContextCards
│   │
│   ├── services/                   # External I/O wrappers — thin, side-effect-full
│   │   ├── openai_service.py       # OpenAI API calls
│   │   ├── external_api.py         # hcomm.in API calls (booking, complaints)
│   │   └── profile_service.py      # Profile fetch + Redis cache
│   │
│   ├── repositories/               # DB access — SQL only, returns dicts/dataclasses
│   │   ├── memory_repo.py
│   │   ├── message_repo.py
│   │   ├── session_repo.py
│   │   └── log_repo.py
│   │
│   ├── schemas/                    # Pydantic models
│   │   ├── requests.py             # ChatRequest, HistoryRequest, etc.
│   │   ├── responses.py            # ChatResponse, Card, UIHint, etc.
│   │   └── internal.py             # AgentMemory, UserProfile, AgentResult
│   │
│   └── core/                       # Infrastructure plumbing
│       ├── database.py             # asyncpg pool setup, acquire() context manager
│       ├── redis.py                # Redis client setup
│       ├── logger.py               # Structlog / standard JSON logger
│       ├── exceptions.py           # DomainError, ValidationError, InfrastructureError
│       └── constants.py            # Action name constants, memory keys
│
├── tests/
│   ├── unit/                       # Pure unit tests — no DB, no HTTP
│   │   ├── domain/
│   │   └── middleware/
│   ├── integration/                # Tests with real DB (test schema)
│   │   ├── repositories/
│   │   └── api/
│   └── conftest.py                 # Fixtures: test DB, mock OpenAI, mock hcomm.in
│
├── migrations/
│   ├── 001_initial_schema.sql
│   ├── 002_add_memory_columns.sql
│   └── run_migrations.py
│
├── docker/
│   ├── Dockerfile
│   └── docker-compose.yml          # local dev: app + postgres + redis
│
├── .env.example                    # All env vars with descriptions
├── pyproject.toml                  # Dependencies, ruff config, mypy config, pytest config
└── README.md
```

**Rule:** Domain layer (`app/domain/`) must never import from `app/services/`, `app/repositories/`, or `app/core/database`. Domain functions receive data objects as parameters and return data objects. The agent.py orchestrator wires them together.

---

## 4.2 Naming Conventions

| Element | Convention | Example |
|---|---|---|
| Files | `snake_case.py` | `memory_manager.py` |
| Classes | `PascalCase` | `AgentMemory`, `ChatRequest` |
| Functions | `snake_case` | `get_or_create_session()` |
| Constants | `UPPER_SNAKE_CASE` | `ACTION_CLUBS_BOOKING` |
| DB tables | `snake_case`, schema-prefixed | `aiagent.ai_messages` |
| DB columns | `snake_case` | `session_id`, `created_at` |
| Env vars | `UPPER_SNAKE_CASE` | `OPENAI_API_KEY` |
| Redis keys | `resource:identifier` | `profile:HC2526Y61`, `rl:user:HC2526Y61` |
| Routes | `kebab-case` nouns | `/v1/chat`, `/v1/clear-history` |

---

## 4.3 Action Name Constants

All action strings live in `app/core/constants.py`. Never use string literals in business logic.

```python
# app/core/constants.py

class Action:
    MAINTENANCE_ADD = "servicemaintainanceadd"
    CLUBS_BOOKING   = "clubsbooking"
    CLUBS           = "clubs"
    CLUB_DETAILS    = "clubdetails"
    SELECT_UNIT     = "select_unit"
    SELECT_CLUB     = "select_club"
    CANCEL          = "cancel_action"
    UNIT_FAMILY     = "unitfamily"
    NAVIGATE        = "navigate"
    VIEW_COMPLAINT  = "view_complaint"
    CLOSE_COMPLAINT = "close_complaint"
    REOPEN_COMPLAINT = "reopen_complaint"

class MemoryKey:
    CONTEXT      = "context"
    BOOKING      = "booking"
    ISSUE        = "issue"
    FLOW         = "flow"
    LAST_INTENT  = "last_intent"
    LAST_SCREEN  = "last_screen"
    SUMMARY      = "summary"
    SUMMARY_COUNT = "summary_message_count"
```

---

## 4.4 Git & Branching Strategy

- **Strategy:** Trunk-based development with short-lived feature branches
- **Branch naming:** `type/TICKET-short-description`
  - Types: `feat/`, `fix/`, `refactor/`, `docs/`, `chore/`, `test/`
  - Example: `feat/HIRA-42-booking-slots-cache`
- **Commit format:** Conventional Commits
  - `feat(booking): add Redis cache for available slots`
  - `fix(auth): prevent code/JWT mismatch bypass`
  - `chore(deps): bump openai to 1.35.0`
- **PR size:** Max 400 lines changed per PR (excluding migrations and generated files)
- **PR checklist before merge:**
  - [ ] All tests pass (CI green)
  - [ ] No new `mypy` type errors
  - [ ] No new `ruff` lint warnings
  - [ ] Security-sensitive changes have a reviewer comment acknowledging the risk
  - [ ] ENV var additions are documented in `.env.example`
  - [ ] API contract changes are reflected in this design doc

---

## 4.5 Error Handling Standards

### Error Taxonomy

```python
# app/core/exceptions.py

class HiraError(Exception):
    """Base class for all application errors."""
    pass

class DomainError(HiraError):
    """Business rule violation. Safe to surface to client."""
    def __init__(self, message: str, code: str = "DOMAIN_ERROR"):
        self.message = message
        self.code = code

class ValidationError(HiraError):
    """Input validation failure. Always a 400."""
    def __init__(self, message: str, field: str | None = None):
        self.message = message
        self.field = field

class AuthError(HiraError):
    """Authentication failure. Always a 401."""
    pass

class ForbiddenError(HiraError):
    """Authorization failure. Always a 403."""
    pass

class InfrastructureError(HiraError):
    """DB, Redis, or external API failure. Log internally, return 500."""
    pass

class RateLimitError(HiraError):
    """Rate limit exceeded. Always a 429."""
    def __init__(self, retry_after_seconds: int = 60):
        self.retry_after = retry_after_seconds
```

### Global Error Handler Contract

| Exception Type | HTTP Status | Client Sees | Logged |
|---|---|---|---|
| `ValidationError` | 400 | `{code, message, field}` | INFO |
| `AuthError` | 401 | `"Unauthorized"` only | WARN |
| `ForbiddenError` | 403 | `"Forbidden"` only | WARN |
| `RateLimitError` | 429 | Message + Retry-After | INFO |
| `DomainError` | 422 | `{code, message}` | INFO |
| `InfrastructureError` | 500 | `"An unexpected error occurred"` | ERROR with full traceback |
| Any uncaught `Exception` | 500 | `"An unexpected error occurred"` | ERROR with full traceback |

**Rule:** Internal error details (`traceback`, DB errors, API keys, file paths) must **never** appear in the response body. Log them. Return only the generic message.

---

## 4.6 Logging Standards

Every log line must be structured JSON with these fields:

```json
{
  "timestamp": "2026-06-05T10:30:00.123Z",
  "level": "INFO",
  "service": "hira-agent",
  "trace_id": "abc123def456",
  "request_id": "req-uuid-here",
  "user_id": "HC2526Y61",
  "endpoint": "POST /v1/chat",
  "duration_ms": 1234,
  "message": "Chat request completed",
  "context": { ...additional fields... }
}
```

**Log level guide:**
- `DEBUG` — Detailed flow tracing (disabled in production by default)
- `INFO` — Normal operations: request received, AI call made, booking confirmed
- `WARN` — Recoverable issues: cache miss on Redis, rate limit near threshold, external API slow
- `ERROR` — Failures requiring attention: DB query failed, OpenAI returned error, unhandled exception
- `FATAL` — Startup failures: cannot connect to DB, missing required env var

**What must NEVER be logged:**
- Bearer tokens
- Passwords or PINs
- Full credit card numbers
- OpenAI API key
- Raw JWT payloads
- Any field named `password`, `token`, `access_token`, `secret`, `authorization`

---

## 4.7 Testing Standards

### Pyramid Ratios
- **70% Unit tests** — Pure functions: card validation, memory merge, recall scoring, markdown highlighting, intent detection
- **20% Integration tests** — Repository functions against a real test DB (separate schema), API endpoints against real handlers with mocked external services
- **10% E2E / smoke tests** — Full request cycle in staging environment

### Test Naming Convention
```python
# describe [unit] when [condition] it should [outcome]
def test_validate_cards_when_unknown_action_it_should_filter_it_out():
    ...

def test_memory_manager_when_saving_unit_context_it_should_not_overwrite_club_context():
    ...
```

### 100% Branch Coverage Required For:
- `app/middleware/auth.py` — every JWT validation path
- `app/domain/agent/agent.py` — every branch of runAgent
- `app/domain/cards/validator.py` — every allowed/denied action
- `app/repositories/*.py` — every DB operation

### Mocking Strategy
- External HTTP calls (OpenAI, hcomm.in): `respx` mock library — mock at the HTTP transport layer, not the function
- Redis: `fakeredis` — in-memory Redis for tests
- DB: real test PostgreSQL instance (Docker Compose in CI) — never mock the DB

---

# Phase 5 — Code Implementation Standards

## 5.1 Pydantic Schemas (Key Examples)

```python
# app/schemas/requests.py

from pydantic import BaseModel, Field, model_validator
from typing import Any

class SelectionData(BaseModel):
    action: str | None = None
    type: str | None = None
    id: str | None = None
    title: str | None = None
    confirm: bool = False
    autocomplete: dict[str, Any] = Field(default_factory=dict)
    data: dict[str, Any] = Field(default_factory=dict)

class ChatRequest(BaseModel):
    code: str = Field(..., min_length=1, max_length=50, description="Resident identifier")
    message: str = Field(default="", max_length=4000, description="User message")
    selection: SelectionData | dict | str | None = None

    @model_validator(mode="after")
    def validate_code_not_empty(self) -> "ChatRequest":
        if not self.code.strip():
            raise ValueError("code cannot be blank")
        return self


# app/schemas/responses.py

from pydantic import BaseModel
from typing import Any

class Card(BaseModel):
    id: str | None = None
    title: str
    action: str
    icon: str | None = None
    button: dict[str, str] | None = None
    data: dict[str, Any] = Field(default_factory=dict)

class UIHint(BaseModel):
    component: str | None = None
    data: dict[str, Any] = Field(default_factory=dict)

class AgentResponse(BaseModel):
    type: str = "response"
    message: str | dict = ""
    cards: list[Card] = Field(default_factory=list)
    ui: UIHint | dict = Field(default_factory=dict)
    suggestions: list[Card] | None = None

class APIResponse(BaseModel):
    success: bool
    data: Any = None
    error: dict | None = None
```

## 5.2 Repository Pattern (Memory Example)

```python
# app/repositories/memory_repo.py

import json
from app.core.database import Database
from app.schemas.internal import AgentMemory

class MemoryRepository:
    def __init__(self, db: Database):
        self._db = db

    async def get(self, code: str) -> AgentMemory:
        async with self._db.acquire() as conn:
            row = await conn.fetchrow(
                "SELECT context, booking, issue, flow, last_intent, last_screen, "
                "summary, summary_message_count FROM aiagent.ai_memory WHERE code = $1",
                code
            )
        if not row:
            return AgentMemory()
        return AgentMemory(
            context=json.loads(row["context"] or "{}"),
            booking=json.loads(row["booking"] or "{}"),
            issue=json.loads(row["issue"] or "{}"),
            flow=json.loads(row["flow"] or "{}"),
            last_intent=row["last_intent"],
            last_screen=row["last_screen"],
            summary=row["summary"],
            summary_message_count=row["summary_message_count"] or 0,
        )

    async def save(self, code: str, memory: AgentMemory) -> None:
        """
        Full replace — caller is responsible for merging before saving.
        This avoids the JSONB || partial-merge bug from the PHP implementation.
        """
        async with self._db.acquire() as conn:
            await conn.execute(
                """
                INSERT INTO aiagent.ai_memory
                    (code, context, booking, issue, flow, last_intent, last_screen,
                     summary, summary_message_count, updated_at)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, NOW())
                ON CONFLICT (code) DO UPDATE SET
                    context              = EXCLUDED.context,
                    booking              = EXCLUDED.booking,
                    issue                = EXCLUDED.issue,
                    flow                 = EXCLUDED.flow,
                    last_intent          = EXCLUDED.last_intent,
                    last_screen          = EXCLUDED.last_screen,
                    summary              = EXCLUDED.summary,
                    summary_message_count = EXCLUDED.summary_message_count,
                    updated_at           = NOW()
                """,
                code,
                json.dumps(memory.context),
                json.dumps(memory.booking),
                json.dumps(memory.issue),
                json.dumps(memory.flow),
                memory.last_intent,
                memory.last_screen,
                memory.summary,
                memory.summary_message_count,
            )

    async def reset(self, code: str) -> None:
        await self.save(code, AgentMemory())
```

## 5.3 Auth Middleware

```python
# app/middleware/auth.py

import time
import httpx
import jwt
from fastapi import Request
from fastapi.responses import JSONResponse
from starlette.middleware.base import BaseHTTPMiddleware
from app.config import settings
from app.core.logger import get_logger

logger = get_logger(__name__)

SKIP_AUTH_PATHS = {"/health", "/metrics"}

class AuthMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request: Request, call_next):
        if request.url.path in SKIP_AUTH_PATHS:
            return await call_next(request)

        token = self._extract_bearer(request)
        if not token:
            return JSONResponse(status_code=401, content={"success": False, "error": {"code": 401, "message": "Unauthorized"}})

        try:
            payload = await self._validate_jwt(token)
        except jwt.ExpiredSignatureError:
            return JSONResponse(status_code=401, content={"success": False, "error": {"code": 401, "message": "Token expired"}})
        except jwt.InvalidTokenError:
            return JSONResponse(status_code=401, content={"success": False, "error": {"code": 401, "message": "Invalid token"}})

        request.state.user_id = payload.get("sub")
        request.state.token   = token
        return await call_next(request)

    def _extract_bearer(self, request: Request) -> str | None:
        auth = request.headers.get("Authorization", "")
        if auth.lower().startswith("bearer "):
            return auth[7:]
        return None

    async def _validate_jwt(self, token: str) -> dict:
        # Uses cached JWKS — see ADR-003
        jwks = await self._get_jwks()
        return jwt.decode(token, jwks, algorithms=["RS256"], audience=settings.JWT_AUDIENCE)

    async def _get_jwks(self):
        # Implementation fetches from settings.JWKS_URL and caches in Redis
        ...
```

## 5.4 Rate Limiting Middleware

```python
# app/middleware/rate_limit.py — Sliding window algorithm

import time
from fastapi import Request
from fastapi.responses import JSONResponse
from starlette.middleware.base import BaseHTTPMiddleware
from app.core.redis import get_redis
from app.config import settings

class RateLimitMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request: Request, call_next):
        user_id = getattr(request.state, "user_id", None)
        ip      = request.client.host

        try:
            redis = await get_redis()
            key   = f"rl:user:{user_id}" if user_id else f"rl:ip:{ip}"
            limit = settings.RATE_LIMIT_PER_USER if user_id else settings.RATE_LIMIT_PER_IP
            count = await redis.incr(key)
            if count == 1:
                await redis.expire(key, 60)

            if count > limit:
                ttl = await redis.ttl(key)
                return JSONResponse(
                    status_code=429,
                    headers={"Retry-After": str(ttl)},
                    content={"success": False, "error": {"code": 429, "message": "Rate limit exceeded"}},
                )
        except Exception:
            # Fail open — Redis down should not block users
            pass

        return await call_next(request)
```

## 5.5 Environment Variables

All configuration lives in `app/config.py` (Pydantic BaseSettings). There are **no hardcoded values** in any other file.

```python
# app/config.py

from pydantic_settings import BaseSettings

class Settings(BaseSettings):
    # App
    APP_ENV: str = "dev"
    APP_VERSION: str = "1.0.0"

    # Database
    DB_DSN: str                          # Required — e.g. postgresql://user:pass@host:5432/db
    DB_POOL_MIN: int = 2
    DB_POOL_MAX: int = 10
    DB_TIMEOUT_SECONDS: int = 5

    # Redis
    REDIS_URL: str = "redis://localhost:6379"
    REDIS_TIMEOUT_SECONDS: int = 2

    # OpenAI
    OPENAI_API_KEY: str                  # Required
    OPENAI_MODEL: str = "gpt-4.1"
    OPENAI_TIMEOUT_SECONDS: int = 30
    OPENAI_MAX_TOOL_LOOPS: int = 3

    # External API
    HCOMM_API_BASE: str = "https://app.hcomm.in/api/v1"
    HCOMM_API_TIMEOUT_SECONDS: int = 10

    # Cloudinary
    CLOUDINARY_BASE: str = "https://res.cloudinary.com/hiranandani/image/upload/"

    # Auth
    JWKS_URL: str = "https://app.hcomm.in/.well-known/jwks.json"
    JWT_AUDIENCE: str = "hira-agent"
    JWKS_CACHE_TTL_SECONDS: int = 3600

    # Rate Limiting
    RATE_LIMIT_PER_USER: int = 60    # per minute
    RATE_LIMIT_PER_IP: int = 200     # per minute

    # Caching
    PROFILE_CACHE_TTL_SECONDS: int = 300
    AMENITY_CACHE_TTL_SECONDS: int = 3600

    # Pagination
    HISTORY_DEFAULT_LIMIT: int = 10
    HISTORY_MAX_LIMIT: int = 50

    # CORS
    CORS_ORIGINS: list[str] = ["https://app.hcomm.in"]

    class Config:
        env_file = ".env"
        case_sensitive = True

settings = Settings()
```

---

# Phase 6 — Runbook & Operational Documentation

## 6.1 Service Runbook

### What Is This Service?

HiRa is the AI chat agent backend for Hiranandani society residents. It receives HTTP POST requests from the mobile app, calls OpenAI GPT-4.1 to generate responses, and stores conversation history and memory in PostgreSQL. It also proxies booking, complaint, and service request mutations to the hcomm.in platform API.

### How to Run Locally

```bash
# Prerequisites: Python 3.12, Docker

git clone <repo>
cd hira_agent

# Copy and fill env vars
cp .env.example .env
# Set: DB_DSN, OPENAI_API_KEY, REDIS_URL, HCOMM_API_BASE, JWKS_URL

# Start dependencies
docker-compose up -d postgres redis

# Run DB migrations
python migrations/run_migrations.py

# Install dependencies
pip install -e ".[dev]"

# Run the server
uvicorn app.main:app --reload --port 8000

# Run tests
pytest tests/ -v
pytest tests/unit/ -v --no-header   # unit only
pytest tests/integration/ -v        # integration (requires DB)
```

### How to Deploy

```bash
docker build -t hira-agent:$(git rev-parse --short HEAD) .
docker push registry.example.com/hira-agent:$(git rev-parse --short HEAD)
kubectl set image deployment/hira-agent app=registry.example.com/hira-agent:<tag>
kubectl rollout status deployment/hira-agent
```

### How to Roll Back

```bash
kubectl rollout undo deployment/hira-agent
kubectl rollout status deployment/hira-agent
```

### Where Are the Logs?

- **Local:** `stdout` from the uvicorn process
- **Production:** Log aggregator (CloudWatch / Loki) — filter by `service: hira-agent`
- **API audit logs:** `aiagent.api_logs` table in PostgreSQL (30-day retention)

### Where Are the Dashboards?

- **Health:** `GET https://api.hira.app/health`
- **Metrics:** Grafana → HiRa Service Dashboard
- **Errors:** Grafana → HiRa Error Rate panel

### Critical Alerts and Meanings

| Alert | Meaning | First Action |
|---|---|---|
| `hira_error_rate > 5%` | More than 5% of requests are returning 500 | Check logs for exception type; check DB connectivity |
| `hira_p95_latency > 8s` | AI calls are very slow | Check OpenAI status page; check if tool loop is maxing out |
| `hira_db_pool_exhausted` | All DB connections in use | Check for slow queries; consider increasing `DB_POOL_MAX` |
| `hira_redis_down` | Redis is unreachable | Rate limiting is bypassed (fail open); check Redis cluster health |
| `hira_openai_errors > 10/min` | OpenAI API errors spiking | Check OpenAI status; check API key quota |

---

## 6.2 Environment Setup (.env.example)

```bash
# ── Application ───────────────────────────────────────────────────────────────
APP_ENV=dev                          # dev | staging | prod
APP_VERSION=1.0.0

# ── Database ─────────────────────────────────────────────────────────────────
DB_DSN=postgresql://user:pass@localhost:5432/hira  # REQUIRED
DB_POOL_MIN=2
DB_POOL_MAX=10
DB_TIMEOUT_SECONDS=5

# ── Redis ─────────────────────────────────────────────────────────────────────
REDIS_URL=redis://localhost:6379
REDIS_TIMEOUT_SECONDS=2

# ── OpenAI ────────────────────────────────────────────────────────────────────
OPENAI_API_KEY=sk-...                # REQUIRED — never commit this
OPENAI_MODEL=gpt-4.1
OPENAI_TIMEOUT_SECONDS=30
OPENAI_MAX_TOOL_LOOPS=3

# ── External API (hcomm.in) ───────────────────────────────────────────────────
HCOMM_API_BASE=https://app.hcomm.in/api/v1
HCOMM_API_TIMEOUT_SECONDS=10

# ── Auth ─────────────────────────────────────────────────────────────────────
JWKS_URL=https://app.hcomm.in/.well-known/jwks.json   # OPEN QUESTION OQ-01
JWT_AUDIENCE=hira-agent
JWKS_CACHE_TTL_SECONDS=3600

# ── Rate Limiting ─────────────────────────────────────────────────────────────
RATE_LIMIT_PER_USER=60              # requests per minute per user
RATE_LIMIT_PER_IP=200               # requests per minute per IP

# ── Caching ───────────────────────────────────────────────────────────────────
PROFILE_CACHE_TTL_SECONDS=300
AMENITY_CACHE_TTL_SECONDS=3600

# ── CORS ──────────────────────────────────────────────────────────────────────
CORS_ORIGINS=["https://app.hcomm.in"]

# ── Cloudinary ────────────────────────────────────────────────────────────────
CLOUDINARY_BASE=https://res.cloudinary.com/hiranandani/image/upload/
```

---

## 6.3 Incident Response Checklist

### Declare an Incident When:
- Error rate on `/v1/chat` exceeds 10% for more than 2 minutes
- p95 latency exceeds 10 seconds for more than 5 minutes
- DB primary is unreachable
- Booking API producing confirmed bookings that are missing from hcomm.in

### Immediate Triage Steps

```
1. Check /health endpoint — which component is degraded?
2. Check Grafana error rate dashboard — when did it start?
3. Check recent deployments — was there a deploy in the last 30 minutes?
   → Yes: kubectl rollout undo deployment/hira-agent
4. Check DB connectivity: psql $DB_DSN -c "SELECT 1"
5. Check Redis: redis-cli -u $REDIS_URL PING
6. Check OpenAI status: https://status.openai.com
7. Check hcomm.in API: curl -s https://app.hcomm.in/health
```

### Mitigation Without a Fix
- Feature flag `OPENAI_MAX_TOOL_LOOPS=0` disables tool calls, reducing AI latency
- Set `RATE_LIMIT_PER_USER=10` to reduce load during an incident
- Roll back to the previous known-good image

### Post-Incident Review Template
```
## Incident Report — [Date]

**Duration:** X minutes
**Impact:** Y% of users affected
**Root cause:** [1 sentence]
**Timeline:**
  - HH:MM — Alert fired
  - HH:MM — On-call notified
  - HH:MM — Root cause identified
  - HH:MM — Mitigation applied
  - HH:MM — Resolution confirmed

**What went wrong:**
**Why it wasn't caught earlier:**
**Action items:**
  - [ ] [Owner] [Action] by [Date]
```

---

# Pre-Implementation Sign-Off Checklist

Before the first `.py` file is written, every item below must be confirmed complete.

## Requirements
- [x] All functional requirements documented (10 use cases)
- [x] All non-functional requirements defined with measurable targets
- [x] Assumptions explicitly listed and labelled `[A-01]` through `[A-08]`
- [x] Open questions listed: `OQ-01` (JWT validation method), `OQ-02` (amenity DB migration timing)
- [x] Out-of-scope items explicitly listed

## Architecture Diagrams
- [x] Context diagram (C4 L1) — complete
- [x] Container diagram (C4 L2) — complete
- [x] Component diagram (C4 L3) — complete
- [x] High-level architecture diagram (HLD) — complete
- [x] Data flow diagram (DFD L0 + L1) — complete
- [x] Entity relationship diagram (ERD) — complete with indexes and justifications
- [x] API contracts defined for all 5 endpoints
- [x] Sequence diagrams for 3 critical flows
- [x] State machine for agent memory flow and session lifecycle
- [x] Infrastructure diagram — complete
- [x] CI/CD pipeline diagram — complete
- [x] Failure mode analysis (FMEA) — 11 failure modes documented

## Technical Decisions
- [x] ADR-001: Python + FastAPI — Accepted
- [x] ADR-002: asyncpg over ORM — Accepted
- [x] ADR-003: JWKS JWT validation — Proposed (pending OQ-01)
- [x] ADR-004: Redis for rate limiting and caching — Accepted
- [x] ADR-005: Monolith first — Accepted
- [x] ADR-006: Structured JSON logging to stdout — Accepted

## Engineering Standards
- [x] Folder structure defined with rationale
- [x] Naming conventions defined for all element types
- [x] Action name constants specified (no magic strings)
- [x] Git branching strategy defined
- [x] Error taxonomy defined (5 error types)
- [x] Logging standards defined (required fields, forbidden fields)
- [x] Testing pyramid and coverage requirements defined

## Security
- [x] Auth mechanism designed (JWT + JWKS)
- [x] Rate limiting designed (per-user + per-IP, Redis-backed)
- [x] Input validation via Pydantic on all endpoints
- [x] No secrets in code — all via env vars
- [x] No internal errors leaked to clients
- [x] CORS headers defined
- [x] Audit P0 issues resolved by design (not patched)

## Operations
- [x] Environment variables documented with examples
- [x] Runbook written (local setup, deploy, rollback, escalation)
- [x] Incident response checklist defined
- [x] Health check endpoint specified
- [x] Alert definitions written

## Open Items Before Sprint Start
- [ ] **OQ-01 resolved:** Confirm JWT validation method with hcomm.in team
- [ ] **OQ-02 resolved:** Confirm whether amenities move to DB this sprint
- [ ] **Staging DB provisioned** with `aiagent` schema
- [ ] **Redis provisioned** in staging environment
- [ ] **OpenAI API key** shared securely to staging secrets manager
- [ ] **CORS origin list** confirmed for staging and production

---

*Document produced by: Advik Divekar*  
*Review required from: Advik Divekar*  
*Sign-off required before: First pull request to `main` is merged*
