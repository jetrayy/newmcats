<?php
session_start();
include_once __DIR__ . '/../../db.php';

// SECURITY: Only allow Super Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../../index.php");
    exit();
}

// Fetch all transactions
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
    ORDER BY t.transaction_date DESC
";
$result = $conn->query($query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MCATS | Transaction Reports</title>
    <link rel="icon" type="image/x-icon" href="../../img/ico.ico">
    <!-- Instant theme apply — prevents flash -->
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
                        <a href="inventory_stats.php" class="luxury-nav-link"><i class="fa-solid fa-chart-pie"></i>Inventory Stats</a>
                        <a href="sessions_report.php" class="luxury-nav-link"><i class="fa-solid fa-calendar-day"></i>Day Sessions</a>
                        <a href="reports.php" class="luxury-nav-link active"><i class="fa-solid fa-file-invoice-dollar"></i>Reports</a>
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
                        </div>
                        <h1 class="h2 mb-0">Sales & POS Reports</h1>
                        <p class="text-muted small mb-0 mt-1">Audit transactions and export verified statements to PDF or Excel spreadsheet</p>
                    </div>
                    <div class="mt-3 mt-md-0 d-flex align-items-center gap-2">
                        <button class="btn btn-outline-danger fw-semibold shadow-sm d-flex align-items-center gap-2 px-3 py-2 rounded-3" onclick="exportPDF()" style="border-width: 1px;">
                            <i class="fa-solid fa-file-pdf"></i> Export PDF
                        </button>
                        <button class="btn btn-luxury-gold shadow-sm px-3 py-2" onclick="exportExcel()">
                            <i class="fa-solid fa-file-excel me-1"></i> Export Excel
                        </button>
                        <button id="themeToggle" class="theme-toggle-btn ms-2">🌙 Dark Mode</button>
                    </div>
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
                                <?php if ($result->num_rows > 0): ?>
                                    <?php while($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <td class="fw-bold text-muted" style="font-family: var(--font-heading);">
                                            #<?php echo str_pad(htmlspecialchars($row['bill_number']), 5, '0', STR_PAD_LEFT); ?>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="fa-regular fa-clock text-muted small"></i>
                                                <span><?php echo date('M d, Y • h:i A', strtotime($row['transaction_date'])); ?></span>
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
                                    <?php endwhile; ?>
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
