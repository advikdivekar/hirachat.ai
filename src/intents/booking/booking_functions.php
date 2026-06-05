<?php

/**
 * intents/booking/booking_functions.php
 * ──────────────────────────────────────
 * Data layer for the booking intent.
 * Contains: amenity catalogue, slot fetching, booking execution, and helpers.
 */

// ─────────────────────────────────────────────
// AMENITY CATALOGUE
// ─────────────────────────────────────────────

function getAmenities(): array {
    return [
        [
            "id"          => 1,
            "title"       => "Badminton",
            "description" => "Forest Club",
            "icon"        => "https://res.cloudinary.com/hiranandani/image/upload/v1773380992/images/icons/icon-sports-badminton.webp"
        ],
        [
            "id"          => 2,
            "title"       => "Gym",
            "description" => "Eden Club",
            "icon"        => "https://res.cloudinary.com/hiranandani/image/upload/v1773380992/images/icons/icon-sports-gym.webp"
        ],
        [
            "id"          => 3,
            "title"       => "Carrom",
            "description" => "Forest Club",
            "icon"        => "https://res.cloudinary.com/hiranandani/image/upload/v1773380992/images/icons/icon-sports-carrom.webp"
        ],
        [
            "id"          => 4,
            "title"       => "Squash",
            "description" => "Available",
            "icon"        => "https://res.cloudinary.com/hiranandani/image/upload/v1773380992/images/icons/icon-sports-squash.webp"
        ]
    ];
}

/** Static cache wrapper – avoids re-fetching amenities on every AI loop iteration. */
function getAmenitiesCached() {
    static $data = null;
    if ($data === null) {
        $data = getAmenities();
    }
    return $data;
}

// ─────────────────────────────────────────────
// AMENITY DETECTION
// ─────────────────────────────────────────────

function findAmenity($message, $amenities) {
    $msg = strtolower($message);
    foreach ($amenities as $amenity) {
        if (str_contains($msg, strtolower($amenity['title']))) {
            return $amenity;
        }
    }
    return null;
}

// ─────────────────────────────────────────────
// SLOT FETCHING  (replace with real DB query in production)
// ─────────────────────────────────────────────

function getAvailableSlots($args) {
    return [
        "amenity"    => $args['amenity'],
        "amenity_id" => $args['amenity_id'],
        "icon" => $args['icon'],
        "description" => $args['description'],
        "date"       => $args['date'],
        "slots"      => [
            ["time" => "08:00AM", "court" => "Court 1"],
            ["time" => "09:00AM", "court" => "Court 2"],
            ["time" => "10:00AM", "court" => "Court 1"]
        ]
    ];
}

// ─────────────────────────────────────────────
// BOOKING EXECUTION  (replace with real DB insert in production)
// ─────────────────────────────────────────────

function bookAmenity($args) {
    return [
        "success" => true,
        "message" => "Booked {$args['amenity']} on {$args['date']} at {$args['time']}"
    ];
}
