<?php
// stkpush.php – Initiate M-Pesa STK Push (FIXED VERSION)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- Database Configuration (Railway) ---
$host = getenv('DB_HOST') ?: 'mainline.proxy.rlwy.net';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: 'jPNMrNeqkNvQtnQNRKkeaMTsrcIkYfxj';
$database = getenv('DB_NAME') ?: 'railway';
$port = intval(getenv('DB_PORT') ?: 54048);

// --- M-Pesa Credentials ---
$consumerKey = getenv('MPESA_CONSUMER_KEY') ?: 'BqGXfPzkAS3Ada7JAV6jNcr26hKRmzVn';
$consumerSecret = getenv('MPESA_CONSUMER_SECRET') ?: 'NHfO1qmG1pMzBiVy';
$shortCode = getenv('MPESA_SHORTCODE') ?: '7887702';
$passkey = getenv('MPESA_PASSKEY') ?: '8ba2b74132b75970ed1d1ca22396f8b4eb79106902bf8e0017f4f0558fb6cc18';
$callbackUrl = getenv('MPESA_CALLBACK_URL') ?: 'https://mpesa-webhook-production.up.railway.app/callback.php';

// --- Logs ---
$logDir = __DIR__ . '/logs';
if (!file_exists($logDir)) mkdir($logDir, 0777, true);
$logFile = $logDir . '/stk_debug.log';

// --- Debug endpoint to view logs in browser ---
if (isset($_GET['debug']) && $_GET['debug'] == '1') {
    header('Content-Type: text/plain');
    echo file_exists($logFile) ? file_get_contents($logFile) : 'No log file found';
    exit;
}

// --- Input from frontend ---
$input = json_decode(file_get_contents('php://input'), true);
$amount = $input['amount'] ?? 1;
$phone = $input['phone'] ?? '';
$accountRef = $input['account'] ?? 'ALPHAPLUS';
$transactionDesc = $input['description'] ?? 'Payment';

// ✅ Sanitize phone number
$phone = preg_replace('/^0/', '254', $phone);
if (!preg_match('/^254\d{9}$/', $phone)) {
    respond(false, "Invalid phone number format. Use 07XXXXXXXX or 2547XXXXXXXX");
}

// --- Get access token ---
$accessToken = getAccessToken($consumerKey, $consumerSecret);
if (!$accessToken) {
    respond(false, "Failed to get access token from M-Pesa");
}

// --- Prepare STK Push request ---
$timestamp = date('YmdHis');
$password = base64_encode($shortCode . $passkey . $timestamp);

$request = [
    'BusinessShortCode' => $shortCode,
    'Password' => $password,
    'Timestamp' => $timestamp,
    'TransactionType' => 'CustomerPayBillOnline',
    'Amount' => $amount,
    'PartyA' => $phone,
    'PartyB' => $shortCode,
    'PhoneNumber' => $phone,
    'CallBackURL' => $callbackUrl,
    'AccountReference' => $accountRef,
    'TransactionDesc' => $transactionDesc
];

// --- Log request ---
file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] REQUEST: " . json_encode($request) . PHP_EOL, FILE_APPEND);

// --- Make STK Push request ---
$response = makeStkRequest($accessToken, $request);

// --- Log response ---
file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] RESPONSE: " . json_encode($response) . PHP_EOL, FILE_APPEND);

// --- Handle response ---
if (!$response || !isset($response['ResponseCode'])) {
    respond(false, "Invalid response from M-Pesa: " . json_encode($response));
}

if ($response['ResponseCode'] == '0') {
    // ✅ Save to database with CORRECT COLUMN NAMES (snake_case)
    $conn = new mysqli($host, $user, $password, $database, $port);
    if ($conn->connect_error) {
        respond(false, "DB Connection failed: " . $conn->connect_error);
    }

    $merchantRequestID = $response['MerchantRequestID'] ?? '';
    $checkoutRequestID = $response['CheckoutRequestID'] ?? '';
    $customerMessage = $response['CustomerMessage'] ?? 'Request accepted for processing';

    // ✅ FIXED: Use snake_case column names matching your schema
    $stmt = $conn->prepare("
        INSERT INTO mpesa_transactions 
        (merchant_request_id, checkout_request_id, result_code, result_desc, amount, phone_number, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");

    $resultCode = 0;
    $resultDesc = 'Request accepted for processing';
    $stmt->bind_param('ssisss', $merchantRequestID, $checkoutRequestID, $resultCode, $resultDesc, $amount, $phone);
    
    if (!$stmt->execute()) {
        file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] DB INSERT ERROR: " . $stmt->error . PHP_EOL, FILE_APPEND);
    } else {
        file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] DB INSERT SUCCESS: CheckoutRequestID=$checkoutRequestID" . PHP_EOL, FILE_APPEND);
    }
    
    $stmt->close();
    $conn->close();

    respond(true, $customerMessage, [
        'MerchantRequestID' => $merchantRequestID,
        'CheckoutRequestID' => $checkoutRequestID
    ]);
} else {
    respond(false, "M-Pesa Error: " . ($response['errorMessage'] ?? 'Unknown error'));
}

// --- Helper Functions ---
function getAccessToken($key, $secret) {
    $credentials = base64_encode("$key:$secret");
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials',
        CURLOPT_HTTPHEADER => ["Authorization: Basic $credentials"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    $result = curl_exec($curl);
    curl_close($curl);
    $json = json_decode($result, true);
    return $json['access_token'] ?? null;
}

function makeStkRequest($token, $data) {
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest',
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer $token"
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    $result = curl_exec($curl);
    curl_close($curl);
    return json_decode($result, true);
}

function respond($success, $message, $data = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}
?>