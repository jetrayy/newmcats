<?php
session_start();
include_once __DIR__ . '/../../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php");
    exit();
}

$date_filter = isset($_GET['date']) ? trim($_GET['date']) : '';
$raw_search = isset($_GET['search']) ? trim($_GET['search']) : '';
$raw_bill = isset($_GET['bill_no']) ? trim($_GET['bill_no']) : '';
$clean_bill = ltrim($raw_bill, '#');
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : 'pending';

$payment_success = isset($_GET['payment_success']) ? $_GET['payment_success'] : null;
$paid_bill_id = isset($_GET['bill_id']) ? intval($_GET['bill_id']) : null;
$paid_amount = isset($_GET['paid']) ? floatval($_GET['paid']) : null;
$returned_pay_later = isset($_GET['pay_later']) ? $_GET['pay_later'] : null;

// Fetch all transactions with renting type that are either pay_later or have 'Pay Later' in notes
$sql = "SELECT t.*, c.full_name as c_name, c.phone_number as c_phone, c.address as c_address 
        FROM transactions t 
        LEFT JOIN customers c ON t.customer_nic = c.nic_number 
        WHERE t.type = 'renting' AND (t.status = 'pay_later' OR t.notes LIKE '%Pay Later%')";

$params = [];
$types = "";

if (!empty($date_filter) && $date_filter !== 'all') {
    $sql .= " AND DATE(t.transaction_date) = ?";
    $params[] = $date_filter;
    $types .= "s";
}

$sql .= " ORDER BY t.bill_number DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

$all_bills = [];
$total_outstanding = 0;
$pending_count = 0;
$settled_count = 0;

while ($row = $res->fetch_assoc()) {
    $tid = intval($row['bill_number']);
    $total_charge = floatval($row['total_lkr']);
    $total_received = floatval($row['received_amount']);
    $pending_due = max(0, $total_charge - $total_received);
    $is_settled = ($pending_due <= 0.001 || $row['status'] === 'returned');

    // Fetch items for this transaction
    $item_stmt = $conn->prepare("SELECT ti.quantity, i.item_name FROM transaction_items ti JOIN inventory i ON ti.item_id = i.item_id WHERE ti.bill_number = ?");
    $item_stmt->bind_param("i", $tid);
    $item_stmt->execute();
    $item_res = $item_stmt->get_result();
    $items = [];
    while ($it = $item_res->fetch_assoc()) {
        $items[] = $it;
    }

    $row_data = array_merge($row, [
        'total_charge' => $total_charge,
        'total_paid' => $total_received,
        'pending_due' => $pending_due,
        'is_settled' => $is_settled,
        'items' => $items
    ]);

    if (!$is_settled) {
        $total_outstanding += $pending_due;
        $pending_count++;
    } else {
        $settled_count++;
    }

    $all_bills[] = $row_data;
}

