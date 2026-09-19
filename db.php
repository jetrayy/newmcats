<?php
date_default_timezone_set('Asia/Colombo');
$servername = "localhost";
$username = "root";
$password = ""; 
$dbname = "mcats"; // Updated name

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Enable full UTF-8 (utf8mb4) for Sinhala and other Unicode characters
$conn->set_charset("utf8mb4");
?>
