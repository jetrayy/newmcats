<?php
session_start();
include_once __DIR__ . '/../../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'super_admin') {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin') {
        header("Location: sahome.php");
    } else {
        header("Location: index.php");
    }
    exit();
}
// Fetch Today's Sales
$today_start = date('Y-m-d 00:00:00');
$today_end = date('Y-m-d 23:59:59');

$today_query = "SELECT SUM(received_amount - balance_amount) as today_total FROM transactions WHERE transaction_date BETWEEN ? AND ?";
$stmt = $conn->prepare($today_query);
$stmt->bind_param("ss", $today_start, $today_end);
$stmt->execute();
$today_res = $stmt->get_result()->fetch_assoc();
$today_total = $today_res['today_total'] ?? 0;

// --- DAY SESSION LOGIC ---
$user_id = $_SESSION['user_id'];

// 1. Auto-close any left-open sessions from previous days
$auto_close_query = "UPDATE cash_sessions SET status='closed', closed_at=CONCAT(DATE(opened_at), ' 23:59:59') WHERE status='open' AND DATE(opened_at) < CURDATE()";
$conn->query($auto_close_query);

// 2. Handle Opening Balance Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['opening_balance'])) {
    $opening_balance = floatval($_POST['opening_balance']);
    $insert_session = $conn->prepare("INSERT INTO cash_sessions (user_id, opening_balance, status) VALUES (?, ?, 'open')");
    $insert_session->bind_param("id", $user_id, $opening_balance);
    $insert_session->execute();
    
    // Refresh to apply the new session
    header("Location: home.php");
    exit();
}

// 3. Check for active session today
$active_session_query = "SELECT * FROM cash_sessions WHERE status='open' AND DATE(opened_at) = CURDATE() ORDER BY id DESC LIMIT 1";
$session_result = $conn->query($active_session_query);
$active_session = $session_result->fetch_assoc();

if ($active_session) {
    $_SESSION['active_session_id'] = $active_session['id'];
} else {
    unset($_SESSION['active_session_id']);
}
// Fetch pending change requests counts
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

// Fetch today's transaction count
$today_count_query = "SELECT COUNT(*) as txn_count FROM transactions WHERE transaction_date BETWEEN ? AND ?";
$stmt_cnt = $conn->prepare($today_count_query);
$stmt_cnt->bind_param("ss", $today_start, $today_end);
$stmt_cnt->execute();
$today_txn_count = $stmt_cnt->get_result()->fetch_assoc()['txn_count'] ?? 0;

