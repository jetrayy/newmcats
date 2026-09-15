<?php
session_start();
include_once __DIR__ . '/../../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php");
    exit();
}

$date_filter = isset($_GET['date']) ? $_GET['date'] : 'all';
$search_filter = isset($_GET['search']) ? $_GET['search'] : '';

// Base query for ongoing rentals
$sql = "SELECT t.*, c.full_name as c_name, c.phone_number as c_phone 
        FROM transactions t 
        LEFT JOIN customers c ON t.customer_nic = c.nic_number 
        WHERE t.type='renting' AND t.status='ongoing'";

$params = [];
$types = "";

if ($date_filter !== 'all') {
    $start = $date_filter . " 00:00:00";
    $end = $date_filter . " 23:59:59";
    $sql .= " AND t.transaction_date BETWEEN ? AND ?";
    $params[] = $start;
    $params[] = $end;
    $types .= "ss";
}

if (!empty($search_filter)) {
    $search_term = "%$search_filter%";
    $sql .= " AND (c.full_name LIKE ? OR c.nic_number LIKE ? OR c.phone_number LIKE ? OR t.notes LIKE ?)";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "ssss";
}

$sql .= " ORDER BY t.transaction_date DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$rentals_result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MCATS | Active Equipment Leases</title>
    <link rel="icon" type="image/x-icon" href="../../img/ico.ico">
    <!-- Instant theme apply — prevents flash -->
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
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--luxury-body-bg); min-height: 100vh; display: flex; flex-direction: column; }
        .top-navbar {
            background: #070a12;
            border-bottom: 1px solid rgba(212, 175, 55, 0.2);
            padding: 1rem 2rem;
        }
        .rental-card {
            background: var(--luxury-card-bg);
            border: 1px solid var(--luxury-card-border);
            border-radius: var(--radius-xl);
            transition: all 0.26s ease;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }
        .rental-card:hover {
            transform: translateY(-4px);
            border-color: rgba(212, 175, 55, 0.45);
            box-shadow: var(--shadow-glow);
            color: inherit;
        }
        .rental-card-footer {
            background: rgba(212, 175, 55, 0.08);
            border-top: 1px solid rgba(212, 175, 55, 0.18);
            color: var(--gold-primary);
            font-weight: 700;
            font-size: 0.82rem;
            letter-spacing: 0.04em;
            transition: all 0.2s ease;
        }
        .rental-card:hover .rental-card-footer {
            background: var(--gold-primary);
            color: #070a12;
        }
    </style>
