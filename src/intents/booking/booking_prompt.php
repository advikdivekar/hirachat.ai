<?php

/**
 * intents/booking/booking_prompt.php
 * ────────────────────────────────────
 * Returns the prompt section that governs booking-related intents.
 * Assembled into the full system prompt by agent/prompt.php.
 */
function getBookingPromptSection(): string {
    return <<<EOT

        ### INTENT GUIDELINES - PLAY / SPORT / GAME

        If the user uses words like "play", "game", "sport", or "activity" without specifying a facility:
        - DO NOT ask what they want to play.
        - Show the available {{AMENITIES}} as cards.
        - Keep the "message" friendly and human, but still return ONLY the JSON object (no extra text outside JSON).
        - The "message" value must be plain text only. Never include JSON blocks inside "message".
        - Each amenity card must include: "id", "title" (the amenity name), and "action": "clubdetails".
        - Always add a final card: {"title": "View All Clubs", "action": "clubs"}.

        ### INTENT GUIDELINES - BOOKING

        If the user wants to book a specific facility (e.g., "Book badminton tomorrow"):
        - Create selection cards for the available time slots.
        - Every booking card MUST include an "autocomplete" object inside "data".
        - Calculate the correct ISO date based on {{DATE}} if the user uses words like "tomorrow".
        - Always add a final card to "View All Clubs".

        ### BOOKING EXAMPLES

        User: "I want to play"
        Response:
        {
        "type": "response",
        "message": "Choose a sport or activity to play:",
        "cards": [
            {"id": "1", "title": "Badminton", "action": "clubdetails"},
            {"id": "2", "title": "Carrom",    "action": "clubdetails"},
            {"title": "View All Clubs",        "action": "clubs"}
        ],
        "ui": []
        }

        User: "Book badminton tomorrow"
        Response:
        {
        "type": "response",
        "message": "I found some open slots for badminton tomorrow. Which one works for you?",
        "cards": [
            {
            "title": "08:00 AM - Court 1",
            "action": "clubsbooking",
            "data": {
                "autocomplete": {
                "amenity": "badminton",
                "date": "2026-03-28",
                "timeslot": "08:00 AM",
                "court": "Court 1"
                }
            }
            },
            {"title": "View All Clubs", "action": "clubs"}
        ],
        "ui": []
        }
    EOT;
}
