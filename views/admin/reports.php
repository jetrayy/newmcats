<?php
session_start();
include_once __DIR__ . '/../../db.php';

// SECURITY: Only allow Super Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../../index.php");
    exit();
}

// Filter parameters
$filter_type = $_GET['type'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';

// Build Query with Filters
$where_clauses = [];
$params = [];
$param_types = "";

if ($filter_type !== 'all') {
    $where_clauses[] = "t.type = ?";
    $params[] = $filter_type;
    $param_types .= "s";
}
if ($filter_status !== 'all') {
    $where_clauses[] = "t.status = ?";
    $params[] = $filter_status;
    $param_types .= "s";
}
if (!empty($filter_date_from)) {
    $where_clauses[] = "t.transaction_date >= ?";
    $params[] = $filter_date_from . " 00:00:00";
    $param_types .= "s";
}
if (!empty($filter_date_to)) {
    $where_clauses[] = "t.transaction_date <= ?";
    $params[] = $filter_date_to . " 23:59:59";
    $param_types .= "s";
}

$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

$query = "
    SELECT 
        t.bill_number, 
        t.customer_nic, 
        c.full_name,
        t.type, 
        t.total_lkr, 
        t.received_amount, 
        t.balance_amount, 
        t.transaction_date,
        t.status
    FROM transactions t
    LEFT JOIN customers c ON t.customer_nic = c.nic_number
    {$where_sql}
    ORDER BY t.transaction_date DESC
";

if (count($params) > 0) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($query);
}

