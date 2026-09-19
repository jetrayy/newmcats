<?php
session_start();
include_once __DIR__ . '/../../db.php';

// SECURITY: Only allow Super Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../../index.php");
    exit();
}

$session_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$s_query = $conn->prepare("SELECT s.*, u.username FROM cash_sessions s JOIN users u ON s.user_id = u.user_id WHERE s.id = ?");
$s_query->bind_param("i", $session_id);
$s_query->execute();
$session_data = $s_query->get_result()->fetch_assoc();

if (!$session_data) {
    die("Session not found.");
}

// Fetch sales matching this session
$sales_q = $conn->prepare("SELECT SUM(received_amount - balance_amount) as total FROM transactions WHERE session_id = ? AND type = 'selling'");
$sales_q->bind_param("i", $session_id);
$sales_q->execute();
$sales_total = $sales_q->get_result()->fetch_assoc()['total'] ?? 0;

// Fetch rentals matching this session
$rents_q = $conn->prepare("SELECT SUM(received_amount - balance_amount) as total FROM transactions WHERE session_id = ? AND type = 'renting'");
$rents_q->bind_param("i", $session_id);
$rents_q->execute();
$rents_total = $rents_q->get_result()->fetch_assoc()['total'] ?? 0;

// Calculate expected cash
$total_pos_revenue = $sales_total + $rents_total;
$expected_cash = $session_data['opening_balance'] + $total_pos_revenue;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MCATS | Session Settlement #<?php echo $session_id; ?></title>
    <link rel="icon" type="image/x-icon" href="../../img/ico.ico">
    <!-- Instant theme apply prevents flash -->
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
        .summary-card {
            border-radius: var(--radius-xl);
            border: 1px solid var(--luxury-card-border);
            background: var(--luxury-card-bg);
            box-shadow: var(--shadow-luxury);
        }
        .f-total {
            border: 1px solid rgba(16, 185, 129, 0.35);
            background: rgba(16, 185, 129, 0.08);
            border-radius: var(--radius-md);
        }
    </style>
