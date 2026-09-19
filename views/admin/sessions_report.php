<?php
session_start();
include_once __DIR__ . '/../../db.php';

// SECURITY: Only allow Super Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../../index.php");
    exit();
}

$query = "SELECT s.*, u.username FROM cash_sessions s JOIN users u ON s.user_id = u.user_id ORDER BY s.opened_at DESC";
$sessions = $conn->query($query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MCATS | Day Cash Sessions</title>
    <link rel="icon" type="image/x-icon" href="../../img/ico.ico">
    <!-- Instant theme apply - prevents flash -->
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
                        <a href="sahome.php" class="luxury-nav-link"><i class="fa-solid fa-gauge"></i>Dashboard</a>
                        <a href="inventory_stats.php" class="luxury-nav-link"><i class="fa-solid fa-chart-pie"></i>Inventory Stats</a>
                        <a href="sessions_report.php" class="luxury-nav-link active"><i class="fa-solid fa-calendar-day"></i>Day Sessions</a>
                        <a href="reports.php" class="luxury-nav-link"><i class="fa-solid fa-file-invoice-dollar"></i>Reports</a>
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
                            <span class="luxury-badge luxury-badge-gold"><i class="fa-solid fa-vault"></i> Drawer Reconciliation</span>
                        </div>
                        <h1 class="h2 mb-0">Day Cash Sessions Audit</h1>
                        <p class="text-muted small mb-0 mt-1">Audit opening balances, cashier shifts, and physical drawer cash settlements</p>
                    </div>
                    <div class="mt-3 mt-md-0 d-flex align-items-center gap-2">
                        <a href="sahome.php" class="btn btn-luxury-outline">
                            <i class="fa-solid fa-arrow-left me-1"></i> Dashboard
                        </a>
                        <button id="themeToggle" class="theme-toggle-btn"><i class="fa-solid fa-moon me-1"></i> Dark Mode</button>
                    </div>
                </div>

                <div class="luxury-card overflow-hidden mb-5">
                    <div class="table-responsive">
                        <table class="luxury-table align-middle">
                            <thead>
                                <tr>
                                    <th>Session ID</th>
                                    <th>Cashier Account</th>
                                    <th>Shift Start</th>
                                    <th>Shift Ended</th>
                                    <th>Status</th>
                                    <th>Opening Float</th>
                                    <th class="text-end">Statement</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($row = $sessions->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold text-muted" style="font-family: var(--font-heading);">
                                        #<?php echo str_pad($row['id'], 4, '0', STR_PAD_LEFT); ?>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; background: var(--bg-surface-elevated); border: 1px solid var(--border-subtle); color: var(--gold-primary);">
                                                <i class="fa-solid fa-user-tie" style="font-size: 0.8rem;"></i>
                                            </div>
                                            <strong><?php echo htmlspecialchars($row['username']); ?></strong>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2 text-muted small">
                                            <i class="fa-regular fa-clock"></i>
                                            <span><?php echo date("M j, Y", strtotime($row['opened_at'])) . ' &bull; ' . date("g:i A", strtotime($row['opened_at'])); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($row['closed_at']): ?>
                                            <div class="d-flex align-items-center gap-2 text-muted small">
                                                <i class="fa-regular fa-circle-check text-success"></i>
                                                <span><?php echo date("M j, Y", strtotime($row['closed_at'])) . ' &bull; ' . date("g:i A", strtotime($row['closed_at'])); ?></span>
                                            </div>
                                        <?php else: ?>
                                            <span class="luxury-badge luxury-badge-gold" style="font-size: 0.7rem;">In Progress</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($row['status'] == 'open'): ?>
                                            <span class="luxury-badge luxury-badge-emerald"><i class="fa-solid fa-door-open"></i> ACTIVE</span>
                                        <?php else: ?>
                                            <span class="luxury-badge luxury-badge-slate"><i class="fa-solid fa-lock"></i> CLOSED</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-bold" style="font-family: var(--font-heading); color: var(--gold-primary);">
                                        Rs. <?php echo number_format($row['opening_balance'], 2); ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="session_payment_summary.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-luxury-gold py-1 px-3">
                                            <i class="fa-solid fa-receipt me-1"></i> Cash Summary
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                                <?php if($sessions->num_rows == 0): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-5"><i class="fa-solid fa-folder-open d-block fs-1 mb-2 opacity-50"></i>No drawer cash sessions recorded yet.</td></tr>
                                <?php endif; ?>
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
                toggleBtn.innerHTML = '<i class="fa-solid fa-sun text-warning me-1"></i> Light Mode';
            } else {
                toggleBtn.innerHTML = '<i class="fa-solid fa-moon me-1"></i> Dark Mode';
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
    </script>
</body>
</html>
