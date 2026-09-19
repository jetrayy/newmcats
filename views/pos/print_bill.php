<?php
session_start();
include_once __DIR__ . '/../../db.php';

// Allow any logged-in user
if (!isset($_SESSION['user_id'])) {
    die("Access Denied.");
}

$bill_id = isset($_GET['bill_id']) ? intval($_GET['bill_id']) : 0;
$type = isset($_GET['type']) ? $_GET['type'] : '';

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

// Fetch Customer if customer_nic is present
$customer = null;
if (!empty($txn['customer_nic'])) {
    $c_stmt = $conn->prepare("SELECT * FROM customers WHERE nic_number = ?");
    $c_stmt->bind_param("s", $txn['customer_nic']);
    $c_stmt->execute();
    $customer = $c_stmt->get_result()->fetch_assoc();
}

// Default type if not explicitly set
if (empty($type)) {
    if ($txn['type'] === 'selling') {
        $type = 'thermal';
    } elseif ($txn['status'] === 'pay_later') {
        $type = 'thermal_pay_later';
    } elseif ($txn['status'] === 'returned') {
        $type = 'a4';
    } else {
        $type = 'thermal_rent';
    }
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
$items_res = $items_stmt->get_result();
$items = [];
while ($it = $items_res->fetch_assoc()) {
    $items[] = $it;
}

$date_formatted = date('M j, Y', strtotime($txn['transaction_date'])) . ' - ' . date('g:i A', strtotime($txn['transaction_date']));

$is_selling = ($txn['type'] === 'selling');
$is_pay_later = ($txn['status'] === 'pay_later');
$is_returned = ($txn['status'] === 'returned' || $is_pay_later);

$client_name = $customer ? $customer['full_name'] : ($txn['customer_nic'] ?: 'Walk-in Customer');
$client_phone = $customer ? $customer['phone_number'] : '';
$client_address = $customer ? $customer['address'] : '';

$total_charge = floatval($txn['total_lkr']);
$total_received = floatval($txn['received_amount']);
$a4_pending = max(0, $total_charge - $total_received);
$paid_all = isset($_GET['paid_all']) && $_GET['paid_all'] === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MCATS | Invoice #<?php echo $bill_id; ?></title>
    <link rel="icon" type="image/x-icon" href="../../img/ico.ico">
    <!-- Instant theme apply - prevents flash -->
    <script>
        (function() {
            var t = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-bs-theme', t);
        })();
    </script>
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
            width: 340px;
            max-width: 100%;
            padding: 22px 18px;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
            font-family: 'JetBrains Mono', 'Courier New', monospace;
            font-size: 12.5px;
            color: #000;
            line-height: 1.4;
            box-sizing: border-box;
        }
        .thermal-receipt h2 { text-align: center; margin: 0 0 4px 0; font-size: 16px; text-transform: uppercase; font-weight: 800; letter-spacing: 0.02em; }
        .thermal-receipt .info { text-align: center; margin-bottom: 12px; border-bottom: 1px dashed #000; padding-bottom: 10px; font-size: 11px; line-height: 1.5; }
        
        .thermal-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; table-layout: fixed; }
        .thermal-table th {
            text-align: left;
            padding: 6px 4px;
            border-bottom: 1px dashed #000;
            color: #000;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .thermal-table th.right, .thermal-table td.right { text-align: right; }
        .thermal-table th.center, .thermal-table td.center { text-align: center; }

        .thermal-table tr.item-name-row td {
            padding: 7px 4px 2px 4px;
            font-weight: 700;
            font-size: 12px;
            color: #000;
            word-break: break-word;
            line-height: 1.35;
        }
        .thermal-table tr.item-meta-row td {
            padding: 1px 4px 7px 4px;
            font-size: 11.5px;
            color: #111;
            border-bottom: 1px dotted #ccc;
        }
        .thermal-table tr.item-meta-row:last-child td {
            border-bottom: none;
        }

        .thermal-totals-box {
            border-top: 2px dashed #000;
            padding-top: 8px;
            margin-top: 8px;
            font-size: 12px;
            line-height: 1.6;
        }
        .thermal-totals-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 2px 2px;
        }
        .thermal-totals-row.grand-total {
            font-size: 14px;
            font-weight: 800;
            border-top: 1px dashed #000;
            border-bottom: 1px dashed #000;
            padding: 6px 2px;
            margin: 4px 0;
        }
        .thermal-totals-row.discount {
            color: #b91c1c;
            font-weight: 700;
        }
        .thermal-complimentary {
            margin-top: 8px;
            padding: 6px 8px;
            background: #f4f4f5;
            border-radius: 4px;
            font-size: 11px;
            line-height: 1.4;
            border-left: 3px solid #000;
        }
        .thermal-footer {
            text-align: center;
            margin-top: 16px;
            font-size: 11px;
            border-top: 1px dashed #000;
            padding-top: 12px;
            line-height: 1.5;
        }

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
                <small class="text-secondary">Docket #<?php echo $bill_id; ?> &bull; Channel: <?php echo ($txn['type'] === 'selling') ? 'Selling' : ($txn['status'] === 'returned' ? 'Rental Settlement' : 'Rental Dispatch'); ?></small>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if ($txn['type'] === 'renting'): ?>
                <?php if ($type === 'thermal_rent' || $type === 'thermal_pay_later' || $type === 'thermal'): ?>
                    <a href="print_bill.php?type=a4&bill_id=<?php echo $bill_id; ?>" class="btn btn-outline-warning btn-sm rounded-pill px-3 shadow-sm fw-semibold">
                        <i class="fa-solid fa-file-lines me-1"></i> Switch to A4 Sheet
                    </a>
                <?php else: ?>
                    <a href="print_bill.php?type=<?php echo $txn['status'] === 'pay_later' ? 'thermal_pay_later' : 'thermal_rent'; ?>&bill_id=<?php echo $bill_id; ?>" class="btn btn-outline-warning btn-sm rounded-pill px-3 shadow-sm fw-semibold">
                        <i class="fa-solid fa-receipt me-1"></i> Switch to Thermal Slip
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <button onclick="window.print()" class="btn btn-luxury-gold fw-bold rounded-pill px-4 shadow-sm">
                <i class="fa-solid fa-print me-2"></i> Print Document
            </button>

            <?php 
                $back_url = 'rent.php';
                $back_label = 'Back to Rentals';
                if ($txn['type'] === 'selling') {
                    $back_url = 'sell.php';
                    $back_label = 'Back to Selling';
                } elseif ($txn['status'] === 'pay_later') {
                    $back_url = 'pay_later.php';
                    $back_label = 'Pay Later Directory';
                } elseif ($txn['status'] === 'returned') {
                    $back_url = 'return_items.php';
                    $back_label = 'Back to Returns';
                }
            ?>
            <?php if ($txn['status'] === 'pay_later'): ?>
                <a href="pay_later.php" class="btn btn-warning btn-sm fw-bold rounded-pill px-3 shadow-sm text-dark">
                    <i class="fa-solid fa-clock-rotate-left me-1"></i> Pay Later Desk
                </a>
            <?php endif; ?>
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
                    Hotline: 072-2097483 | 081-2244550<br>
                    <strong>CASH SALES RECEIPT</strong><br>
                    Timestamp: <?php echo $date_formatted; ?><br>
                    Invoice No: #<?php echo str_pad($bill_id, 6, '0', STR_PAD_LEFT); ?>
                </div>
                
                <table class="thermal-table">
                    <thead>
                        <tr>
                            <th style="width: 32%;">Item</th>
                            <th class="center" style="width: 18%;">Qty</th>
                            <th class="right" style="width: 25%;">Price</th>
                            <th class="right" style="width: 25%;">Amt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                            $total_item_count = count($items);
                            $total_qty_count = 0;
                            $computed_subtotal = 0;
                            foreach ($items as $row):
                                $qty = intval($row['quantity'] ?: 1);
                                $price = floatval($row['unit_price'] ?: 0);
                                $line_total = $qty * $price;
                                $total_qty_count += $qty;
                                $computed_subtotal += $line_total;
                        ?>
                        <tr class="item-name-row">
                            <td colspan="4"><?php echo htmlspecialchars($row['item_name']); ?></td>
                        </tr>
                        <tr class="item-meta-row">
                            <td style="color: #666; font-size: 10.5px;">#<?php echo $row['item_id']; ?></td>
                            <td class="center"><?php echo $qty; ?></td>
                            <td class="right"><?php echo number_format($price, 2); ?></td>
                            <td class="right fw-bold"><?php echo number_format($line_total, 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php 
                    $subtotal = floatval($txn['subtotal_lkr'] ?? $computed_subtotal);
                    $disc_amt = floatval($txn['discount_amount'] ?? 0);
                    $disc_type = $txn['discount_type'] ?? 'none';
                    $disc_val = floatval($txn['discount_value'] ?? 0);
                    $net_total = floatval($txn['total_lkr']);
                    $cash_paid = floatval($txn['received_amount']);
                    $change = floatval($txn['balance_amount']);
                ?>
                
                <div class="thermal-totals-box">
                    <div class="thermal-totals-row" style="font-size: 11px; color: #555;">
                        <span>TOTAL ITEMS</span>
                        <span><?php echo $total_qty_count; ?> pcs (<?php echo $total_item_count; ?> lines)</span>
                    </div>
                    
                    <?php if ($disc_amt > 0): ?>
                    <div class="thermal-totals-row">
                        <span>GROSS TOTAL</span>
                        <span>Rs. <?php echo number_format($subtotal, 2); ?></span>
                    </div>
                    <div class="thermal-totals-row discount">
                        <span>DISCOUNT <?php echo $disc_type === 'percentage' ? '(' . $disc_val . '%)' : '(FLAT)'; ?></span>
                        <span>- Rs. <?php echo number_format($disc_amt, 2); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="thermal-totals-row grand-total">
                        <span>NET TOTAL</span>
                        <span>Rs. <?php echo number_format($net_total, 2); ?></span>
                    </div>
                    
                    <div class="thermal-totals-row">
                        <span>CASH PAID</span>
                        <span>Rs. <?php echo number_format($cash_paid, 2); ?></span>
                    </div>
                    
                    <div class="thermal-totals-row" style="font-weight: 700;">
                        <span>CHANGE / BALANCE</span>
                        <span>Rs. <?php echo number_format($change, 2); ?></span>
                    </div>
                </div>

                <?php if (!empty($txn['free_equipment'])): ?>
                    <div class="thermal-complimentary">
                        <strong>* Free Complimentary Item:</strong><br>
                        <?php echo htmlspecialchars($txn['free_equipment']); ?>
                    </div>
                <?php endif; ?>
                
                <div class="thermal-footer">
                    Thank you for choosing Mahinda Constructions!<br>
                    * Merchandise once sold is non-returnable.<br>
                    System Generated via MCATS Enterprise
                </div>
            </div>

        <?php elseif ($type === 'thermal_rent'): ?>
            <!-- THERMAL SLIP (Rent-out Initial Dispatch) -->
            <div class="thermal-receipt">
                <h2>Mahinda Constructions<br>& ToolShop</h2>
                <div class="info">
                    140/2, Kandy Road, Rikillagaskada<br>
                    Hotline: 072-2097483 | 081-2244550<br>
                    <strong>RENTAL DISPATCH SLIP</strong><br>
                    Docket No: #<?php echo str_pad($bill_id, 6, '0', STR_PAD_LEFT); ?><br>
                    Date: <?php echo $date_formatted; ?>
                </div>

                <div style="font-size: 11px; margin-bottom: 10px; border-bottom: 1px dashed #000; padding-bottom: 8px;">
                    <div><strong>Customer:</strong> <?php echo htmlspecialchars($client_name); ?></div>
                    <div><strong>NIC:</strong> <?php echo htmlspecialchars($txn['customer_nic'] ?: 'N/A'); ?></div>
                    <div><strong>Phone:</strong> <?php echo htmlspecialchars($client_phone ?: 'N/A'); ?></div>
                </div>
                
                <table class="thermal-table">
                    <thead>
                        <tr>
                            <th style="width: 40%;">Item</th>
                            <th class="center" style="width: 20%;">Qty</th>
                            <th class="right" style="width: 40%;">Price Per Day</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $row): ?>
                        <tr class="item-name-row">
                            <td colspan="3"><?php echo htmlspecialchars($row['item_name']); ?></td>
                        </tr>
                        <tr class="item-meta-row">
                            <td style="color: #666; font-size: 10.5px;">#<?php echo $row['item_id']; ?></td>
                            <td class="center"><?php echo $row['quantity']; ?></td>
                            <td class="right fw-bold">Rs. <?php echo number_format($row['unit_price'], 2); ?> / Day</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div class="thermal-totals-box">
                    <div class="thermal-totals-row grand-total">
                        <span>ADVANCE (CASH RECEIVED)</span>
                        <span>Rs. <?php echo number_format($txn['advance_paid'], 2); ?> <?php echo floatval($txn['advance_paid']) == 0 ? '(NO ADVANCE)' : ''; ?></span>
                    </div>
                </div>
                
                <?php if (!empty($txn['free_equipment'])): ?>
                    <div class="thermal-complimentary">
                        <strong>* Free Complimentary Item:</strong><br>
                        <?php echo htmlspecialchars($txn['free_equipment']); ?>
                    </div>
                <?php endif; ?>
                
                <div class="thermal-footer">
                    <strong>*** INITIAL DISPATCH SLIP ***</strong><br>
                    Final bill will be calculated on return<br>
                    (Total Days &times; Daily Rate)<br>
                    * Retain this slip for return check *<br>
                    MCATS Enterprise System
                </div>
            </div>

        <?php elseif ($type === 'thermal_pay_later'): ?>
            <!-- THERMAL RECEIPT (Pay Later Agreement & Credit Slip) -->
            <?php
                $total_rent_val = floatval($txn['total_lkr']);
                $advance_held_val = floatval($txn['advance_paid']);
                $total_paid_val = floatval($txn['received_amount']);
                $additional_cash_paid = max(0, $total_paid_val - $advance_held_val);
                $has_to_pay_val = max(0, $total_rent_val - $total_paid_val);
                $disc_val = floatval($txn['discount_amount'] ?? 0);
            ?>
            <div class="thermal-receipt">
                <h2>Mahinda Constructions<br>& ToolShop</h2>
                <div class="info">
                    140/2, Kandy Road, Rikillagaskada<br>
                    Hotline: 072-2097483 | 081-2244550<br>
                    <strong style="font-size: 13px; color: #b45309;">*** PAY LATER RECEIPT ***</strong><br>
                    Pay Later Bill No: #<?php echo str_pad($bill_id, 6, '0', STR_PAD_LEFT); ?><br>
                    Date & Time: <?php echo $date_formatted; ?>
                </div>

                <div style="font-size: 11px; margin-bottom: 10px; border-bottom: 1px dashed #000; padding-bottom: 8px;">
                    <div><strong>Customer:</strong> <?php echo htmlspecialchars($client_name); ?></div>
                    <div><strong>NIC:</strong> <?php echo htmlspecialchars($txn['customer_nic'] ?: 'N/A'); ?></div>
                    <div><strong>Phone:</strong> <?php echo htmlspecialchars($client_phone ?: 'N/A'); ?></div>
                </div>
                
                <table class="thermal-table">
                    <thead>
                        <tr>
                            <th style="width: 40%;">Item</th>
                            <th class="center" style="width: 15%;">Qty</th>
                            <th class="center" style="width: 20%;">Days</th>
                            <th class="right" style="width: 25%;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $row): 
                            $days = intval($row['billed_days'] ?: 1);
                            $line_total = intval($row['quantity']) * floatval($row['unit_price']) * $days;
                        ?>
                        <tr class="item-name-row">
                            <td colspan="4"><?php echo htmlspecialchars($row['item_name']); ?></td>
                        </tr>
                        <tr class="item-meta-row">
                            <td style="color: #666; font-size: 10.5px;">@ Rs. <?php echo number_format($row['unit_price'], 2); ?>/day</td>
                            <td class="center"><?php echo $row['quantity']; ?></td>
                            <td class="center"><?php echo $days; ?>d</td>
                            <td class="right fw-bold"><?php echo number_format($line_total, 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div class="thermal-totals-box">
                    <div class="thermal-totals-row">
                        <span>FULL RENT CHARGE</span>
                        <span class="fw-bold">Rs. <?php echo number_format($total_rent_val, 2); ?></span>
                    </div>

                    <?php if ($disc_val > 0): ?>
                    <div class="thermal-totals-row discount">
                        <span>DISCOUNT APPLIED</span>
                        <span>- Rs. <?php echo number_format($disc_val, 2); ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($advance_held_val > 0): ?>
                    <div class="thermal-totals-row">
                        <span>ADVANCE AT DISPATCH</span>
                        <span>Rs. <?php echo number_format($advance_held_val, 2); ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($additional_cash_paid > 0): ?>
                    <div class="thermal-totals-row">
                        <span>CASH PAID TODAY</span>
                        <span>Rs. <?php echo number_format($additional_cash_paid, 2); ?></span>
                    </div>
                    <?php endif; ?>

                    <div class="thermal-totals-row" style="border-top: 1px dashed #000; padding-top: 5px; font-weight: 700;">
                        <span>HOW MUCH PAID</span>
                        <span class="fw-bold text-success">Rs. <?php echo number_format($total_paid_val, 2); ?></span>
                    </div>

                    <div class="thermal-totals-row grand-total" style="background: #fdfaf3; padding: 6px 4px; margin-top: 4px; border: 1.5px solid #000;">
                        <span style="font-weight: 800;">HOW MUCH HAS TO BE PAID</span>
                        <span style="font-weight: 800; font-size: 14.5px;">Rs. <?php echo number_format($has_to_pay_val, 2); ?></span>
                    </div>

                    <div class="thermal-totals-row" style="font-size: 11.5px; font-weight: 700; padding: 4px 2px;">
                        <span>PAY LATER BILL NUMBER</span>
                        <span style="font-size: 13px; font-family: monospace;">#<?php echo str_pad($bill_id, 6, '0', STR_PAD_LEFT); ?></span>
                    </div>
                </div>

                <?php if (!empty($txn['notes'])): ?>
                    <div class="thermal-complimentary" style="background: #fffbeb; border-left-color: #f59e0b;">
                        <strong>* Credit Note:</strong><br>
                        <?php echo htmlspecialchars($txn['notes']); ?>
                    </div>
                <?php endif; ?>

                <div class="thermal-footer">
                    <strong>*** PAY LATER NOTICE ***</strong><br>
                    Customer has promised to clear due balance.<br>
                    Please settle before future rentals.<br>
                    MCATS Enterprise System
                </div>
            </div>

        <?php else: ?>
            <!-- A4 SHEET INVOICE (Sales & Final Rental Settlement) -->
            <?php if ($paid_all): ?>
            <div class="container mb-4 d-print-none" style="max-width: 210mm;">
                <div class="alert alert-success border-success border-2 rounded-4 shadow-sm p-3 d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
                            <i class="fa-solid fa-circle-check fs-4"></i>
                        </div>
                        <div>
                            <strong class="text-success fs-6">All Money Paid & Full Settlement Reconciled!</strong>
                            <div class="small text-secondary">The complete A4 settlement invoice is ready and printing automatically.</div>
                        </div>
                    </div>
                    <button onclick="window.print()" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm">
                        <i class="fa-solid fa-print me-1"></i> Print A4 Bill
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <!-- INTERACTIVE TOP PAYMENT COLLECTOR ON A4 PAGE -->
            <?php if (($a4_pending > 0.001 || $is_pay_later) && !$paid_all): ?>
            <div class="container mb-4 d-print-none" style="max-width: 210mm;">
                <div class="p-3 rounded-4 shadow-lg" style="background: #0f172a; border: 2px solid #f59e0b; color: #ffffff;">
                    <form method="POST" action="pay_later_settle.php" class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-0">
                        <input type="hidden" name="bill_id" value="<?php echo $bill_id; ?>">
                        <input type="hidden" name="redirect_to" value="print_bill.php?type=a4&bill_id=<?php echo $bill_id; ?>">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center bg-warning text-dark fw-bold" style="width: 44px; height: 44px;">
                                <i class="fa-solid fa-hand-holding-dollar fs-5"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold text-white">Collect Payment & Settle Bill #<?php echo $bill_id; ?></h6>
                                <div class="text-white-50 small">Outstanding Due: <strong class="text-warning fs-6">Rs. <?php echo number_format($a4_pending, 2); ?></strong></div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2 flex-grow-1 flex-md-grow-0">
                            <div class="input-group input-group-sm" style="width: 170px;">
                                <span class="input-group-text bg-dark border-secondary text-warning fw-bold">Rs.</span>
                                <input type="number" name="payment_amount" class="form-control bg-dark text-white fw-bold border-secondary" min="0.01" step="0.01" max="<?php echo number_format($a4_pending, 2, '.', ''); ?>" value="<?php echo number_format($a4_pending, 2, '.', ''); ?>" required>
                            </div>
                            <select name="payment_method" class="form-select form-select-sm bg-dark text-white border-secondary" style="width: 120px;">
                                <option value="Cash" selected>Cash</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Debit/Credit Card">Card</option>
                            </select>
                            <button type="submit" class="btn btn-warning btn-sm fw-bold rounded-pill px-4 text-dark shadow-sm">
                                <i class="fa-solid fa-print me-1"></i> Pay & Print A4 Bill
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <div class="a4-receipt position-relative d-flex flex-column">
                <div class="d-flex justify-content-between align-items-end border-bottom border-dark border-2 pb-3 mb-4">
                    <div>
                        <h1 class="display-6 fw-bold mb-1" style="font-family: 'Outfit', sans-serif; color: #0b1120;">Mahinda Constructions</h1>
                        <h5 class="fw-semibold text-secondary mb-2">& Heavy Tool Lease System</h5>
                        <p class="mb-0 text-dark small">140/2, Kandy Road, Rikillagaskada</p>
                        <p class="mb-0 text-dark small">Hotline: 072-2097483 | Support Desk: 081-2244550</p>
                    </div>
                    <div class="text-end">
                        <span class="badge text-uppercase mb-1" style="background: <?php echo $is_selling ? 'rgba(59, 130, 246, 0.15)' : ($is_pay_later ? 'rgba(245, 158, 11, 0.15)' : ($is_returned ? 'rgba(16, 185, 129, 0.15)' : 'rgba(212, 175, 55, 0.15)')); ?>; color: <?php echo $is_selling ? '#1d4ed8' : ($is_pay_later ? '#b45309' : ($is_returned ? '#065f46' : '#8c6d1f')); ?>; font-size: 0.75rem; letter-spacing: 0.1em; font-weight: 700;">
                            <?php echo $is_selling ? 'Commercial Sales & Retail Invoice' : ($is_pay_later ? 'Pay Later Settlement & Credit Invoice' : ($is_returned ? 'Final Lease Settlement & Return Invoice' : 'Equipment Lease Agreement')); ?>
                        </span>
                        <h2 class="text-uppercase tracking-wide mb-0 fw-bold" style="letter-spacing: 2px; color: #0b1120;">Invoice</h2>
                        <p class="fs-5 fw-bold text-dark mb-0 font-monospace">#<?php echo str_pad($bill_id, 6, '0', STR_PAD_LEFT); ?></p>
                    </div>
                </div>
                
                <div class="row mb-4 text-dark">
                    <div class="col-7">
                        <div class="fw-bold text-uppercase small text-secondary mb-1" style="font-size: 0.72rem; letter-spacing: 0.06em;"><?php echo $is_selling ? 'Billed To / Customer Profile' : 'Lessee / Client Profile'; ?></div>
                        <div class="fs-6 fw-bold"><?php echo htmlspecialchars($client_name); ?></div>
                        <div class="text-secondary small">
                            <?php if ($txn['customer_nic']): ?>NIC: <strong><?php echo htmlspecialchars($txn['customer_nic']); ?></strong> &bull; <?php endif; ?>
                            <?php if ($client_phone): ?>Phone: <strong><?php echo htmlspecialchars($client_phone); ?></strong><?php endif; ?>
                            <?php if ($client_address): ?><br>Address: <?php echo htmlspecialchars($client_address); ?><?php endif; ?>
                        </div>
                    </div>
                    <div class="col-5 text-end">
                        <div class="mb-1"><span class="fw-bold text-secondary text-uppercase small me-2">Date & Time:</span> <span class="fw-semibold"><?php echo $date_formatted; ?></span></div>
                        <div>
                            <span class="fw-bold text-secondary text-uppercase small me-2">Status:</span>
                            <span class="badge <?php echo $is_selling ? 'bg-primary' : ($is_pay_later ? 'bg-warning text-dark' : ($is_returned ? 'bg-success' : 'bg-warning text-dark')); ?>">
                                <?php echo $is_selling ? 'Completed / Paid' : ($is_pay_later ? 'Pay Later / Balance Pending' : ($is_returned ? 'Closed / Returned' : 'Active / Dispatched')); ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <table class="table border-top mb-4">
                    <thead class="table-light text-dark">
                        <tr>
                            <th class="py-3 border-bottom border-secondary border-2 small text-uppercase fw-bold">Equipment / Item Description</th>
                            <th class="py-3 text-center border-bottom border-secondary border-2 small text-uppercase fw-bold">Quantity</th>
                            <th class="py-3 text-end border-bottom border-secondary border-2 small text-uppercase fw-bold">Unit Price (LKR)</th>
                            <?php if (!$is_selling && $is_returned): ?>
                                <th class="py-3 text-center border-bottom border-secondary border-2 small text-uppercase fw-bold">Billed Days</th>
                            <?php endif; ?>
                            <th class="py-3 text-end border-bottom border-secondary border-2 small text-uppercase fw-bold">
                                <?php echo $is_selling ? 'Line Total (LKR)' : ($is_returned ? 'Rental Charge (LKR)' : 'Rate / Day (LKR)'); ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="text-dark">
                        <?php foreach ($items as $row): 
                            $days = (!$is_selling && $is_returned && !empty($row['billed_days'])) ? intval($row['billed_days']) : 1;
                            $line_total = (!$is_selling && $is_returned) ? (intval($row['quantity']) * floatval($row['unit_price']) * $days) : (intval($row['quantity']) * floatval($row['unit_price']));
                        ?>
                        <tr>
                            <td class="py-3 fw-bold"><?php echo htmlspecialchars($row['item_name']); ?></td>
                            <td class="py-3 text-center"><?php echo $row['quantity']; ?></td>
                            <td class="py-3 text-end"><?php echo number_format($row['unit_price'], 2); ?></td>
                            <?php if (!$is_selling && $is_returned): ?>
                                <td class="py-3 text-center fw-bold"><?php echo $days; ?> <?php echo $days === 1 ? 'day' : 'days'; ?></td>
                            <?php endif; ?>
                            <td class="py-3 text-end fw-bold"><?php echo number_format($line_total, 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div class="row mb-4">
                    <div class="col-7">
                        <?php if (!empty($txn['free_equipment'])): ?>
                            <div class="p-3 rounded-3 border bg-light mb-3">
                                <span class="fw-bold small text-secondary text-uppercase d-block mb-1">Complimentary Item Included:</span>
                                <span class="small fw-semibold text-dark"><?php echo htmlspecialchars($txn['free_equipment']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-5">
                        <?php if ($is_selling): ?>
                            <?php
                                $a4_subtotal = floatval($txn['subtotal_lkr'] ?? $txn['total_lkr']);
                                $a4_disc_amt = floatval($txn['discount_amount'] ?? 0);
                                $a4_disc_type = $txn['discount_type'] ?? 'none';
                                $a4_disc_val = floatval($txn['discount_value'] ?? 0);
                                $a4_net = floatval($txn['total_lkr']);
                                $a4_paid = floatval($txn['received_amount']);
                                $a4_bal = floatval($txn['balance_amount']);
                            ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="fw-bold text-secondary text-uppercase small">Gross Subtotal:</span>
                                <span class="fs-6 fw-bold text-dark">LKR <?php echo number_format($a4_subtotal, 2); ?></span>
                            </div>
                            <?php if ($a4_disc_amt > 0): ?>
                            <div class="d-flex justify-content-between mb-2 text-danger">
                                <span class="fw-bold text-uppercase small">Discount <?php echo $a4_disc_type === 'percentage' ? '(' . $a4_disc_val . '%)' : '(FLAT)'; ?>:</span>
                                <span class="fs-6 fw-bold">- LKR <?php echo number_format($a4_disc_amt, 2); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="d-flex justify-content-between mb-2 pb-2 border-bottom">
                                <span class="fw-bold text-secondary text-uppercase small">Net Payable Amount:</span>
                                <span class="fs-5 fw-bold text-dark" style="font-family: 'Outfit', sans-serif;">LKR <?php echo number_format($a4_net, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="fw-bold text-secondary text-uppercase small">Cash Tendered:</span>
                                <span class="fs-6 fw-bold text-dark">LKR <?php echo number_format($a4_paid, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between pt-1">
                                <span class="fw-bold text-secondary text-uppercase small">Change / Balance:</span>
                                <span class="fs-6 fw-bold text-success">LKR <?php echo number_format($a4_bal, 2); ?></span>
                            </div>
                        <?php elseif ($is_returned): ?>
                            <?php 
                                $total_charge = floatval($txn['total_lkr']);
                                $advance_paid = floatval($txn['advance_paid']);
                                $total_received = floatval($txn['received_amount']);
                                $additional_cash = max(0, $total_received - $advance_paid);
                                $balance = floatval($txn['balance_amount']);
                            ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="fw-bold text-secondary text-uppercase small">Total Rental Charge:</span>
                                <span class="fs-6 fw-bold text-dark">LKR <?php echo number_format($total_charge, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="fw-bold text-secondary text-uppercase small">Advance Paid at Dispatch:</span>
                                <span class="fs-6 fw-bold text-dark">LKR <?php echo number_format($advance_paid, 2); ?></span>
                            </div>
                            <?php if ($additional_cash > 0): ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="fw-bold text-secondary text-uppercase small">Additional Cash Paid on Return:</span>
                                <span class="fs-6 fw-bold text-dark">LKR <?php echo number_format($additional_cash, 2); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="d-flex justify-content-between mb-2 pb-2 border-bottom">
                                <span class="fw-bold text-secondary text-uppercase small">Total Tendered:</span>
                                <span class="fs-6 fw-bold text-dark">LKR <?php echo number_format($total_received, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between pt-1">
                                <span class="fw-bold text-secondary text-uppercase small">
                                    <?php echo $is_pay_later ? 'Outstanding Pay Later Balance (Due):' : ($balance >= 0 ? 'Customer Refund / Change:' : 'Net Balance Due:'); ?>
                                </span>
                                <span class="fs-5 fw-bold" style="color: <?php echo ($is_pay_later || $balance < 0) ? '#d97706' : '#10b981'; ?>;">
                                    <?php echo ($is_pay_later || $balance < 0) ? 'Due ' : ''; ?>LKR <?php echo number_format(abs($balance), 2); ?>
                                </span>
                            </div>
                            <?php if (!empty($txn['notes'])): ?>
                            <div class="mt-2 p-2 rounded small text-dark border <?php echo $is_pay_later ? 'bg-warning bg-opacity-10 border-warning border-opacity-50' : 'bg-light'; ?>">
                                <i class="fa-solid fa-note-sticky me-1 <?php echo $is_pay_later ? 'text-warning' : 'text-secondary'; ?>"></i>
                                <span class="fw-semibold"><?php echo $is_pay_later ? 'Credit Terms & Notes:' : 'Notes:'; ?></span> <?php echo htmlspecialchars($txn['notes']); ?>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <!-- Initial Rent-out Dispatch A4 Summary -->
                            <div class="d-flex justify-content-between mb-2">
                                <span class="fw-bold text-secondary text-uppercase small">Total Equipment Rate:</span>
                                <span class="fs-6 fw-bold text-dark">LKR <?php echo number_format($txn['total_lkr'], 2); ?> / day</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2 pb-2 border-bottom">
                                <span class="fw-bold text-secondary text-uppercase small">Advance Deposit Held:</span>
                                <span class="fs-6 fw-bold" style="color: #10b981;">LKR <?php echo number_format($txn['advance_paid'], 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between pt-1">
                                <span class="fw-bold text-secondary text-uppercase small">Final Settlement:</span>
                                <span class="small text-muted fw-semibold">Calculated on Return</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="p-4 rounded-3 border-start border-4 <?php echo $is_selling ? 'border-primary' : 'border-warning'; ?> mb-5 mt-auto" style="background: <?php echo $is_selling ? '#f8fafc' : '#fdfaf3'; ?>;">
                    <h6 class="fw-bold mb-2 text-dark" style="font-size: 0.88rem;"><?php echo $is_selling ? 'Terms of Sale & Warranty Policy:' : 'Terms & Conditions of Equipment Hire:'; ?></h6>
                    <ol class="mb-0 small text-secondary ps-3" style="font-size: 0.8rem; line-height: 1.5;">
                        <?php if ($is_selling): ?>
                            <li class="mb-1">Merchandise sold is subject to manufacturer standard warranty guidelines where applicable.</li>
                            <li class="mb-1">Original cash sales invoice must be presented for any warranty evaluation or exchange inquiry.</li>
                            <li class="mb-1">Electrical and power accessories damaged due to surge or improper handling are excluded.</li>
                            <li>Goods once sold and delivered in good order are non-refundable.</li>
                        <?php else: ?>
                            <li class="mb-1">All machinery and tools must be returned in full operational and clean condition.</li>
                            <li class="mb-1">Calculated days run from dispatch timestamp to physical handover check at the return desk.</li>
                            <li class="mb-1">Lessee bears sole financial and legal liability for broken parts, burnt motors, or misplaced accessories.</li>
                            <li>Security advances are reconciled against actual operational days upon return inspection.</li>
                        <?php endif; ?>
                    </ol>
                </div>
                
                <div class="row mt-auto pt-4 text-center text-dark">
                    <div class="col-4 offset-1">
                        <div class="border-top border-dark pt-2 fw-bold small">Authorized Store Officer</div>
                    </div>
                    <div class="col-4 offset-2">
                        <div class="border-top border-dark pt-2 fw-bold small">Customer / Lessee Acknowledgement</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($paid_all): ?>
    <script>
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 500);
        });
    </script>
    <?php endif; ?>
</body>
</html>