</head>
<body class="min-vh-100 pb-5">
    <?php include __DIR__ . '/../includes/navbar.php'; ?>

    <div class="container py-4" style="max-width: 680px;">
        <div class="card summary-card overflow-hidden">
            <div class="p-4 p-md-5 border-bottom border-secondary-subtle d-flex justify-content-between align-items-center">
                <div>
                    <span class="badge text-uppercase mb-2" style="background: rgba(212, 175, 55, 0.15); color: var(--gold-primary); font-size: 0.72rem; letter-spacing: 0.1em;">Audit Settlement</span>
                    <h3 class="mb-0 fw-bold d-flex align-items-center gap-2">
                        <i class="fa-solid fa-file-invoice-dollar" style="color: var(--gold-primary);"></i>
                        Session #<?php echo $session_id; ?>
                    </h3>
                </div>
                <a href="sessions_report.php" class="btn btn-sm btn-outline-secondary fw-semibold rounded-pill px-3 shadow-sm">
                    <i class="fa-solid fa-arrow-left me-1"></i> Back
                </a>
            </div>
            
            <div class="p-4 p-md-5">
                <!-- Info Grid -->
                <div class="row g-3 mb-4 fw-medium">
                    <div class="col-6 d-flex justify-content-between pb-2 border-bottom border-secondary-subtle">
                        <span class="text-secondary small text-uppercase fw-bold">Cashier</span>
                        <span class="fw-semibold"><?php echo htmlspecialchars($session_data['username']); ?></span>
                    </div>
                    <div class="col-6 d-flex justify-content-between pb-2 border-bottom border-secondary-subtle">
                        <span class="text-secondary small text-uppercase fw-bold">Status</span>
                        <span>
                            <?php if($session_data['status'] == 'open'): ?>
                                <span class="badge bg-success bg-opacity-10 text-success border border-success px-2 py-1">OPEN</span>
                            <?php else: ?>
                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-2 py-1">CLOSED</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="col-12 d-flex justify-content-between pb-2 border-bottom border-secondary-subtle">
                        <span class="text-secondary small text-uppercase fw-bold">Opened At</span>
                        <span><i class="fa-regular fa-clock me-1 text-muted"></i><?php echo date("M j, Y, g:i A", strtotime($session_data['opened_at'])); ?></span>
                    </div>
                    <div class="col-12 d-flex justify-content-between pb-2 border-bottom border-secondary-subtle">
                        <span class="text-secondary small text-uppercase fw-bold">Closed At</span>
                        <span>
                            <?php if($session_data['closed_at']): ?>
                                <i class="fa-regular fa-clock me-1 text-muted"></i><?php echo date("M j, Y, g:i A", strtotime($session_data['closed_at'])); ?>
                            <?php else: ?>
                                <span class="text-muted fst-italic">Ongoing Session</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <!-- Financial Summary -->
                <div class="p-4 rounded-4" style="background: rgba(212, 175, 55, 0.04); border: 1px solid rgba(212, 175, 55, 0.18);">
                    <h5 class="fw-bold mb-4 d-flex align-items-center gap-2">
                        <i class="fa-solid fa-cash-register" style="color: var(--gold-primary);"></i>
                        Cash Flow Breakdown
                    </h5>
                    
                    <div class="d-flex justify-content-between mb-3 fs-6">
                        <span class="text-secondary">Opening Drawer Float:</span>
                        <span class="fw-bold">Rs. <?php echo number_format($session_data['opening_balance'], 2); ?></span>
                    </div>
                    
                    <div class="d-flex justify-content-between mb-3 fs-6">
                        <span class="text-secondary">+ Selling Revenue:</span>
                        <span class="fw-bold text-success">+ Rs. <?php echo number_format($sales_total, 2); ?></span>
                    </div>

                    <div class="d-flex justify-content-between mb-4 fs-6">
                        <span class="text-secondary">+ POS Revenue:</span>
                        <span class="fw-bold text-success">+ Rs. <?php echo number_format($rents_total, 2); ?></span>
                    </div>

                    <div class="d-flex justify-content-between align-items-center p-3 f-total mt-2">
                        <div>
                            <div class="fw-bold fs-6">Expected Drawer Total</div>
                            <small class="text-muted">Opening Float + Selling + POS</small>
                        </div>
                        <span class="fw-bold text-success fs-3">Rs. <?php echo number_format($expected_cash, 2); ?></span>
                    </div>
                    
                    <p class="text-center text-secondary small mt-4 mb-0">
                        <i class="fa-solid fa-circle-info me-1 text-warning"></i>
                        Verification reference for physical cash reconciliation at end of shift.
                    </p>
                </div>
            </div>
            <div class="p-4 border-top border-secondary-subtle text-center">
                <button onclick="window.print()" class="btn btn-luxury-gold btn-sm rounded-pill px-4 shadow-sm d-print-none">
                    <i class="fa-solid fa-print me-2"></i> Print Settlement Slip
                </button>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const toggleBtn = document.getElementById('themeToggle');
        const html = document.documentElement;

        if (toggleBtn) {
            if (localStorage.getItem('theme') === 'dark') {
                html.setAttribute('data-bs-theme', 'dark');
                toggleBtn.innerHTML = '<i class="fa-solid fa-sun me-1 text-warning"></i> Light Mode';
            }

            toggleBtn.addEventListener('click', () => {
                if (html.getAttribute('data-bs-theme') === 'dark') {
                    html.setAttribute('data-bs-theme', 'light');
                    localStorage.setItem('theme', 'light');
                    toggleBtn.innerHTML = '<i class="fa-solid fa-moon me-1"></i> Dark Mode';
                } else {
                    html.setAttribute('data-bs-theme', 'dark');
                    localStorage.setItem('theme', 'dark');
                    toggleBtn.innerHTML = '<i class="fa-solid fa-sun me-1 text-warning"></i> Light Mode';
                }
            });
        }
    </script>
</body>
</html>
