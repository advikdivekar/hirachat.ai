<?php

/**
 * intents/booking/booking_tools.php
 * ────────────────────────────────────
 * Tool definitions exposed to the AI model, and the dispatcher that
 * routes tool calls to the correct booking function.
 *
 * Adding a new tool:
 *  1. Add its schema to getAgentTools().
 *  2. Add a case in handleTool().
 *  3. Add the business logic function in booking_functions.php.
 */

// ─────────────────────────────────────────────
// TOOL DEFINITIONS  (sent to the AI on every request)
// ─────────────────────────────────────────────

function getAgentTools() {
    return [
        [
            "type"        => "function",
            "name"        => "get_available_slots",
            "description" => "Get available slots. If date is missing, assume tomorrow.",
            "parameters"  => [
                "type"       => "object",
                "properties" => [
                    "amenity" => ["type" => "string"],
                    "date"    => ["type" => "string"]
                ],
                "required" => ["amenity"]
            ]
        ],
        [
            "type"        => "function",
            "name"        => "book_amenity",
            "description" => "Book amenity",
            "parameters"  => [
                "type"       => "object",
                "properties" => [
                    "amenity" => ["type" => "string"],
                    "date"    => ["type" => "string"],
                    "time"    => ["type" => "string"]
                ],
                "required" => ["amenity", "date", "time"]
            ]
        ]
    ];
}

function formatFriendlyDate($date) {
    $inputDate = date('Y-m-d', strtotime($date));
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));

    if ($inputDate === $today) {
        return "Today (" . date('d M', strtotime($date)) . ")";
    }
    if ($inputDate === $tomorrow) {
        return "Tomorrow (" . date('d M', strtotime($date)) . ")";
    }
    return date('d M', strtotime($date));
}

// ─────────────────────────────────────────────
// TOOL DISPATCHER
// ─────────────────────────────────────────────

function handleTool($name, $args) {
    switch ($name) {

        case "get_available_slots":
            $amenity   = $args['amenity']    ?? 'badminton';
            $amenityId = $args['amenity_id'] ?? 1;
            $amenityIcon = $args['icon'] ?? "";
            $amenityDesc = $args['description'] ?? "";
            $date      = $args['date']       ?? date('Y-m-d', strtotime('+1 day'));

            $data = getAvailableSlots([
                "amenity"    => $amenity,
                "amenity_id" => $amenityId,
                "icon" => $amenityIcon,
                "description" => $amenityDesc,
                "date"       => $date
            ]);

            if (empty($data) || empty($data['slots'])) {

                $messages_00 = [
                    "Looks like %s is fully booked on %s. Want to try another time or day?",
                    "No slots available for %s on %s right now. You can check other timings or dates.",
                    "All slots for %s on %s are taken. Try a different time or explore other options.",
                    "Oops, %s is fully booked on %s. Let me help you find another slot.",
                    "Nothing available for %s on %s at the moment. Want to check another day?",
                    "Seems like %s is quite busy on %s. Try a different timing or date.",
                    "No luck for %s on %s 😕 Want me to show availability for another day?",
                    "All booked for %s on %s. You can try again for a different date.",
                    "%s is fully occupied on %s. Let’s find you another slot.",
                    "No available slots for %s on %s. Try exploring other timings or clubs."
                ];

                $msg_00 = $messages_00[array_rand($messages_00)];

                return [
                    "type"    => "response",
                    //"message" => "No slots available for {$amenity} on " . formatFriendlyDate($data['date']),
                    "message" => sprintf($msg_00, $amenity, formatFriendlyDate($data['date'])),
                    "cards"   => [],
                    "ui"      => []
                ];
            }

            $slots = $data['slots'];

            $cards = array_map(function ($slot, $i) use ($data) {
                return [
                    "id"     => (string)($i + 1),
                    "title"  => "{$slot['time']} - {$slot['court']}",
                    "action" => "clubsbooking",
                    "data"   => [
                        "id"    => $data['amenity_id'],
                        "title" => $slot['time'],
                        "description" => $slot['court'],
                        "icon" => $data['icon'],
                        "amenity" => [
                            "id"    => $data['amenity_id'],
                            "title" => $data['amenity'],
                            "description" => $data['description'],
                        ],
                        "autocomplete" => [
                            "amenity_id" => $data['amenity_id'],
                            "amenity"    => $data['amenity'],
                            "date"       => $data['date'],
                            "timeslot"   => $slot['time'],
                            "court"      => $slot['court']
                        ]
                    ]
                ];
            }, $slots, array_keys($slots));

            $messages_01 = [
                "Nice choice 👍 Here are some available slots for %s on %s.",
                "Great! I found some slots for %s on %s.",
                "You're all set to play 🏸 Here are the slots for %s on %s.",
                "Good news! %s is available on %s. Pick a time that works for you.",
                "Here’s what’s open for %s on %s. Go ahead and choose your slot.",
                "Looks like %s has a few openings on %s. Select your preferred time.",
                "Awesome! These %s slots are available on %s.",
                "You're in luck! I found some %s slots on %s. Choose one to continue.",
                "Planning to play %s? Here are the available timings for %s.",
                "Here are your %s booking options for %s. Let me know what suits you."
            ];

            $msg_01 = $messages_01[array_rand($messages_01)];

            return [
                "type"    => "card",
                //"message" => "Available slots for {$data['amenity']} on " . date('d M', strtotime($data['date'])),
                "message" => sprintf($msg_01, $data['amenity'], formatFriendlyDate($data['date'])),
                "cards"   => $cards,
                "ui"      => [
                    "component" => "booking_card"
                ]
            ];


        case "book_amenity":

            if (empty($args['amenity']) || empty($args['date']) || empty($args['time'])) {

                $messages_02 = [
                    "Almost there! Please select a time slot to complete your booking.",
                    "Just one step left. Choose a slot to proceed with your booking.",
                    "Please pick a time slot first so I can confirm your booking.",
                    "I’ll need a selected slot to continue. Go ahead and choose one.",
                    "Looks like a slot wasn’t selected. Please choose a time to proceed.",
                    "Select a time slot and I’ll take care of the booking for you.",
                    "You’re close! Just choose a slot to finish your booking.",
                    "Let’s get this booked. Please select a time slot first.",
                    "Please choose your preferred time slot before confirming the booking.",
                    "Once you select a slot, I can complete your booking right away."
                ];

                $msg_02 = $messages_02[array_rand($messages_02)];

                return [
                    "type"    => "response",
                    //"message" => "Missing booking details. Please select a slot first.",
                    "message" => sprintf($msg_02),
                    "cards"   => [],
                    "ui"      => []
                ];
            }

            return bookAmenity($args);


        default:
            return [
                "type"    => "response",
                "message" => "Unknown action",
                "cards"   => [],
                "ui"      => []
            ];
    }

}
