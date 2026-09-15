<?php
session_start();
include_once __DIR__ . '/../../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php");
    exit();
}

if (!isset($_GET['bill_id'])) {
    header("Location: return_items.php");
    exit();
}

$bill_id = intval($_GET['bill_id']);
$msg = "";

// 1. Fetch Transaction Details
$tsql = "SELECT t.*, c.full_name as c_name, c.phone_number as c_phone 
         FROM transactions t 
         LEFT JOIN customers c ON t.customer_nic = c.nic_number 
         WHERE t.bill_number = ?";
$stmt = $conn->prepare($tsql);
$stmt->bind_param("i", $bill_id);
$stmt->execute();
$t_res = $stmt->get_result();
if ($t_res->num_rows === 0) {
    die("Transaction not found.");
}
$trans = $t_res->fetch_assoc();

if ($trans['status'] === 'returned') {
    $msg = "<div class='alert alert-warning shadow-sm rounded-3 mt-3'><i class='fa-solid fa-triangle-exclamation me-2'></i>This rental has already been returned and closed. <a href='print_bill.php?type=a4&bill_id=$bill_id' class='alert-link'>View Receipt</a></div>";
}

// 2. Fetch Rented Items
$isql = "SELECT i.item_name, i.item_id, ti.id as ti_id, ti.quantity, ti.unit_price 
         FROM transaction_items ti 
         JOIN inventory i ON ti.item_id = i.item_id 
         WHERE ti.bill_number = ?";
$istmt = $conn->prepare($isql);
$istmt->bind_param("i", $bill_id);
$istmt->execute();
$items_res = $istmt->get_result();

$items = [];
$total_hardware_value = 0;
while($row = $items_res->fetch_assoc()) {
    $items[] = $row;
    $total_hardware_value += ($row['quantity'] * $row['unit_price']);
}

// Calculate Default Days based on timestamp
$rentDate = new DateTime($trans['transaction_date']);
$nowDate = new DateTime();
$interval = $rentDate->diff($nowDate);
$defaultDays = $interval->days;
// Ensure at least 1 day if returned on the exact same day
if ($defaultDays == 0) $defaultDays = 1;


// 3. Process Final Return
if (isset($_POST['process_return']) && $trans['status'] !== 'returned') {
    $cash_received = floatval($_POST['cash_received']);
    
    // Total cash collected over the lifespan of this bill
    $new_received_total = $trans['received_amount'] + $cash_received;
    
    $conn->begin_transaction();
    try {
        $final_fee = 0;
        
        // Loop through submitted days per item and update DB + securely calculate fee
        $upd_stmt = $conn->prepare("UPDATE transaction_items SET billed_days = ? WHERE id = ?");
        foreach ($_POST['days'] as $ti_id => $days) {
            $days = intval($days);
            $upd_stmt->bind_param("ii", $days, $ti_id);
            $upd_stmt->execute();
            // recalculate fee securely
            foreach ($items as $itm) {
                if ($itm['ti_id'] == $ti_id) {
                    $final_fee += ($itm['quantity'] * $itm['unit_price'] * $days);
                }
            }
        }
        
        $new_balance = $new_received_total - $final_fee;

        // Update transaction logic for final rent
        $u_sql = "UPDATE transactions 
                  SET status='returned', 
                      total_lkr = ?, 
                      received_amount = ?, 
                      balance_amount = ? 
                  WHERE bill_number = ?";
        $u_stmt = $conn->prepare($u_sql);
        $u_stmt->bind_param("dddi", $final_fee, $new_received_total, $new_balance, $bill_id);
        $u_stmt->execute();

        // Release inventory back to available
        $inv_sql = "UPDATE inventory SET status='available' WHERE item_id = ?";
        $inv_stmt = $conn->prepare($inv_sql);
        foreach ($items as $item) {
            $inv_stmt->bind_param("i", $item['item_id']);
            $inv_stmt->execute();
        }

        $conn->commit();
        echo "<script>window.location.href = 'print_bill.php?type=a4&bill_id=$bill_id';</script>";
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $msg = "<div class='alert alert-danger shadow-sm rounded-3 mt-3'><i class='fa-solid fa-triangle-exclamation me-2'></i>Error processing return: " . $e->getMessage() . "</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MCATS | Lease Settlement #<?php echo $bill_id; ?></title>
    <link rel="icon" type="image/x-icon" href="../../img/ico.ico">
    <!-- Instant theme apply — prevents flash -->
    <script>document.documentElement.setAttribute('data-bs-theme', localStorage.getItem('theme') || 'light');</script>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Luxury Core CSS -->
    <link rel="stylesheet" href="../../css/luxury.css">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--luxury-body-bg); min-height: 100vh; display: flex; flex-direction: column; }
        .top-navbar {
            background: #070a12;
            border-bottom: 1px solid rgba(212, 175, 55, 0.2);
            padding: 1rem 2rem;
        }
        .luxury-panel {
            background: var(--luxury-card-bg);
            border: 1px solid var(--luxury-card-border);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-sm);
        }
    </style>
