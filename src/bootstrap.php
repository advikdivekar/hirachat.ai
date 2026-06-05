<?php

/**
 * bootstrap.php
 * -------------
 * Single entry point. Include this file anywhere you need the AI agent.
 * To add a new intent: create its folder under intents/, then require it here.
 */

require_once __DIR__ . '/config.php';

// Core infrastructure
require_once __DIR__ . '/core/memory.php';
require_once __DIR__ . '/core/logger.php';
require_once __DIR__ . '/core/response.php';
require_once __DIR__ . '/core/cache.php';

require_once __DIR__ . '/shared/api.php';

// Shared utilities
require_once __DIR__ . '/shared/profile.php';

// Centralized card management
require_once __DIR__ . '/cards/cards.php';

// ── Intents ────────────────────────────────────────────────────────────────
require_once __DIR__ . '/intents/greeting/greeting_prompt.php';

require_once __DIR__ . '/intents/booking/booking_functions.php';
require_once __DIR__ . '/intents/booking/booking_tools.php';
require_once __DIR__ . '/intents/booking/booking_prompt.php';

require_once __DIR__ . '/intents/complaints/complaints_handler.php';
require_once __DIR__ . '/intents/complaints/complaints_prompt.php';

require_once __DIR__ . '/intents/family/family_prompt.php';

require_once __DIR__ . '/intents/directory/directory_prompt.php';

// Agent (must load after all intents)
require_once __DIR__ . '/agent/prompt.php';
require_once __DIR__ . '/agent/agent.php';


/* 
## Full request/response flow
```
// User clicks booking slot card → frontend sends:
{
    "code": "HC2526Y61",
    "message": "book this slot",
    "selection": {
        "action": "clubsbooking",
        "autocomplete": {
            "amenity": "Badminton",
            "amenity_id": 1,
            "date": "2026-04-01",
            "timeslot": "08:00 AM",
            "court": "Court 1"
        }
    }
}

// API success → agent returns:
{
    "message": "✅ Booking confirmed! Badminton on 2026-04-01 at 08:00 AM.",
    "cards": [
        { "title": "View My Bookings", "action": "clubs" },
        { "title": "Book Another",     "action": "clubs" }
    ],
    "ui": { "component": "success_card" }
}

// API fail → agent returns:
{
    "message": "⚠️ Something went wrong: Slot no longer available.",
    "cards": [
        { "title": "Try Again",      "action": "clubsbooking" },
        { "title": "View All Clubs", "action": "clubs" }
    ],
    "ui": { "component": "error_card" }
}

*/