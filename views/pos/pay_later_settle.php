<?php
session_start();
include_once __DIR__ . '/../../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php");
    exit();
}

$bill_id = isset($_POST['bill_id']) ? intval($_POST['bill_id']) : 0;
$payment_amount = isset($_POST['payment_amount']) ? floatval($_POST['payment_amount']) : 0;
$payment_method = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : 'Cash';
$payment_notes = isset($_POST['payment_notes']) ? trim($_POST['payment_notes']) : '';
$redirect_to = isset($_POST['redirect_to']) ? trim($_POST['redirect_to']) : '';

if ($bill_id <= 0 || $payment_amount <= 0) {
    header("Location: " . ($redirect_to ?: "pay_later.php"));
    exit();
}

// Fetch transaction
$stmt = $conn->prepare("SELECT * FROM transactions WHERE bill_number = ?");
$stmt->bind_param("i", $bill_id);
$stmt->execute();
$tx = $stmt->get_result()->fetch_assoc();

if (!$tx) {
    die("Transaction not found.");
}

$conn->begin_transaction();
try {
    $prev_received = floatval($tx['received_amount']);
    $total_fee = floatval($tx['total_lkr']);
    $new_received = $prev_received + $payment_amount;
    $new_balance = $new_received - $total_fee;

    $date_str = date('M j, Y');
    $log_entry = "Payment of Rs. " . number_format($payment_amount, 2) . " received via " . $payment_method . " on " . $date_str . ($payment_notes ? " (" . $payment_notes . ")" : "");
    $existing_notes = trim($tx['notes'] ?? '');
    $updated_notes = $existing_notes ? ($existing_notes . " | " . $log_entry) : $log_entry;

    // Record into pay_later_payments table
    $p_stmt = $conn->prepare("INSERT INTO pay_later_payments (bill_number, amount, payment_method, payment_date, notes) VALUES (?, ?, ?, NOW(), ?)");
    $p_stmt->bind_param("idss", $bill_id, $payment_amount, $payment_method, $payment_notes);
    $p_stmt->execute();

    // Check if fully settled
    $new_status = ($new_received >= $total_fee) ? 'returned' : 'pay_later';

    $u_stmt = $conn->prepare("UPDATE transactions SET received_amount = ?, balance_amount = ?, notes = ?, status = ? WHERE bill_number = ?");
    $u_stmt->bind_param("ddssi", $new_received, $new_balance, $updated_notes, $new_status, $bill_id);
    $u_stmt->execute();

    $conn->commit();

    $is_fully_paid = ($new_received >= $total_fee);

    if ($redirect_to && strpos($redirect_to, 'print_bill') === false) {
        $sep = (strpos($redirect_to, '?') !== false) ? '&' : '?';
        header("Location: {$redirect_to}{$sep}payment_success=1&bill_id={$bill_id}&paid={$payment_amount}&paid_all=" . ($is_fully_paid ? '1' : '0'));
        exit();
    }

    // Default redirect to A4 print bill
    header("Location: print_bill.php?type=a4&bill_id={$bill_id}&payment_success=1&paid={$payment_amount}&paid_all=" . ($is_fully_paid ? '1' : '0'));
    exit();

} catch (Exception $e) {
    $conn->rollback();
    die("Settlement error: " . $e->getMessage());
}
