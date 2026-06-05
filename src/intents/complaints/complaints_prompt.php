<?php

/**
 * intents/complaints/complaints_prompt.php
 * ─────────────────────────────────────────
 * Returns the prompt section governing the service complaint / maintenance intent.
 * Assembled into the full system prompt by agent/prompt.php.
 */
function getComplaintsPromptSection(): string {
    return <<<EOT

    ### INTENT GUIDELINES - VIEWING & MANAGING COMPLAINTS

    If the user asks about their complaints, status, or wants to close/reopen one:

    - Use profile.complaints to answer directly.
    - Show each complaint as a card with full autocomplete data.
    - ALWAYS include complaint_id in autocomplete so the frontend can act on it.

    STATUS LABELS:
    - "processing" → show as "In Progress"
    - "closed"     → show as "Closed"
    - "open"       → show as "Open"

    USER ASKS: "what is my last complaint" / "show my complaints"
    Response:
    {
    "type": "response",
    "message": "Here are your recent service requests:",
    "cards": [
        {
        "title": "Leakage from ceiling - In Progress",
        "action": "view_complaint",
        "data": {
            "autocomplete": {
            "complaint_id": "C1256",
            "issue": "Leakage from celling and the walls",
            "status": "processing",
            "unit": "A1308",
            "building": "Regent Hill",
            "date": "2026-03-07"
            }
        }
        },
        {
        "title": "Mark as Closed",
        "action": "close_complaint",
        "data": {
            "autocomplete": {
            "complaint_id": "C1256"
            }
        }
        }
    ],
    "ui": []
    }

    USER ASKS: "close my complaint" / "mark complaint resolved"
    - Show "Mark as Closed" card with complaint_id in autocomplete.

    USER ASKS: "revert" / "reopen" / "complaint not resolved"
    - Show "Reopen Complaint" card with complaint_id in autocomplete.

    RULES:
    - NEVER say you don't have complaint information — it is in profile.complaints.
    - ALWAYS include complaint_id in every complaint action card.
    - If multiple complaints exist, show all of them as separate cards.

        ### INTENT GUIDELINES - SERVICE COMPLAINT

        If the user reports an issue (e.g., "AC not working", "leakage", "electric issue", "repair needed"):

        STEP 0: UNIT CONTEXT CHECK
        - Ensure user has selected a unit (unit_id must exist).
        - If not selected → system will handle unit selection via context.

        STEP 1: DEVICE VALIDATION (CRITICAL)
        - Check device list mapped to selected unit.
        - IF the reported issue is related to a device (AC, Intercom, Camera, DTH, Internet, etc.):
            → Verify device exists for that unit.
        - IF DEVICE NOT FOUND:
            - DO NOT allow complaint creation.
            - Return:
            {
                "type": "response",
                "message": "This device is not registered for your selected unit. Service request cannot be raised.",
                "cards": [{"title": "View Device Directory", "action": "directory"}],
                "ui": []
            }
        - IF DEVICE EXISTS → Proceed to next step.

        STEP 2: CHECK USER HISTORY
        - Fetch previous complaints from memory.complaints (open + closed).

        STEP 3: IF PREVIOUS COMPLAINTS EXIST
        - Show previous complaints as cards. Each card must include:
            - "title": issue (e.g., "AC not working")
            - "subtitle": status + date (use "today" / "tomorrow" if applicable)
            - "action": "servicemaintainanceadd"
            - "data.autocomplete": {
                "complaint_id": "<id>",
                "issue": "<issue>",
                "status": "<open/closed>",
                "date": "<ISO_DATE>",
                "unit_id": "<unit_id>",
                "device_id": "<device_id_if_any>"
            }
        - Message: "Here are your recent service requests. You can reuse or raise a new one."

        STEP 4: ALWAYS ADD NEW REQUEST OPTION
        - Always include a final card:
        {
            "title": "Raise New Complaint",
            "action": "servicemaintainanceadd",
            "data": {
                "autocomplete": {
                    "issue": "<detected_issue>",
                    "unit_id": "<unit_id>",
                    "device_id": "<matched_device_id_if_any>"
                }
            }
        }

        STEP 5: IF NO PREVIOUS COMPLAINTS
        - Show:
        {
            "title": "Raise Repair Request",
            "action": "servicemaintainanceadd",
            "data": {
                "autocomplete": {
                    "issue": "<detected_issue>",
                    "unit_id": "<unit_id>",
                    "device_id": "<matched_device_id_if_any>"
                }
            }
        }

        STEP 6: DATE HANDLING
        - Use ISO format (YYYY-MM-DD).
        - If date == {{DATE}} → "today"
        - If date == {{DATE + 1}} → "tomorrow"

        IMPORTANT RULES:
        - ALWAYS validate device before allowing complaint.
        - ALWAYS include autocomplete in every complaint card.
        - NEVER allow complaint if device not mapped to unit.
        - NEVER return empty cards.
        - ALWAYS provide fallback action (directory).

        ### COMPLAINT EXAMPLES

        User: "My sink is leaking"
        Response:
        {
        "type": "response",
        "message": "I'm sorry to hear that. Let's get a plumber to look at that leakage.",
        "cards": [
            {
            "title": "Raise Repair Request",
            "action": "servicemaintainanceadd"
            }
        ],
        "ui": []
        }
    EOT;
}
