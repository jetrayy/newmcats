<?php
include 'db.php';
$conn->query("ALTER TABLE inventory MODIFY COLUMN bought_price VARCHAR(50) NULL");
$r = $conn->query("DESCRIBE inventory");
while($row = $r->fetch_assoc()) {
    print_r($row);
}
@unlink(__FILE__);
?>
