<?php

/**
 * cards/cards.php
 * ───────────────
 * Single source of truth for all UI cards.
 *
 * Sections:
 *  1. Predefined card registry  – static definitions keyed by action name
 *  2. Validation                – strip unknown actions before returning to client
 *  3. Enrichment – Predefined   – attach icon / title / data from registry
 *  4. Enrichment – Amenity      – attach amenity data to clubdetails cards
 *  5. Context resolution        – swap cards for unit/club selection when needed
 *
 * Adding a new card type:
 *  → Add its action to $allowedActions in validateCards()
 *  → Optionally add a preset in getPredefinedCards()
 */

// ─────────────────────────────────────────────
// 1. PREDEFINED CARD REGISTRY
// ─────────────────────────────────────────────

function getPredefinedCards() {
    return [

        "servicemaintainanceadd" => [
            "title" => "Raise Repair Request",
            "icon"  => "images/icons/icon-menu-repair",
            "action" => "navigate",
            "button" => ["title" => "Services & Maintainance", "action" => "servicemaintainance"]
        ],
        "unitfamily" => [
            "title" => "Add Family Member",
            "icon"  => "images/icons/icon-menu-family",
            "action" => "navigate",
            "button" => ["title" => "Family & Tenants", "action" => "unitfamily"]
        ],
        "clubsbooking" => [
            "title" => "Book Amenity",
            "icon"  => "images/icons/icon-menu-sports",
            "action" => "navigate",
            "button" => ["title" => "Clubs", "action" => "clubs"]
        ],
        "clubs" => [
            "title" => "View All Clubs",
            "icon"  => "images/icons/icon-menu-sports",
            "action" => "navigate",
            "button" => ["title" => "Clubs", "action" => "clubs"]
        ],
        "clubdetails" => [
            "title" => "Club Details",
            "icon"  => "img/icon-club-details.svg",
            "action" => "navigate",
            "button" => ["title" => "Clubs", "action" => "clubs"]
        ]
    ];
}

// ─────────────────────────────────────────────
// 2. VALIDATION
// ─────────────────────────────────────────────

function validateCards($cards) {
    if (!is_array($cards)) {
        return [];
    }

    $allowedActions = [
        "navigate",
        "unitfamily",
        "servicemaintainanceadd",
        "clubs",
        "clubdetails",
        "clubsbooking",
        "select_unit",
        "select_club",
        "cancel_action",
        "view_complaint",     // ✅ add
        "close_complaint",    // ✅ add
        "reopen_complaint",   // ✅ add
    ];

    return array_values(array_filter($cards, function ($card) use ($allowedActions) {
        return isset($card['action']) && in_array($card['action'], $allowedActions, true);
    }));
}

// ─────────────────────────────────────────────
// 3. ENRICHMENT – PREDEFINED
// ─────────────────────────────────────────────

function enrichPredefinedCards($cards) {
    $registry = getPredefinedCards();
    if (!is_array($cards)) return [];

    foreach ($cards as &$card) {
        $actionKey = $card['action'] ?? null;

        if ($actionKey && isset($registry[$actionKey])) {
            $preset = $registry[$actionKey];

            if (empty($card['title'])) {
                $card['title'] = $preset['title'];
            }

            $card['icon'] = $preset['icon'] ?? null;
            $card['button'] = $preset['button'] ?? null;

            $card['data'] = array_merge(
                $preset['data'] ?? [],
                $card['data'] ?? []
            );

            if (($preset['action'] ?? '') === 'navigate') {
                $auto = $card['data']['autocomplete'] ?? null;
                $hasAutocomplete = is_array($auto) && !empty($auto);

                $hasEntity =
                    !empty($card['id']) ||
                    !empty($card['data']['unit_id']) ||
                    !empty($card['data']['club_id']) ||
                    !empty($card['data']['amenity_id']);

                if (!$hasAutocomplete && !$hasEntity) {
                    $card['action'] = 'navigate';
                }
            }
        }
    }

    return $cards;
}

// ─────────────────────────────────────────────
// 4. ENRICHMENT – AMENITY
// ─────────────────────────────────────────────

function enrichAmenityCards($cards) {
    $amenities = getAmenities();
    if (!is_array($cards)) return [];

    foreach ($cards as &$card) {
        if (($card['action'] ?? '') === 'clubdetails' && isset($card['id'])) {
            foreach ($amenities as $a) {
                if ((string)$a['id'] === (string)$card['id']) {
                    $card['data'] = [
                        "amenity_id" => $a['id'],
                        "icon"       => $a['icon'],
                        "title"      => $a['title'],
                        "description" => $a['description'],
                    ];
                    break;
                }
            }
        }
    }

    return $cards;
}