// Filter bills based on filters
$filtered_bills = [];
foreach ($all_bills as $bill) {
    // Status filter
    if ($status_filter === 'pending' && $bill['is_settled']) {
        continue;
    }
    if ($status_filter === 'settled' && !$bill['is_settled']) {
        continue;
    }

    // Dedicated bill filter
    if (!empty($clean_bill)) {
        $b_str = (string)$bill['bill_number'];
        if ($b_str !== $clean_bill && strpos($b_str, $clean_bill) === false) {
            continue;
        }
    }

    // General search filter
    if (!empty($raw_search)) {
        $search_lower = strtolower(ltrim($raw_search, '#'));
        $c_name = strtolower($bill['c_name'] ?? '');
        $c_nic = strtolower($bill['customer_nic'] ?? '');
        $c_phone = strtolower($bill['c_phone'] ?? '');
        $b_str = (string)$bill['bill_number'];
        $notes = strtolower($bill['notes'] ?? '');

        $matched = (strpos($b_str, $search_lower) !== false) ||
                   (strpos($c_name, $search_lower) !== false) ||
                   (strpos($c_nic, $search_lower) !== false) ||
                   (strpos($c_phone, $search_lower) !== false) ||
                   (strpos($notes, $search_lower) !== false);

        if (!$matched) {
            continue;
        }
    }

    $filtered_bills[] = $bill;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MCATS | Pay Later Directory</title>
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
            background: var(--obsidian-sidebar);
            border-bottom: 1px solid var(--obsidian-border);
            padding: 1rem 1.5rem;
        }
        .rental-card {
            background: var(--luxury-card-bg);
            border: 1px solid var(--luxury-card-border);
            border-radius: var(--radius-xl);
            transition: all 0.26s ease;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }
        .rental-card:hover {
            transform: translateY(-4px);
            border-color: rgba(245, 158, 11, 0.5);
            box-shadow: var(--shadow-glow);
            color: inherit;
        }
        .rental-card.settled {
            opacity: 0.88;
            border-color: rgba(16, 185, 129, 0.3);
        }
        .rental-card-footer {
            background: rgba(245, 158, 11, 0.08);
            border-top: 1px solid rgba(245, 158, 11, 0.18);
            color: #d97706;
            font-weight: 700;
            font-size: 0.82rem;
            letter-spacing: 0.04em;
            transition: all 0.2s ease;
        }
        .rental-card:hover .rental-card-footer {
            background: #f59e0b;
            color: #070a12;
        }
        .stat-card {
            background: var(--luxury-card-bg);
            border: 1px solid var(--luxury-card-border);
            border-radius: var(--radius-xl);
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1.25rem;
            box-shadow: var(--shadow-sm);
        }
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
        }
    </style>
