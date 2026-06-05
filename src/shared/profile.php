<?php

/**
 * Returns the full resident profile for a given code (user identifier).
 * In production this would query the database; currently returns mock data.
 */

function getFirstName($fullName) {
    $fullName = trim($fullName);
    $parts = explode(" ", $fullName);
    return $parts[0] ?? '';
}

function getBearerToken() {
    $headers = function_exists('getallheaders') ? getallheaders() : [];

    // Normalize header keys
    $headers = array_change_key_case($headers, CASE_LOWER);

    $authHeader = $headers['authorization'] 
        ?? $_SERVER['HTTP_AUTHORIZATION'] 
        ?? null;

    if (!$authHeader) return null;

    // Extract token using regex
    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        return $matches[1];
    }

    return null;
}

function getProfile(array $params):array{
    $code = $params["code"] ?? "";
    $response = array();

    $token = getBearerToken();

    $url = "https://app.hcomm.in/api/v1/default/profile/summery";
    $ch = curl_init($url);
      curl_setopt_array($ch, [
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_POST => true,
          CURLOPT_POSTFIELDS => json_encode($params),
          CURLOPT_HTTPHEADER => [
              'Content-Type: application/json',
              'Authorization: Bearer ' . $token,
          ],
          CURLOPT_TIMEOUT => 30,
      ]);

      $response = curl_exec($ch);
      if ($response === false) {
          $response = array();
      } else {
          $response = json_decode($response, true);
      }

      curl_close($ch);

        /* $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM public.users WHERE code = :code LIMIT 1');
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            response_error(404, 'User not found');
            exit;
        }
        $userproperties = get_user_properties($row['code']);
        $response = $row;
        $response["units"] = $userproperties; */
    return $response["data"] ?? [];
}

/*
function getUserProfile($code = null): array {
    $userId = $code ?: null;
    return [
        "user_id"     => $userId,
        "society_id"  => null,
        "property_id" => null,
        "name"        => "Yogesh Darge",
        "complaints"  => [
            [
                "id"       => "C1256",
                "category" => "Civil",
                "type"     => "Complaint",
                "tag"      => "open",
                "unit"     => "A1308",
                "building" => "Regent Hill",
                "details"  => "Leakage from celling and the walls, Cracks over celling and walls",
                "status"   => "processing",
                "task"     => [
                    [
                        "id"      => "1",
                        "date"    => "2026-03-09 17:06:47.036112+05:30",
                        "task"    => "scheduled",
                        "details" => "Request Resolved"
                    ],
                    [
                        "id"      => "1",
                        "date"    => "2026-03-07 17:06:47.036112+05:30",
                        "task"    => "initial",
                        "details" => "Request Registered"
                    ]
                ]
            ]
        ],
        "units" => [
            [
                "id"       => "E2204",
                "title"    => "E2204",
                "description" => "Regent Hill",
                "icon"     => "img/icon-building-pinned.svg",
            ],
            [
                "id"       => "801",
                "title"    => "801",
                "description" => "Zenia",
                "icon"     => "img/icon-building-pinned.svg",
            ],
            [
                "id"       => "A1308",
                "title"    => "A1308",
                "description" => "Regent Hill",
                "icon"     => "img/icon-building.svg",
            ]
        ],
        "devices" => [
            "E2204" => [
                [
                    "id"       => "cam_1",
                    "name"     => "Main Door Camera",
                    "type"     => "camera",
                    "category" => "Electrical",
                    "keywords" => ["camera", "door camera", "cctv"]
                ],
                [
                    "id"       => "ac_1",
                    "name"     => "Living Room AC",
                    "type"     => "ac",
                    "category" => "Appliance",
                    "keywords" => ["ac", "air conditioner"]
                ]
            ],
            "801" => [
                [
                    "id"       => "cam_1",
                    "name"     => "Main Door Camera",
                    "type"     => "camera",
                    "category" => "Electrical",
                    "keywords" => ["camera", "door camera", "cctv"]
                ],
                [
                    "id"       => "ac_1",
                    "name"     => "Living Room AC",
                    "type"     => "ac",
                    "category" => "Appliance",
                    "keywords" => ["ac", "air conditioner"]
                ]
            ],
            "A1308" => [
                [
                    "id"       => "plumbing_1",
                    "name"     => "Plumbing",
                    "type"     => "civil",
                    "category" => "Plumbing",
                    "keywords" => ["plumbing", "pipes", "water leakage"]
                ],
                [
                    "id"       => "ac_1",
                    "name"     => "Living Room AC",
                    "type"     => "ac",
                    "category" => "Appliance",
                    "keywords" => ["ac", "air conditioner"]
                ]
            ]
        ],
        "clubs" => [
            [
                "id"          => "1204",
                "title"       => "Forest Club",
                "description" => "Cliff Ave, Hiranandani Gardens, Powai, Mumbai",
                "icon"     => "img/icon-building.svg",
            ],
            [
                "id"          => "1205",
                "title"       => "Eden Club",
                "description" => "Cliff Ave, Hiranandani Gardens, Powai, Mumbai",
                "icon"     => "img/icon-building.svg",
            ]
        ]
    ];
}

*/

function getUserProfile($code = null): array {
    static $cache = [];

    $params = [];
    if (is_array($code)) {
        $params = $code;
        $code = (string)($params['code'] ?? '');
    } else {
        $code = $code !== null ? (string)$code : '';
        $params = $code !== '' ? ['code' => $code] : [];
    }

    $cacheKey = $code !== '' ? $code : md5(json_encode($params));
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $profile = getProfile($params);
    if (!is_array($profile)) {
        $profile = [];
    }

    $cache[$cacheKey] = $profile;
    return $profile;
}
