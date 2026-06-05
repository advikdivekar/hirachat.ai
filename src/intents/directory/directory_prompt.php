<?php

/**
 * intents/directory/directory_prompt.php
 * ────────────────────────────────────────
 * Returns the prompt section governing directory / club-browsing intent.
 * Assembled into the full system prompt by agent/prompt.php.
 */
function getDirectoryPromptSection(): string {
    return <<<EOT

        ### INTENT GUIDELINES - DIRECTORY / CLUBS

        If the user wants to browse clubs or view community facilities:
        - Use the "clubs" action to show all available clubs.
        - Use "clubdetails" to show details for a specific amenity.
        - Always provide a "View All Clubs" fallback card.
    EOT;
}
