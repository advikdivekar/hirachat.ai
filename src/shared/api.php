<?php

/**
 * shared/api.php
 * Central place for all outbound API calls.
 * Add new endpoint functions here as the system grows.
 */

function getCloudImg(?string $publicid, string $type = "profile"): string {

    if(empty($publicid) || $publicid == null){
      return "";
    }

    $bucketurl = "https://res.cloudinary.com/hiranandani/image/upload/";
    
    switch ($type) {
        case 'thumb':
            return "$bucketurl/ar_1:1,c_fill,q_75,g_auto,h_150/$publicid.webp";
            break;
        default:
            return "$bucketurl/$publicid.jpg";
            break;
      }
    }

function generateImage(array $params):array{
    set_time_limit(120);

    $response = array();
    $response = $params;
    $prompt = "
        Create a single composite image (1500px width x 500px height) divided into three equal vertical sections (each 500x500).
        Vehicle details:
        - Type: Motorcycle / Scooter (2WN)
        - Model: Bajaj Pulsar 180
        - Color: Red
        - Fuel: Petrol
        - Number Plate: MH04EE3264

        Layout instructions:
        - Left section (500x500): Top view of the motorcycle (clear, centered, full vehicle visible)
        - Middle section (500x500): Front view of the motorcycle (symmetrical, headlight and handle clearly visible)
        - Right section (500x500): Front-side perspective view (3D angle showing depth and side profile)

        Design requirements:
        - Plain pure white background (#FFFFFF)
        - Add a soft, realistic light shadow under the vehicle in each section
        - Keep lighting consistent across all three views
        - Each view must be fully contained within its 500x500 area with no overlap
        - Maintain consistent scale, alignment, and proportions across all sections
        - Realistic rendering style (high detail, not cartoon)

        Goal:
        The final image must be cleanly divisible into three equal 500x500 images:
        - Left crop → Top view
        - Middle crop → Front view
        - Right crop → Perspective view

        Output:
        One single 1500x500 image containing all three views of the same motorcycle.";

    $client = openai();

    return $response;
}


function callApi(string $endpoint, array $payload): array {
    $baseUrl = 'https://app.hcomm.in/api/v1';

    $token = getBearerToken();
    $ch = curl_init("{$baseUrl}/{$endpoint}");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_TIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error || $httpCode >= 500) {
        return ['success' => false, 'message' => 'Service unavailable. Please try again.'];
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'message' => 'Invalid response.'];
}

// ─────────────────────────────────────────────
// BOOKING API
// ─────────────────────────────────────────────

function confirmBookingApi(array $data): array {
    return callApi('mcp/clubs/booking/create', [
        'amenity_id' => $data['amenity_id'] ?? null,
        'amenity'    => $data['amenity']    ?? null,
        'date'       => $data['date']       ?? null,
        'timeslot'   => $data['timeslot']   ?? null,
        'court'      => $data['court']      ?? null,
        'unit_id'    => $data['unit_id']    ?? null,
        'club_id'    => $data['club_id']    ?? null,
    ]);
}

// ─────────────────────────────────────────────
// SERVICE REQUEST API
// ─────────────────────────────────────────────

function confirmServiceRequestApi(array $data): array {
    return callApi('service-requests/create', [
        'unit_id'   => $data['unit_id']   ?? null,
        'device_id' => $data['device_id'] ?? null,
        'issue'     => $data['issue']     ?? null,
        'category'  => $data['category']  ?? 'General',
        'priority'  => $data['priority']  ?? 'Medium',
    ]);
}

// ── COMPLAINT MANAGEMENT API ──────────────────────────────────

function closeComplaintApi(array $data): array {
    return callApi('complaints/close', [
        'complaint_id' => $data['complaint_id'] ?? null,
        'code'         => $data['code']         ?? null,
    ]);
}

function reopenComplaintApi(array $data): array {
    return callApi('complaints/reopen', [
        'complaint_id' => $data['complaint_id'] ?? null,
        'code'         => $data['code']         ?? null,
    ]);
}