</head>
<body>

    <!-- Global Navigation Bar -->
    <?php include_once __DIR__ . '/../includes/navbar.php'; ?>

    <!-- TOP BAR -->
    <div class="top-navbar d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center rounded-circle" style="width: 42px; height: 42px; background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.3);">
                <i class="fa-solid fa-clock-rotate-left" style="color: #f59e0b;"></i>
            </div>
            <div>
                <h5 class="mb-0 fw-bold text-white d-flex align-items-center gap-2" style="font-family: 'Outfit', sans-serif; letter-spacing: 0.04em;">
                    Pay Later Directory
                    <span class="badge bg-warning text-dark fs-6" style="font-family: 'Outfit', sans-serif;">
                        <?php echo $total_outstanding > 0 ? ('Rs. ' . number_format($total_outstanding, 2)) : 'Active'; ?>
                    </span>
                </h5>
                <small class="text-secondary">Customer deferred balances & partial payment settlements</small>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="return_items.php" class="btn btn-sm btn-outline-secondary text-white rounded-pill px-3 shadow-sm border-secondary fw-semibold">
                <i class="fa-solid fa-rotate-left me-1"></i> Return Desk
            </a>
            <a href="rent.php" class="btn btn-sm btn-luxury-gold rounded-pill px-3 fw-bold shadow-sm">
                <i class="fa-solid fa-cart-flatbed me-1"></i> Rentals Terminal
            </a>
            <a href="../admin/home.php" class="btn btn-sm btn-outline-secondary text-white rounded-pill px-3 fw-semibold shadow-sm border-secondary">
                <i class="fa-solid fa-gauge me-1"></i> Hub
            </a>
        </div>
    </div>

    <div class="container-fluid py-4 flex-grow-1" style="max-width: 1440px; margin: 0 auto;">

        <!-- Flash Notice if just returned with Pay Later -->
        <?php if ($returned_pay_later): ?>
            <div class="alert alert-warning alert-dismissible fade show rounded-4 border-0 shadow-sm p-3 mb-4 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-warning text-dark d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                        <i class="fa-solid fa-clock-rotate-left fs-5"></i>
                    </div>
                    <div>
                        <strong class="d-block text-dark">Rental Return Logged Under Pay Later!</strong>
                        <span class="text-secondary small">
                            Equipment for Docket #<?php echo $paid_bill_id; ?> has been received back into inventory. The outstanding balance is tracked below.
                        </span>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <a href="print_bill.php?type=a4&bill_id=<?php echo $paid_bill_id; ?>" class="btn btn-sm btn-luxury-gold rounded-pill px-3 fw-bold">
                        <i class="fa-solid fa-print me-1"></i> Print Bill
                    </a>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            </div>
        <?php endif; ?>

        <!-- Flash Notice if payment recorded -->
        <?php if ($payment_success): ?>
            <div class="alert alert-success alert-dismissible fade show rounded-4 border-0 shadow-sm p-3 mb-4 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                        <i class="fa-solid fa-check fs-5"></i>
                    </div>
                    <div>
                        <strong class="d-block text-dark">Payment Recorded Successfully!</strong>
                        <span class="text-secondary small">
                            Docket #<?php echo $paid_bill_id; ?> updated. Received Rs. <?php echo number_format($paid_amount, 2); ?> towards outstanding balance.
                        </span>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <a href="print_bill.php?type=a4&bill_id=<?php echo $paid_bill_id; ?>" class="btn btn-sm btn-luxury-gold rounded-pill px-3 fw-bold">
                        <i class="fa-solid fa-print me-1"></i> Print Bill
                    </a>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            </div>
        <?php endif; ?>

        <!-- METRICS ROW -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(239, 68, 68, 0.12); color: #ef4444;">
                        <i class="fa-solid fa-hand-holding-dollar"></i>
                    </div>
                    <div>
                        <span class="text-secondary small fw-bold text-uppercase" style="letter-spacing: 0.05em;">Total Outstanding Balance</span>
                        <h4 class="mb-0 fw-bold text-danger" style="font-family: 'Outfit', sans-serif;">
                            Rs. <?php echo number_format($total_outstanding, 2); ?>
                        </h4>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(245, 158, 11, 0.12); color: #f59e0b;">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                    <div>
                        <span class="text-secondary small fw-bold text-uppercase" style="letter-spacing: 0.05em;">Pending Pay Later Dues</span>
                        <h4 class="mb-0 fw-bold text-warning" style="font-family: 'Outfit', sans-serif;">
                            <?php echo $pending_count; ?> <span class="fs-6 fw-normal text-secondary">pending bills</span>
                        </h4>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(16, 185, 129, 0.12); color: #10b981;">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <div>
                        <span class="text-secondary small fw-bold text-uppercase" style="letter-spacing: 0.05em;">Settled Accounts</span>
                        <h4 class="mb-0 fw-bold text-success" style="font-family: 'Outfit', sans-serif;">
                            <?php echo $settled_count; ?> <span class="fs-6 fw-normal text-secondary">settled bills</span>
                        </h4>
                    </div>
                </div>
            </div>
        </div>

        <!-- SEARCH & DIRECTORIES BAR -->
        <div class="card border-0 rounded-4 shadow-sm p-3 mb-4" style="background: var(--luxury-card-bg); border: 1px solid var(--luxury-card-border) !important;">
            <form method="GET" action="pay_later.php" class="row g-2 align-items-center">
                <!-- Directory 1: Unique Bill ID (#12345) -->
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-secondary mb-1 text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">
                        <i class="fa-solid fa-hashtag" style="color: var(--gold-primary);"></i> Bill # Directory
                    </label>
                    <div class="input-group">
                        <span class="input-group-text bg-transparent border-secondary-subtle fw-bold" style="color: var(--gold-primary); font-family: monospace;">#</span>
                        <input type="text" name="bill_no" class="form-control border-secondary-subtle font-monospace fw-bold" placeholder="e.g. 101 or #12345" value="<?php echo htmlspecialchars($raw_bill); ?>">
                    </div>
                </div>

                <!-- Directory 2: Customer / General Search -->
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-secondary mb-1 text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">
                        <i class="fa-solid fa-user-tag" style="color: var(--gold-primary);"></i> Customer Directory
                    </label>
                    <div class="input-group">
                        <span class="input-group-text bg-transparent border-secondary-subtle text-secondary">
                            <i class="fa-solid fa-magnifying-glass"></i>
                        </span>
                        <input type="text" name="search" class="form-control border-secondary-subtle" placeholder="Search #12345, customer name, NIC or phone..." value="<?php echo htmlspecialchars($raw_search); ?>">
                    </div>
                </div>

                <!-- Status Filter -->
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-secondary mb-1 text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Status</label>
                    <select name="status" class="form-select border-secondary-subtle" onchange="this.form.submit()">
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending Only</option>
                        <option value="settled" <?php echo $status_filter === 'settled' ? 'selected' : ''; ?>>Fully Settled</option>
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Bills</option>
                    </select>
                </div>

                <!-- Date Filter -->
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-secondary mb-1 text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Date</label>
                    <input type="date" name="date" class="form-control border-secondary-subtle" value="<?php echo htmlspecialchars($date_filter); ?>" onchange="this.form.submit()">
                </div>

                <!-- Action buttons -->
                <div class="col-md-1 d-flex gap-1 align-items-end" style="padding-top: 1.25rem;">
                    <button type="submit" class="btn btn-luxury-gold flex-grow-1 rounded-3" title="Search Directories">
                        <i class="fa-solid fa-search"></i>
                    </button>
                    <a href="pay_later.php" class="btn btn-outline-secondary rounded-3" title="Reset Filters">
                        <i class="fa-solid fa-rotate"></i>
                    </a>
                </div>
            </form>
        </div>

        <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="fw-bold mb-0 text-white d-flex align-items-center gap-2">
                <i class="fa-solid fa-clock-rotate-left" style="color: #f59e0b;"></i> Pay Later Records (<?php echo count($filtered_bills); ?>)
            </h5>
        </div>

        <!-- Bills Grid: Format matches return_items exactly -->
        <div class="row g-4">
            <?php if (!empty($filtered_bills)): ?>
                <?php foreach ($filtered_bills as $bill): 
                    $tid = $bill['bill_number'];
                    $cname = $bill['c_name'] ?: "Walk-in Client";
                    $cphone = $bill['c_phone'] ?: "No Phone";
                    $cnic = $bill['customer_nic'] ?: "Unspecified";
                    $is_settled = (bool)$bill['is_settled'];
                    $pending_due = floatval($bill['pending_due']);
                    $date_fmt = date('M j, Y', strtotime($bill['transaction_date'])) . ' &bull; ' . date('g:i A', strtotime($bill['transaction_date']));
                ?>
                <div class="col-md-6 col-lg-4 col-xl-3">
                    <div class="rental-card h-100 <?php echo $is_settled ? 'settled' : ''; ?>">
                        <div class="p-3 border-bottom border-secondary-subtle d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-bold fs-6 mb-1 text-truncate" title="<?php echo htmlspecialchars($cname); ?>"><?php echo htmlspecialchars($cname); ?></div>
                                <div class="text-secondary small">
                                    <i class="fa-regular fa-id-card me-1"></i><?php echo htmlspecialchars($cnic); ?>
                                </div>
                                <div class="text-secondary small">
                                    <i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($cphone); ?>
                                </div>
                            </div>
                            <span class="badge" style="background: rgba(212, 175, 55, 0.15); color: var(--gold-primary); font-family: 'Outfit', sans-serif; font-size: 0.85rem; padding: 0.35rem 0.6rem;">
                                #<?php echo $tid; ?>
                            </span>
                        </div>
                        
                        <div class="p-3 flex-grow-1">
                            <div class="d-flex justify-content-between mb-2 small">
                                <span class="text-secondary">Agreement Timestamp:</span>
                                <span class="fw-semibold small"><?php echo $date_fmt; ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2 small">
                                <span class="text-secondary">Total Lease Charge:</span>
                                <span class="fw-bold">Rs. <?php echo number_format($bill['total_charge'], 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2 small">
                                <span class="text-secondary">Paid to Date:</span>
                                <span class="fw-bold text-success">Rs. <?php echo number_format($bill['total_paid'], 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-3 small p-2 rounded-2 <?php echo $is_settled ? 'bg-success bg-opacity-10 border border-success border-opacity-25' : 'bg-danger bg-opacity-10 border border-danger border-opacity-25'; ?>">
                                <span class="fw-bold <?php echo $is_settled ? 'text-success' : 'text-danger'; ?>"><?php echo $is_settled ? 'Status:' : 'Pay Later Due:'; ?></span>
                                <span class="fw-bold <?php echo $is_settled ? 'text-success' : 'text-danger'; ?> fs-6">
                                    <?php echo $is_settled ? 'Fully Settled' : ('Rs. ' . number_format($pending_due, 2)); ?>
                                </span>
                            </div>
                            
                            <div class="p-2 rounded-3 small" style="background: rgba(0, 0, 0, 0.03); border: 1px solid var(--luxury-card-border);">
                                <strong class="d-block mb-1 text-secondary" style="font-size: 0.76rem; text-uppercase; letter-spacing: 0.05em;">
                                    <i class="fa-solid fa-camera-retro me-1" style="color: var(--gold-primary);"></i> Dispatched Equipment:
                                </strong>
                                <ul class="mb-0 ps-3 text-secondary" style="font-size: 0.8rem;">
                                    <?php if (!empty($bill['items'])): ?>
                                        <?php 
                                        $display_items = array_slice($bill['items'], 0, 3);
                                        foreach ($display_items as $it): 
                                        ?>
                                            <li><?php echo $it['quantity']; ?>× <?php echo htmlspecialchars($it['item_name']); ?></li>
                                        <?php endforeach; ?>
                                        <?php if (count($bill['items']) > 3): ?>
                                            <li class='fst-italic'>+ <?php echo (count($bill['items']) - 3); ?> additional items...</li>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <li class='fst-italic'>Rental package items</li>
                                    <?php endif; ?>
                                </ul>
                            </div>

                            <?php if (!empty($bill['notes'])): ?>
                                <div class="mt-2 p-1 px-2 rounded bg-warning bg-opacity-10 border border-warning border-opacity-25 text-dark" style="font-size: 0.78rem;">
                                    <i class="fa-solid fa-note-sticky me-1 text-warning"></i> <?php echo htmlspecialchars($bill['notes']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="p-2 px-3 border-top border-secondary-subtle d-flex gap-2 align-items-center">
                            <?php if (!$is_settled): ?>
                                <a href="print_bill.php?type=a4&bill_id=<?php echo $tid; ?>&collect=1" target="_blank" class="btn btn-warning btn-sm rounded-pill fw-bold text-dark flex-grow-1 shadow-sm d-flex align-items-center justify-content-center gap-2 py-2">
                                    <i class="fa-solid fa-hand-holding-dollar"></i> Collect Payment
                                </a>
                            <?php else: ?>
                                <a href="print_bill.php?type=a4&bill_id=<?php echo $tid; ?>" target="_blank" class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 py-2 px-3 flex-grow-1 text-center text-decoration-none">
                                    <i class="fa-solid fa-circle-check me-1"></i> Paid & Settled
                                </a>
                            <?php endif; ?>
                            <a href="print_bill.php?type=a4&bill_id=<?php echo $tid; ?>" target="_blank" class="btn btn-outline-secondary btn-sm rounded-pill px-3" title="View / Print A4 Bill">
                                <i class="fa-solid fa-print"></i>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12">
                    <div class="text-center py-5 rounded-4 border border-secondary-subtle" style="background: var(--luxury-card-bg);">
                        <i class="fa-solid fa-clipboard-check fs-1 d-block mb-3 text-warning opacity-50"></i>
                        <h4 class="fw-bold">No Pay Later Bills Found</h4>
                        <p class="text-secondary small mb-0">No deferred credit records match your search criteria or date filter.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
