<?php
session_start();
include_once __DIR__ . '/../../db.php'; // Path to your mcats database connection

// SECURITY: Only allow Super Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../../index.php");
    exit();
}

// 1. Fetch Total Sales for Stats View
$total_query = "SELECT SUM(received_amount - balance_amount) as all_total FROM transactions";
$sales_result = $conn->query($total_query);
$sales_data = $sales_result->fetch_assoc();
$total_sales = $sales_data['all_total'] ?? 0;

// 1.5 Fetch Today Sales
$today_start = date('Y-m-d 00:00:00');
$today_end = date('Y-m-d 23:59:59');
$today_query = "SELECT SUM(received_amount - balance_amount) as today_total FROM transactions WHERE transaction_date BETWEEN ? AND ?";
$stmt_t = $conn->prepare($today_query);
$stmt_t->bind_param("ss", $today_start, $today_end);
$stmt_t->execute();
$today_res = $stmt_t->get_result()->fetch_assoc();
$today_sales = $today_res['today_total'] ?? 0;

// 1.8 Handle SA Approve / Cancel Request Actions directly from sahome.php
$msg = "";
if (isset($_SESSION['req_msg'])) {
    $msg = $_SESSION['req_msg'];
    unset($_SESSION['req_msg']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve') {
    $req_id = intval($_POST['request_id'] ?? 0);
    $app_stmt = $conn->prepare("UPDATE change_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
    $app_stmt->bind_param("si", $_SESSION['username'], $req_id);
    if ($app_stmt->execute()) {
        $_SESSION['req_msg'] = "<div class='alert alert-success d-flex align-items-center gap-2 mb-4 shadow-sm'><i class='fa-solid fa-circle-check fs-5'></i><span>Change Request #REQ-" . str_pad($req_id, 3, '0', STR_PAD_LEFT) . " has been <b>approved</b>! The cashier is authorized to update the item.</span></div>";
    }
    header("Location: sahome.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel') {
    $req_id = intval($_POST['request_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? 'Cancelled by Super Admin');
    $can_stmt = $conn->prepare("UPDATE change_requests SET status = 'cancelled', approved_by = ?, approved_at = NOW(), sa_notes = ? WHERE id = ?");
    $can_stmt->bind_param("ssi", $_SESSION['username'], $reason, $req_id);
    if ($can_stmt->execute()) {
        $_SESSION['req_msg'] = "<div class='alert alert-warning d-flex align-items-center gap-2 mb-4 shadow-sm'><i class='fa-solid fa-ban fs-5 text-danger'></i><span>Change Request #REQ-" . str_pad($req_id, 3, '0', STR_PAD_LEFT) . " was <b>cancelled</b>. Item values remain locked.</span></div>";
    }
    header("Location: sahome.php");
    exit();
}

// 2. Fetch Pending Change Requests for the Accept / Cancel Approval Panel
$pending_reqs_query = "SELECT * FROM change_requests WHERE status='pending' ORDER BY id DESC";
$pending_reqs_res = $conn->query($pending_reqs_query);
$pending_requests = [];
if ($pending_reqs_res) {
    while ($pr = $pending_reqs_res->fetch_assoc()) {
        $pending_requests[] = $pr;
    }
}

// 2.2 Fetch Pending counts for categories
$req_counts = ['pendingTotal' => 0, 'pendingQty' => 0, 'pendingRate' => 0, 'pendingCost' => 0];
$c_res = $conn->query("SELECT type, COUNT(*) as cnt FROM change_requests WHERE status='pending' GROUP BY type");
if ($c_res) {
    while ($cr = $c_res->fetch_assoc()) {
        $req_counts['pendingTotal'] += $cr['cnt'];
        if ($cr['type'] === 'quantity') $req_counts['pendingQty'] += $cr['cnt'];
        if ($cr['type'] === 'daily_rate') $req_counts['pendingRate'] += $cr['cnt'];
        if ($cr['type'] === 'cost_price') $req_counts['pendingCost'] += $cr['cnt'];
    }
}

// 3. Fetch All Admin Accounts for the User Management Table
$users_result = $conn->query("SELECT user_id, username, role FROM users ORDER BY user_id ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MCATS | Super Admin Dashboard</title>
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

    <!-- Mobile Navbar (Sidebar trigger for smaller viewports) -->
    <nav class="navbar navbar-dark d-md-none px-3" style="background: var(--obsidian-sidebar); border-bottom: 1px solid var(--obsidian-border);">
        <span class="navbar-brand mb-0 h1 luxury-brand-title">MCATS SA</span>
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
                        <h4 class="luxury-brand-title m-0">MCATS SA</h4>
                        <small class="text-white-50" style="font-size: 0.725rem; letter-spacing: 0.1em;">EXECUTIVE CONTROL</small>
                    </div>
                    <div class="nav flex-column">
                        <a href="sahome.php" class="luxury-nav-link active"><i class="fa-solid fa-gauge"></i>Dashboard</a>
                        
                        <!-- Request Approvals Hub -->
                        <a href="requests.php" class="luxury-nav-link d-flex align-items-center justify-content-between">
                            <span><i class="fa-solid fa-shield-halved"></i>Requests Hub</span>
                            <?php if ($req_counts['pendingTotal'] > 0): ?>
                                <span class="badge bg-warning text-dark rounded-pill ms-1" style="font-size: 0.65rem;"><?php echo $req_counts['pendingTotal']; ?></span>
                            <?php endif; ?>
                        </a>

                        <!-- Analytics & Reports -->
                        <a href="inventory_stats.php" class="luxury-nav-link"><i class="fa-solid fa-chart-pie"></i>Analytics</a>
                        <a href="reports.php" class="luxury-nav-link"><i class="fa-solid fa-file-invoice-dollar"></i>Reports</a>

                        <!-- User Management -->
                        <a href="manage_user.php?action=add" class="luxury-nav-link"><i class="fa-solid fa-users-gear"></i>User Accounts</a>
                        
                        <a href="../../logout.php" class="luxury-nav-link logout"><i class="fa-solid fa-right-from-bracket"></i>Logout</a>
                    </div>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-2 pb-3 mb-4 border-bottom position-relative" style="border-color: var(--border-subtle) !important;">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="luxury-badge luxury-badge-gold"><i class="fa-solid fa-crown"></i> Super Admin</span>
                            <span class="text-muted small">Full System Access</span>
                        </div>
                        <h1 class="h2 mb-0">Executive Dashboard</h1>
                        <div id="realtimeClock" class="text-muted small mt-1"></div>
                    </div>
                    <div class="d-flex align-items-center gap-3 mt-3 mt-md-0">
                        <div class="d-none d-sm-flex align-items-center gap-2 px-3 py-2 rounded-3" style="background: var(--bg-surface-elevated); border: 1px solid var(--border-subtle);">
                            <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 28px; height: 28px; background: var(--gold-subtle-bg); color: var(--gold-primary);">
                                <i class="fa-solid fa-user-shield" style="font-size: 0.8rem;"></i>
                            </div>
                            <span class="small">Administrator: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong></span>
                        </div>
                        <button id="themeToggle" class="theme-toggle-btn"><i class="fa-solid fa-moon me-1"></i> Dark Mode</button>
                    </div>
                </div>

                <?php if (!empty($msg)) echo $msg; ?>

                <!-- STATS CARDS -->
                <div class="row g-4 mb-4">
                    <div class="col-md-6 col-lg-4">
                        <div class="luxury-stat-card">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.05em;">Today's Revenue</span>
                                <div class="rounded-3 p-2" style="background: rgba(16, 185, 129, 0.12); color: var(--success-emerald);">
                                    <i class="fa-solid fa-chart-line fs-5"></i>
                                </div>
                            </div>
                            <div class="luxury-stat-value my-2">Rs. <?php echo number_format($today_sales, 2); ?></div>
                            <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top" style="border-color: var(--border-subtle) !important;">
                                <span class="text-muted small">Daily net collections</span>
                                <a href="reports.php" class="text-decoration-none fw-semibold small" style="color: var(--gold-primary);">
                                    Audit Details <i class="fa-solid fa-arrow-right ms-1"></i>
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-4">
                        <div class="luxury-stat-card">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.05em;">All-Time Gross Sales</span>
                                <div class="rounded-3 p-2" style="background: var(--gold-subtle-bg); color: var(--gold-primary);">
                                    <i class="fa-solid fa-vault fs-5"></i>
                                </div>
                            </div>
                            <div class="luxury-stat-value my-2" style="color: var(--gold-primary);">Rs. <?php echo number_format($total_sales, 2); ?></div>
                            <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top" style="border-color: var(--border-subtle) !important;">
                                <span class="text-muted small">Total system turnover</span>
                                <a href="reports.php" class="text-decoration-none fw-semibold small" style="color: var(--gold-primary);">
                                    Full Statement <i class="fa-solid fa-arrow-right ms-1"></i>
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-4">
                        <div class="luxury-stat-card">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.05em;">Pending Edits</span>
                                <div class="rounded-3 p-2" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b;">
                                    <i class="fa-solid fa-bell-concierge fs-5"></i>
                                </div>
                            </div>
                            <div class="luxury-stat-value my-2" style="color: #f59e0b;"><?php echo count($pending_requests); ?></div>
                            <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top" style="border-color: var(--border-subtle) !important;">
                                <span class="text-muted small">Price / Qty / Cost requests</span>
                                <a href="requests.php" class="text-decoration-none fw-semibold small" style="color: #f59e0b;">
                                    View Audit Hub <i class="fa-solid fa-arrow-right ms-1"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- INCOMING CHANGE REQUESTS QUEUE (ACCEPT / CANCEL PANEL) -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 mt-4">
                    <div>
                        <h4 class="mb-0 fw-bold d-flex align-items-center gap-2">
                            <span>Incoming Change Requests Queue</span>
                            <?php if (count($pending_requests) > 0): ?>
                                <span class="badge bg-warning text-dark fs-6"><?php echo count($pending_requests); ?> Pending</span>
                            <?php endif; ?>
                        </h4>
                        <small class="text-muted">Cashier edit requests for Stock Quantity, Daily Rate, and Cost Cipher Code awaiting Super Admin approval</small>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <a href="requests.php" class="btn btn-sm btn-luxury-outline">
                            <i class="fa-solid fa-list-check me-1"></i> Full Request Registry &rarr;
                        </a>
                    </div>
                </div>

                <?php if (count($pending_requests) > 0): ?>
                <div class="luxury-card overflow-hidden mb-5">
                    <div class="table-responsive">
                        <table class="luxury-table align-middle">
                            <thead>
                                <tr>
                                    <th>Req ID</th>
                                    <th>Target Item</th>
                                    <th>Category Type</th>
                                    <th>Current &rarr; Proposed</th>
                                    <th>Cashier Reason</th>
                                    <th>Requested By</th>
                                    <th class="text-end">Actions (Approve / Cancel)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_requests as $req): ?>
                                <tr>
                                    <td class="fw-bold text-muted" style="font-family: var(--font-heading);">
                                        #REQ-<?php echo str_pad($req['id'], 3, '0', STR_PAD_LEFT); ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-main"><?php echo htmlspecialchars($req['item_name']); ?></div>
                                        <small class="text-muted">Item ID #<?php echo $req['item_id']; ?></small>
                                    </td>
                                    <td>
                                        <?php if ($req['type'] === 'quantity'): ?>
                                            <span class="badge bg-warning text-dark"><i class="fa-solid fa-cubes-stacked me-1"></i> Quantity</span>
                                        <?php elseif ($req['type'] === 'daily_rate'): ?>
                                            <span class="badge bg-info text-dark"><i class="fa-solid fa-tags me-1"></i> Daily Rate</span>
                                        <?php elseif ($req['type'] === 'cost_price'): ?>
                                            <span class="badge bg-secondary"><i class="fa-solid fa-key me-1"></i> Cost Cipher Code</span>
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
                                        <div class="small" style="max-width: 250px; line-height: 1.4;"><?php echo htmlspecialchars($req['reason'] ?: 'No details specified'); ?></div>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold"><i class="fa-solid fa-user me-1 text-muted"></i><?php echo htmlspecialchars($req['requested_by']); ?></div>
                                        <div class="text-muted" style="font-size: 0.7rem;"><?php echo date('M j • g:i A', strtotime($req['requested_at'])); ?></div>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <div class="d-inline-flex gap-2">
                                            <form method="POST" action="sahome.php" class="d-inline m-0">
                                                <input type="hidden" name="action" value="approve">
                                                <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-success px-3 py-1" title="Accept & Authorize Admin">
                                                    <i class="fa-solid fa-check me-1"></i> Accept
                                                </button>
                                            </form>
                                            <button type="button" class="btn btn-sm btn-outline-danger px-3 py-1 btn-cancel-req"
                                                data-req-id="<?php echo $req['id']; ?>"
                                                data-item-name="<?php echo htmlspecialchars($req['item_name']); ?>"
                                                data-req-type="<?php echo $req['type']; ?>"
                                                data-current-val="<?php echo htmlspecialchars($req['current_value']); ?>"
                                                data-requested-val="<?php echo htmlspecialchars($req['requested_value']); ?>"
                                                title="Cancel Request (Keep Original Value)">
                                                <i class="fa-solid fa-ban me-1"></i> Cancel
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="luxury-card p-3 mb-5 d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-2 text-muted small">
                        <i class="fa-solid fa-circle-check text-success fs-5"></i>
                        <span>Zero pending change requests. All item quantities, selling rates, and cost codes are up to date.</span>
                    </div>
                    <a href="requests.php" class="small text-decoration-none" style="color: var(--gold-primary);">View Audit Trail &rarr;</a>
                </div>
                <?php endif; ?>

                <!-- USER MANAGEMENT -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 mt-4">
                    <div>
                        <h4 class="mb-0 fw-bold">Admin Accounts & Security</h4>
                        <small class="text-muted">Manage staff credentials and access roles</small>
                    </div>
                    <a href="manage_user.php?action=add" class="btn btn-luxury-gold shadow-sm">
                        <i class="fa-solid fa-user-plus me-1"></i> Create New Account
                    </a>
                </div>

                <div class="luxury-card overflow-hidden">
                    <div class="table-responsive">
                        <table class="luxury-table align-middle">
                            <thead>
                                <tr>
                                    <th>User ID</th>
                                    <th>Username</th>
                                    <th>Assigned Role</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($row = $users_result->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold text-muted" style="font-family: var(--font-heading);">
                                        #<?php echo str_pad(htmlspecialchars($row['user_id']), 4, '0', STR_PAD_LEFT); ?>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; background: var(--bg-surface-elevated); border: 1px solid var(--border-subtle); color: var(--gold-primary);">
                                                <i class="fa-solid fa-user" style="font-size: 0.75rem;"></i>
                                            </div>
                                            <span class="fw-semibold"><?php echo htmlspecialchars($row['username']); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if($row['role'] == 'super_admin'): ?>
                                            <span class="luxury-badge luxury-badge-gold"><i class="fa-solid fa-crown"></i> SUPER ADMIN</span>
                                        <?php else: ?>
                                            <span class="luxury-badge luxury-badge-slate"><i class="fa-solid fa-shield"></i> ADMIN</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="manage_user.php?edit=<?php echo htmlspecialchars($row['user_id']); ?>" class="btn btn-sm btn-luxury-outline py-1 px-2">
                                            <i class="fa-solid fa-key"></i> Edit Pwd
                                        </a>
                                        <?php if($row['username'] !== $_SESSION['username']): ?>
                                            <a href="manage_user.php?delete=<?php echo htmlspecialchars($row['user_id']); ?>" class="btn btn-sm btn-outline-danger py-1 px-2 ms-1 rounded-2" onclick="return confirm('Are you sure you want to delete this user?');" style="border-width: 1px;">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Super Admin Cancel Request Confirmation Modal -->
    <div class="modal fade" id="cancelRequestModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="background: var(--bg-surface); border: 1px solid var(--border-subtle); color: var(--text-primary);">
                <form action="sahome.php" method="POST" id="cancelRequestForm">
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="request_id" id="cancelReqIdInput">
                    <div class="modal-header border-bottom" style="border-color: var(--border-subtle) !important;">
                        <h5 class="modal-title fw-bold text-danger d-flex align-items-center gap-2">
                            <i class="fa-solid fa-ban"></i>
                            <span>Cancel Edit Request</span>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-4">
                        <p class="mb-3">Are you sure you want to cancel this request? <strong>The target item will strictly keep its existing catalog values.</strong></p>
                        
                        <div class="p-3 rounded-3 mb-3" style="background: var(--bg-surface-elevated); border: 1px solid var(--border-subtle);">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="small text-muted">Item:</span>
                                <span class="fw-bold" id="cancelItemNameDisplay"></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="small text-muted">Category:</span>
                                <span class="badge bg-secondary" id="cancelTypeDisplay"></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="small text-muted">Proposed Edit:</span>
                                <span class="small text-muted text-decoration-line-through" id="cancelRequestedValDisplay"></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center pt-2 border-top" style="border-color: var(--border-subtle) !important;">
                                <span class="small text-muted">Value Kept:</span>
                                <span class="small text-success fw-bold" id="cancelCurrentValDisplay"></span>
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
        const toggleBtn = document.getElementById('themeToggle');
        const html = document.documentElement;

        function updateToggleText() {
            if (html.getAttribute('data-bs-theme') === 'dark') {
                toggleBtn.innerHTML = '<i class="fa-solid fa-sun text-warning me-1"></i> Light Mode';
            } else {
                toggleBtn.innerHTML = '<i class="fa-solid fa-moon text-info me-1"></i> Dark Mode';
            }
        }

        if (localStorage.getItem('theme') === 'dark') {
            html.setAttribute('data-bs-theme', 'dark');
            updateToggleText();
        }

        toggleBtn.addEventListener('click', () => {
            if (html.getAttribute('data-bs-theme') === 'dark') {
                html.setAttribute('data-bs-theme', 'light');
                localStorage.setItem('theme', 'light');
            } else {
                html.setAttribute('data-bs-theme', 'dark');
                localStorage.setItem('theme', 'dark');
            }
            updateToggleText();
        });

        function updateClock() {
            const now = new Date();
            const dateStr = now.toLocaleDateString('en-US', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
            const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
            document.getElementById('realtimeClock').innerHTML = '<i class="fa-regular fa-clock me-1"></i> ' + dateStr + ' &bull; ' + timeStr;
        }
        setInterval(updateClock, 1000);
        updateClock();

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
