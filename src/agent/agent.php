<?php

/**
 * agent/agent.php
 * ────────────────
 * Orchestrates a full agent turn:
 *
 *  runAgent()
 *    → session & memory setup
 *    → selection normalisation
 *    → pre-process (PHP-side flow steps)
 *    → buildConversation()
 *    → processAI()        (with tool loop)
 *    → card pipeline      (validate → resolve context → enrich)
 *    → persist messages & memory
 */

// ─────────────────────────────────────────────
// CARD VALIDATION
// ─────────────────────────────────────────────
// validateCards() lives in cards/cards.php (already required via bootstrap).

// ─────────────────────────────────────────────
// RECALL UTILITIES  (token-based history search)
// ─────────────────────────────────────────────

function tokenizeForRecall($text) {
    $text  = strtolower((string)$text);
    $parts = preg_split('/[^a-z0-9_]+/i', $text);
    if (!is_array($parts)) {
        return [];
    }

    $stop = array_fill_keys([
        'a','an','the','and','or','but','if','then','else','so','to','of','in','on','at','by','for','from','with','as',
        'is','am','are','was','were','be','been','being','do','does','did','done','have','has','had','having',
        'i','me','my','mine','you','your','yours','we','our','ours','they','their','theirs','he','him','his','she','her','hers',
        'it','its','this','that','these','those','there','here','what','which','who','whom','when','where','why','how',
        'can','could','should','would','will','may','might','must','please','pls','ok','okay','hi','hello','hey'
    ], true);

    $tokens = [];
    foreach ($parts as $p) {
        $t = trim((string)$p);
        if ($t === '' || isset($stop[$t])) continue;
        if (strlen($t) <= 2) continue;
        $tokens[$t] = true;
    }

    return array_keys($tokens);
}

function recallScore($queryTokens, $textTokens) {
    if (empty($queryTokens) || empty($textTokens)) {
        return 0;
    }

    $q     = array_fill_keys($queryTokens, true);
    $score = 0;
    foreach ($textTokens as $t) {
        if (isset($q[$t])) $score++;
    }

    return $score;
}

function buildRelevantHistorySnippet($sessionId, $userMessage, $alreadyIncludedCount = 12, $window = 80, $maxItems = 6) {
    $all = getMessages($sessionId, $window);
    if (!is_array($all) || empty($all)) return '';

    $baseCount  = count($all);
    $cut        = max(0, $baseCount - (int)$alreadyIncludedCount);
    $candidates = $cut > 0 ? array_slice($all, 0, $cut) : [];
    if (empty($candidates)) return '';

    $qTokens = tokenizeForRecall($userMessage);
    if (empty($qTokens)) return '';

    $scored = [];
    foreach ($candidates as $msg) {
        $role  = (string)($msg['role']    ?? '');
        $text  = trim((string)($msg['message'] ?? ''));
        if ($role === '' || $text === '') continue;

        $tTokens = tokenizeForRecall($text);
        $score   = recallScore($qTokens, $tTokens);
        if ($score <= 0) continue;

        $scored[] = ['score' => $score, 'role' => $role, 'message' => $text];
    }

    if (empty($scored)) return '';

    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

    $top   = array_slice($scored, 0, $maxItems);
    $lines = [];
    foreach ($top as $item) {
        $lines[] = strtoupper((string)($item['role'] ?? '')) . ': ' . (string)($item['message'] ?? '');
    }

    return implode("\n", $lines);
}

// ─────────────────────────────────────────────
// CONVERSATION BUILDER
// ─────────────────────────────────────────────

function buildConversation($sessionId, $userMessage, $memory, $selection, $profile = []) {
    $summary = '';
    if (isset($memory['summary']) && is_string($memory['summary'])) {
        $summary = trim($memory['summary']);
    }

    $allowedMemoryKeys = ['context', 'booking', 'last_intent', 'flow', 'issue', 'last_screen'];
    $memory = array_intersect_key($memory, array_flip($allowedMemoryKeys));

    $historyCount = 12;
    $history      = getMessages($sessionId, $historyCount);
    $relevant     = buildRelevantHistorySnippet($sessionId, $userMessage, $historyCount, 80, 6);

    $conversation = [
        ["role" => "system", "content" => (new SystemPrompt())->getPrompt()]
    ];

    if ($summary !== '') {
        $conversation[] = ["role" => "system", "content" => "Long-term conversation summary: " . $summary];
    }

    if (!empty($memory)) {
        $conversation[] = ["role" => "system", "content" => "User memory: " . json_encode($memory)];
    }

    if (!empty($profile) && is_array($profile)) {
        $profileContext = array_filter([
            "name"       => $profile['name']       ?? null,
            "units"      => $profile['units']       ?? [],
            "clubs"      => $profile['clubs']       ?? [],
            "complaints" => $profile['complaints']  ?? [],
        ], fn($v) => !empty($v));

        if (!empty($profileContext)) {
            $conversation[] = [
                "role"    => "system",
                "content" => "User profile: " . json_encode($profileContext)
            ];
        }
    }
    // ──────────────────────────────────────────────────────────


    if ($relevant !== '') {
        $conversation[] = ["role" => "system", "content" => "Relevant past messages:\n" . $relevant];
    }

    foreach ($history as $msg) {
        $conversation[] = ["role" => $msg['role'], "content" => $msg['message']];
    }

    if (!empty($selection)) {
        $conversation[] = ["role" => "system", "content" => "User selected: " . json_encode($selection)];
    }

    $conversation[] = ["role" => "user", "content" => $userMessage];

    return $conversation;
}