// Fetch total active catalog items
$item_count_res = $conn->query("SELECT COUNT(*) as item_count FROM inventory");
$total_items_count = $item_count_res ? ($item_count_res->fetch_assoc()['item_count'] ?? 0) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MCATS | Admin Dashboard</title>
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

    <!-- Global Navigation Bar -->
    <?php include_once __DIR__ . '/../includes/navbar.php'; ?>
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
                    <div class="d-none d-md-flex align-items-center gap-3 mb-4 pb-3 border-bottom" style="border-color: var(--obsidian-border) !important;">
                        <img src="../../img/logo.png" style="width: 42px; height: 42px; object-fit: contain; filter: drop-shadow(0 2px 8px rgba(0,0,0,0.4));" alt="MCATS Logo">
                        <div>
                            <div class="luxury-brand-title" style="font-size: 1.1rem; line-height: 1.2;">MCATS</div>
                            <div class="text-white-50 small" style="font-size: 0.7rem; letter-spacing: 0.08em; text-transform: uppercase;">Terminal Suite</div>
                        </div>
                    </div>
                    <div class="nav flex-column">
                        <a href="home.php" class="luxury-nav-link active"><i class="fa-solid fa-gauge"></i>Dashboard</a>
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
                        <a href="#inventorySubmenu" data-bs-toggle="collapse" class="luxury-nav-link collapsed d-flex align-items-center" role="button" aria-expanded="false">
                            <i class="fa-solid fa-boxes-stacked"></i>
                            <span>Inventory</span>
                            <?php if ($req_counts['pendingTotal'] > 0): ?>
                                <span class="badge bg-warning text-dark rounded-pill ms-2" style="font-size: 0.65rem;"><?php echo $req_counts['pendingTotal']; ?></span>
                            <?php endif; ?>
                            <i class="fa-solid fa-chevron-down nav-arrow ms-auto"></i>
                        </a>
                        <div class="collapse luxury-submenu" id="inventorySubmenu">
                            <a href="inventory.php" class="luxury-sub-link">
                                <i class="fa-solid fa-warehouse"></i> Stock Inventory
                            </a>
                            <a href="requests_qty.php" class="luxury-sub-link d-flex align-items-center justify-content-between">
                                <span><i class="fa-solid fa-cubes-stacked"></i> Qty Requests</span>
                                <?php if ($req_counts['pendingQty'] > 0): ?>
                                    <span class="badge bg-warning text-dark rounded-pill ms-1" style="font-size: 0.62rem;"><?php echo $req_counts['pendingQty']; ?></span>
                                <?php endif; ?>
                            </a>
                            <a href="requests_rate.php" class="luxury-sub-link d-flex align-items-center justify-content-between">
                                <span><i class="fa-solid fa-tags"></i> Rate Requests</span>
                                <?php if ($req_counts['pendingRate'] > 0): ?>
                                    <span class="badge bg-info text-white rounded-pill ms-1" style="font-size: 0.62rem;"><?php echo $req_counts['pendingRate']; ?></span>
                                <?php endif; ?>
                            </a>
                            <a href="requests_cost.php" class="luxury-sub-link d-flex align-items-center justify-content-between">
                                <span><i class="fa-solid fa-key"></i> Cost Requests</span>
                                <?php if ($req_counts['pendingCost'] > 0): ?>
                                    <span class="badge bg-secondary text-white rounded-pill ms-1" style="font-size: 0.62rem;"><?php echo $req_counts['pendingCost']; ?></span>
                                <?php endif; ?>
                            </a>
                        </div>

                        <a href="../../logout.php" class="luxury-nav-link logout"><i class="fa-solid fa-right-from-bracket"></i>Logout</a>
                    </div>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-2 pb-3 mb-4 border-bottom position-relative" style="border-color: var(--border-subtle) !important;">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="luxury-badge luxury-badge-gold"><i class="fa-solid fa-circle text-success" style="font-size: 0.45rem;"></i> Active System</span>
                            <span class="text-muted small fw-medium">Rikillagaskada Branch</span>
                        </div>
                        <h1 class="h2 mb-0 fw-bold" style="letter-spacing: -0.02em;">Admin Dashboard</h1>
                        <div id="realtimeClock" class="text-muted small mt-1"></div>
                    </div>
                    <div class="d-flex align-items-center gap-2 mt-3 mt-md-0">
                        <?php if ($active_session): ?>
                            <a href="end_session.php" class="btn btn-outline-danger fw-semibold px-3 py-2 rounded-3 shadow-sm d-flex align-items-center gap-2" onclick="return confirm('Are you sure you want to end the current day session?');" style="font-size: 0.85rem;">
                                <i class="fa-solid fa-power-off"></i> End Day Session
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- STATS SECTION -->
                <div class="row g-4 mb-4">
                    <div class="col-md-4">
                        <div class="luxury-stat-card h-100">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.05em;">Today's Revenue</span>
                                <div class="rounded-3 p-2" style="background: rgba(16, 185, 129, 0.12); color: var(--success-emerald);">
                                    <i class="fa-solid fa-chart-line fs-5"></i>
                                </div>
                            </div>
                            <div class="luxury-stat-value my-2">Rs. <?php echo number_format($today_total, 2); ?></div>
                            <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top" style="border-color: var(--border-subtle) !important;">
                                <span class="text-muted small">Daily net collections</span>
                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 small">Live</span>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="luxury-stat-card h-100">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.05em;">Transactions Today</span>
                                <div class="rounded-3 p-2" style="background: rgba(59, 130, 246, 0.12); color: #60a5fa;">
                                    <i class="fa-solid fa-receipt fs-5"></i>
                                </div>
                            </div>
                            <div class="luxury-stat-value my-2" style="color: #60a5fa;"><?php echo number_format($today_txn_count); ?></div>
                            <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top" style="border-color: var(--border-subtle) !important;">
                                <span class="text-muted small">Processed sales & rentals</span>
                                <a href="../pos/sell.php" class="text-decoration-none fw-semibold small" style="color: #60a5fa;">
                                    New Sale &rarr;
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="luxury-stat-card h-100">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.05em;">Catalog & Approvals</span>
                                <div class="rounded-3 p-2" style="background: var(--gold-subtle-bg); color: var(--gold-primary);">
                                    <i class="fa-solid fa-boxes-stacked fs-5"></i>
                                </div>
                            </div>
                            <div class="luxury-stat-value my-2" style="color: var(--gold-primary);"><?php echo number_format($total_items_count); ?> <span class="fs-6 text-muted fw-normal">Items</span></div>
                            <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top" style="border-color: var(--border-subtle) !important;">
                                <span class="text-muted small"><?php echo $req_counts['pendingTotal']; ?> Pending change request(s)</span>
                                <a href="requests.php" class="text-decoration-none fw-semibold small" style="color: var(--gold-primary);">
                                    View Requests &rarr;
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- MODULES SECTION -->
                <div class="d-flex align-items-center justify-content-between mb-3 mt-4">
                    <div>
                        <h5 class="fw-bold mb-0 text-uppercase" style="font-size: 0.85rem; letter-spacing: 0.08em; color: var(--text-muted);">Operations & Commercial Terminals</h5>
                        <small class="text-muted">Primary workstation access for counter desk operations</small>
                    </div>
                </div>

                <div class="row g-4">
                    <!-- SELLING CARD -->
                    <div class="col-md-6 col-lg-4">
                        <div class="luxury-card h-100 p-4 d-flex flex-column justify-content-between position-relative overflow-hidden">
                            <div>
                                <div class="d-flex align-items-center justify-content-between mb-3">
                                    <div class="rounded-3 p-3 d-flex align-items-center justify-content-center" style="width: 52px; height: 52px; background: rgba(212, 175, 55, 0.12); color: var(--gold-primary); border: 1px solid rgba(212, 175, 55, 0.25);">
                                        <i class="fa-solid fa-cart-shopping fs-4"></i>
                                    </div>
                                    <span class="luxury-badge luxury-badge-gold">Retail POS</span>
                                </div>
                                <h4 class="mb-2 fw-bold">Retail Checkout</h4>
                                <p class="text-muted small mb-4" style="line-height: 1.6;">
                                    Instant point-of-sale checkout for power tools and hardware accessories with automatic discount calculation and thermal slip printing.
                                </p>
                            </div>
                            <div class="pt-3 border-top d-flex align-items-center justify-content-between" style="border-color: var(--border-subtle) !important;">
                                <span class="text-muted small"><i class="fa-solid fa-barcode me-1"></i> Barcode Ready</span>
                                <a href="../pos/sell.php" class="btn btn-sm btn-luxury-gold px-3">
                                    Launch Terminal <i class="fa-solid fa-arrow-right ms-1"></i>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- POS CARD (RENTAL) -->
                    <div class="col-md-6 col-lg-4">
                        <div class="luxury-card h-100 p-4 d-flex flex-column justify-content-between position-relative overflow-hidden">
                            <div>
                                <div class="d-flex align-items-center justify-content-between mb-3">
                                    <div class="rounded-3 p-3 d-flex align-items-center justify-content-center" style="width: 52px; height: 52px; background: rgba(16, 185, 129, 0.12); color: var(--success-emerald); border: 1px solid rgba(16, 185, 129, 0.25);">
                                        <i class="fa-solid fa-clock-rotate-left fs-4"></i>
                                    </div>
                                    <span class="luxury-badge luxury-badge-emerald">Rental Fleet</span>
                                </div>
                                <h4 class="mb-2 fw-bold">Equipment Rental</h4>
                                <p class="text-muted small mb-4" style="line-height: 1.6;">
                                    Track customer identification, verify NIC/guarantor records, record initial security float, and execute final returns or settlements.
                                </p>
                            </div>
                            <div class="pt-3 border-top d-flex align-items-center justify-content-between" style="border-color: var(--border-subtle) !important;">
                                <span class="text-muted small"><i class="fa-solid fa-id-card me-1"></i> Customer KYC</span>
                                <a href="../pos/rent.php" class="btn btn-sm btn-luxury-outline px-3">
                                    Rental Terminal <i class="fa-solid fa-arrow-right ms-1"></i>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- INVENTORY CARD -->
                    <div class="col-md-6 col-lg-4">
                        <div class="luxury-card h-100 p-4 d-flex flex-column justify-content-between position-relative overflow-hidden">
                            <div>
                                <div class="d-flex align-items-center justify-content-between mb-3">
                                    <div class="rounded-3 p-3 d-flex align-items-center justify-content-center" style="width: 52px; height: 52px; background: rgba(59, 130, 246, 0.12); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.25);">
                                        <i class="fa-solid fa-boxes-stacked fs-4"></i>
                                    </div>
                                    <span class="luxury-badge luxury-badge-slate">Stock Suite</span>
                                </div>
                                <h4 class="mb-2 fw-bold">Master Inventory</h4>
                                <p class="text-muted small mb-4" style="line-height: 1.6;">
                                    Real-time product catalog with cost cipher code encryption, threshold warnings, and direct requests to Super Admin for price or quantity adjustments.
                                </p>
                            </div>
                            <div class="pt-3 border-top d-flex align-items-center justify-content-between" style="border-color: var(--border-subtle) !important;">
                                <span class="text-muted small"><i class="fa-solid fa-shield-keyhole me-1"></i> Cipher Protected</span>
                                <a href="inventory.php" class="btn btn-sm btn-luxury-outline px-3">
                                    Catalog Hub <i class="fa-solid fa-arrow-right ms-1"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Opening Balance Modal -->
    <?php if (!$active_session): ?>
    <div class="modal fade show luxury-modal" id="openingBalanceModal" style="display: block; background: rgba(7, 10, 18, 0.85); backdrop-filter: blur(10px); z-index: 1055;" aria-modal="true" role="dialog">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
            <div class="modal-content border-0 p-3">
                <div class="modal-header border-0 pb-0 text-center d-block">
                    <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 56px; height: 56px; background: var(--gold-subtle-bg); color: var(--gold-primary);">
                        <i class="fa-solid fa-vault fs-3"></i>
                    </div>
                    <h4 class="modal-title fw-bold">Start Cash Session</h4>
                    <p class="text-muted small mt-1 mb-0">Record opening drawer cash before processing sales</p>
                </div>
                <div class="modal-body pt-3 pb-2">
                    <form method="POST" action="">
                        <label class="form-label small fw-semibold text-muted">Drawer Opening Balance (LKR)</label>
                        <div class="input-group mb-4">
                            <span class="input-group-text bg-transparent fw-bold" style="border-color: var(--border-subtle); color: var(--gold-primary);">Rs.</span>
                            <input type="number" step="0.01" name="opening_balance" class="form-control luxury-input" placeholder="0.00" required autofocus style="font-size: 1.25rem; font-weight: 700;">
                        </div>
                        <button type="submit" class="btn btn-luxury-gold w-100 py-3 fw-bold">
                            <i class="fa-solid fa-lock-open me-2"></i> Open Session & Begin
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <script> document.body.classList.add('modal-open'); </script>
    <?php endif; ?>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Dark Mode Logic
        const toggleBtn = document.getElementById('themeToggle');
        const html = document.documentElement;

        function updateToggleText() {
            if (!toggleBtn) return;
            if (html.getAttribute('data-bs-theme') === 'dark') {
                toggleBtn.innerHTML = '<i class="fa-solid fa-sun text-warning me-1"></i> Light Mode';
            } else {
                toggleBtn.innerHTML = '<i class="fa-solid fa-moon text-info me-1"></i> Dark Mode';
            }
        }

        if (toggleBtn) {
            if (localStorage.getItem('theme') === 'dark') {
                html.setAttribute('data-bs-theme', 'dark');
                updateToggleText();
            }

            toggleBtn.addEventListener('click', () => {
                const nextTheme = (html.getAttribute('data-bs-theme') === 'dark') ? 'light' : 'dark';
                html.setAttribute('data-bs-theme', nextTheme);
                localStorage.setItem('theme', nextTheme);
                updateToggleText();
            });
        }

        // Realtime Clock Function
        function updateClock() {
            const now = new Date();
            const dateStr = now.toLocaleDateString('en-US', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
            const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
            document.getElementById('realtimeClock').innerHTML = '<i class="fa-regular fa-clock me-1"></i> ' + dateStr + ' &bull; ' + timeStr;
        }
        setInterval(updateClock, 1000);
        updateClock();
    </script>
</body>
</html>