</head>
<body>

    <div class="top-navbar d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center rounded-circle" style="width: 42px; height: 42px; background: rgba(212, 175, 55, 0.15); border: 1px solid rgba(212, 175, 55, 0.3);">
                <i class="fa-solid fa-boxes-packing" style="color: var(--gold-primary);"></i>
            </div>
            <div>
                <h5 class="mb-0 fw-bold text-white" style="font-family: 'Outfit', sans-serif; letter-spacing: 0.04em;">Active Rentals Directory</h5>
                <small class="text-secondary">Track live gear dispatch and process returns</small>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button id="themeToggle" class="btn btn-sm btn-outline-secondary text-white rounded-pill px-3 shadow-sm border-secondary">
                <i class="fa-solid fa-moon me-1"></i> Dark Mode
            </button>
            <a href="rent.php" class="btn btn-sm btn-luxury-gold rounded-pill px-3 fw-bold shadow-sm">
                <i class="fa-solid fa-arrow-left me-1"></i> Back to POS
            </a>
        </div>
    </div>

    <div class="container-fluid py-4 flex-grow-1" style="max-width: 1400px; margin: 0 auto;">
        
        <!-- Filters -->
        <div class="card p-3 p-md-4 mb-4 rounded-4" style="background: var(--luxury-card-bg); border: 1px solid var(--luxury-card-border); box-shadow: var(--shadow-sm);">
            <form method="GET" class="row align-items-end g-3">
                <div class="col-md-5">
                    <label class="form-label fw-bold text-secondary small mb-1 text-uppercase" style="letter-spacing: 0.05em;">
                        <i class="fa-solid fa-magnifying-glass me-1"></i> Client Search
                    </label>
                    <input type="text" name="search" class="form-control border-secondary-subtle" placeholder="Query by client name, NIC, or phone number..." value="<?php echo htmlspecialchars($search_filter); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold text-secondary small mb-1 text-uppercase" style="letter-spacing: 0.05em;">
                        <i class="fa-regular fa-calendar me-1"></i> Agreement Date
                    </label>
                    <input type="date" name="date" class="form-control border-secondary-subtle" value="<?php echo $date_filter !== 'all' ? $date_filter : ''; ?>">
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-luxury-gold flex-grow-1 fw-bold rounded-pill">
                        <i class="fa-solid fa-filter me-1"></i> Filter Leases
                    </button>
                    <a href="return_items.php" class="btn btn-outline-secondary border-secondary-subtle rounded-pill px-3">
                        <i class="fa-solid fa-rotate-left"></i>
                    </a>
                </div>
            </form>
        </div>

        <!-- Rentals Grid -->
        <div class="row g-4">
            <?php if ($rentals_result && $rentals_result->num_rows > 0): ?>
                <?php while($rental = $rentals_result->fetch_assoc()): ?>
                    <?php
                        $tid = $rental['bill_number'];
                        $i_sql = "SELECT i.item_name, ti.quantity FROM transaction_items ti JOIN inventory i ON ti.item_id = i.item_id WHERE ti.bill_number = ?";
                        $i_stmt = $conn->prepare($i_sql);
                        $i_stmt->bind_param("i", $tid);
                        $i_stmt->execute();
                        $items_res = $i_stmt->get_result();
                        
                        $cname = !empty($rental['c_name']) ? $rental['c_name'] : "Walk-in Client";
                        $cphone = !empty($rental['c_phone']) ? $rental['c_phone'] : "No Phone";
                        $cnic = !empty($rental['customer_nic']) ? $rental['customer_nic'] : "Unspecified";
                        $date_fmt = date("M j, Y • g:i A", strtotime($rental['transaction_date']));
                    ?>
                    
                    <div class="col-md-6 col-lg-4 col-xl-3">
                        <a href="return_checkout.php?bill_id=<?php echo $tid; ?>" class="rental-card h-100">
                            <div class="p-3 border-bottom border-secondary-subtle d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="fw-bold fs-6 mb-1 text-truncate" title="<?php echo htmlspecialchars($cname); ?>"><?php echo htmlspecialchars($cname); ?></div>
                                    <div class="text-secondary small">
                                        <i class="fa-regular fa-id-card me-1"></i><?php echo htmlspecialchars($cnic); ?>
                                    </div>
                                    <div class="text-secondary small">
                                        <i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($cphone); ?>
                                    </div>
                                </div>
                                <span class="badge" style="background: rgba(212, 175, 55, 0.15); color: var(--gold-primary); font-family: 'Outfit', sans-serif; font-size: 0.85rem; padding: 0.35rem 0.6rem;">
                                    #<?php echo $tid; ?>
                                </span>
                            </div>
                            
                            <div class="p-3 flex-grow-1">
                                <div class="d-flex justify-content-between mb-2 small">
                                    <span class="text-secondary">Agreement Timestamp:</span>
                                    <span class="fw-semibold small"><?php echo $date_fmt; ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-3 small">
                                    <span class="text-secondary">Security Advance:</span>
                                    <span class="fw-bold text-success">Rs. <?php echo number_format($rental['advance_paid'], 2); ?></span>
                                </div>
                                
                                <div class="p-2 rounded-3 small" style="background: rgba(0, 0, 0, 0.03); border: 1px solid var(--luxury-card-border);">
                                    <strong class="d-block mb-1 text-secondary" style="font-size: 0.76rem; text-uppercase; letter-spacing: 0.05em;">
                                        <i class="fa-solid fa-camera-retro me-1" style="color: var(--gold-primary);"></i> Dispatched Equipment:
                                    </strong>
                                    <ul class="mb-0 ps-3 text-secondary" style="font-size: 0.8rem;">
                                    <?php 
                                        $count = 0;
                                        while($it = $items_res->fetch_assoc()) {
                                            echo "<li>" . $it['quantity'] . "× " . htmlspecialchars($it['item_name']) . "</li>";
                                            $count++;
                                            if($count >= 3) break;
                                        }
                                        if($items_res->num_rows > 3) {
                                            echo "<li class='fst-italic'>+ " . ($items_res->num_rows - 3) . " additional items...</li>";
                                        }
                                        if($count == 0) echo "<li class='fst-italic'>Standard package</li>";
                                    ?>
                                    </ul>
                                </div>
                            </div>
                            
                            <div class="rental-card-footer text-center p-3">
                                Process Return & Settlement <i class="fa-solid fa-arrow-right ms-1"></i>
                            </div>
                        </a>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="col-12">
                    <div class="p-5 text-center rounded-4 border border-secondary-subtle" style="background: var(--luxury-card-bg);">
                        <i class="fa-solid fa-boxes-packing fs-1 d-block mb-3" style="color: var(--gold-primary); opacity: 0.5;"></i>
                        <h5 class="fw-bold">No Active Leases Found</h5>
                        <p class="text-secondary small mb-0">All equipment returned or no records matched the applied filter criteria.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const toggleBtn = document.getElementById('themeToggle');
        const html = document.documentElement;

        function updateThemeBtns(isDark) {
            toggleBtn.innerHTML = isDark ? '<i class="fa-solid fa-sun me-1 text-warning"></i> Light Mode' : '<i class="fa-solid fa-moon me-1"></i> Dark Mode';
        }

        if (localStorage.getItem('theme') === 'dark') {
            html.setAttribute('data-bs-theme', 'dark');
            updateThemeBtns(true);
        }

        toggleBtn.addEventListener('click', () => {
            if (html.getAttribute('data-bs-theme') === 'dark') {
                html.setAttribute('data-bs-theme', 'light');
                localStorage.setItem('theme', 'light');
                updateThemeBtns(false);
            } else {
                html.setAttribute('data-bs-theme', 'dark');
                localStorage.setItem('theme', 'dark');
                updateThemeBtns(true);
            }
        });
    </script>
</body>
</html>