// ─────────────────────────────────────────────
// CONVERSATION SUMMARISER
// ─────────────────────────────────────────────

function updateConversationSummaryIfNeeded($code, $sessionId, $client, $memory) {
    $totalMessages       = getMessageCount($sessionId);
    $lastSummarizedCount = (int)($memory['summary_message_count'] ?? 0);

    if ($totalMessages < 16 || ($totalMessages - $lastSummarizedCount) < 12) {
        return $memory;
    }

    $recent = getMessages($sessionId, 24);
    $lines  = [];
    foreach ($recent as $msg) {
        $role = strtoupper((string)($msg['role']    ?? ''));
        $text = trim((string)($msg['message'] ?? ''));
        if ($role === '' || $text === '') continue;
        $lines[] = $role . ': ' . $text;
    }

    $transcript = implode("\n", $lines);
    if ($transcript === '') return $memory;

    $previousSummary = '';
    if (isset($memory['summary']) && is_string($memory['summary'])) {
        $previousSummary = trim($memory['summary']);
    }

    $summaryInput = [
        [
            "role"    => "system",
            "content" => "Create a compact long-term memory summary of the conversation for a future assistant turn. Keep only stable facts, preferences, selected unit/club, booking details, and unresolved tasks. Do not include secrets or personally sensitive information. Output plain text only (no JSON). Max 900 characters."
        ],
        [
            "role"    => "user",
            "content" => "Previous summary:\n" . ($previousSummary === '' ? "(none)" : $previousSummary) . "\n\nRecent chat:\n" . $transcript . "\n\nWrite the updated summary:"
        ]
    ];

    $response = $client->responses()->create([
        "model" => "gpt-4.1",
        "input" => $summaryInput
    ]);

    $text = '';
    foreach ($response->output as $item) {
        if ($item->type !== "message") continue;
        foreach ($item->content as $content) {
            if ($content->type === "output_text") {
                $text .= $content->text;
            }
        }
    }

    $newSummary = trim($text);
    if ($newSummary === '') return $memory;

    $update = ["summary" => $newSummary, "summary_message_count" => $totalMessages];
    saveMemory($code, $update);

    return mergeMemory($memory, $update);
}

// ─────────────────────────────────────────────
// AI PROCESSOR  (tool call loop)
// ─────────────────────────────────────────────

function processAI($conversation, $userMessage, $memory = []) {
    $client         = openai();
    $amenitiesCache = getAmenitiesCached();
    $context        = is_array($memory['context'] ?? null) ? $memory['context'] : [];

    $maxLoops = 3;
    $loop     = 0;

    while ($loop++ < $maxLoops) {

        $response = $client->responses()->create([
            "model" => "gpt-4.1",
            "input" => $conversation,
            "tools" => getAgentTools()
        ]);

        $toolCalls  = [];
        $outputText = '';

        foreach ($response->output as $item) {
            if ($item->type === "function_call") {
                $toolCalls[] = $item;
            }
            if ($item->type === "message") {
                foreach ($item->content as $content) {
                    if ($content->type === "output_text") {
                        $outputText .= $content->text;
                    }
                }
            }
        }

        if (empty($toolCalls)) {
            return $outputText;
        }

        foreach ($toolCalls as $call) {
            $args = json_decode($call->arguments, true);
            if (!is_array($args)) $args = [];

            foreach (["user_id", "society_id", "property_id", "unit_id", "club_id"] as $k) {
                if (!array_key_exists($k, $args) && array_key_exists($k, $context)) {
                    $args[$k] = $context[$k];
                }
            }

            $amenity = findAmenity($userMessage, $amenitiesCache);
            if ($amenity) {
                $args['id'] = $amenity['id'];
                $args['title'] = $amenity['title'];
                $args['description'] = $amenity['description'];
                $args['icon'] = $amenity['icon'];
            }

            $result = handleTool($call->name, $args);

            $conversation[] = [
                "type"      => "function_call",
                "call_id"   => $call->callId,
                "name"      => $call->name,
                "arguments" => $call->arguments
            ];

            $conversation[] = [
                "type"    => "function_call_output",
                "call_id" => $call->callId,
                "output"  => json_encode($result)
            ];

            if (!empty($result['cards']) || !empty($result['ui'])) {
                return $result;
            }
        }
    }
}

// ─────────────────────────────────────────────
// RESPONSE PARSER
// ─────────────────────────────────────────────

