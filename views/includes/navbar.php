<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$current_script = basename($_SERVER['PHP_SELF']);
$is_pos_page = (strpos($_SERVER['PHP_SELF'], '/views/pos/') !== false);
$pos_url = $is_pos_page ? '' : '../pos/';
$admin_url = $is_pos_page ? '../admin/' : '';
$root_url = $is_pos_page ? '../../' : '../../';

$user_role = $_SESSION['role'] ?? 'admin';
$user_name = $_SESSION['username'] ?? 'User';
$is_sa = ($user_role === 'super_admin');
$dash_link = $admin_url . ($is_sa ? 'sahome.php' : 'home.php');

// Active Session Check
$current_session_id = $_SESSION['active_session_id'] ?? null;
?>

<!-- Universal Luxury Navigation Bar -->
<nav class="navbar navbar-expand-xl navbar-dark navbar-luxury sticky-top">
    <div class="container-fluid px-2 px-lg-3">
        <!-- Brand Logo & Title -->
        <a class="navbar-brand d-flex align-items-center gap-2 py-0 me-3" href="<?php echo $dash_link; ?>">
            <img src="<?php echo $root_url; ?>img/logo.png" alt="MCATS" style="height: 32px; filter: drop-shadow(0 2px 5px rgba(0,0,0,0.5));">
            <span class="luxury-brand-title text-white" style="font-size: 1.15rem; letter-spacing: 0.5px;">MCATS</span>
            <span class="badge rounded-pill" style="font-size: 0.65rem; background: rgba(212, 175, 55, 0.15); color: #d4af37; border: 1px solid rgba(212, 175, 55, 0.35);">
                <?php echo $is_sa ? 'SUPER ADMIN' : 'ADMIN'; ?>
            </span>
        </a>

        <!-- Mobile Toggler -->
        <button class="navbar-toggler border-0 p-1" type="button" data-bs-toggle="collapse" data-bs-target="#mcatsNavbarCollapse" aria-controls="mcatsNavbarCollapse" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Navbar Links -->
        <div class="collapse navbar-collapse" id="mcatsNavbarCollapse">
            <ul class="navbar-nav mx-auto mb-2 mb-xl-0 gap-1 gap-xxl-2">
                <?php if ($is_sa): ?>
                    <!-- SUPER ADMIN NAVIGATION (Executive Only) -->
                    <li class="nav-item">
                        <a class="nav-link px-3 rounded-pill <?php echo ($current_script === 'sahome.php') ? 'active' : ''; ?>" href="<?php echo $dash_link; ?>">
                            <i class="fa-solid fa-gauge me-1 opacity-75"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link px-3 rounded-pill <?php echo in_array($current_script, ['requests.php', 'requests_qty.php', 'requests_rate.php', 'requests_cost.php']) ? 'active' : ''; ?>" href="<?php echo $admin_url; ?>requests.php">
                            <i class="fa-solid fa-shield-halved me-1 opacity-75"></i> Requests Hub
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link px-3 rounded-pill <?php echo ($current_script === 'inventory_stats.php') ? 'active' : ''; ?>" href="<?php echo $admin_url; ?>inventory_stats.php">
                            <i class="fa-solid fa-chart-pie me-1 opacity-75"></i> Analytics
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link px-3 rounded-pill <?php echo in_array($current_script, ['reports.php', 'sessions_report.php', 'session_payment_summary.php']) ? 'active' : ''; ?>" href="<?php echo $admin_url; ?>reports.php">
                            <i class="fa-solid fa-file-invoice-dollar me-1 opacity-75"></i> Reports
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link px-3 rounded-pill <?php echo ($current_script === 'manage_user.php') ? 'active' : ''; ?>" href="<?php echo $admin_url; ?>manage_user.php?action=add">
                            <i class="fa-solid fa-users-gear me-1 opacity-75"></i> User Accounts
                        </a>
                    </li>
                <?php else: ?>
                    <!-- CASHIER / ADMIN OPERATIONAL NAVIGATION -->
                    <li class="nav-item">
                        <a class="nav-link px-3 rounded-pill <?php echo ($current_script === 'home.php') ? 'active' : ''; ?>" href="<?php echo $dash_link; ?>">
                            <i class="fa-solid fa-gauge me-1 opacity-75"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link px-3 rounded-pill <?php echo ($current_script === 'sell.php') ? 'active' : ''; ?>" href="<?php echo $pos_url; ?>sell.php">
                            <i class="fa-solid fa-cart-shopping me-1 opacity-75"></i> Selling
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link px-3 rounded-pill <?php echo ($current_script === 'rent.php') ? 'active' : ''; ?>" href="<?php echo $pos_url; ?>rent.php">
                            <i class="fa-solid fa-cart-flatbed me-1 opacity-75"></i> Rentals
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link px-3 rounded-pill <?php echo in_array($current_script, ['return_items.php', 'return_checkout.php']) ? 'active' : ''; ?>" href="<?php echo $pos_url; ?>return_items.php">
                            <i class="fa-solid fa-rotate-left me-1 opacity-75"></i> Return Desk
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link px-3 rounded-pill <?php echo in_array($current_script, ['pay_later.php', 'pay_later_settle.php']) ? 'active' : ''; ?>" href="<?php echo $pos_url; ?>pay_later.php">
                            <i class="fa-solid fa-hand-holding-dollar me-1 opacity-75"></i> Pay Later
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link px-3 rounded-pill <?php echo ($current_script === 'select_customer.php') ? 'active' : ''; ?>" href="<?php echo $pos_url; ?>select_customer.php">
                            <i class="fa-solid fa-users me-1 opacity-75"></i> Customers
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link px-3 rounded-pill <?php echo ($current_script === 'inventory.php') ? 'active' : ''; ?>" href="<?php echo $admin_url; ?>inventory.php">
                            <i class="fa-solid fa-boxes-stacked me-1 opacity-75"></i> Inventory
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link px-3 rounded-pill <?php echo in_array($current_script, ['requests.php', 'requests_qty.php', 'requests_rate.php', 'requests_cost.php']) ? 'active' : ''; ?>" href="<?php echo $admin_url; ?>requests.php">
                            <i class="fa-solid fa-clipboard-check me-1 opacity-75"></i> Change Requests
                        </a>
                    </li>
                <?php endif; ?>
            </ul>

            <!-- Right Controls: Session status, Theme Toggle, User, Logout -->
            <div class="d-flex align-items-center gap-2 mt-3 mt-xl-0 flex-wrap">
                <?php if ($current_session_id): ?>
                    <span class="badge rounded-pill px-2 py-1 small d-inline-flex align-items-center gap-1" style="background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3);">
                        <i class="fa-solid fa-circle" style="font-size: 0.45rem;"></i> Session #<?php echo $current_session_id; ?>
                    </span>
                <?php endif; ?>

                <!-- Theme Toggle Button -->
                <button id="navThemeToggle" class="btn btn-sm btn-outline-secondary rounded-pill px-3 d-inline-flex align-items-center gap-1" type="button" title="Toggle Theme" style="border-color: rgba(255,255,255,0.18); color: #cbd5e1;">
                    <i class="fa-solid fa-moon"></i> <span class="d-none d-sm-inline">Dark Mode</span>
                </button>

                <!-- User Badge -->
                <span class="badge rounded-pill px-3 py-1 text-white-50 border border-secondary border-opacity-25 d-none d-sm-inline-flex align-items-center gap-1" style="background: rgba(255,255,255,0.05);">
                    <i class="fa-solid fa-circle-user text-warning"></i> <?php echo htmlspecialchars($user_name); ?>
                </span>

                <!-- Exit / Logout -->
                <a href="<?php echo $root_url; ?>logout.php" class="btn btn-sm btn-outline-danger rounded-pill px-3 d-inline-flex align-items-center gap-1 shadow-sm" title="Sign Out">
                    <i class="fa-solid fa-right-from-bracket"></i> <span class="d-none d-md-inline">Logout</span>
                </a>
            </div>
        </div>
    </div>