// ─────────────────────────────────────────────
// 5. CONTEXT RESOLUTION
// ─────────────────────────────────────────────

/**
 * Maps an action name to the context type it requires.
 * Used by resolveContextCards to decide when to intercept.
 */
function getActionContext($action) {
    $map = [
        "servicemaintainanceadd" => "unit",
        "clubsbooking"           => "club",
        "clubdetails"            => "club",
        "clubs"                  => null,
        "unitfamily"             => null,
    ];

    return $map[$action] ?? null;
}

/**
 * Intercepts the AI's card list and replaces it with unit/club selection
 * cards when the required context has not yet been established.
 */
function resolveContextCards($cards, $profile, $memory = [], &$message = null) {

    if (!is_array($cards) || empty($cards)) return $cards;

    $hasClubSelected = !empty($memory['context']['club_id']);
    $hasUnitSelected = !empty($memory['context']['unit_id']);

    $cardsContainSlotSelection       = false;
    $cardsAlreadyAskForClubSelection = false;

    foreach ($cards as $c) {
        if (($c['action'] ?? null) === 'select_club') {
            $cardsAlreadyAskForClubSelection = true;
        }
        $auto = $c['data']['autocomplete'] ?? null;
        if (is_array($auto) && (!empty($auto['timeslot']) || !empty($auto['time']) || !empty($auto['court']))) {
            $cardsContainSlotSelection = true;
        }
    }

    // ── PASS 1: UNIT CONTEXT (highest priority) ────────────────
    // Check unit-requiring cards first regardless of their position
    // in the array. Complaints must never fall through to club selection.
    if (!$hasUnitSelected) {
        foreach ($cards as $card) {
            $action = $card['action'] ?? null;
            if (!$action) continue;
            if (getActionContext($action) !== 'unit') continue;

            // Unit is needed and not selected → show unit selection
            if (!empty($profile['units']) && count($profile['units']) > 1) {
                if (is_string($message)) {
                    $message = ($action === 'servicemaintainanceadd')
                        ? "Select your unit to raise a maintenance request."
                        : "Select your unit to continue.";
                }
                return array_map(function ($unit) {
                    return [
                        "title"  => $unit['title'],
                        "action" => "select_unit",
                        "data"   => [
                            "type"    => "unit",
                            "unit_id" => $unit['id'],
                            "id"      => $unit['id'],
                            "title"   => $unit['title'],
                            "description"   => $unit['description'],
                            "icon"   => $unit['icon'],
                        ]
                    ];
                }, $profile['units']);
            }

            // Only one unit → auto-select silently, keep original cards
            break;
        }
    }

    // ── PASS 2: CLUB CONTEXT (only if no unit need was found) ──
    if (!$hasClubSelected && !$cardsContainSlotSelection && !$cardsAlreadyAskForClubSelection) {
        foreach ($cards as $card) {
            $action = $card['action'] ?? null;
            if (!$action) continue;
            if (getActionContext($action) !== 'club') continue;

            if (!empty($profile['clubs']) && count($profile['clubs']) > 1) {
                if (is_string($message)) {
                    $message = "Select your club to continue.";
                }
                return array_map(function ($club) {
                    return [
                        "title"  => $club['title'],
                        "action" => "select_club",
                        "data"   => [
                            "type"    => "club",
                            "club_id" => $club['id'],
                            "id"      => $club['id'],
                            "title"   => $club['title']
                        ]
                    ];
                }, $profile['clubs']);
            }

            break;
        }
    }

    /// FIX: Enrich any AI-generated select_club cards that have empty data
    // (AI can output select_club directly, but doesn't populate the data payload)
    if (!empty($profile['clubs'])) {
        $clubMap = [];
        foreach ($profile['clubs'] as $club) {
            $clubMap[(string)$club['id']] = $club;
        }
        foreach ($cards as &$card) {
            if (($card['action'] ?? '') === 'select_club' && empty($card['data'])) {
                $cardId = (string)($card['id'] ?? '');
                if ($cardId && isset($clubMap[$cardId])) {
                    $c = $clubMap[$cardId];
                    $card['data'] = [
                        'type'    => 'club',
                        'club_id' => $c['id'],
                        'id'      => $c['id'],
                        'title'   => $c['title'],
                    ];
                }
            }
        }
        unset($card);
    }
    return $cards;
}
