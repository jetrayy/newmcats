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
// --- END DAY SESSION LOGIC ---

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
                        <h4 class="luxury-brand-title m-0">MCATS ADMIN</h4>
                        <small class="text-white-50" style="font-size: 0.725rem; letter-spacing: 0.1em;">TERMINAL SUITE</small>
                    </div>
                    <div class="nav flex-column">
                        <a href="home.php" class="luxury-nav-link active"><i class="fa-solid fa-gauge"></i>Dashboard</a>
                        <a href="../pos/sell.php" class="luxury-nav-link"><i class="fa-solid fa-cart-shopping"></i>Selling</a>
                        <a href="../pos/rent.php" class="luxury-nav-link"><i class="fa-solid fa-clock-rotate-left"></i>POS</a>
                        <a href="inventory.php" class="luxury-nav-link"><i class="fa-solid fa-box"></i>Inventory</a>
                        <a href="../../logout.php" class="luxury-nav-link logout"><i class="fa-solid fa-right-from-bracket"></i>Logout</a>
                    </div>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-2 pb-3 mb-4 border-bottom position-relative" style="border-color: var(--border-subtle) !important;">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="luxury-badge luxury-badge-gold"><i class="fa-solid fa-circle text-success" style="font-size: 0.5rem;"></i> Active System</span>
                            <span class="text-muted small">Rikillagaskada Branch</span>
                        </div>
                        <h1 class="h2 mb-0">Admin Dashboard</h1>
                        <div id="realtimeClock" class="text-muted small mt-1"></div>
                    </div>
                    <div class="d-flex align-items-center gap-3 mt-3 mt-md-0">
                        <?php if ($active_session): ?>
                            <a href="end_session.php" class="btn btn-outline-danger fw-semibold px-3 py-2 rounded-3 shadow-sm d-flex align-items-center gap-2" onclick="return confirm('Are you sure you want to end the current day session?');" style="border-width: 1px;">
                                <i class="fa-solid fa-power-off"></i> End Day Session
                            </a>
                        <?php endif; ?>
                        <div class="d-none d-sm-flex align-items-center gap-2 px-3 py-2 rounded-3" style="background: var(--bg-surface-elevated); border: 1px solid var(--border-subtle);">
                            <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 28px; height: 28px; background: var(--gold-subtle-bg); color: var(--gold-primary);">
                                <i class="fa-solid fa-user-tie" style="font-size: 0.8rem;"></i>
                            </div>
                            <span class="small">Cashier: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong></span>
                        </div>
                        <button id="themeToggle" class="theme-toggle-btn">🌙 Dark Mode</button>
                    </div>
                </div>

                <!-- STATS SECTION -->
                <div class="row mb-5">
                    <div class="col-md-6 col-lg-4">
                        <div class="luxury-stat-card">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.05em;">Today's Revenue</span>
                                <div class="rounded-3 p-2" style="background: rgba(16, 185, 129, 0.12); color: var(--success-emerald);">
                                    <i class="fa-solid fa-chart-line fs-5"></i>
                                </div>
                            </div>
                            <div class="luxury-stat-value my-2">Rs. <?php echo number_format($today_total, 2); ?></div>
                            <p class="text-muted small mb-0 d-flex align-items-center gap-1">
                                <i class="fa-solid fa-arrow-trend-up text-success"></i> Aggregated collected sales across all transactions today
                            </p>
                        </div>
                    </div>
                </div>

                <!-- MODULES SECTION -->
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h5 class="fw-bold mb-0 text-uppercase" style="font-size: 0.85rem; letter-spacing: 0.08em; color: var(--text-muted);">Operations & Terminals</h5>
                </div>

                <div class="row g-4">
                    <!-- SELLING CARD -->
                    <div class="col-md-6 col-lg-4">
                        <a href="../pos/sell.php" class="text-decoration-none text-reset">
                            <div class="luxury-card h-100 p-4 position-relative overflow-hidden">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div class="rounded-3 p-3 d-flex align-items-center justify-content-center" style="width: 54px; height: 54px; background: rgba(212, 175, 55, 0.15); color: var(--gold-primary);">
                                        <i class="fa-solid fa-cart-shopping fs-4"></i>
                                    </div>
                                    <div>
                                        <h4 class="mb-0 fw-bold">Selling</h4>
                                        <span class="luxury-badge luxury-badge-gold mt-1">Retail POS</span>
                                    </div>
                                </div>
                                <p class="text-muted small mb-4" style="line-height: 1.6;">
                                    Process retail sales for Power Tools & Hardware Goods. Quick barcode/catalog checkout and instant 80mm thermal receipt printing.
                                </p>
                                <div class="d-flex align-items-center text-warning fw-semibold small gap-1">
                                    Launch Terminal <i class="fa-solid fa-arrow-right ms-1"></i>
                                </div>
                            </div>
                        </a>
                    </div>

                    <!-- POS CARD (RENTAL) -->
                    <div class="col-md-6 col-lg-4">
                        <a href="../pos/rent.php" class="text-decoration-none text-reset">
                            <div class="luxury-card h-100 p-4 position-relative overflow-hidden">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div class="rounded-3 p-3 d-flex align-items-center justify-content-center" style="width: 54px; height: 54px; background: rgba(16, 185, 129, 0.15); color: var(--success-emerald);">
                                        <i class="fa-solid fa-clock-rotate-left fs-4"></i>
                                    </div>
                                    <div>
                                        <h4 class="mb-0 fw-bold">POS</h4>
                                        <span class="luxury-badge luxury-badge-emerald mt-1">Rental Terminal</span>
                                    </div>
                                </div>
                                <p class="text-muted small mb-4" style="line-height: 1.6;">
                                    Manage 2-stage rental lifecycle for heavy equipment. Handle customer profiles, collect advance deposits, and settle final returns.
                                </p>
                                <div class="d-flex align-items-center text-success fw-semibold small gap-1">
                                    Launch Terminal <i class="fa-solid fa-arrow-right ms-1"></i>
                                </div>
                            </div>
                        </a>
                    </div>

                    <!-- INVENTORY CARD -->
                    <div class="col-md-6 col-lg-4">
                        <a href="inventory.php" class="text-decoration-none text-reset">
                            <div class="luxury-card h-100 p-4 position-relative overflow-hidden">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div class="rounded-3 p-3 d-flex align-items-center justify-content-center" style="width: 54px; height: 54px; background: rgba(59, 130, 246, 0.15); color: #60a5fa;">
                                        <i class="fa-solid fa-boxes-stacked fs-4"></i>
                                    </div>
                                    <div>
                                        <h4 class="mb-0 fw-bold">Inventory</h4>
                                        <span class="luxury-badge luxury-badge-slate mt-1">Stock Suite</span>
                                    </div>
                                </div>
                                <p class="text-muted small mb-4" style="line-height: 1.6;">
                                    Manage master catalog items, images, stock quantities, and availability. Includes the BLACKHORSE cost cipher integration.
                                </p>
                                <div class="d-flex align-items-center text-primary fw-semibold small gap-1">
                                    Manage Catalog <i class="fa-solid fa-arrow-right ms-1"></i>
                                </div>
                            </div>
                        </a>
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
            if (html.getAttribute('data-bs-theme') === 'dark') {
                toggleBtn.innerHTML = '☀️ Light Mode';
            } else {
                toggleBtn.innerHTML = '🌙 Dark Mode';
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