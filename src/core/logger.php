 <?php
$__api_start_time = microtime(true);

function maskdata($data){
    $json = json_decode($data, true);
    if (!$json) return $data;

    $keys = ['password','otp','token','access_token','pin','authorization'];

    foreach ($keys as $k) {
        if (isset($json[$k])) {
            $json[$k] = '***MASKED***';
        }
    }
    return json_encode($json);
}

function maskheaders($headers){
    $sensitive = ['authorization','x-api-key','token','access-token','cookie'];

    foreach ($headers as $key => $value) {
        if (in_array(strtolower($key), $sensitive)) {
            $headers[$key] = '***MASKED***';
        }
    }
    return $headers;
}

function api_log_response($response, $status = 200, $userId = null){
    global $__api_start_time;

    //$headers = getallheaders();
    $headers = maskheaders(getallheaders());
    
    $method  = $_SERVER['REQUEST_METHOD'];
    $ip      = $_SERVER['REMOTE_ADDR'] ?? '';
    $agent   = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $uri     = $_SERVER['REQUEST_URI'];

    //$body = file_get_contents("php://input");

    $body = maskdata(file_get_contents("php://input"));
    if (strlen($body) > 100000) {   // 100 KB
        $body = json_encode(["skipped" => "Payload too large"]);
    }
    $query = $_GET;

    $executionTime = round((microtime(true) - $__api_start_time) * 1000);
    
    $pdo = db();
    $stmt = $pdo->prepare(" INSERT INTO aiagent.api_logs
        (api_name, endpoint, request_method, request_headers, request_body, query_params,
         response_status, response_body, ip_address, user_agent, user_id, execution_time_ms)
        VALUES
        (:api, :endpoint, :method, :headers, :body, :query,
         :status, :response, :ip, :agent, :user_id, :time)
    ");

    $stmt->execute([
        ':api' => basename($_SERVER['SCRIPT_NAME']),
        ':endpoint' => $uri,
        ':method' => $method,
        ':headers' => json_encode($headers),
        ':body' => json_encode($body),
        ':query' => json_encode($query),
        ':status' => $status,
        ':response' => json_encode($response),
        ':ip' => $ip,
        ':agent' => $agent,
        ':user_id' => $userId,
        ':time' => $executionTime
    ]);
}