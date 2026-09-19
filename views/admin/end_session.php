<?php
session_start();
include_once __DIR__ . '/../../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'super_admin') {
    header("Location: ../../index.php");
    exit();
}

if (isset($_SESSION['active_session_id'])) {
    $session_id = $_SESSION['active_session_id'];
    $stmt = $conn->prepare("UPDATE cash_sessions SET status='closed', closed_at=CURRENT_TIMESTAMP WHERE id=?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    unset($_SESSION['active_session_id']);
}

header("Location: ../../logout.php");
exit();
?>
