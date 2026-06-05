<?php

require_once __DIR__ . '/../config.php';

// ─────────────────────────────────────────────
// MEMORY
// ─────────────────────────────────────────────

function mergeMemory($old, $new) {
    foreach ($new as $key => $value) {
        if (is_array($value) && isset($old[$key])) {
            $old[$key] = mergeMemory($old[$key], $value);
        } else {
            $old[$key] = $value;
        }
    }
    return $old;
}

function getMemory($code) {
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT context 
        FROM ai_memory 
        WHERE code = ?
    ");

    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return [];
    }

    return json_decode($row['context'], true) ?? [];
}

function saveMemory($code, $newData) {
    $pdo = db();

    $stmt = $pdo->prepare("
        INSERT INTO ai_memory (code, context, updated_at)
        VALUES (?, ?, NOW())
        ON CONFLICT (code)
        DO UPDATE SET
            context    = ai_memory.context || EXCLUDED.context,
            updated_at = NOW()
    ");

    $stmt->execute([
        $code,
        json_encode($newData)
    ]);
}

function resetMemory($code): void {
    $pdo = db();
    $stmt = $pdo->prepare("
        INSERT INTO ai_memory (code, context, updated_at)
        VALUES (?, '{}'::jsonb, NOW())
        ON CONFLICT (code)
        DO UPDATE SET
            context    = '{}'::jsonb,
            updated_at = NOW()
    ");
    $stmt->execute([$code]);
}

function replaceMemory($code, array $context): void {
    $pdo = db();
    $stmt = $pdo->prepare("
        INSERT INTO ai_memory (code, context, updated_at)
        VALUES (?, ?, NOW())
        ON CONFLICT (code)
        DO UPDATE SET
            context    = EXCLUDED.context,
            updated_at = NOW()
    ");
    $stmt->execute([$code, json_encode($context)]);
}

// ─────────────────────────────────────────────
// MESSAGES
// ─────────────────────────────────────────────

function getMessages($sessionId, $limit = 10) {
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT role, message
        FROM ai_messages
        WHERE session_id = ?
        ORDER BY id DESC
        LIMIT ?
    ");

    $stmt->bindValue(1, $sessionId);
    $stmt->bindValue(2, (int)$limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_reverse($rows);
}

function getMessageCount($sessionId) {
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS count
        FROM ai_messages
        WHERE session_id = ?
    ");
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return (int)($row['count'] ?? 0);
}

function saveMessage($code, $sessionId, $role, $type, $message = null, $meta = null) {
    $pdo = db();

    if (is_array($message)) {
        $message = json_encode($message);
    }

    $message = $message ?? '';

    if (is_array($meta)) {
        $meta = json_encode($meta);
    }

    if (trim((string)$message) === '' && empty($meta)) {
        return false;
    }

    $stmt = $pdo->prepare("
        INSERT INTO ai_messages (code, session_id, role, type, message, meta)
        VALUES (:code, :session_id, :role, :type, :message, :meta)
    ");

    return $stmt->execute([
        ':code'       => $code,
        ':session_id' => $sessionId,
        ':role'       => $role,
        ':type'       => $type,
        ':message'    => $message,
        ':meta'       => $meta
    ]);
}


function get_user_profile(string $code): array {

  $pdo = db();
  $stmt = $pdo->prepare('SELECT * FROM users WHERE code = :code LIMIT 1');
  $stmt->execute([':code' => $code]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    response_error(404, 'User not found');
    exit;
  }
  $result = [
      "code" => $row['code'],
      "type" => $row['type'],
      "username" => (string) $row['username'],
      "fullname" => $row['fullname'],
      "countrycode" => $row['countrycode'],
      "gender" => $row['gender'],
      "mobile" => $row['mobile'],
      "email" => $row['email'],
      "photo" => $row['photo'],
      "active" => $row['active'],
      "propertyid" => $row['propertyid'],
      "createdat" => $row['createdat'],
    ];
  return $result ?: array();
}

/*  */

function defaultmsg($code):array{
    $profile = get_user_profile($code);
    $response = array(
            "type" => "array",
            //"message" => "Hi, I’m HiRa 👋 \nYour smart assistant for bookings, complaints, and more."
            //"message" => "Hi 👋, \n#$fullname# \n\nI’m HiRa, How can I assist you today?.",
            "message" => [
                "title" => "Hi 👋",
                "headline" => $profile["fullname"] ?? "Guest",
                "subtitle" => "I'm HiRa, How can I assist you today?.",
                "description" => "",
            ],
            "data" => [
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
            ],
        );

        return $response;
}

// ─────────────────────────────────────────────
// HISTORY
// ─────────────────────────────────────────────

function clearhistory(array $params):array {
    $response = array();
    $code = $params["code"] ?? "";

    //Delete Messages :
    $pdo = db();
    $stmt = $pdo->prepare("DELETE FROM aiagent.ai_messages WHERE code = :code");
    $stmt->execute([':code' => $code]);

    $stmt = $pdo->prepare("DELETE FROM aiagent.ai_memory WHERE code = :code");
    $stmt->execute([':code' => $code]);

    $response = defaultmsg($code);

    return $response;
}

function history(array $params):array{
    $response = array();
    $response = historynext($params);
    return $response;
}

function historynext(array $params):array{
    $response = array();
    $code = $params["code"] ?? "";
    $index = (int)$params["index"] ?? 0;

    if(empty($code)){
        response_error(500, "User Not Found");
        exit;
    };

    $pdo = db();
    $stmt = $pdo->prepare("SELECT m.*, u.photo AS photo 
                    FROM aiagent.ai_messages m
                    LEFT JOIN public.users u ON u.code = m.code
                    WHERE m.code = :code ORDER BY m.created_at DESC LIMIT 10 OFFSET :limit");
    $stmt->bindValue(':code', $code);
    $stmt->bindValue(':limit', (int)$index, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if(empty($rows)){
        $response[] = defaultmsg($code);
        return $response;
        //response_error(500, "No History");
        //exit;
    };

    $messages = array_map(function ($row) {
        $meta = !empty($row["meta"])? json_decode($row["meta"]) : [];
        return [
            "id" => $row["id"],
            "code" => $row["code"],
            "role" => $row["role"],
            "type" => $row["type"],
            "message" => $row["message"],
            "photo" => $row["photo"],
            "data" => $meta,
            "shortdate" => (string)date('M d, Y h:i A', strtotime($row["created_at"])),
            "date" => $row["created_at"],
        ];
        }, $rows);

    return $messages;
}

// ─────────────────────────────────────────────
// SESSIONS
// ─────────────────────────────────────────────

function getOrCreateSession($code) {
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT id, updated_at
        FROM ai_sessions
        WHERE code = ?
        ORDER BY updated_at DESC
        LIMIT 1
    ");

    $stmt->execute([$code]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($session && !empty($session['id'])) {
        $updatedAt = (string)($session['updated_at'] ?? '');
        $today = date('Y-m-d');
        $lastDay = $updatedAt !== '' ? date('Y-m-d', strtotime($updatedAt)) : '';
        if ($lastDay !== '' && $lastDay !== $today) {
            resetMemory($code);
            return createSession($code);
        }
        return $session['id'];
    }

    return createSession($code);
}

function createSession($code) {
    $pdo = db();

    $stmt = $pdo->prepare("
        INSERT INTO ai_sessions (code)
        VALUES (?)
        RETURNING id
    ");

    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row['id'];
}

function rotateSession($code) {
    return createSession($code);
}

// ─────────────────────────────────────────────
// LAST MESSAGE
// ─────────────────────────────────────────────


// Returns the cards array from the last assistant message meta
function getLastAssistantCards($sessionId): array {
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT meta
        FROM ai_messages
        WHERE session_id = ?
          AND role = 'assistant'
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute([$sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['meta'])) return [];

    $meta = json_decode($row['meta'], true);
    return $meta['cards'] ?? [];
}

function getLastAssistantMessage($sessionId): ?string {
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT message
        FROM ai_messages
        WHERE session_id = ?
          AND role = 'assistant'
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $msg = $row['message'] ?? null;
    return is_string($msg) ? $msg : null;
}

// Returns true if the last assistant message was already asking for unit/club selection
function lastResponseWasSelection($sessionId): bool {
    $cards = getLastAssistantCards($sessionId);
    foreach ($cards as $card) {
        $action = $card['action'] ?? '';
        if ($action === 'select_unit' || $action === 'select_club') {
            return true;
        }
    }
    return false;
}

// Returns what type of selection was last asked (unit or club)
function lastSelectionType($sessionId): ?string {
    $cards = getLastAssistantCards($sessionId);
    foreach ($cards as $card) {
        $action = $card['action'] ?? '';
        if ($action === 'select_unit') return 'unit';
        if ($action === 'select_club') return 'club';
    }
    return null;
}
