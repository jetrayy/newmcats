<?php
session_start();
include_once __DIR__ . '/../../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php");
    exit();
}

// If a customer was selected, save to session and go back to rent.php
if (isset($_POST['select_nic'])) {
    $nic = $_POST['select_nic'];
    $stmt = $conn->prepare("SELECT * FROM customers WHERE nic_number = ? LIMIT 1");
    $stmt->bind_param("s", $nic);
    $stmt->execute();
    $cust = $stmt->get_result()->fetch_assoc();
    if ($cust) {
        $_SESSION['selected_customer'] = $cust;
    }
    header("Location: rent.php");
    exit();
}

// Search Logic
$search = trim($_GET['q'] ?? '');
$customers = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = $conn->prepare("SELECT * FROM customers WHERE phone_number LIKE ? OR nic_number LIKE ? OR full_name LIKE ? ORDER BY full_name LIMIT 50");
    $stmt->bind_param("sss", $like, $like, $like);
    $stmt->execute();
    $customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $res = $conn->query("SELECT * FROM customers ORDER BY full_name LIMIT 100");
    if ($res) $customers = $res->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MCATS | Customer Directory</title>
    <link rel="icon" type="image/x-icon" href="../../img/ico.ico">
    <!-- Instant theme apply - prevents flash -->
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
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--luxury-body-bg); min-height: 100vh; }
        .top-navbar {
            background: #070a12;
            border-bottom: 1px solid rgba(212, 175, 55, 0.2);
            padding: 1rem 2rem;
        }
        .luxury-card-container {
            background: var(--luxury-card-bg);
            border: 1px solid var(--luxury-card-border);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-luxury);
            overflow: hidden;
        }
        .customer-row {
            transition: background 0.18s ease;
        }
        .customer-row:hover {
            background-color: rgba(212, 175, 55, 0.05) !important;
        }
    </style>
</head>
<body>

<!-- Global Navigation Bar -->
<?php include_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="top-navbar d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div class="d-flex align-items-center gap-3">
        <div class="d-flex align-items-center justify-content-center rounded-circle" style="width: 42px; height: 42px; background: rgba(212, 175, 55, 0.15); border: 1px solid rgba(212, 175, 55, 0.3);">
            <i class="fa-solid fa-users" style="color: var(--gold-primary);"></i>
        </div>
        <div>
            <h5 class="mb-0 fw-bold text-white" style="font-family: 'Outfit', sans-serif; letter-spacing: 0.04em;">Client Dossier Lookup</h5>
            <small class="text-secondary">Attach registered account to rental docket</small>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button id="themeToggle" class="btn btn-sm btn-outline-secondary rounded-pill px-3 shadow-sm text-white border-secondary">
            <i class="fa-solid fa-moon me-1"></i> Dark Mode
        </button>
        <a href="rent.php" class="btn btn-sm btn-outline-secondary text-white rounded-pill px-3 shadow-sm border-secondary">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to POS
        </a>
    </div>
</div>

<div class="container py-4" style="max-width: 950px;">

    <!-- Search Card -->
    <div class="card luxury-card-container mb-4 p-3 p-md-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="fw-bold mb-0 d-flex align-items-center gap-2">
                <i class="fa-solid fa-magnifying-glass" style="color: var(--gold-primary);"></i>
                Search Directory
            </h5>
            <span class="badge text-uppercase" style="background: rgba(212, 175, 55, 0.15); color: var(--gold-primary); font-size: 0.72rem; letter-spacing: 0.1em;">Instant Lookup</span>
        </div>
        <form method="GET" action="">
            <div class="input-group input-group-lg shadow-sm">
                <span class="input-group-text bg-transparent border-secondary-subtle"><i class="fa-solid fa-search text-secondary"></i></span>
                <input type="text" name="q" class="form-control border-secondary-subtle fs-6" placeholder="Query by client name, telephone, or identification number..." value="<?php echo htmlspecialchars($search); ?>" autofocus>
                <button class="btn btn-luxury-gold fw-bold px-4" type="submit">
                    <i class="fa-solid fa-magnifying-glass me-1"></i> Find
                </button>
            </div>
        </form>
    </div>

    <!-- Results Table -->
    <div class="card luxury-card-container">
        <div class="card-body p-0">
            <?php if (empty($customers)): ?>
                <div class="text-center text-secondary p-5">
                    <i class="fa-solid fa-user-slash fs-1 mb-3 d-block opacity-40"></i>
                    <h6>No Customer Dossiers Found</h6>
                    <p class="small text-secondary mb-0">No records match<?php echo $search ? ' "' . htmlspecialchars($search) . '"' : ''; ?>. You can register new details directly on the POS page.</p>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead style="background: rgba(0, 0, 0, 0.04); border-bottom: 1px solid var(--luxury-card-border);">
                        <tr>
                            <th class="fw-bold text-secondary ps-4 py-3 text-uppercase small" style="letter-spacing: 0.05em;">Client Name</th>
                            <th class="fw-bold text-secondary text-uppercase small" style="letter-spacing: 0.05em;">NIC / Identity</th>
                            <th class="fw-bold text-secondary text-uppercase small" style="letter-spacing: 0.05em;">Phone</th>
                            <th class="fw-bold text-secondary text-uppercase small" style="letter-spacing: 0.05em;">Registered Address</th>
                            <th class="fw-bold text-secondary text-center text-uppercase small" style="letter-spacing: 0.05em;">Select</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($customers as $c): ?>
                        <tr class="customer-row border-bottom border-secondary-subtle">
                            <td class="fw-bold ps-4 py-3">
                                <i class="fa-regular fa-user me-2 text-secondary"></i>
                                <?php echo htmlspecialchars($c['full_name']); ?>
                            </td>
                            <td class="text-secondary small font-monospace"><?php echo htmlspecialchars($c['nic_number']); ?></td>
                            <td class="fw-medium"><?php echo htmlspecialchars($c['phone_number']); ?></td>
                            <td class="text-secondary small"><?php echo htmlspecialchars($c['address'] ?? '-'); ?></td>
                            <td class="text-center">
                                <form method="POST" action="" class="d-inline">
                                    <input type="hidden" name="select_nic" value="<?php echo htmlspecialchars($c['nic_number']); ?>">
                                    <button type="submit" class="btn btn-luxury-gold btn-sm rounded-pill px-3 shadow-sm fw-bold">
                                        <i class="fa-solid fa-check me-1"></i> Apply to POS
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const toggleBtn = document.getElementById('themeToggle');
    const html = document.documentElement;

    function updateBtn(isDark) {
        toggleBtn.innerHTML = isDark ? '<i class="fa-solid fa-sun me-1 text-warning"></i> Light Mode' : '<i class="fa-solid fa-moon me-1"></i> Dark Mode';
    }

    if (localStorage.getItem('theme') === 'dark') {
        html.setAttribute('data-bs-theme', 'dark');
        updateBtn(true);
    }

    toggleBtn.addEventListener('click', () => {
        const isDark = html.getAttribute('data-bs-theme') === 'dark';
        html.setAttribute('data-bs-theme', isDark ? 'light' : 'dark');
        localStorage.setItem('theme', isDark ? 'light' : 'dark');
        updateBtn(!isDark);
    });
</script>
</body>
</html>
