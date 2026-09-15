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

// 2. Fetch All Admin Accounts for the Management Table
$users_query = "SELECT * FROM users";
$users_result = $conn->query($users_query);
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

    <!-- Mobile Navbar -->
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
                        <a href="inventory.php" class="luxury-nav-link"><i class="fa-solid fa-boxes-stacked"></i>Inventory</a>
                        <a href="inventory_stats.php" class="luxury-nav-link"><i class="fa-solid fa-chart-pie"></i>Inventory Stats</a>
                        <a href="sessions_report.php" class="luxury-nav-link"><i class="fa-solid fa-calendar-day"></i>Day Sessions</a>
                        <a href="reports.php" class="luxury-nav-link"><i class="fa-solid fa-file-invoice-dollar"></i>Reports</a>
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
                        <button id="themeToggle" class="theme-toggle-btn">🌙 Dark Mode</button>
                    </div>
                </div>

                <!-- STATS CARDS -->
                <div class="row g-4 mb-5">
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
                </div>

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

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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