</head>
<body>

    <div class="top-navbar d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center rounded-circle" style="width: 42px; height: 42px; background: rgba(212, 175, 55, 0.15); border: 1px solid rgba(212, 175, 55, 0.3);">
                <i class="fa-solid fa-receipt" style="color: var(--gold-primary);"></i>
            </div>
            <div>
                <h5 class="mb-0 fw-bold text-white" style="font-family: 'Outfit', sans-serif; letter-spacing: 0.04em;">Lease Settlement & Return #<?php echo $bill_id; ?></h5>
                <small class="text-secondary">Calculate final billable days and release equipment</small>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button id="themeToggle" class="btn btn-sm btn-outline-secondary text-white rounded-pill px-3 shadow-sm border-secondary">
                <i class="fa-solid fa-moon me-1"></i> Dark Mode
            </button>
            <a href="return_items.php" class="btn btn-sm btn-outline-secondary text-white rounded-pill px-3 shadow-sm border-secondary">
                <i class="fa-solid fa-arrow-left me-1"></i> Return Directory
            </a>
            <a href="rent.php" class="btn btn-sm btn-luxury-gold rounded-pill px-3 fw-bold shadow-sm">
                <i class="fa-solid fa-camera-retro me-1"></i> Back to POS
            </a>
        </div>
    </div>

    <div class="container py-4 flex-grow-1" style="max-width: 1250px;">
        
        <?php if($msg) echo $msg; ?>

        <div class="row g-4 mt-1">
            
            <!-- Left Side: Rental Dossier & Gear List -->
            <div class="col-lg-7">
                <div class="luxury-panel p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center border-bottom border-secondary-subtle pb-3 mb-4">
                        <h5 class="fw-bold mb-0 d-flex align-items-center gap-2" style="font-family: 'Outfit', sans-serif;">
                            <i class="fa-regular fa-file-lines" style="color: var(--gold-primary);"></i>
                            Agreement Overview
                        </h5>
                        <span class="badge" style="background: rgba(212, 175, 55, 0.15); color: var(--gold-primary); font-size: 0.75rem;">
                            Docket #<?php echo $bill_id; ?>
                        </span>
                    </div>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="p-3 rounded-4 h-100" style="background: rgba(0, 0, 0, 0.03); border: 1px solid var(--luxury-card-border);">
                                <div class="text-uppercase fw-bold text-secondary small mb-1" style="font-size: 0.72rem; letter-spacing: 0.08em;">Client Profile</div>
                                <div class="fw-bold fs-6 mb-1"><?php echo htmlspecialchars($trans['c_name'] ?: 'Walk-in Customer'); ?></div>
                                <div class="text-secondary small">
                                    <i class="fa-regular fa-id-card me-1"></i><?php echo htmlspecialchars($trans['customer_nic'] ?: 'No NIC'); ?><br>
                                    <i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($trans['c_phone'] ?: 'No Phone'); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 rounded-4 h-100" style="background: rgba(0, 0, 0, 0.03); border: 1px solid var(--luxury-card-border);">
                                <div class="text-uppercase fw-bold text-secondary small mb-1" style="font-size: 0.72rem; letter-spacing: 0.08em;">Dispatched Timestamp</div>
                                <div class="fw-bold fs-6 mb-2"><?php echo date("M j, Y", strtotime($trans['transaction_date'])); ?> <span class="text-secondary small fw-normal ms-1"><?php echo date("g:i A", strtotime($trans['transaction_date'])); ?></span></div>
                                <div class="fw-bold text-success bg-success bg-opacity-10 px-2 py-1 rounded d-inline-block small border border-success border-opacity-25">
                                    Advance Deposited: Rs. <?php echo number_format($trans['advance_paid'], 2); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-3 mb-2">
                        <div class="fw-bold text-secondary text-uppercase small" style="letter-spacing: 0.05em;">Dispatched Equipment & Duration</div>
                        <div class="badge text-secondary border border-secondary-subtle">Est. Valuation: Rs. <?php echo number_format($total_hardware_value,2); ?></div>
                    </div>
                    <div class="table-responsive border border-secondary-subtle rounded-4 overflow-hidden">
                        <table class="table table-hover mb-0">
                            <thead style="background: rgba(0, 0, 0, 0.04);">
                                <tr>
                                    <th style="width: 250px;" class="fw-bold text-secondary text-uppercase small ps-3">Equipment</th>
                                    <th class="fw-bold text-secondary text-center text-uppercase small">Rate / Day</th>
                                    <th class="fw-bold text-secondary text-center text-uppercase small">Days</th>
                                    <th class="fw-bold text-secondary text-end text-uppercase small pe-3">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($items as $item): ?>
                                <tr class="border-bottom border-secondary-subtle">
                                    <td class="align-middle ps-3">
                                        <div class="fw-bold"><i class="fa-solid fa-camera-retro me-2 text-secondary small"></i><?php echo htmlspecialchars($item['item_name']); ?></div>
                                        <div class="small text-secondary">Units: <?php echo $item['quantity']; ?></div>
                                    </td>
                                    <td class="align-middle text-center small fw-semibold">Rs. <?php echo number_format($item['unit_price'], 2); ?></td>
                                    <td class="align-middle" style="width: 110px;">
                                        <input type="number" name="days[<?php echo $item['ti_id']; ?>]" class="form-control form-control-sm text-center item-days fw-bold" min="1" value="<?php echo $defaultDays; ?>" 
                                            data-qty="<?php echo $item['quantity']; ?>" data-price="<?php echo $item['unit_price']; ?>" oninput="calculateSettle()">
                                    </td>
                                    <td class="align-middle text-end fw-bold item-amt pe-3" style="color: var(--gold-primary);">Rs. 0.00</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Right Side: Final Settlement Calculator -->
            <div class="col-lg-5">
                <div class="luxury-panel p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center border-bottom border-secondary-subtle pb-3 mb-4">
                        <h5 class="fw-bold mb-0 d-flex align-items-center gap-2" style="font-family: 'Outfit', sans-serif;">
                            <i class="fa-solid fa-calculator" style="color: var(--gold-primary);"></i>
                            Financial Settlement
                        </h5>
                    </div>
                    
                    <?php if ($trans['status'] !== 'returned'): ?>
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-secondary text-uppercase mb-1" style="letter-spacing: 0.05em;">Total Final Rental Charge</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text bg-transparent border-secondary-subtle fw-bold" style="color: var(--gold-primary);">Rs.</span>
                                <input type="number" name="final_fee" id="finalFee" class="form-control fw-bold border-secondary-subtle" readonly min="0" placeholder="0.00">
                            </div>
                            <div class="text-secondary small mt-1"><i class="fa-solid fa-circle-info me-1"></i>Computed automatically from duration rates.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold small text-secondary text-uppercase mb-1" style="letter-spacing: 0.05em;"><i class="fa-solid fa-tag me-1 text-warning"></i>Special Concession / Discount</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-transparent border-secondary-subtle">Rs.</span>
                                <input type="number" name="discount" id="discountInput" class="form-control fw-bold text-danger border-secondary-subtle" min="0" placeholder="0.00" value="0" oninput="calculateSettle()">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-secondary text-uppercase mb-1" style="letter-spacing: 0.05em;">Settlement Cash Tendered Today</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text bg-transparent border-secondary-subtle fw-bold" style="color: var(--gold-primary);">Rs.</span>
                                <input type="number" name="cash_received" id="cashReceived" class="form-control fw-bold border-secondary-subtle fs-4" required min="0" placeholder="0.00" oninput="calculateSettle()">
                            </div>
                        </div>

                        <!-- Statement Card -->
                        <div class="p-3 rounded-4 mb-4" style="background: rgba(0, 0, 0, 0.03); border: 1px solid var(--luxury-card-border);">
                            <div class="d-flex justify-content-between mb-2 small fw-semibold text-secondary">
                                <span>Gross Lease Total:</span>
                                <span id="dispFee" class="text-dark">Rs. 0.00</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2 small fw-semibold text-secondary">
                                <span>Less Applied Discount:</span>
                                <span class="text-danger" id="dispDiscount">- Rs. 0.00</span>
                            </div>
                            <div class="d-flex justify-content-between mb-3 small fw-semibold text-secondary border-top border-secondary-subtle pt-2">
                                <span>Less Security Advance Held:</span>
                                <span class="text-success">- Rs. <?php echo number_format($trans['advance_paid'], 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between pt-2 border-top border-secondary-subtle mb-3">
                                <span class="fw-bold">Net Remaining Due:</span>
                                <span id="dispDue" class="fw-bold fs-5">Rs. 0.00</span>
                            </div>
                            
                            <div class="d-flex justify-content-between align-items-center p-3 rounded-3" id="balanceBox" style="background-color: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3);">
                                <span id="balLabel" class="fw-bold small text-success">Customer Refund / Change:</span>
                                <span id="dispBalance" class="fw-bold fs-4 text-success">Rs. 0.00</span>
                            </div>
                        </div>

                        <button type="submit" name="process_return" class="btn btn-luxury-gold btn-lg w-100 fw-bold shadow rounded-pill py-3" id="btnSubmit">
                            <i class="fa-solid fa-check-double me-2"></i> Finalize Return & Print Settlement
                        </button>
                    </form>
                    <?php else: ?>
                        <div class="alert alert-success mt-4 p-4 rounded-4 border-0 shadow-sm">
                            <h5 class="alert-heading fw-bold mb-3 d-flex align-items-center gap-2">
                                <i class="fa-solid fa-circle-check"></i> Agreement Closed & Reconciled
                            </h5>
                            <hr>
                            <div class="d-flex justify-content-between mb-2"><span class="text-secondary">Gross Charge:</span> <span class="fw-bold">Rs. <?php echo number_format($trans['total_lkr'], 2); ?></span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-secondary">Total Cash Tendered:</span> <span class="fw-bold">Rs. <?php echo number_format($trans['received_amount'], 2); ?></span></div>
                            <div class="d-flex justify-content-between"><span class="text-secondary">Change Remitted:</span> <span class="fw-bold text-success">Rs. <?php echo number_format($trans['balance_amount'], 2); ?></span></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const toggleBtn = document.getElementById('themeToggle');
        const html = document.documentElement;

        function updateThemeBtns(isDark) {
            toggleBtn.innerHTML = isDark ? '<i class="fa-solid fa-sun me-1 text-warning"></i> Light Mode' : '<i class="fa-solid fa-moon me-1"></i> Dark Mode';
        }

        if (localStorage.getItem('theme') === 'dark') {
            html.setAttribute('data-bs-theme', 'dark');
            updateThemeBtns(true);
        }

        toggleBtn.addEventListener('click', () => {
            if (html.getAttribute('data-bs-theme') === 'dark') {
                html.setAttribute('data-bs-theme', 'light');
                localStorage.setItem('theme', 'light');
                updateThemeBtns(false);
            } else {
                html.setAttribute('data-bs-theme', 'dark');
                localStorage.setItem('theme', 'dark');
                updateThemeBtns(true);
            }
        });

        // Calculation Logic
        const advance = <?php echo $trans['advance_paid']; ?>;
        
        function calculateSettle() {
            let subtotal = 0;
            
            const inputs = document.querySelectorAll('.item-days');
            inputs.forEach(input => {
                const days = parseInt(input.value) || 0;
                const qty = parseInt(input.getAttribute('data-qty')) || 0;
                const price = parseFloat(input.getAttribute('data-price')) || 0;
                
                const amt = qty * price * Math.max(days, 0);
                subtotal += amt;
                
                const tgtTd = input.parentElement.nextElementSibling;
                tgtTd.innerText = 'Rs. ' + amt.toFixed(2);
            });
            
            const discount = parseFloat(document.getElementById('discountInput').value) || 0;
            const finalFee = Math.max(subtotal - discount, 0);
            
            document.getElementById('finalFee').value = finalFee.toFixed(2);
            document.getElementById('dispFee').innerText = 'Rs. ' + subtotal.toFixed(2);
            document.getElementById('dispDiscount').innerText = '- Rs. ' + discount.toFixed(2);
            
            const newCash = parseFloat(document.getElementById('cashReceived').value) || 0;
            
            let remainingDue = finalFee - advance;
            const totalCash = advance + newCash;
            const balance = totalCash - finalFee;

            const dueEl = document.getElementById('dispDue');
            if (remainingDue > 0) {
                dueEl.innerText = 'Rs. ' + remainingDue.toFixed(2);
                dueEl.className = 'fw-bold fs-5 text-danger';
            } else {
                dueEl.innerText = '(Covered by Advance)';
                dueEl.className = 'fw-bold fs-5 text-success';
            }

            const balBox = document.getElementById('balanceBox');
            const balLabel = document.getElementById('balLabel');
            const dispBal = document.getElementById('dispBalance');
            
            if (balance >= 0) {
                balBox.style.backgroundColor = 'rgba(16, 185, 129, 0.1)';
                balBox.style.borderColor = 'rgba(16, 185, 129, 0.3)';
                balLabel.innerText = 'Change / Excess to Remit:';
                balLabel.className = 'fw-bold small text-success';
                dispBal.innerText = 'Rs. ' + balance.toFixed(2);
                dispBal.className = 'fw-bold fs-4 text-success';
            } else {
                balBox.style.backgroundColor = 'rgba(239, 68, 68, 0.1)';
                balBox.style.borderColor = 'rgba(239, 68, 68, 0.3)';
                balLabel.innerText = 'Outstanding Balance Short:';
                balLabel.className = 'fw-bold small text-danger';
                dispBal.innerText = 'Short Rs. ' + Math.abs(balance).toFixed(2);
                dispBal.className = 'fw-bold fs-4 text-danger';
            }
        }
        
        window.addEventListener('DOMContentLoaded', calculateSettle);
    </script>
</body>
</html>
