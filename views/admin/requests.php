<?php
session_start();
include_once __DIR__ . '/../../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php");
    exit();
}

$user_role = $_SESSION['role'] ?? 'admin';
$is_sa = ($user_role === 'super_admin');
$username = $_SESSION['username'] ?? 'admin';

// $page_type can be preset or extracted from script name or GET parameter
if (!isset($page_type)) {
    $script = basename($_SERVER['PHP_SELF']);
    if ($script === 'requests_qty.php') {
        $page_type = 'quantity';
    } elseif ($script === 'requests_rate.php') {
        $page_type = 'daily_rate';
    } elseif ($script === 'requests_cost.php') {
        $page_type = 'cost_price';
    } else {
        $page_type = isset($_GET['type']) ? trim($_GET['type']) : 'all';
    }
}

$msg = "";
if (isset($_SESSION['req_msg'])) {
    $msg = $_SESSION['req_msg'];
    unset($_SESSION['req_msg']);
}

// 1. Handle Submit New Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $type = trim($_POST['type'] ?? 'quantity');
    $item_id = intval($_POST['item_id'] ?? 0);
    $requested_value = trim($_POST['requested_value'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $redirect_to = trim($_POST['redirect_to'] ?? '');

    // Get current item data
    $i_stmt = $conn->prepare("SELECT * FROM inventory WHERE item_id = ?");
    $i_stmt->bind_param("i", $item_id);
    $i_stmt->execute();
    $item = $i_stmt->get_result()->fetch_assoc();

    if ($item && !empty($requested_value)) {
        $current_value = '';
        if ($type === 'quantity') {
            $current_value = (string)$item['stock_quantity'];
        } elseif ($type === 'daily_rate') {
            $current_value = (string)$item['price_per_unit'];
        } elseif ($type === 'cost_price') {
            $current_value = (string)($item['bought_price'] ?? '');
        }

        $ins = $conn->prepare("INSERT INTO change_requests (type, item_id, item_name, current_value, requested_value, reason, requested_by, status, requested_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
        $ins->bind_param("sisssss", $type, $item_id, $item['item_name'], $current_value, $requested_value, $reason, $username);
        if ($ins->execute()) {
            $new_id = $ins->insert_id;
            $_SESSION['req_msg'] = "<div class='alert alert-success'>Change Request #REQ-" . str_pad($new_id, 3, '0', STR_PAD_LEFT) . " for \"" . htmlspecialchars($item['item_name']) . "\" submitted to Super Admin for approval.</div>";
        } else {
            $_SESSION['req_msg'] = "<div class='alert alert-danger'>Error submitting change request: " . htmlspecialchars($conn->error) . "</div>";
        }
    }
    header("Location: " . ($redirect_to ?: $_SERVER['PHP_SELF']));
    exit();
}

// 2. Handle Super Admin Approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve') {
    if (!$is_sa) {
        die("Access Denied.");
    }
    $req_id = intval($_POST['request_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $redirect_to = trim($_POST['redirect_to'] ?? '');

    $app_stmt = $conn->prepare("UPDATE change_requests SET status = 'approved', approved_by = ?, approved_at = NOW(), sa_notes = ? WHERE id = ?");
    $app_stmt->bind_param("ssi", $username, $notes, $req_id);
    if ($app_stmt->execute()) {
        $_SESSION['req_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-circle-check me-2'></i>Request #REQ-" . str_pad($req_id, 3, '0', STR_PAD_LEFT) . " has been <b>approved</b>! The admin now receives a linked button to edit that item.</div>";
    } else {
        $_SESSION['req_msg'] = "<div class='alert alert-danger'>Failed to approve request.</div>";
    }
    header("Location: " . ($redirect_to ?: $_SERVER['PHP_SELF']));
    exit();
}

// 3. Handle Super Admin Cancel / Reject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel') {
    if (!$is_sa) {
        die("Access Denied.");
    }
    $req_id = intval($_POST['request_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? 'Cancelled by Super Admin');
    $redirect_to = trim($_POST['redirect_to'] ?? '');

    $can_stmt = $conn->prepare("UPDATE change_requests SET status = 'cancelled', approved_by = ?, approved_at = NOW(), sa_notes = ? WHERE id = ?");
    $can_stmt->bind_param("ssi", $username, $reason, $req_id);
    if ($can_stmt->execute()) {
        $_SESSION['req_msg'] = "<div class='alert alert-warning d-flex align-items-center gap-2 shadow-sm'><i class='fa-solid fa-ban fs-5 text-danger'></i><span>Request #REQ-" . str_pad($req_id, 3, '0', STR_PAD_LEFT) . " was <b>cancelled</b> by Super Admin. Item details remain locked at original value.</span></div>";
    } else {
        $_SESSION['req_msg'] = "<div class='alert alert-danger'>Failed to cancel request.</div>";
    }
    header("Location: " . ($redirect_to ?: $_SERVER['PHP_SELF']));
    exit();
}

// Fetch Inventory Items for selection
$items_res = $conn->query("SELECT * FROM inventory ORDER BY item_name ASC");
$all_inventory = [];
while ($it = $items_res->fetch_assoc()) {
    $all_inventory[] = $it;
}

// Compute Request Counts
$counts = [
    'pendingTotal' => 0,
    'pendingQty' => 0,
    'pendingRate' => 0,
    'pendingCost' => 0,
    'approvedTotal' => 0
];

$c_res = $conn->query("SELECT type, status, COUNT(*) as cnt FROM change_requests GROUP BY type, status");
if ($c_res) {
    while ($cr = $c_res->fetch_assoc()) {
        if ($cr['status'] === 'pending') {
            $counts['pendingTotal'] += $cr['cnt'];
            if ($cr['type'] === 'quantity') $counts['pendingQty'] += $cr['cnt'];
            if ($cr['type'] === 'daily_rate') $counts['pendingRate'] += $cr['cnt'];
            if ($cr['type'] === 'cost_price') $counts['pendingCost'] += $cr['cnt'];
        } elseif ($cr['status'] === 'approved') {
            $counts['approvedTotal'] += $cr['cnt'];
        }
    }
}

// Fetch Requests based on $page_type
$q = "SELECT * FROM change_requests";
if ($page_type !== 'all') {
    $q .= " WHERE type = '" . $conn->real_escape_string($page_type) . "'";
}
$q .= " ORDER BY id DESC";
$requests_res = $conn->query($q);
$requests = [];
if ($requests_res) {
    while ($r = $requests_res->fetch_assoc()) {
        $requests[] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MCATS | Inventory Change Requests</title>
    <link rel="icon" type="image/x-icon" href="../../img/ico.ico">
    <!-- Instant theme apply — prevents flash -->
    <script>document.documentElement.setAttribute('data-bs-theme', localStorage.getItem('theme') || 'light');</script>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Luxury Executive Design System -->
    <link rel="stylesheet" href="../../css/luxury.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/navbar.php'; ?>

    <!-- Mobile Navbar -->
    <nav class="navbar navbar-dark d-md-none px-3" style="background: var(--obsidian-sidebar); border-bottom: 1px solid var(--obsidian-border);">
        <span class="navbar-brand mb-0 h1 luxury-brand-title">MCATS ADMIN</span>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block luxury-sidebar offcanvas-md offcanvas-start">
                <div class="position-sticky pt-3 px-3">
                    <div class="d-none d-md-block mb-4 text-center pb-3 border-bottom" style="border-color: var(--obsidian-border) !important;">
                        <img src="../../img/logo.png" style="width: 70px; filter: drop-shadow(0 4px 10px rgba(0,0,0,0.5));" class="mb-2" alt="MCATS Logo">
                        <h4 class="luxury-brand-title m-0">MCATS <?php echo $is_sa ? 'SA' : 'ADMIN'; ?></h4>
                        <small class="text-white-50" style="font-size: 0.725rem; letter-spacing: 0.1em;">CONTROL SUITE</small>
                    </div>
                    <div class="nav flex-column">
                        <?php if ($is_sa): ?>
                            <a href="sahome.php" class="luxury-nav-link"><i class="fa-solid fa-gauge"></i>Dashboard</a>
                            <a href="requests.php" class="luxury-nav-link active d-flex align-items-center justify-content-between">
                                <span><i class="fa-solid fa-shield-halved"></i>Requests Hub</span>
                                <?php if ($counts['pendingTotal'] > 0): ?>
                                    <span class="badge bg-warning text-dark rounded-pill ms-1" style="font-size: 0.65rem;"><?php echo $counts['pendingTotal']; ?></span>
                                <?php endif; ?>
                            </a>
                            <a href="inventory_stats.php" class="luxury-nav-link"><i class="fa-solid fa-chart-pie"></i>Analytics</a>
                            <a href="reports.php" class="luxury-nav-link"><i class="fa-solid fa-file-invoice-dollar"></i>Reports</a>
                            <a href="manage_user.php?action=add" class="luxury-nav-link"><i class="fa-solid fa-users-gear"></i>User Accounts</a>
                        <?php else: ?>
                            <a href="home.php" class="luxury-nav-link"><i class="fa-solid fa-gauge"></i>Dashboard</a>
                            <a href="../pos/sell.php" class="luxury-nav-link"><i class="fa-solid fa-cart-shopping"></i>Selling</a>
                            
                            <!-- Rentals Dropdown -->
                            <a href="#rentalsSubmenu" data-bs-toggle="collapse" class="luxury-nav-link collapsed d-flex align-items-center" role="button" aria-expanded="false">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                                <span>Rentals</span>
                                <i class="fa-solid fa-chevron-down nav-arrow ms-auto"></i>
                            </a>
                            <div class="collapse luxury-submenu" id="rentalsSubmenu">
                                <a href="../pos/rent.php" class="luxury-sub-link">
                                    <i class="fa-solid fa-cart-flatbed"></i> Rental Terminal
                                </a>
                                <a href="../pos/return_items.php" class="luxury-sub-link">
                                    <i class="fa-solid fa-rotate-left"></i> Item Return Desk
                                </a>
                                <a href="../pos/pay_later.php" class="luxury-sub-link">
                                    <i class="fa-solid fa-hand-holding-dollar"></i> Pay Later
                                </a>
                            </div>

                            <!-- Inventory Dropdown -->
                            <a href="#inventorySubmenu" data-bs-toggle="collapse" class="luxury-nav-link active d-flex align-items-center" role="button" aria-expanded="true">
                                <i class="fa-solid fa-boxes-stacked"></i>
                                <span>Inventory</span>
                                <?php if ($counts['pendingTotal'] > 0): ?>
                                    <span class="badge bg-warning text-dark rounded-pill ms-2" style="font-size: 0.65rem;"><?php echo $counts['pendingTotal']; ?></span>
                                <?php endif; ?>
                                <i class="fa-solid fa-chevron-down nav-arrow ms-auto"></i>
                            </a>
                            <div class="collapse show luxury-submenu" id="inventorySubmenu">
                                <a href="inventory.php" class="luxury-sub-link">
                                    <i class="fa-solid fa-warehouse"></i> Stock Inventory
                                </a>
                                <a href="requests_qty.php" class="luxury-sub-link d-flex align-items-center justify-content-between <?php echo $page_type === 'quantity' ? 'active' : ''; ?>">
                                    <span><i class="fa-solid fa-cubes-stacked"></i> Qty Requests</span>
                                    <?php if ($counts['pendingQty'] > 0): ?>
                                        <span class="badge bg-warning text-dark rounded-pill ms-1" style="font-size: 0.62rem;"><?php echo $counts['pendingQty']; ?></span>
                                    <?php endif; ?>
                                </a>
                                <a href="requests_rate.php" class="luxury-sub-link d-flex align-items-center justify-content-between <?php echo $page_type === 'daily_rate' ? 'active' : ''; ?>">
                                    <span><i class="fa-solid fa-tags"></i> Rate Requests</span>
                                    <?php if ($counts['pendingRate'] > 0): ?>
                                        <span class="badge bg-info text-white rounded-pill ms-1" style="font-size: 0.62rem;"><?php echo $counts['pendingRate']; ?></span>
                                    <?php endif; ?>
                                </a>
                                <a href="requests_cost.php" class="luxury-sub-link d-flex align-items-center justify-content-between <?php echo $page_type === 'cost_price' ? 'active' : ''; ?>">
                                    <span><i class="fa-solid fa-key"></i> Cost Requests</span>
                                    <?php if ($counts['pendingCost'] > 0): ?>
                                        <span class="badge bg-secondary text-white rounded-pill ms-1" style="font-size: 0.62rem;"><?php echo $counts['pendingCost']; ?></span>
                                    <?php endif; ?>
                                </a>
                            </div>
                        <?php endif; ?>
                        <a href="../../logout.php" class="luxury-nav-link logout"><i class="fa-solid fa-right-from-bracket"></i>Logout</a>
                    </div>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-2 pb-3 mb-4 border-bottom position-relative" style="border-color: var(--border-subtle) !important;">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="luxury-badge luxury-badge-gold">
                                <i class="fa-solid fa-code-pull-request"></i> 
                                <?php 
                                    if ($page_type === 'quantity') echo 'Stock Quantity Controls';
                                    elseif ($page_type === 'daily_rate') echo 'Daily Rate Controls';
                                    elseif ($page_type === 'cost_price') echo 'Cost Price Cipher Controls';
                                    else echo 'All Change Requests';
                                ?>
                            </span>
                            <span class="text-muted small">&bull; <?php echo $is_sa ? 'Executive Approval Portal' : 'Admin Request Center'; ?></span>
                        </div>
                        <h1 class="h2 mb-0">
                            <?php 
                                if ($page_type === 'quantity') echo 'Item Quantity Edit Requests';
                                elseif ($page_type === 'daily_rate') echo 'Daily Rate Edit Requests';
                                elseif ($page_type === 'cost_price') echo 'Cost Price Edit Requests';
                                else echo 'Inventory Change Requests Hub';
                            ?>
                        </h1>
                        <div id="realtimeClock" class="text-muted small mt-1"></div>
                    </div>
                    
                    <div class="d-flex align-items-center gap-2 mt-3 mt-md-0 flex-wrap">
                        <!-- Dropdown Menu to switch categories -->
                        <div class="dropdown">
                            <button class="btn btn-luxury-outline dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fa-solid fa-list-ul text-warning"></i> Switch Category
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-lg" style="background: var(--bg-surface); border: 1px solid var(--border-subtle);">
                                <li><h6 class="dropdown-header text-uppercase" style="font-size: 0.68rem; color: var(--gold-primary); letter-spacing: 1px;">Request Types</h6></li>
                                <li><a class="dropdown-item py-2 d-flex justify-content-between <?php echo $page_type === 'quantity' ? 'active fw-bold' : ''; ?>" href="requests_qty.php">
                                    <span><i class="fa-solid fa-cubes-stacked text-warning me-2"></i>Item Qty Edit Requests</span>
                                    <span class="badge bg-warning-subtle text-warning"><?php echo $counts['pendingQty']; ?></span>
                                </a></li>
                                <li><a class="dropdown-item py-2 d-flex justify-content-between <?php echo $page_type === 'daily_rate' ? 'active fw-bold' : ''; ?>" href="requests_rate.php">
                                    <span><i class="fa-solid fa-tags text-info me-2"></i>Daily Rate Edit Requests</span>
                                    <span class="badge bg-info-subtle text-info"><?php echo $counts['pendingRate']; ?></span>
                                </a></li>
                                <li><a class="dropdown-item py-2 d-flex justify-content-between <?php echo $page_type === 'cost_price' ? 'active fw-bold' : ''; ?>" href="requests_cost.php">
                                    <span><i class="fa-solid fa-key text-secondary me-2"></i>Cost Price Edit Requests</span>
                                    <span class="badge bg-secondary-subtle text-secondary"><?php echo $counts['pendingCost']; ?></span>
                                </a></li>
                                <li><hr class="dropdown-divider" style="border-color: var(--border-subtle);"></li>
                                <li><a class="dropdown-item py-2 <?php echo $page_type === 'all' ? 'active fw-bold' : ''; ?>" href="requests.php">
                                    <i class="fa-solid fa-table-list me-2"></i>All Requests (Summary)
                                </a></li>
                            </ul>
                        </div>

                        <!-- New Request Modal trigger button -->
                        <button type="button" class="btn btn-luxury-gold" data-bs-toggle="modal" data-bs-target="#newRequestModal">
                            <i class="fa-solid fa-plus me-1"></i> Submit New Request
                        </button>

                        <a href="inventory.php" class="btn btn-luxury-outline">
                            <i class="fa-solid fa-boxes-stacked me-1"></i> Inventory
                        </a>
                    </div>
                </div>

                <?php if ($msg) echo $msg; ?>

                <!-- Navigation Tabs between request pages -->
                <ul class="nav nav-pills mb-4 p-2 rounded-3" style="background: var(--bg-surface-elevated); border: 1px solid var(--border-subtle);">
                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center gap-2 <?php echo $page_type === 'quantity' ? 'active' : 'text-muted'; ?>" href="requests_qty.php">
                            <i class="fa-solid fa-cubes-stacked"></i> Quantity Edit Requests
                            <span class="badge <?php echo $page_type === 'quantity' ? 'bg-dark text-warning' : 'bg-warning-subtle text-warning'; ?>"><?php echo $counts['pendingQty']; ?></span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center gap-2 <?php echo $page_type === 'daily_rate' ? 'active' : 'text-muted'; ?>" href="requests_rate.php">
                            <i class="fa-solid fa-tags"></i> Daily Rate Edit Requests
                            <span class="badge <?php echo $page_type === 'daily_rate' ? 'bg-dark text-info' : 'bg-info-subtle text-info'; ?>"><?php echo $counts['pendingRate']; ?></span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center gap-2 <?php echo $page_type === 'cost_price' ? 'active' : 'text-muted'; ?>" href="requests_cost.php">
                            <i class="fa-solid fa-key"></i> Cost Price Edit Requests
                            <span class="badge <?php echo $page_type === 'cost_price' ? 'bg-dark text-secondary' : 'bg-secondary-subtle text-secondary'; ?>"><?php echo $counts['pendingCost']; ?></span>
                        </a>
                    </li>
                    <li class="nav-item ms-auto">
                        <a class="nav-link d-flex align-items-center gap-2 <?php echo $page_type === 'all' ? 'active' : 'text-muted'; ?>" href="requests.php">
                            <i class="fa-solid fa-bars-staggered"></i> View All
                        </a>
                    </li>
                </ul>

                <!-- KPI Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-sm-6 col-lg-3">
                        <div class="luxury-stat-card p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="text-muted small fw-semibold">Pending Requests</div>
                                    <h3 class="mb-0 fw-bold text-warning"><?php echo $counts['pendingTotal']; ?></h3>
                                </div>
                                <div class="rounded-circle p-3 d-flex align-items-center justify-content-center" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; width: 48px; height: 48px;">
                                    <i class="fa-solid fa-hourglass-half fs-5"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="luxury-stat-card p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="text-muted small fw-semibold">Stock Qty Requests</div>
                                    <h3 class="mb-0 fw-bold text-warning"><?php echo $counts['pendingQty']; ?></h3>
                                </div>
                                <div class="rounded-circle p-3 d-flex align-items-center justify-content-center" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; width: 48px; height: 48px;">
                                    <i class="fa-solid fa-cubes-stacked fs-5"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="luxury-stat-card p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="text-muted small fw-semibold">Daily Rate Requests</div>
                                    <h3 class="mb-0 fw-bold text-info"><?php echo $counts['pendingRate']; ?></h3>
                                </div>
                                <div class="rounded-circle p-3 d-flex align-items-center justify-content-center" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; width: 48px; height: 48px;">
                                    <i class="fa-solid fa-tags fs-5"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="luxury-stat-card p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="text-muted small fw-semibold">Cost Cipher Requests</div>
                                    <h3 class="mb-0 fw-bold text-secondary"><?php echo $counts['pendingCost']; ?></h3>
                                </div>
                                <div class="rounded-circle p-3 d-flex align-items-center justify-content-center" style="background: rgba(148, 163, 184, 0.15); color: #94a3b8; width: 48px; height: 48px;">
                                    <i class="fa-solid fa-key fs-5"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Table Card -->
                <div class="card border-0 rounded-4 shadow-sm" style="background: var(--bg-surface); border: 1px solid var(--border-subtle) !important;">
                    <div class="card-header border-0 bg-transparent p-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h5 class="mb-0 fw-bold">
                                <?php 
                                    if ($page_type === 'quantity') echo 'Quantity Change Requests Registry';
                                    elseif ($page_type === 'daily_rate') echo 'Daily Rate Change Requests Registry';
                                    elseif ($page_type === 'cost_price') echo 'Cost Price Change Requests Registry';
                                    else echo 'All Master Change Requests';
                                ?>
                            </h5>
                            <small class="text-muted">Showing <?php echo count($requests); ?> records</small>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <input type="text" id="tableFilterInput" class="form-control form-control-sm luxury-input" placeholder="Search requests..." onkeyup="filterTable()">
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0" id="requestsTable">
                            <thead>
                                <tr style="border-bottom: 2px solid var(--border-subtle); color: var(--gold-primary); font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em;">
                                    <th>Req ID</th>
                                    <th>Item</th>
                                    <th>Type</th>
                                    <th>Current &rarr; Proposed</th>
                                    <th>Reason</th>
                                    <th>Requested By</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($requests)): ?>
                                    <?php foreach ($requests as $req): ?>
                                        <tr style="border-bottom: 1px solid var(--border-subtle);">
                                            <td class="font-monospace fw-bold text-muted">
                                                #REQ-<?php echo str_pad($req['id'], 3, '0', STR_PAD_LEFT); ?>
                                            </td>
                                            <td>
                                                <div class="fw-bold text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($req['item_name']); ?>">
                                                    <?php echo htmlspecialchars($req['item_name']); ?>
                                                </div>
                                                <small class="text-muted">ID: #<?php echo $req['item_id']; ?></small>
                                            </td>
                                            <td>
                                                <?php if ($req['type'] === 'quantity'): ?>
                                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1">
                                                        <i class="fa-solid fa-cubes-stacked me-1"></i> Quantity
                                                    </span>
                                                <?php elseif ($req['type'] === 'daily_rate'): ?>
                                                    <span class="badge bg-info-subtle text-info border border-info-subtle px-2 py-1">
                                                        <i class="fa-solid fa-tags me-1"></i> Daily Rate
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1">
                                                        <i class="fa-solid fa-key me-1"></i> Cost Price
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="text-muted small text-decoration-line-through">
                                                        <?php echo $req['type'] === 'daily_rate' ? 'Rs. ' : ''; ?><?php echo htmlspecialchars($req['current_value']); ?><?php echo $req['type'] === 'quantity' ? ' units' : ''; ?>
                                                    </span>
                                                    <i class="fa-solid fa-arrow-right small text-warning"></i>
                                                    <span class="fw-bold" style="color: var(--gold-primary);">
                                                        <?php echo $req['type'] === 'daily_rate' ? 'Rs. ' : ''; ?><?php echo htmlspecialchars($req['requested_value']); ?><?php echo $req['type'] === 'quantity' ? ' units' : ''; ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="small" style="max-width: 240px; line-height: 1.4;">
                                                    <?php echo htmlspecialchars($req['reason'] ?: 'No details provided'); ?>
                                                </div>
                                                <?php if (!empty($req['sa_notes'])): ?>
                                                    <div class="mt-1 small" style="font-size: 0.72rem; color: <?php echo in_array($req['status'], ['cancelled', 'rejected']) ? '#f87171' : 'var(--text-muted)'; ?>;">
                                                        <strong>SA Note:</strong> <?php echo htmlspecialchars($req['sa_notes']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="small fw-semibold"><i class="fa-solid fa-user me-1 text-muted"></i><?php echo htmlspecialchars($req['requested_by']); ?></div>
                                                <div class="text-muted" style="font-size: 0.7rem;">
                                                    <?php echo date('M j • g:i A', strtotime($req['requested_at'])); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($req['status'] === 'pending'): ?>
                                                    <span class="badge bg-warning text-dark px-2 py-1">
                                                        <i class="fa-solid fa-hourglass-half me-1"></i> Pending SA
                                                    </span>
                                                <?php elseif ($req['status'] === 'approved'): ?>
                                                    <span class="badge bg-success px-2 py-1">
                                                        <i class="fa-solid fa-check me-1"></i> Approved (Unlocked)
                                                    </span>
                                                <?php elseif (in_array($req['status'], ['rejected', 'cancelled'])): ?>
                                                    <span class="badge bg-danger px-2 py-1">
                                                        <i class="fa-solid fa-ban me-1"></i> Cancelled by SA
                                                    </span>
                                                <?php elseif ($req['status'] === 'completed'): ?>
                                                    <span class="badge bg-secondary px-2 py-1">
                                                        <i class="fa-solid fa-check-double me-1"></i> Completed
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end text-nowrap">
                                                <?php if ($is_sa): ?>
                                                    <?php if ($req['status'] === 'pending'): ?>
                                                        <div class="d-inline-flex gap-1">
                                                            <form method="POST" action="" class="d-inline m-0">
                                                                <input type="hidden" name="action" value="approve">
                                                                <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                                                <button type="submit" class="btn btn-sm btn-success px-2 py-1" title="Accept & Authorize Admin">
                                                                    <i class="fa-solid fa-check me-1"></i> Accept
                                                                </button>
                                                            </form>
                                                            <button type="button" class="btn btn-sm btn-outline-danger px-2 py-1 btn-cancel-req"
                                                                data-req-id="<?php echo $req['id']; ?>"
                                                                data-item-name="<?php echo htmlspecialchars($req['item_name']); ?>"
                                                                data-req-type="<?php echo $req['type']; ?>"
                                                                data-current-val="<?php echo htmlspecialchars($req['current_value']); ?>"
                                                                data-requested-val="<?php echo htmlspecialchars($req['requested_value']); ?>"
                                                                title="Cancel Request (Keep Original Value)">
                                                                <i class="fa-solid fa-ban me-1"></i> Cancel
                                                            </button>
                                                        </div>
                                                    <?php elseif ($req['status'] === 'approved'): ?>
                                                        <div class="d-inline-flex align-items-center gap-1">
                                                            <a href="inventory.php?edit=<?php echo $req['item_id']; ?>&grant_id=<?php echo $req['id']; ?>" class="btn btn-sm btn-luxury-outline py-1 px-2 text-warning" title="View Item">
                                                                <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> View Item
                                                            </a>
                                                            <button type="button" class="btn btn-sm btn-outline-danger py-1 px-2 btn-cancel-req"
                                                                data-req-id="<?php echo $req['id']; ?>"
                                                                data-item-name="<?php echo htmlspecialchars($req['item_name']); ?>"
                                                                data-req-type="<?php echo $req['type']; ?>"
                                                                data-current-val="<?php echo htmlspecialchars($req['current_value']); ?>"
                                                                data-requested-val="<?php echo htmlspecialchars($req['requested_value']); ?>"
                                                                title="Revoke Approval">
                                                                <i class="fa-solid fa-ban"></i>
                                                            </button>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted small">—</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <?php if ($req['status'] === 'approved'): ?>
                                                        <a href="inventory.php?edit=<?php echo $req['item_id']; ?>&grant_id=<?php echo $req['id']; ?>" class="btn btn-sm btn-success py-1 px-2">
                                                            <i class="fa-solid fa-pen-to-square me-1"></i> Apply Change
                                                        </a>
                                                    <?php elseif ($req['status'] === 'pending'): ?>
                                                        <span class="text-warning small fst-italic"><i class="fa-solid fa-hourglass-half me-1"></i>Awaiting SA</span>
                                                    <?php else: ?>
                                                        <span class="text-muted small">—</span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-5 text-muted">
                                            <i class="fa-solid fa-inbox fs-2 d-block mb-2 opacity-50"></i>
                                            No change requests recorded in this section.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- New Request Modal -->
    <div class="modal fade" id="newRequestModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="background: var(--bg-surface); border: 1px solid var(--border-subtle);">
                <div class="modal-header border-bottom" style="border-color: var(--border-subtle) !important;">
                    <h5 class="modal-title fw-bold">
                        <i class="fa-solid fa-paper-plane text-warning me-2"></i> Submit Change Request
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="create">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold">Change Request Category</label>
                            <select name="type" id="modalTypeSelect" class="form-select luxury-input" required onchange="updateCurrentValuePreview()">
                                <option value="quantity" <?php echo $page_type === 'quantity' ? 'selected' : ''; ?>>Stock Quantity Modification</option>
                                <option value="daily_rate" <?php echo $page_type === 'daily_rate' ? 'selected' : ''; ?>>Daily Rate (Price) Adjustment</option>
                                <option value="cost_price" <?php echo $page_type === 'cost_price' ? 'selected' : ''; ?>>Cost Price / BLACKHORSE Cipher Update</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold">Select Target Item</label>
                            <select name="item_id" id="modalItemSelect" class="form-select luxury-input" required onchange="updateCurrentValuePreview()">
                                <option value="" disabled selected>Select an inventory item...</option>
                                <?php foreach ($all_inventory as $inv): ?>
                                    <option value="<?php echo $inv['item_id']; ?>" 
                                            data-qty="<?php echo $inv['stock_quantity']; ?>" 
                                            data-rate="<?php echo $inv['price_per_unit']; ?>" 
                                            data-cost="<?php echo htmlspecialchars($inv['bought_price'] ?? ''); ?>">
                                        #<?php echo $inv['item_id']; ?> - <?php echo htmlspecialchars($inv['item_name']); ?> (Stock: <?php echo $inv['stock_quantity']; ?> | Rs. <?php echo number_format($inv['price_per_unit'], 2); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="p-2 mb-3 rounded-3" style="background: var(--bg-surface-elevated); border: 1px solid var(--border-subtle);">
                            <small class="text-muted d-block" style="font-size: 0.75rem;">CURRENT RECORDED VALUE:</small>
                            <div id="currentValDisplay" class="fw-bold fs-6" style="color: var(--gold-primary);">Select an item</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold" id="requestedValueLabel">Requested New Value</label>
                            <input type="text" name="requested_value" id="requestedValueInput" class="form-control luxury-input" required placeholder="Enter proposed value">
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold">Reason / Operational Justification</label>
                            <textarea name="reason" rows="2" class="form-control luxury-input" placeholder="e.g. Stock replenished from warehouse, seasonal price update, supplier invoice update..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-top" style="border-color: var(--border-subtle) !important;">
                        <button type="button" class="btn btn-luxury-outline" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-luxury-gold fw-bold px-4">
                            <i class="fa-solid fa-paper-plane me-1"></i> Submit to Super Admin
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Super Admin Cancel Modal -->
    <div class="modal fade" id="cancelRequestModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="background: var(--bg-surface); border: 1px solid var(--border-subtle);">
                <div class="modal-header border-bottom" style="border-color: var(--border-subtle) !important;">
                    <h5 class="modal-title fw-bold text-danger">
                        <i class="fa-solid fa-ban me-2"></i> Cancel Change Request
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="request_id" id="cancelReqIdInput" value="">
                    <div class="modal-body p-4">
                        <p class="small text-muted mb-3">
                            As Super Admin, cancelling this change request ensures the original value is <b>retained without modification</b>.
                        </p>

                        <div class="p-3 rounded-3 mb-3" style="background: var(--bg-surface-elevated); border: 1px solid var(--border-subtle);">
                            <div class="d-flex justify-content-between mb-1 small">
                                <span class="text-muted">Item:</span>
                                <strong id="cancelItemNameDisplay">Item</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-1 small">
                                <span class="text-muted">Field:</span>
                                <span class="badge bg-secondary" id="cancelTypeDisplay">Quantity</span>
                            </div>
                            <div class="d-flex justify-content-between mb-1 small">
                                <span class="text-muted">Original Baseline:</span>
                                <strong class="text-success" id="cancelCurrentValDisplay">Kept Unchanged</strong>
                            </div>
                            <div class="d-flex justify-content-between small">
                                <span class="text-muted">Requested Edit:</span>
                                <span class="text-muted text-decoration-line-through" id="cancelRequestedValDisplay">Rejected</span>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold">Reason for Cancellation (Optional)</label>
                            <textarea name="reason" id="cancelReasonInput" rows="2" class="form-control luxury-input" placeholder="e.g. Existing pricing policy maintained, supplier confirmation pending, physical count verified..."></textarea>
                        </div>

                        <div class="alert alert-warning small py-2 mb-0 d-flex align-items-center gap-2">
                            <i class="fa-solid fa-shield-halved fs-5 text-warning flex-shrink-0"></i>
                            <span>Once cancelled, stock quantity, selling rate, or cost code remain locked at their original values.</span>
                        </div>
                    </div>
                    <div class="modal-footer border-top" style="border-color: var(--border-subtle) !important;">
                        <button type="button" class="btn btn-luxury-outline" data-bs-dismiss="modal">Keep Request</button>
                        <button type="submit" class="btn btn-danger fw-bold px-4">
                            <i class="fa-solid fa-ban me-1"></i> Cancel Request & Keep Values
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Realtime Clock Function
        function updateClock() {
            const now = new Date();
            const dateStr = now.toLocaleDateString('en-US', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
            const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
            const el = document.getElementById('realtimeClock');
            if (el) el.innerHTML = '<i class="fa-regular fa-clock me-1"></i> ' + dateStr + ' &bull; ' + timeStr;
        }
        setInterval(updateClock, 1000);
        updateClock();

        // Current Value Preview in Modal
        function updateCurrentValuePreview() {
            const itemSelect = document.getElementById('modalItemSelect');
            const typeSelect = document.getElementById('modalTypeSelect');
            const display = document.getElementById('currentValDisplay');
            const inputLabel = document.getElementById('requestedValueLabel');
            const inputField = document.getElementById('requestedValueInput');

            if (!itemSelect || !typeSelect || !display) return;
            const opt = itemSelect.options[itemSelect.selectedIndex];
            if (!opt || !opt.dataset) {
                display.innerText = 'Select an item';
                return;
            }

            const type = typeSelect.value;
            if (type === 'quantity') {
                display.innerText = (opt.dataset.qty || '0') + ' units in stock';
                inputLabel.innerText = 'Requested New Stock Quantity';
                inputField.type = 'number';
                inputField.placeholder = 'e.g. 15';
            } else if (type === 'daily_rate') {
                display.innerText = 'Rs. ' + Number(opt.dataset.rate || 0).toFixed(2);
                inputLabel.innerText = 'Requested New Selling / Daily Rate (LKR)';
                inputField.type = 'number';
                inputField.step = '0.01';
                inputField.placeholder = 'e.g. 2500.00';
            } else if (type === 'cost_price') {
                display.innerText = opt.dataset.cost ? opt.dataset.cost : 'No cost cipher set';
                inputLabel.innerText = 'Requested Cost Price or BLACKHORSE Cipher';
                inputField.type = 'text';
                inputField.placeholder = 'e.g. 12000 or BLACKHORSE code';
            }
        }

        // Table search filter
        function filterTable() {
            const val = document.getElementById('tableFilterInput').value.toLowerCase().trim();
            const rows = document.querySelectorAll('#requestsTable tbody tr');
            rows.forEach(r => {
                const text = r.innerText.toLowerCase();
                r.style.display = text.includes(val) ? '' : 'none';
            });
        }

        // Cancel Request Modal Helper
        function openCancelModal(reqId, itemName, reqType, currentVal, requestedVal) {
            document.getElementById('cancelReqIdInput').value = reqId;
            document.getElementById('cancelItemNameDisplay').textContent = itemName;
            
            let typeText = 'Quantity';
            let currFormatted = currentVal + ' units';
            let reqFormatted = requestedVal + ' units';
            
            if (reqType === 'daily_rate') {
                typeText = 'Daily Rate (Price)';
                currFormatted = 'Rs. ' + Number(currentVal || 0).toFixed(2);
                reqFormatted = 'Rs. ' + Number(requestedVal || 0).toFixed(2);
            } else if (reqType === 'cost_price') {
                typeText = 'Cost Cipher Code';
                currFormatted = currentVal || 'None';
                reqFormatted = requestedVal || 'None';
            }
            
            document.getElementById('cancelTypeDisplay').textContent = typeText;
            document.getElementById('cancelCurrentValDisplay').textContent = currFormatted + ' (Kept Unchanged)';
            document.getElementById('cancelRequestedValDisplay').textContent = reqFormatted;
            document.getElementById('cancelReasonInput').value = '';
            
            const modal = new bootstrap.Modal(document.getElementById('cancelRequestModal'));
            modal.show();
        }

        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-cancel-req');
            if (!btn) return;
            openCancelModal(
                btn.dataset.reqId,
                btn.dataset.itemName,
                btn.dataset.reqType,
                btn.dataset.currentVal,
                btn.dataset.requestedVal
            );
        });
    </script>
</body>
</html>