function parseResponse($outputText) {
    $raw = trim((string)$outputText);
    $data = json_decode($raw, true);
    if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
        $data = extractFirstJsonObject($raw);
    }

    if (!$data || !is_array($data)) {
        return [
            "type"    => "response",
            "message" => $raw,
            "cards"   => [],
            "ui"      => []
        ];
    }

    if (is_string($data['message'] ?? null)) {
        $inner = extractFirstJsonObject($data['message']);
        if (is_array($inner) && (isset($inner['message']) || isset($inner['cards']) || isset($inner['ui']))) {
            $data = $inner;
        }
    }

    $data['type']  = is_string($data['type'] ?? null) ? $data['type'] : 'response';
    $data['message'] = $data['message'] ?? '';
    $data['cards'] = is_array($data['cards'] ?? null) ? $data['cards'] : [];
    $data['ui']    = is_array($data['ui'] ?? null) ? $data['ui'] : [];

    return $data;
}

function extractFirstJsonObject($text): ?array {
    $s = (string)$text;
    $len = strlen($s);
    if ($len === 0) return null;

    for ($i = 0; $i < $len; $i++) {
        if ($s[$i] !== '{') continue;

        $depth = 0;
        $inString = false;
        $escape = false;

        for ($j = $i; $j < $len; $j++) {
            $ch = $s[$j];

            if ($inString) {
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escape = true;
                    continue;
                }
                if ($ch === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($ch === '"') {
                $inString = true;
                continue;
            }

            if ($ch === '{') {
                $depth++;
                continue;
            }

            if ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    $candidate = substr($s, $i, $j - $i + 1);
                    $decoded = json_decode($candidate, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        return $decoded;
                    }
                    break;
                }
            }
        }
    }

    return null;
}

function _wrapPhraseMarkdown(string $text, string $phrase, int &$replCount): string {
    $replCount = 0;
    $p = trim(str_replace('*', '', $phrase));
    if ($p === '') return $text;

    $pattern = '/(^|[^*])(' . preg_quote($p, '/') . ')(?=$|[^*])/i';
    $out = preg_replace($pattern, '$1*$2*', $text, -1, $replCount);
    return is_string($out) ? $out : $text;
}

function _wrapRegexMarkdown(string $text, string $innerPattern, int &$replCount): string {
    $replCount = 0;
    $pattern = '/(^|[^*])(' . $innerPattern . ')(?=$|[^*])/i';
    $out = preg_replace($pattern, '$1*$2*', $text, -1, $replCount);
    return is_string($out) ? $out : $text;
}

function highlightMessageMarkdown(string $text, array $profile = [], array $memory = [], $selection = null): string {
    $msg = (string)$text;
    if ($msg === '' || str_contains($msg, '*')) {
        return $msg;
    }

    $maxHighlights = 8;
    $highlightsUsed = 0;

    $candidates = [];
    $add = function ($v) use (&$candidates) {
        $s = trim((string)$v);
        if ($s !== '') $candidates[] = $s;
    };

    $add($memory['context']['unit_name'] ?? null);
    $add($memory['context']['unit_id'] ?? null);
    $add($memory['context']['club_name'] ?? null);
    $add($memory['context']['club_id'] ?? null);
    $add($memory['booking']['amenity'] ?? null);
    $add($memory['booking']['date'] ?? null);
    $add($memory['booking']['time'] ?? null);

    if (is_array($selection)) {
        $add($selection['title'] ?? null);
        $auto = $selection['autocomplete'] ?? ($selection['data']['autocomplete'] ?? null);
        if (is_array($auto)) {
            $add($auto['amenity'] ?? null);
            $add($auto['date'] ?? null);
            $add($auto['timeslot'] ?? null);
            $add($auto['court'] ?? null);
        }
    }

    if (!empty($profile['clubs']) && is_array($profile['clubs'])) {
        foreach (array_slice($profile['clubs'], 0, 6) as $c) {
            if (is_array($c)) $add($c['title'] ?? null);
        }
    }
    if (!empty($profile['units']) && is_array($profile['units'])) {
        foreach (array_slice($profile['units'], 0, 6) as $u) {
            if (is_array($u)) $add($u['title'] ?? null);
        }
    }

    $candidates = array_values(array_unique($candidates));
    usort($candidates, fn($a, $b) => strlen($b) <=> strlen($a));

    foreach ($candidates as $p) {
        if ($highlightsUsed >= $maxHighlights) break;
        $count = 0;
        $new = _wrapPhraseMarkdown($msg, $p, $count);
        if ($count > 0 && $new !== $msg) {
            $msg = $new;
            $highlightsUsed++;
        }
    }

    if ($highlightsUsed < $maxHighlights) {
        $patterns = [
            '\\b\\d{4}-\\d{2}-\\d{2}\\b',
            '\\b\\d{1,2}:\\d{2}\\s?(?:AM|PM)\\b',
            '\\bCourt\\s+\\d+\\b',
            '\\b(today|tomorrow)\\b',
        ];
        foreach ($patterns as $pat) {
            if ($highlightsUsed >= $maxHighlights) break;
            $count = 0;
            $new = _wrapRegexMarkdown($msg, $pat, $count);
            if ($count > 0 && $new !== $msg) {
                $msg = $new;
                $highlightsUsed++;
            }
        }
    }

    return $msg;
}

