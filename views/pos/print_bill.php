<?php
session_start();
include_once __DIR__ . '/../../db.php';

// Allow any logged-in user
if (!isset($_SESSION['user_id'])) {
    die("Access Denied.");
}

$bill_id = isset($_GET['bill_id']) ? intval($_GET['bill_id']) : 0;
$type = isset($_GET['type']) ? $_GET['type'] : 'thermal';

if ($bill_id <= 0) {
    die("Invalid Bill ID.");
}

// Fetch Transaction
$stmt = $conn->prepare("SELECT * FROM transactions WHERE bill_number = ?");
$stmt->bind_param("i", $bill_id);
$stmt->execute();
$txn = $stmt->get_result()->fetch_assoc();

if (!$txn) {
    die("Bill not found.");
}

// Fetch Items
$items_stmt = $conn->prepare("
    SELECT ti.*, i.item_name 
    FROM transaction_items ti 
    JOIN inventory i ON ti.item_id = i.item_id 
    WHERE ti.bill_number = ?
");
$items_stmt->bind_param("i", $bill_id);
$items_stmt->execute();
$items = $items_stmt->get_result();

$date = date('Y-m-d H:i:A', strtotime($txn['transaction_date']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MCATS | Invoice #<?php echo $bill_id; ?></title>
    <link rel="icon" type="image/x-icon" href="../../img/ico.ico">
    <!-- Instant theme apply — prevents flash -->
    <script>document.documentElement.setAttribute('data-bs-theme', localStorage.getItem('theme') || 'light');</script>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Luxury Core CSS -->
    <link rel="stylesheet" href="../../css/luxury.css">
    
    <style>
        body { background: #0c101c; font-family: 'Plus Jakarta Sans', sans-serif; display: flex; flex-direction: column; min-height: 100vh; }
        
        .print-controls {
            background: #070a12;
            border-bottom: 1px solid rgba(212, 175, 55, 0.25);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow-luxury);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .receipt-container { flex: 1; display: flex; justify-content: center; align-items: flex-start; padding: 35px 15px; }

        /* Thermal Receipt Style (80mm) */
        .thermal-receipt {
            background: #ffffff;
            width: 320px;
            padding: 24px;
            border-radius: 6px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
            font-family: 'JetBrains Mono', 'Courier New', monospace;
            font-size: 13px;
            color: #000;
            line-height: 1.4;
        }
        .thermal-receipt h2 { text-align: center; margin: 0 0 6px 0; font-size: 17px; text-transform: uppercase; font-weight: 800; }
        .thermal-receipt .info { text-align: center; margin-bottom: 14px; border-bottom: 1px dashed #333; padding-bottom: 10px; font-size: 11px; }
        .thermal-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .thermal-table th, .thermal-table td { text-align: left; padding: 5px 0; border-bottom: 1px dashed #ccc; color: #000; }
        .thermal-table .right { text-align: right; }
        .thermal-total { font-weight: 700; font-size: 14px; text-align: right; border-top: 2px dashed #000; padding-top: 10px; margin-top: 10px; line-height: 1.6; }
        .thermal-footer { text-align: center; margin-top: 20px; font-size: 11px; border-top: 1px dashed #333; padding-top: 12px; }

        /* A4 Receipt Style */
        .a4-receipt {
            background: #ffffff;
            width: 210mm;
            min-height: 297mm;
            padding: 20mm;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.4);
            border-radius: 8px;
            font-family: 'Plus Jakarta Sans', Arial, sans-serif;
            box-sizing: border-box;
            color: #111827;
        }
        
        @media print {
            body { background: white !important; margin: 0; padding: 0; display: block; }
            .print-controls { display: none !important; }
            .receipt-container { padding: 0; justify-content: flex-start; }
            .thermal-receipt { margin: 0; box-shadow: none; border-radius: 0; width: 100%; }
            .a4-receipt { margin: 0; box-shadow: none; border-radius: 0; width: 100%; min-height: auto; padding: 12mm; }
        }
    </style>
</head>
<body>

    <div class="print-controls">
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center rounded-circle" style="width: 38px; height: 38px; background: rgba(212, 175, 55, 0.15); border: 1px solid rgba(212, 175, 55, 0.3);">
                <i class="fa-solid fa-file-invoice" style="color: var(--gold-primary);"></i>
            </div>
            <div>
                <h5 class="mb-0 fw-bold text-white" style="font-family: 'Outfit', sans-serif; letter-spacing: 0.04em;">Official Billing Record</h5>
                <small class="text-secondary">Docket #<?php echo $bill_id; ?> &bull; Channel: <?php echo ($txn['type'] === 'selling') ? 'Selling' : 'POS'; ?></small>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button onclick="window.print()" class="btn btn-luxury-gold fw-bold rounded-pill px-4 shadow-sm">
                <i class="fa-solid fa-print me-2"></i> Print Document
            </button>
            <?php 
                $back_url = ($txn['type'] === 'selling') ? 'sell.php' : 'rent.php';
                $back_label = ($txn['type'] === 'selling') ? 'Back to Selling' : 'Back to POS';
            ?>
            <a href="<?php echo $back_url; ?>" class="btn btn-outline-secondary text-white rounded-pill px-3 shadow-sm border-secondary fw-semibold">
                <i class="fa-solid fa-arrow-left me-1"></i> <?php echo $back_label; ?>
            </a>
        </div>
    </div>

    <div class="receipt-container">
        <?php if ($type === 'thermal'): ?>
            <!-- THERMAL RECEIPT (Selling) -->
            <div class="thermal-receipt">
                <h2>Mahinda Constructions<br>& ToolShop</h2>
                <div class="info">
                    140/2, Kandy Road, Rikillagaskada<br>
                    Hotline: 072-2097483<br>
                    Timestamp: <?php echo $date; ?><br>
                    Invoice No: #<?php echo str_pad($bill_id, 6, '0', STR_PAD_LEFT); ?>
                </div>
                
                <table class="thermal-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="right">Qty</th>
                            <th class="right">Price</th>
                            <th class="right">Amt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $items->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(substr($row['item_name'], 0, 16)); ?><?php if(strlen($row['item_name'])>16) echo '..'; ?></td>
                            <td class="right"><?php echo $row['quantity']; ?></td>
                            <td class="right"><?php echo number_format($row['unit_price'], 2); ?></td>
                            <td class="right"><?php echo number_format($row['unit_price'] * $row['quantity'], 2); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                
                <div class="thermal-total">
                    NET TOTAL : Rs. <?php echo number_format($txn['total_lkr'], 2); ?><br>
                    CASH PAID : Rs. <?php echo number_format($txn['received_amount'], 2); ?><br>
                    CHANGE    : Rs. <?php echo number_format($txn['balance_amount'], 2); ?>
                </div>
                
                <div class="thermal-footer">
                    Thank you for choosing Mahinda Constructions!<br>
                    * Merchandise once sold is non-returnable.<br>
                    System Generated via MCATS Enterprise
                </div>
            </div>

        <?php else: ?>
            <!-- A4 RECEIPT (POS) -->
            <div class="a4-receipt position-relative d-flex flex-column">
                <div class="d-flex justify-content-between align-items-end border-bottom border-dark border-2 pb-3 mb-4">
                    <div>
                        <h1 class="display-6 fw-bold mb-1" style="font-family: 'Outfit', sans-serif; color: #0b1120;">Mahinda Constructions</h1>
                        <h5 class="fw-semibold text-secondary mb-2">& Heavy Tool Lease System</h5>
                        <p class="mb-0 text-dark small">140/2, Kandy Road, Rikillagaskada</p>
                        <p class="mb-0 text-dark small">Hotline: 072-2097483 | Support Desk: 081-2244550</p>
                    </div>
                    <div class="text-end">
                        <span class="badge text-uppercase mb-1" style="background: rgba(212, 175, 55, 0.15); color: #8c6d1f; font-size: 0.75rem; letter-spacing: 0.1em; font-weight: 700;">Equipment Lease Agreement</span>
                        <h2 class="text-uppercase tracking-wide mb-0 fw-bold" style="letter-spacing: 2px; color: #0b1120;">Invoice</h2>
                        <p class="fs-5 fw-bold text-dark mb-0 font-monospace">#<?php echo str_pad($bill_id, 6, '0', STR_PAD_LEFT); ?></p>
                    </div>
                </div>
                
                <div class="row mb-4 text-dark">
                    <div class="col-7">
                        <div class="fw-bold text-uppercase small text-secondary mb-1" style="font-size: 0.72rem; letter-spacing: 0.06em;">Lessee / Client Information</div>
                        <div class="fs-6 fw-bold"><?php echo htmlspecialchars($txn['customer_nic'] ? 'Client Ref: ' . $txn['customer_nic'] : 'Walk-in Customer / Registered Account'); ?></div>
                        <div class="text-secondary small">Authorized rental agreement on file under MCATS POS.</div>
                    </div>
                    <div class="col-5 text-end">
                        <div class="mb-1"><span class="fw-bold text-secondary text-uppercase small me-2">Date & Time:</span> <span class="fw-semibold"><?php echo $date; ?></span></div>
                        <div><span class="fw-bold text-secondary text-uppercase small me-2">Channel:</span> <span class="badge bg-dark">POS Rental</span></div>
                    </div>
                </div>
                
                <table class="table border-top mb-4">
                    <thead class="table-light text-dark">
                        <tr>
                            <th class="py-3 border-bottom border-secondary border-2 small text-uppercase fw-bold">Equipment Description</th>
                            <th class="py-3 text-center border-bottom border-secondary border-2 small text-uppercase fw-bold">Quantity</th>
                            <th class="py-3 text-end border-bottom border-secondary border-2 small text-uppercase fw-bold">Daily Rate (LKR)</th>
                            <th class="py-3 text-end border-bottom border-secondary border-2 small text-uppercase fw-bold">Amount (LKR)</th>
                        </tr>
                    </thead>
                    <tbody class="text-dark">
                        <?php $items->data_seek(0); ?>
                        <?php while($row = $items->fetch_assoc()): ?>
                        <tr>
                            <td class="py-3 fw-bold"><?php echo htmlspecialchars($row['item_name']); ?></td>
                            <td class="py-3 text-center"><?php echo $row['quantity']; ?></td>
                            <td class="py-3 text-end"><?php echo number_format($row['unit_price'], 2); ?></td>
                            <td class="py-3 text-end fw-bold"><?php echo number_format($row['unit_price'] * $row['quantity'], 2); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                
                <div class="row mb-4">
                    <div class="col-6 offset-6">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="fw-bold text-secondary text-uppercase small">Total Valuation / Charge:</span>
                            <span class="fs-6 fw-bold text-dark">LKR <?php echo number_format($txn['total_lkr'], 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 pb-2 border-bottom">
                            <span class="fw-bold text-secondary text-uppercase small">Total Deposited / Received:</span>
                            <span class="fs-6 fw-bold text-dark">LKR <?php echo number_format($txn['received_amount'], 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="fw-bold text-secondary text-uppercase small">Balance / Change Remitted:</span>
                            <span class="fs-5 fw-bold" style="color: #10b981;">LKR <?php echo number_format($txn['balance_amount'], 2); ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="p-4 rounded-3 border-start border-4 border-warning mb-5 mt-auto" style="background: #fdfaf3;">
                    <h6 class="fw-bold mb-2 text-dark" style="font-size: 0.88rem;">Terms & Conditions of Equipment Hire:</h6>
                    <ol class="mb-0 small text-secondary ps-3" style="font-size: 0.8rem; line-height: 1.5;">
                        <li class="mb-1">All machinery and tools must be returned in full operational and clean condition.</li>
                        <li class="mb-1">Calculated days run from dispatch timestamp to physical handover check at the return desk.</li>
                        <li class="mb-1">Lessee bears sole financial and legal liability for broken parts, burnt motors, or misplaced accessories.</li>
                        <li>Security advances are settled against actual operational days upon return inspection.</li>
                    </ol>
                </div>
                
                <div class="row mt-auto pt-4 text-center text-dark">
                    <div class="col-4 offset-1">
                        <div class="border-top border-dark pt-2 fw-bold small">Authorized Store Officer</div>
                    </div>
                    <div class="col-4 offset-2">
                        <div class="border-top border-dark pt-2 fw-bold small">Customer / Renter Acknowledgement</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
