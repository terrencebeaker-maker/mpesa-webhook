<?php
// check_status.php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

// Include the centralized database connection
require_once 'config.php';

// Get CheckoutRequestID from query parameter
$checkoutRequestID = $_GET['checkout_request_id'] ?? '';

if (empty($checkoutRequestID)) {
    echo json_encode([
        "success" => false,
        "message" => "CheckoutRequestID is required",
        "resultCode" => null,
        "resultDesc" => null
    ]);
    exit();
}

try {
    // Query the transaction using PascalCase columns
    $stmt = $conn->prepare("
        SELECT 
            CheckoutRequestID,
            MerchantRequestID,
            ResultCode,
            ResultDesc,
            Amount,
            MpesaReceiptNumber,
            PhoneNumber,
            TransactionDate,
            created_at
        FROM mpesa_transactions 
        WHERE CheckoutRequestID = ? 
        ORDER BY id DESC 
        LIMIT 1
    ");
    
    $stmt->bind_param("s", $checkoutRequestID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Transaction found
        echo json_encode([
            "success" => true,
            "message" => "Transaction found",
            "resultCode" => $row['ResultCode'],
            "resultDesc" => $row['ResultDesc'],
            "checkoutRequestID" => $row['CheckoutRequestID'],
            "merchantRequestID" => $row['MerchantRequestID'],
            "amount" => floatval($row['Amount'] ?? 0),
            "mpesaReceiptNumber" => $row['MpesaReceiptNumber'],
            "phoneNumber" => $row['PhoneNumber'],
            "transactionDate" => $row['TransactionDate']
        ]);
    } else {
        // Transaction not found yet
        echo json_encode([
            "success" => false,
            "message" => "Transaction not found",
            "resultCode" => null,
            "resultDesc" => "Pending - awaiting callback",
            "checkoutRequestID" => $checkoutRequestID
        ]);
    }
    
    $stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Error: " . $e->getMessage(),
        "resultCode" => null,
        "resultDesc" => null
    ]);
}
?>