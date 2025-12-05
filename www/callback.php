<?php
// mpesa_callback.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include the centralized database connection
require_once 'config.php';

// =============================
// VB.NET API ENDPOINT
// =============================
$vbnetApiUrl = "https://alphaplusapi.onrender.com/api/Mpesa/update";

// =============================
// LOGGING HELPER
// =============================
function logMessage($msg)
{
    $ts = date('Y-m-d H:i:s');
    $line = "[$ts] $msg\n";
    file_put_contents('mpesa_callback.log', $line, FILE_APPEND | LOCK_EX);
    // Don't echo log lines to output as M-Pesa expects JSON
}

try {
    // =============================
    // READ INCOMING JSON
    // =============================
    $callbackJSON = file_get_contents('php://input');
    logMessage("📥 Received callback: $callbackJSON");

    if (empty($callbackJSON)) throw new Exception("No callback data received");

    $data = json_decode($callbackJSON, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON: " . json_last_error_msg());
    }

    if (!isset($data['Body']['stkCallback'])) throw new Exception("Missing stkCallback object");

    $cb = $data['Body']['stkCallback'];

    // =============================
    // EXTRACT FIELDS
    // =============================
    $MerchantRequestID = $cb['MerchantRequestID'] ?? '';
    $CheckoutRequestID = $cb['CheckoutRequestID'] ?? '';
    $ResultCode = $cb['ResultCode'] ?? '';
    $ResultDesc = $cb['ResultDesc'] ?? '';

    $Amount = 0.00;
    $MpesaReceiptNumber = '';
    $PhoneNumber = '';
    $TransactionDate = date('Y-m-d H:i:s'); // Default to now

    // Extract CallbackMetadata (only present on success ResultCode=0)
    if (isset($cb['CallbackMetadata']['Item'])) {
        foreach ($cb['CallbackMetadata']['Item'] as $item) {
            switch ($item['Name']) {
                case 'Amount':
                    $Amount = $item['Value'];
                    break;
                case 'MpesaReceiptNumber':
                    $MpesaReceiptNumber = $item['Value'];
                    break;
                case 'PhoneNumber':
                    $PhoneNumber = $item['Value'];
                    break;
                case 'TransactionDate':
                    // M-Pesa format: 20231115143022 (YYYYMMDDHHmmss)
                    $dateStr = (string)$item['Value'];
                    if (strlen($dateStr) == 14) {
                        $TransactionDate = date('Y-m-d H:i:s', strtotime(
                            substr($dateStr, 0, 4) . '-' .
                            substr($dateStr, 4, 2) . '-' .
                            substr($dateStr, 6, 2) . ' ' .
                            substr($dateStr, 8, 2) . ':' .
                            substr($dateStr, 10, 2) . ':' .
                            substr($dateStr, 12, 2)
                        ));
                    }
                    break;
            }
        }
    }

    logMessage("Parsed -> CheckoutRequestID: $CheckoutRequestID | ResultCode: $ResultCode | Amount: $Amount | Receipt: $MpesaReceiptNumber");

    // =============================
    // CHECK EXISTING TRANSACTION
    // =============================
    // Using PascalCase Column name CheckoutRequestID
    $stmt = $conn->prepare("SELECT id, ResultCode, MpesaReceiptNumber FROM mpesa_transactions WHERE CheckoutRequestID = ? LIMIT 1");
    $stmt->bind_param("s", $CheckoutRequestID);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    $message = "";

    if ($row) {
        // =============================
        // UPDATE EXISTING TRANSACTION
        // =============================
        logMessage("📝 Updating existing transaction ID: {$row['id']}");
        
        // Using PascalCase Column names for Update
        $upd = $conn->prepare("
            UPDATE mpesa_transactions 
            SET ResultCode = ?, 
                ResultDesc = ?, 
                Amount = IF(? > 0, ?, Amount), 
                MpesaReceiptNumber = IF(? != '', ?, MpesaReceiptNumber), 
                PhoneNumber = IF(? != '', ?, PhoneNumber), 
                TransactionDate = ?
            WHERE id = ?
        ");
        
        // Parameters: i (int), s (string), d (double), d, s, s, s, s, s, i
        $upd->bind_param("isdssssssi", 
            $ResultCode, 
            $ResultDesc, 
            $Amount, $Amount,
            $MpesaReceiptNumber, $MpesaReceiptNumber,
            $PhoneNumber, $PhoneNumber,
            $TransactionDate,
            $row['id']
        );
        
        if ($upd->execute()) {
            $message = "Transaction updated: ResultCode=$ResultCode";
            logMessage("✅ Updated transaction successfully");
        } else {
            logMessage("❌ Update failed: " . $upd->error);
        }
        $upd->close();
        
    } else {
        // =============================
        // INSERT NEW TRANSACTION
        // =============================
        logMessage("🆕 Inserting new transaction (Not found via CheckoutRequestID)");
        
        // Using PascalCase Column names (except created_at)
        $ins = $conn->prepare("
            INSERT INTO mpesa_transactions 
            (MerchantRequestID, CheckoutRequestID, ResultCode, ResultDesc, Amount, MpesaReceiptNumber, PhoneNumber, TransactionDate, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $ins->bind_param("ssisdsss", 
            $MerchantRequestID, 
            $CheckoutRequestID, 
            $ResultCode, 
            $ResultDesc, 
            $Amount, 
            $MpesaReceiptNumber, 
            $PhoneNumber, 
            $TransactionDate
        );
        
        if ($ins->execute()) {
            $message = "Transaction saved: ResultCode=$ResultCode";
            logMessage("✅ Inserted transaction successfully");
        } else {
            logMessage("❌ Insert failed: " . $ins->error);
        }
        $ins->close();
    }

    // =============================
    // FORWARD TO VB.NET API (Only on success)
    // =============================
    if ($ResultCode == 0 && !empty($MpesaReceiptNumber)) {
        try {
            $payload = [
                "CheckoutRequestID" => $CheckoutRequestID,
                "MpesaReceiptNumber" => $MpesaReceiptNumber,
                "ResultCode" => $ResultCode,
                "ResultDesc" => $ResultDesc,
                "StatusMessage" => $ResultDesc,
                "PhoneNumber" => $PhoneNumber,
                "Amount" => $Amount,
                "TransactionDate" => $TransactionDate
            ];

            $ch = curl_init($vbnetApiUrl);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $resp = curl_exec($ch);
            
            if (curl_errno($ch)) {
                logMessage("⚠️ CURL error forwarding to VB.NET: " . curl_error($ch));
            } else {
                logMessage("➡️ Forwarded to VB.NET API: Response=$resp");
            }
            curl_close($ch);
        } catch (Exception $ex) {
            logMessage("❌ ERROR forwarding to VB.NET API: " . $ex->getMessage());
        }
    }

    // =============================
    // SUCCESS RESPONSE
    // =============================
    header('Content-Type: application/json');
    echo json_encode([
        'ResultCode' => 0,
        'ResultDesc' => 'Callback processed successfully',
        'Status' => $message
    ]);

} catch (Exception $e) {
    // =============================
    // ERROR HANDLER
    // =============================
    logMessage("❌ ERROR: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode([
        'ResultCode' => 1,
        'ResultDesc' => 'Callback received but processing failed',
        'Error' => $e->getMessage()
    ]);
}
?>