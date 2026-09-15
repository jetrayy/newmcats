<?php
include 'db.php';
$tables = ['inventory', 'transactions', 'transaction_items', 'customers'];
foreach ($tables as $table) {
    echo "TABLE: $table\n";
    $result = $conn->query("DESCRIBE $table");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
        }
    } else {
        echo "Table does not exist or error.\n";
    }
    echo "\n";
}
?>