// ─────────────────────────────────────────────
// MEMORY UPDATER
// ─────────────────────────────────────────────

function updateMemoryFromResponse($code, $data, $profile, $memory) {
    $memoryUpdate = [
        "last_intent" => $memory['last_intent'] ?? "general"
    ];

    if (!empty($data['cards'])) {
        $card = $data['cards'][0];

        if (!empty($card['action'])) {
            $memoryUpdate['last_screen'] = $card['action'];
            if (!in_array($card['action'], ["select_unit", "select_club"], true)) {
                $memoryUpdate['last_intent'] = $card['action'];
            }
        }

        if (!empty($card['data']['autocomplete'])) {
            $auto = $card['data']['autocomplete'];
            $memoryUpdate['booking'] = array_filter([
                "amenity" => $auto['amenity']   ?? null,
                "date"    => $auto['date']       ?? null,
                "time"    => $auto['timeslot']   ?? null
            ]);
        }
    }

    $merged = mergeMemory($memory, $memoryUpdate);
    saveMemory($code, $merged);

    return $merged;
}

// ─────────────────────────────────────────────
// CONFIRMATION HANDLER
// Called when user clicks an action card that
// requires a real API call before confirming.

function handleConfirmationSelection(string $code, array $selection, array $memory): ?array {

    $action   = $selection['action']       ?? null;
    $auto     = $selection['autocomplete'] ?? $selection['data']['autocomplete'] ?? [];
    $confirm  = $selection['confirm']      ?? false;

    // ── BOOKING CONFIRMATION ──────────────────
    if ($action === 'clubsbooking' && !empty($auto['timeslot'])) {

        $result = confirmBookingApi(array_merge($auto, [
            'club_id' => $memory['context']['club_id'] ?? null,
            'unit_id' => $memory['context']['unit_id'] ?? null,
        ]));

        if (!empty($result['success'])) {
            return [
                "type"    => "response",
                "message" => "✅ Booking confirmed! {$auto['amenity']} on {$auto['date']} at {$auto['timeslot']}.",
                "cards"   => [
                    ["title" => "View My Bookings", "action" => "navigate" , "button" => ["title" => "Clubs", "action" => "clubs"]],
                    ["title" => "Book Another",     "action" => "clubs"]
                ],
                "ui" => ["component" => "success_card"]
            ];
        }



        return [
            "type"    => "response",
            "message" => "⚠️ Something went wrong: " . ($result['message'] ?? 'Booking failed. Please try again.'),
            "cards"   => [
                [
                    "title"  => "Try Again",
                    "action" => "clubsbooking",
                    "data"   => [
                        "id"    => $auto['amenity_id'] ?? null,
                        "title" => $auto['amenity']    ?? null,
                        "action" => "clubsbooking",
                        "amenity" => [
                            "id"    => $auto['amenity_id'] ?? null,
                            "title" => $auto['amenity']    ?? null,
                        ],
                        "autocomplete" => [
                            "amenity_id" => $auto['amenity_id'] ?? null,
                            "amenity"    => $auto['amenity']    ?? null,
                            "date"       => $auto['date']       ?? null,
                            "timeslot"   => $auto['timeslot']   ?? null,
                            "court"      => $auto['court']      ?? null,
                        ]
                    ]
                ],
                ["title" => "View All Clubs", "action" => "navigate" , "button" => ["title" => "Clubs", "action" => "clubs"]]
            ],
            "ui" => ["component" => "error_card"]
        ];
    }

    // ── SERVICE REQUEST CONFIRMATION ──────────
    if ($action === 'servicemaintainanceadd' && !empty($confirm)) {

        $result = confirmServiceRequestApi([
            'unit_id'   => $auto['unit_id']   ?? $memory['context']['unit_id'] ?? null,
            'device_id' => $auto['device_id'] ?? $memory['issue']['device_id'] ?? null,
            'issue'     => $auto['issue']     ?? $memory['issue']['title']     ?? null,
            'category'  => $memory['issue']['category'] ?? 'General',
        ]);

        // clear issue from memory on success
        if (!empty($result['success'])) {
            saveMemory($code, ['issue' => null, 'flow' => null]);

            return [
                "type"    => "response",
                "message" => "✅ Service request raised! Our team will get in touch with you shortly.",
                "cards"   => [
                    ["title" => "Track My Request", "action" => "servicemaintainanceadd"],
                    ["title" => "Go to Home",        "action" => "clubs"]
                ],
                "ui" => ["component" => "success_card"]
            ];
        }

        return [
            "type"    => "response",
            "message" => "⚠️ Something went wrong: " . ($result['message'] ?? 'Could not raise request. Please try again.'),
            "cards"   => [
                ["title" => "Try Again", "action" => "servicemaintainanceadd"]
            ],
            "ui" => ["component" => "error_card"]
        ];
    }

    // ── CLOSE COMPLAINT ───────────────────────────────────────────
    if ($action === 'close_complaint' && !empty($auto['complaint_id'])) {

        $result = closeComplaintApi([
            'complaint_id' => $auto['complaint_id'],
            'code'         => $code
        ]);

        if (!empty($result['success'])) {
            return [
                "type"    => "response",
                "message" => "✅ Complaint #{$auto['complaint_id']} has been marked as closed.",
                "cards"   => [
                    [
                        "title"  => "Reopen if needed",
                        "action" => "reopen_complaint",
                        "data"   => [
                            "autocomplete" => [
                                "complaint_id" => $auto['complaint_id']
                            ]
                        ]
                    ]
                ],
                "ui" => ["component" => "success_card"]
            ];
        }

        return [
            "type"    => "response",
            "message" => "⚠️ Could not close the complaint. Please try again.",
            "cards"   => [
                [
                    "title"  => "Try Again",
                    "action" => "close_complaint",
                    "data"   => ["autocomplete" => ["complaint_id" => $auto['complaint_id']]]
                ]
            ],
            "ui" => ["component" => "error_card"]
        ];
    }

    // ── REOPEN COMPLAINT ──────────────────────────────────────────
    if ($action === 'reopen_complaint' && !empty($auto['complaint_id'])) {

        $result = reopenComplaintApi([
            'complaint_id' => $auto['complaint_id'],
            'code'         => $code
        ]);

        if (!empty($result['success'])) {
            return [
                "type"    => "response",
                "message" => "✅ Complaint #{$auto['complaint_id']} has been reopened. Our team will follow up.",
                "cards"   => [],
                "ui"      => ["component" => "success_card"]
            ];
        }

        return [
            "type"    => "response",
            "message" => "⚠️ Could not reopen the complaint. Please try again.",
            "cards"   => [
                [
                    "title"  => "Try Again",
                    "action" => "reopen_complaint",
                    "data"   => ["autocomplete" => ["complaint_id" => $auto['complaint_id']]]
                ]
            ],
            "ui" => ["component" => "error_card"]
        ];
    }

    return null; // not a confirmation click, continue normal flow
}