</nav>

<!-- Universal Theme Sync Script -->
<script>
(function() {
    function applyNavbarTheme(theme) {
        document.documentElement.setAttribute('data-bs-theme', theme);
        localStorage.setItem('theme', theme);

        const isDark = (theme === 'dark');
        const sunIcon = '<i class="fa-solid fa-sun text-warning me-1"></i> <span class="d-none d-sm-inline">Light Mode</span>';
        const moonIcon = '<i class="fa-solid fa-moon text-info me-1"></i> <span class="d-none d-sm-inline">Dark Mode</span>';

        // Update all toggle buttons on the page
        document.querySelectorAll('#navThemeToggle, #themeToggle, #themeToggleMobile').forEach(function(btn) {
            btn.innerHTML = isDark ? sunIcon : moonIcon;
        });
    }

    // Initial sync
    const savedTheme = localStorage.getItem('theme') || 'dark';
    applyNavbarTheme(savedTheme);

    // Global listener for navbar toggle
    document.addEventListener('DOMContentLoaded', function() {
        applyNavbarTheme(localStorage.getItem('theme') || 'dark');

        document.querySelectorAll('#navThemeToggle, #themeToggle, #themeToggleMobile').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const current = document.documentElement.getAttribute('data-bs-theme') || 'dark';
                const next = (current === 'dark') ? 'light' : 'dark';
                applyNavbarTheme(next);
            });
        });
    });
})();
</script>
