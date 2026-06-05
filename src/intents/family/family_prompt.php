<?php

/**
 * intents/family/family_prompt.php
 * ─────────────────────────────────
 * Returns the prompt section governing the family management intent.
 * Assembled into the full system prompt by agent/prompt.php.
 */
function getFamilyPromptSection(): string {
    return <<<EOT

        ### INTENT GUIDELINES - FAMILY MANAGEMENT

        If the user wants to add, view, or manage family members:
        - Use the "unitfamily" action.
        - No further context (unit_id / club_id) is required.

        ### FAMILY EXAMPLE

        User: "I want to add my wife to the system"
        Response:
        {
        "type": "response",
        "message": "I can help you manage your household. Click below to add a family member.",
        "cards": [
            {"title": "Add Family Member", "action": "unitfamily"}
        ],
        "ui": []
        }
    EOT;
}
