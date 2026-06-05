<?php

/**
 * intents/complaints/complaints_handler.php
 * ──────────────────────────────────────────
 * Handles service complaint / maintenance-request intent.
 *
 * Functions:
 *  - detectIssue()    – keyword-match a user message against registered devices
 *  - preProcessAI()   – intercepts the agent loop for multi-step maintenance flow
 */

// ─────────────────────────────────────────────
// ISSUE DETECTION
// ─────────────────────────────────────────────

function detectIssue($message, $devices = []) {
    $msg = strtolower($message);

    foreach ($devices as $device) {

        foreach (($device['keywords'] ?? []) as $keyword) {
            if (str_contains($msg, strtolower($keyword))) {
                return [
                    "title"       => $message,
                    "device_id"   => $device['id'],
                    "device_name" => $device['name'],
                    "type"        => $device['type'],
                    "category"    => $device['category']
                ];
            }
        }

        if (str_contains($msg, strtolower($device['name']))) {
            return [
                "title"       => $message,
                "device_id"   => $device['id'],
                "device_name" => $device['name'],
                "type"        => $device['type'],
                "category"    => $device['category']
            ];
        }
    }

    return null;
}

// ─────────────────────────────────────────────
// PRE-PROCESS (MAINTENANCE FLOW)
// ─────────────────────────────────────────────

/**
 * Intercepts the agent before calling the AI model.
 * Handles multi-step maintenance confirmation flow entirely in PHP,
 * returning a structured response array when a step is matched,
 * or null to let the AI handle the message normally.
 */
function preProcessAI($code, $selection, $memory) {

    // Normalize selection
    if (is_string($selection)) {
        $decoded = json_decode($selection, true);
        $selection = (json_last_error() === JSON_ERROR_NONE) ? $decoded : [];
    }

    if (!is_array($selection)) {
        $selection = [];
    }

    if (isset($selection['data']) && is_array($selection['data'])) {
        $selection = array_merge($selection, $selection['data']);
    }

    if (!empty($selection['unit_id'])) {
        $selection['type']  = $selection['type']  ?? 'unit';
        $selection['id']    = $selection['id']    ?? $selection['unit_id'];
        $selection['title'] = $selection['title'] ?? $selection['unit_id'];
    }

    if (!empty($selection['club_id'])) {
        $selection['type']  = $selection['type']  ?? 'club';
        $selection['id']    = $selection['id']    ?? $selection['club_id'];
        $selection['title'] = $selection['title'] ?? ($selection['club_name'] ?? $selection['club_id']);
    }

    // CANCEL
    if (($selection['action'] ?? '') === 'cancel_action') {
        resetMemory($code);
        rotateSession($code);

        return [
            "type"    => "response",
            "message" => "Cancelled. Starting a new session.",
            "cards"   => [],
            "ui"      => []
        ];
    }

    // UNIT SELECTED while maintenance intent is active
    if (
        ($selection['type'] ?? '') === 'unit' &&
        (!empty($memory['issue']) || ($memory['last_intent'] ?? '') === 'servicemaintainanceadd')
    ) {
        $unit  = $memory['context']['unit_id'] ?? ($selection['unit_id'] ?? ($selection['id'] ?? ''));
        $issue = $memory['issue']['title'] ?? 'this issue';

        if (empty($unit)) {
            $profile = getUserProfile($code);
            $units   = $profile['units'] ?? [];
            if (is_array($units) && count($units) > 1) {
                return [
                    "type"    => "response",
                    "message" => "Select your unit to raise a maintenance request.",
                    "cards"   => _buildUnitSelectionCards($units),
                    "ui"      => []
                ];
            }
        }

        return _buildMaintenanceConfirmationResponse($unit, $issue);
    }

    // CONFIRM CLICK
    if (!empty($selection['confirm'])) {
        saveMemory($code, ["flow" => null]);

        return [
            "type"    => "response",
            "message" => "Your request has been submitted successfully.",
            "cards"   => [],
            "ui"      => []
        ];
    }

    // MAINTENANCE FORM STEP
    if (($memory['flow']['step'] ?? '') === 'maintenance_form') {
        return [
            "type"    => "response",
            "message" => "Ready to raise your request",
            "cards"   => [],
            "ui"      => [
                "component" => "maintenance_form",
                "data"      => [
                    "unit_id"  => $memory['context']['unit_id'],
                    "issue"    => $memory['issue']['title'] ?? '',
                    "category" => $memory['issue']['category'] ?? 'General',
                    "priority" => "Medium"
                ]
            ]
        ];
    }

    // MAINTENANCE CONFIRMATION STEP
    if (($memory['flow']['step'] ?? '') === 'maintenance_confirm') {
        $unit  = $memory['context']['unit_id'] ?? '';
        $issue = $memory['issue']['title'] ?? 'this issue';

        if (empty($unit)) {
            $profile = getUserProfile($code);
            $units   = $profile['units'] ?? [];
            if (is_array($units) && count($units) > 1) {
                return [
                    "type"    => "response",
                    "message" => "Select your unit to raise a maintenance request.",
                    "cards"   => _buildUnitSelectionCards($units),
                    "ui"      => []
                ];
            }
        }

        return _buildMaintenanceConfirmationResponse($unit, $issue);
    }

    return null;
}

// ─────────────────────────────────────────────
// PRIVATE HELPERS
// ─────────────────────────────────────────────

function _buildUnitSelectionCards(array $units): array {
    return array_map(function ($u) {
        return [
            "title"  => $u['title'],
            "action" => "select_unit",
            "data"   => [
                "type"    => "unit",
                "unit_id" => $u['id'],
                "id"      => $u['id'],
                "title"   => $u['title'],
                "description" => $u['description'],
                "icon" => $u['icon'],
            ]
        ];
    }, $units);
}

function _buildMaintenanceConfirmationResponse(string $unit, string $issue): array {
    return [
        "type"    => "response",
        "message" => "Do you want to raise a repair request for {$issue} for unit {$unit}?",
        "cards"   => [
            [
                "title"  => "Yes, Raise Request",
                "action" => "servicemaintainanceadd",
                "data"   => [
                    "unit_id" => $unit,
                    "issue"   => $issue,
                    "confirm" => true
                ]
            ],
            [
                "title"  => "Cancel",
                "action" => "cancel_action"
            ]
        ],
        "ui" => [
            "component" => "confirmation_card"
        ]
    ];
}