// Compute aggregate metrics from the result set
$all_transactions = [];
$kpi_total_revenue = 0;
$kpi_total_received = 0;
$kpi_selling_count = 0;
$kpi_pos_count = 0;
$kpi_completed_count = 0;

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $all_transactions[] = $row;
        $kpi_total_revenue += floatval($row['total_lkr']);
        $kpi_total_received += floatval($row['received_amount']);
        if ($row['type'] === 'selling') $kpi_selling_count++;
        else $kpi_pos_count++;
        if (in_array(strtolower($row['status']), ['completed', 'returned'])) $kpi_completed_count++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MCATS | Transaction Reports & Financial Analytics</title>
    <link rel="icon" type="image/x-icon" href="../../img/ico.ico">
    <!-- Instant theme apply - prevents flash -->
    <script>document.documentElement.setAttribute('data-bs-theme', localStorage.getItem('theme') || 'light');</script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdn.sheetjs.com/xlsx-latest/package/dist/xlsx.full.min.js"></script>
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
                        <a href="requests.php" class="luxury-nav-link"><i class="fa-solid fa-shield-halved"></i>Requests Hub</a>
                        <a href="inventory_stats.php" class="luxury-nav-link"><i class="fa-solid fa-chart-pie"></i>Analytics</a>
                        <a href="reports.php" class="luxury-nav-link active"><i class="fa-solid fa-file-invoice-dollar"></i>Reports</a>
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
                            <span class="luxury-badge luxury-badge-gold"><i class="fa-solid fa-file-invoice"></i> Financial Records</span>
                            <span class="text-muted small">&bull; Executive Audit Intelligence</span>
                        </div>
                        <h1 class="h2 mb-0">Financial & Sales Intelligence</h1>
                        <p class="text-muted small mb-0 mt-1">Real-time revenue audits, transactional velocity, and exportable statements</p>
                    </div>
                    <div class="mt-3 mt-md-0 d-flex align-items-center gap-2 flex-wrap">
                        <button class="btn btn-outline-danger fw-semibold shadow-sm d-flex align-items-center gap-2 px-3 py-2 rounded-3" onclick="exportPDF()" style="border-width: 1px;">
                            <i class="fa-solid fa-file-pdf"></i> Export PDF
                        </button>
                        <button class="btn btn-luxury-gold shadow-sm px-3 py-2" onclick="exportExcel()">
                            <i class="fa-solid fa-file-excel me-1"></i> Export Excel
                        </button>
                        <button id="themeToggle" class="theme-toggle-btn ms-2"><i class="fa-solid fa-moon me-1"></i> Dark Mode</button>
                    </div>
                </div>

                <!-- ANALYTICAL SUMMARY CARDS -->
                <div class="row g-3 mb-4">
                    <div class="col-sm-6 col-lg-3">
                        <div class="luxury-stat-card p-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted small fw-semibold text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.05em;">Billed Revenue</span>
                                <div class="rounded-circle p-2 d-flex align-items-center justify-content-center" style="background: rgba(212, 175, 55, 0.15); color: var(--gold-primary); width: 36px; height: 36px;">
                                    <i class="fa-solid fa-vault"></i>
                                </div>
                            </div>
                            <div class="luxury-stat-value fs-4 my-1" style="color: var(--gold-primary);">Rs. <?php echo number_format($kpi_total_revenue, 2); ?></div>
                            <small class="text-muted" style="font-size: 0.72rem;">Total invoice turnover</small>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="luxury-stat-card p-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted small fw-semibold text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.05em;">Cash Received</span>
                                <div class="rounded-circle p-2 d-flex align-items-center justify-content-center" style="background: rgba(16, 185, 129, 0.15); color: var(--success-emerald); width: 36px; height: 36px;">
                                    <i class="fa-solid fa-money-bill-wave"></i>
                                </div>
                            </div>
                            <div class="luxury-stat-value fs-4 my-1" style="color: var(--success-emerald);">Rs. <?php echo number_format($kpi_total_received, 2); ?></div>
                            <small class="text-muted" style="font-size: 0.72rem;">Physical collections cleared</small>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="luxury-stat-card p-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted small fw-semibold text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.05em;">Sales vs POS</span>
                                <div class="rounded-circle p-2 d-flex align-items-center justify-content-center" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; width: 36px; height: 36px;">
                                    <i class="fa-solid fa-shuffle"></i>
                                </div>
                            </div>
                            <div class="luxury-stat-value fs-4 my-1 text-white">
                                <span style="color: var(--gold-primary);"><?php echo $kpi_selling_count; ?></span> / <span style="color: #38bdf8;"><?php echo $kpi_pos_count; ?></span>
                            </div>
                            <small class="text-muted" style="font-size: 0.72rem;"><?php echo $kpi_selling_count; ?> Retail &bull; <?php echo $kpi_pos_count; ?> Rental POS</small>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="luxury-stat-card p-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted small fw-semibold text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.05em;">Completion Rate</span>
                                <div class="rounded-circle p-2 d-flex align-items-center justify-content-center" style="background: rgba(168, 85, 247, 0.15); color: #a855f7; width: 36px; height: 36px;">
                                    <i class="fa-solid fa-circle-check"></i>
                                </div>
                            </div>
                            <?php 
                                $total_recs = count($all_transactions);
                                $rate = $total_recs > 0 ? round(($kpi_completed_count / $total_recs) * 100) : 0;
                            ?>
                            <div class="luxury-stat-value fs-4 my-1 text-info"><?php echo $rate; ?>%</div>
                            <small class="text-muted" style="font-size: 0.72rem;"><?php echo $kpi_completed_count; ?> of <?php echo $total_recs; ?> closed / settled</small>
                        </div>
                    </div>
                </div>

                <!-- FILTER BAR FOR ANALYTICS -->
                <div class="luxury-card p-3 mb-4">
                    <form method="GET" action="reports.php" class="row g-2 align-items-end">
                        <div class="col-sm-6 col-md-3">
                            <label class="form-label text-muted small fw-bold mb-1">Channel Type</label>
                            <select name="type" class="form-select form-select-sm luxury-input">
                                <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Channels</option>
                                <option value="selling" <?php echo $filter_type === 'selling' ? 'selected' : ''; ?>>Retail Selling</option>
                                <option value="pos" <?php echo $filter_type === 'pos' ? 'selected' : ''; ?>>Rental POS</option>
                            </select>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <label class="form-label text-muted small fw-bold mb-1">Lifecycle Status</label>
                            <select name="status" class="form-select form-select-sm luxury-input">
                                <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="returned" <?php echo $filter_status === 'returned' ? 'selected' : ''; ?>>Returned</option>
                                <option value="pay_later" <?php echo $filter_status === 'pay_later' ? 'selected' : ''; ?>>Pay Later / Pending</option>
                                <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="col-sm-6 col-md-2">
                            <label class="form-label text-muted small fw-bold mb-1">From Date</label>
                            <input type="date" name="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>" class="form-control form-control-sm luxury-input">
                        </div>
                        <div class="col-sm-6 col-md-2">
                            <label class="form-label text-muted small fw-bold mb-1">To Date</label>
                            <input type="date" name="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>" class="form-control form-control-sm luxury-input">
                        </div>
                        <div class="col-sm-12 col-md-2 d-flex gap-2">
                            <button type="submit" class="btn btn-sm btn-luxury-gold flex-grow-1">
                                <i class="fa-solid fa-filter me-1"></i> Filter
                            </button>
                            <a href="reports.php" class="btn btn-sm btn-luxury-outline" title="Reset Filters">
                                <i class="fa-solid fa-rotate-left"></i>
                            </a>
                        </div>
                    </form>
                </div>

                <div class="luxury-card overflow-hidden mb-5" id="reportData">
                    <div class="table-responsive">
                        <table class="luxury-table align-middle" id="transactionsTable">
                            <thead>
                                <tr>
                                    <th>Bill #</th>
                                    <th>Timestamp</th>
                                    <th>Customer Entity</th>
                                    <th>Channel</th>
                                    <th>Lifecycle Status</th>
                                    <th>Total Billed</th>
                                    <th>Received Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($all_transactions) > 0): ?>
                                    <?php foreach($all_transactions as $row): ?>
                                    <tr>
                                        <td class="fw-bold text-muted" style="font-family: var(--font-heading);">
                                            #<?php echo str_pad(htmlspecialchars($row['bill_number']), 5, '0', STR_PAD_LEFT); ?>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="fa-regular fa-clock text-muted small"></i>
                                                <span><?php echo date('M d, Y', strtotime($row['transaction_date'])) . ' &bull; ' . date('h:i A', strtotime($row['transaction_date'])); ?></span>
                                            </div>
                                        </td>
                                        <td class="fw-bold">
                                            <?php echo htmlspecialchars($row['full_name'] ?? 'Walk-in Customer'); ?>
                                            <?php if($row['customer_nic']): ?>
                                                <small class="d-block text-muted fw-normal" style="font-size: 0.75rem;">NIC: <?php echo htmlspecialchars($row['customer_nic']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if($row['type'] === 'selling'): ?>
                                                <span class="luxury-badge luxury-badge-gold"><i class="fa-solid fa-cart-shopping"></i> Selling</span>
                                            <?php else: ?>
                                                <span class="luxury-badge luxury-badge-emerald"><i class="fa-solid fa-clock-rotate-left"></i> POS</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                                $st = strtolower($row['status']);
                                                if($st == 'completed') {
                                                    echo '<span class="luxury-badge luxury-badge-emerald"><i class="fa-solid fa-check"></i> Completed</span>';
                                                } elseif($st == 'returned') {
                                                    echo '<span class="luxury-badge luxury-badge-gold"><i class="fa-solid fa-box-archive"></i> Returned</span>';
                                                } elseif($st == 'cancelled') {
                                                    echo '<span class="luxury-badge luxury-badge-crimson"><i class="fa-solid fa-ban"></i> Cancelled</span>';
                                                } else {
                                                    echo '<span class="luxury-badge luxury-badge-slate"><i class="fa-solid fa-hourglass-half"></i> Ongoing</span>';
                                                }
                                            ?>
                                        </td>
                                        <td class="fw-bold" style="font-family: var(--font-heading); color: var(--gold-primary);">
                                            Rs. <?php echo number_format($row['total_lkr'], 2); ?>
                                        </td>
                                        <td class="fw-semibold">
                                            Rs. <?php echo number_format($row['received_amount'], 2); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="7" class="text-center text-muted py-5"><i class="fa-solid fa-folder-open d-block fs-1 mb-2 opacity-50"></i>No transaction history recorded yet.</td></tr>
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
        // Theme Toggle
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

        // Export to PDF
        function exportPDF() {
            const element = document.getElementById('reportData');
            const opt = {
                margin:       10,
                filename:     'MCATS_Sales_Report.pdf',
                image:        { type: 'jpeg', quality: 0.98 },
                html2canvas:  { scale: 2 },
                jsPDF:        { unit: 'mm', format: 'a4', orientation: 'landscape' }
            };
            const currentTheme = html.getAttribute('data-bs-theme');
            html.setAttribute('data-bs-theme', 'light');
            
            html2pdf().set(opt).from(element).save().then(() => {
                html.setAttribute('data-bs-theme', currentTheme);
            });
        }

        // Export to Excel
        function exportExcel() {
            const table = document.getElementById('transactionsTable');
            const wb = XLSX.utils.table_to_book(table, {sheet: "Sales Report"});
            XLSX.writeFile(wb, 'MCATS_Sales_Report.xlsx');
        }
    </script>
</body>
</html>