// ─────────────────────────────────────────────
// MAIN AGENT ENTRY POINT
// ─────────────────────────────────────────────

 function runAgent(array $params) {
    $code = (string)($params["code"] ?? '');
    $userMessage = $params["message"] ?? '';
    $selection = $params["selection"] ?? ($params["selected"] ?? null);
    if (is_array($selection)) {
        if (isset($selection['selected']) && is_array($selection['selected']) && empty($selection['type'])) {
            $selection = $selection['selected'];
        } elseif (isset($selection['selection']) && is_array($selection['selection']) && empty($selection['type'])) {
            $selection = $selection['selection'];
        }
    }
    if (is_string($selection)) {
        $rawSelection = trim($selection);
        if ($rawSelection === '') {
            $selection = null;
        } else {
            $decoded = json_decode($rawSelection, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $selection = $decoded;
            } else {
                $selection = ["action" => $rawSelection];
            }
        }
    }
    if (is_array($selection)) {
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
    }

    /* if (!$code || !$userMessage) {
        response_error(400, 'Invalid input');
        return;
    } */

    if ($code === '') {
        return [
            "type"    => "response",
            "message" => "Missing user code.",
            "cards"   => [],
            "ui"      => []
        ];
    }

    $sessionId = getOrCreateSession($code) ?: createSession($code);
    $profile = getUserProfile($params);
    $fullname =  getFirstName($profile["name"]) ?? "Guest";

    $hasSelection = is_array($selection) && !empty($selection);
    if ((trim((string)$userMessage) === '') && !$hasSelection) {
        $response = array(
            "type" => "array",
            //"message" => "Hi, I’m HiRa 👋 \nYour smart assistant for bookings, complaints, and more."
            //"message" => "Hi 👋, \n#$fullname# \n\nI’m HiRa, How can I assist you today?.",
            "message" => [
                "title" => "Hi 👋",
                "headline" => $fullname,
                "subtitle" => "I'm HiRa, How can I assist you today?.",
                "description" => "",
            ],
            "cards" => [],
            "suggestions" => [
                [
                    "title" => "Book Badminton",
                    "action" => "clubs",
                    //"icon" => "img/icon-sports-badminton.png",
                    "icon" => getCloudImg("images/icons/icon-sports-badminton", 'thumb'),
                    "data" => []
                ],[
                    "title" => "Raise Complaint",
                    "action" => "servicemaintainanceadd",
                    "icon" => getCloudImg("images/icons/img-no-complaints", 'thumb'),
                    //"icon" => "img/img-no-complaints.png",
                    "data" => []
                ],[
                    "title" => "My Last Complaint Status",
                    "action" => "servicemaintainance",
                    //"icon" => "img/icon-menu-directory.png",
                    "icon" => getCloudImg("images/icons/img-no-complaints", 'thumb'),
                    "data" => []
                ]
            ]
        );
        return $response;
    }


    // GREETING SHORTCUT
    if (!$hasSelection && preg_match('/^(hi|hello|hey)[!. ]*$/i', trim((string)$userMessage))) {
        saveMessage($code, $sessionId, 'user', 'text', $userMessage);
        return ["message" => "Hi 👋"];
    }

    // SAVE USER MESSAGE
    if (trim((string)$userMessage) !== '') {
        saveMessage($code, $sessionId, 'user', 'text', $userMessage);
    }

    // MEMORY
    $memory = getMemory($code);

    // NORMALIZE SELECTION from request body if not passed directly
    /* if (empty($selection)) {
        $body = null;
        if (function_exists('request_response')) {
            $body = request_response();
        } else {
            $raw = file_get_contents('php://input') ?: '';
            if ($raw !== '') {
                $decodedBody = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedBody)) {
                    $body = $decodedBody;
                }
            }
        }

        if (is_array($body)) {
            if (isset($body['selected']))  $selection = $body['selected'];
            elseif (isset($body['selection'])) $selection = $body['selection'];
        }
    } */

    // CONFIRMATION API CALL (before anything else)
    if (is_array($selection) && !empty($selection)) {
        $confirmResponse = handleConfirmationSelection($code, $selection, $memory);
        if ($confirmResponse) {
            saveMessage($code, $sessionId, 'assistant', 'response', $confirmResponse['message']);
            return $confirmResponse;
        }
    }

    if (is_array($selection) && (($selection['action'] ?? '') === 'clubs')) {
        $amenities = getAmenitiesCached();
        $cards = array_map(function ($a, $i) {
            return [
                "id"     => (string)($a['id'] ?? ($i + 1)),
                "title"  => (string)($a['title'] ?? ''),
                "action" => "clubdetails",
                "data"   => []
            ];
        }, is_array($amenities) ? $amenities : [], array_keys(is_array($amenities) ? $amenities : []));

        $data = [
            "type"    => "card",
            "message" => "Select a sport or activity to get started:",
            "cards"   => enrichAmenityCards(enrichPredefinedCards(validateCards($cards))),
            "ui"      => []
        ];

        saveMessage(
            $code,
            $sessionId,
            'assistant',
            $data['type'],
            $data['message'],
            ['cards' => $data['cards'], 'ui' => $data['ui']]
        );

        $memory = mergeMemory($memory, ["last_intent" => "clubs", "last_screen" => "clubs"]);
        saveMemory($code, $memory);

        return $data;
    }

    if (is_array($selection) && (($selection['action'] ?? '') === 'unitfamily')) {
        $cards = enrichPredefinedCards(validateCards([
            ["action" => "unitfamily", "data" => []]
        ]));

        $data = [
            "type"    => "response",
            "message" => "Opening Family & Tenants.",
            "cards"   => $cards,
            "ui"      => []
        ];

        saveMessage(
            $code,
            $sessionId,
            'assistant',
            $data['type'],
            $data['message'],
            ['cards' => $data['cards'], 'ui' => $data['ui']]
        );

        $memory = mergeMemory($memory, ["last_intent" => "unitfamily", "last_screen" => "unitfamily"]);
        saveMemory($code, $memory);

        return $data;
    }

    $client = openai();
    $memory = updateConversationSummaryIfNeeded($code, $sessionId, $client, $memory);

    // UNIT SELECTION HANDLER
    /* if (is_array($selection) && (($action ?? '') === 'select_unit')) {
        $unitId   = $selection['unit_id'] ?? ($selection['id'] ?? null);
        $unitName = $selection['title']   ?? ($unitId ?? '');

        if (!empty($unitId)) {
            $existingContext  = is_array($memory['context'] ?? null) ? $memory['context'] : [];
            $memory['context'] = array_merge($existingContext, [
                "unit_id"   => $unitId,
                "unit_name" => $unitName
            ]);

            saveMemory($code, ["context" => ["unit_id" => $unitId, "unit_name" => $unitName]]);
            $memory = mergeMemory($memory, getMemory($code));
        }

        if (empty($memory['issue']) && !empty($unitId)) {
            $devices         = [];

            if (!empty($profile['devices'][$unitId])) {
                $devices = $profile['devices'][$unitId];
            } else {
                foreach (($profile['devices'] ?? []) as $unitDevices) {
                    $devices = array_merge($devices, is_array($unitDevices) ? $unitDevices : []);
                }
            }

            $history = getMessages($sessionId, 10);
            for ($i = count($history) - 1; $i >= 0; $i--) {
                $msg  = $history[$i] ?? null;
                if (!is_array($msg) || ($msg['role'] ?? '') !== 'user') continue;

                $text = (string)($msg['message'] ?? '');
                if (!preg_match('/(not working|issue|problem|broken|repair)/i', $text)) continue;

                $issue = detectIssue($text, $devices);
                if (!$issue) $issue = ["title" => $text, "category" => "General"];

                $memory['issue']       = $issue;
                $memory['last_intent'] = 'servicemaintainanceadd';
                saveMemory($code, ["issue" => $issue, "last_intent" => "servicemaintainanceadd"]);
                break;
            }
        }

        if (!empty($memory['issue']) || (($memory['last_intent'] ?? '') === 'servicemaintainanceadd')) {
            $memory['flow']        = ["step" => "maintenance_confirm"];
            $memory['last_intent'] = 'servicemaintainanceadd';
            saveMemory($code, ["flow" => ["step" => "maintenance_confirm"], "last_intent" => "servicemaintainanceadd"]);
        }

        $preResponse = preProcessAI($code, $selection, $memory);
        if ($preResponse) return $preResponse;
    } */

    // PROFILE + CONTEXT SEEDING

    $contextSeed = array_filter([
        "user_id"     => $profile['user_id']     ?? $code,
        "society_id"  => $profile['society_id']  ?? null,
        "property_id" => $profile['property_id'] ?? null
    ], fn($v) => $v !== null && $v !== '');

    if (!empty($contextSeed)) {
        $existingContext = is_array($memory['context'] ?? null) ? $memory['context'] : [];
        $mergedContext   = array_merge($existingContext, $contextSeed);
        if ($mergedContext !== $existingContext) {
            saveMemory($code, ["context" => $mergedContext]);
            $memory = mergeMemory($memory, getMemory($code));
        }
    }

    if (empty($memory['context']['unit_id']) && !empty($profile['units']) && count($profile['units']) === 1) {
        $existingContext = is_array($memory['context'] ?? null) ? $memory['context'] : [];
        $mergedContext   = array_merge($existingContext, [
            "unit_id"   => $profile['units'][0]['id'],
            "unit_name" => $profile['units'][0]['title']
        ]);
        saveMemory($code, ["context" => $mergedContext]);
        $memory = mergeMemory($memory, getMemory($code));
    }

    if (empty($memory['context']['club_id']) && !empty($profile['clubs']) && count($profile['clubs']) === 1) {
        $existingContext = is_array($memory['context'] ?? null) ? $memory['context'] : [];
        $mergedContext   = array_merge($existingContext, [
            "club_id"   => $profile['clubs'][0]['id'],
            "club_name" => $profile['clubs'][0]['title']
        ]);
        saveMemory($code, ["context" => $mergedContext]);
        $memory = mergeMemory($memory, getMemory($code));
    }

    // UNIT CHANGE REQUEST
    if (empty($selection) && preg_match('/(other unit|another unit|different unit|change unit)/i', $userMessage)) {
        $memory['context']['unit_id']   = null;
        $memory['context']['unit_name'] = null;
        $memory['last_intent']          = "servicemaintainanceadd";
        $memory['flow']                 = ["step" => "maintenance_confirm"];

        /* saveMemory($code, [
            "context"     => ["unit_id" => null, "unit_name" => null],
            "last_intent" => "servicemaintainanceadd",
            "flow"        => ["step" => "maintenance_confirm"]
        ]); */

        $units = $profile['units'] ?? [];
        if (is_array($units) && count($units) > 1) {
            $cards = array_map(function ($u) {
                return [
                    "title"  => $u['title'] ?? "",
                    "description"  => $u['description'] ?? "",
                    "action" => "select_unit",
                    "data"   => ["type" => "unit", "action" => "select_unit", "unit_id" => $u['id'], "id" => $u['id'], "title" => $u['title'] ?? "", "description" => $u['description'] ?? ""]
                ];
            }, $units);

            return [
                "type"    => "response",
                "message" => "Select your unit to raise a maintenance request.",
                "cards"   => $cards,
                "ui"      => []
            ];
        }
    }

    // ISSUE DETECTION FROM MESSAGE
    if (empty($selection) && preg_match('/(not working|issue|problem|broken|repair|leak|leakage|leaking)/i', $userMessage)) {
        $previousIssueTitle = strtolower(trim((string)($memory['issue']['title'] ?? '')));
        $newIssueTitle      = strtolower(trim($userMessage));
        $shouldUpdate       = empty($memory['issue']) || ($previousIssueTitle !== '' && $previousIssueTitle !== $newIssueTitle);

        if ($shouldUpdate) {
            $unitId  = $memory['context']['unit_id'] ?? null;
            $devices = [];

            if ($unitId && !empty($profile['devices'][$unitId])) {
                $devices = $profile['devices'][$unitId];
            } else {
                foreach (($profile['devices'] ?? []) as $unitDevices) {
                    $devices = array_merge($devices, is_array($unitDevices) ? $unitDevices : []);
                }
            }

            $issue = detectIssue($userMessage, $devices);
            if (!$issue) $issue = ["title" => $userMessage, "category" => "General"];

            $memory['issue']       = $issue;
            $memory['last_intent'] = "servicemaintainanceadd";
            $memory['flow']        = ["step" => "maintenance_confirm"];

            saveMemory($code, [
                "issue"       => $issue,
                "last_intent" => "servicemaintainanceadd",
                "flow"        => ["step" => "maintenance_confirm"]
            ]);

            $memory = mergeMemory($memory, getMemory($code));
        }
    }

    if (!is_array($selection)) $selection = null;

    // SELECTION CONTEXT SAVE
    if (!empty($selection)) {
        $update = [];

        if (($selection['type'] ?? '') === 'club') {
            $update['context'] = ["club_id" => $selection['id'], "club_name" => $selection['title']];
        }

        if (($selection['type'] ?? '') === 'unit') {
            $update['context'] = ["unit_id" => $selection['id'], "unit_name" => $selection['title']];

            if (($memory['last_intent'] ?? '') === 'servicemaintainanceadd' || !empty($memory['issue'])) {
                $update['flow'] = ["step" => "maintenance_confirm"];
            }
        }

        $memory = mergeMemory($memory, $update);
        saveMemory($code, $memory);
    }

    $memory = mergeMemory($memory, getMemory($code));

    // PRE-PROCESS AI
    $preResponse = preProcessAI($code, $selection, $memory);
    if ($preResponse) return $preResponse;

    // BUILD CONVERSATION
    $conversation = buildConversation($sessionId, $userMessage, $memory, $selection, $profile);

    $output = processAI($conversation, $userMessage, $memory);
    $retry = 0;
    while (true) {
        $data = is_array($output) ? $output : parseResponse($output);


    // ── AUTO-SELECT SINGLE UNIT ────────────────────────────────────
    if (
        empty($memory['context']['unit_id']) &&
        !empty($profile['units']) &&
        count($profile['units']) === 1
    ) {
        $only = $profile['units'][0];
        $memory['context']['unit_id']   = $only['id'];
        $memory['context']['unit_name'] = $only['title'];
        saveMemory($code, ["context" => [
            "unit_id"   => $only['id'],
            "unit_name" => $only['title']
        ]]);
        $memory = mergeMemory($memory, getMemory($code));
    }

    // ── AUTO-SELECT SINGLE CLUB ────────────────────────────────────
    if (
        empty($memory['context']['club_id']) &&
        !empty($profile['clubs']) &&
        count($profile['clubs']) === 1
    ) {
        $only = $profile['clubs'][0];
        $memory['context']['club_id']   = $only['id'];
        $memory['context']['club_name'] = $only['title'];
        saveMemory($code, ["context" => [
            "club_id"   => $only['id'],
            "club_name" => $only['title']
        ]]);
        $memory = mergeMemory($memory, getMemory($code));
    }


        $data['cards']   = validateCards($data['cards'] ?? []);
        $resolvedMessage = (string)($data['message'] ?? '');

    // ── DUPLICATE SELECTION GUARD ─────────────────────────────────
    // If the previous assistant message was ALREADY asking for unit/club
    // selection and the user sent another message without clicking a card,
    // skip resolveContextCards so the same prompt doesn't repeat.
    // Instead, re-show the same selection cards with a nudge message.
        if (lastResponseWasSelection($sessionId) && empty($selection)) {

            $lastType    = lastSelectionType($sessionId);
            $lastCards   = getLastAssistantCards($sessionId);

            $nudgeMsg = $lastType === 'unit'
                ? "Please select your unit to continue."
                : "Please select your club to continue.";

            $newCardsHaveSelection = false;
            foreach ($data['cards'] as $c) {
                if (in_array($c['action'] ?? '', ['select_unit', 'select_club'], true)) {
                    $newCardsHaveSelection = true;
                    break;
                }
            }

            if (!$newCardsHaveSelection) {
                $resolvedMessage = $nudgeMsg;
                $data['cards']   = $lastCards;
            }

        } else {
            $data['cards'] = resolveContextCards($data['cards'], $profile, $memory, $resolvedMessage);
        }
    // ─────────────────────────────────────────────────────────────

        $resolvedMessage = highlightMessageMarkdown($resolvedMessage, $profile, $memory, $selection);
        $data['message'] = $resolvedMessage;

        $data['cards'] = enrichPredefinedCards($data['cards']);
        $data['cards'] = enrichAmenityCards($data['cards']);

        $lastMsg = getLastAssistantMessage($sessionId);
        $isDuplicate = false;
        if ($retry === 0 && empty($selection) && $lastMsg !== null) {
            $currentMsg = trim((string)($data['message'] ?? ''));
            $prevMsg = trim((string)$lastMsg);
            if ($currentMsg !== '' && $prevMsg !== '' && $currentMsg === $prevMsg) {
                $currActions = array_map(fn($c) => (string)($c['action'] ?? ''), $data['cards'] ?? []);
                $prevActions = array_map(fn($c) => (string)($c['action'] ?? ''), getLastAssistantCards($sessionId));
                if ($currActions === $prevActions) {
                    $isDuplicate = true;
                }
            }
        }

        if ($isDuplicate) {
            $conversationRetry = $conversation;
            $conversationRetry[] = [
                "role" => "system",
                "content" => "Do not repeat your previous assistant message. Provide a different helpful next step. Return only valid JSON."
            ];
            $output = processAI($conversationRetry, $userMessage, $memory);
            $retry++;
            if ($retry > 1) {
                break;
            }
            continue;
        }

        break;
    }

    // SAVE ASSISTANT MESSAGE
    saveMessage(
        $code,
        $sessionId,
        'assistant',
        $data['type']    ?? 'response',
        $data['message'] ?? '',
        ['cards' => $data['cards'], 'ui' => $data['ui'] ?? []]
    );

    // UPDATE MEMORY
    updateMemoryFromResponse($code, $data, $profile, $memory);

    return $data;
}
