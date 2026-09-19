<?php
session_start();
include_once __DIR__ . '/../../db.php';

// Security: Allow admin or super_admin
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php");
    exit();
}

$msg = "";

// --- DELETE LOGIC ---
if (isset($_GET['delete'])) {
    $del_id = intval($_GET['delete']);
    try {
        $stmt = @$conn->prepare("DELETE FROM inventory WHERE item_id = ?");
        if($stmt) {
            $stmt->bind_param("i", $del_id);
            if($stmt->execute()) {
                $msg = "<div class='alert alert-success'>Item deleted successfully.</div>";
            } else {
                $msg = "<div class='alert alert-error'>Error deleting item.</div>";
            }
        }
    } catch (Exception $e) {
        $msg = "<div class='alert alert-error'><b>Deletion Blocked:</b> Cannot delete this item because it is linked to existing bills or transaction histories.</div>";
    }
}

// --- ADD/EDIT LOGIC ---
if (isset($_POST['save_item'])) {
    $id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
    $name = $_POST['item_name'];
    $cat = $_POST['category'];
    $price = $_POST['price_per_unit'];
    $stock = $_POST['stock_quantity'];
    $status = $_POST['status'];
    $bought_price = (isset($_POST['bought_price']) && $_POST['bought_price'] !== '') ? trim($_POST['bought_price']) : null;
    
    // Handle Image Upload
    $img = $_POST['existing_image'] ?? 'placeholder.jpg';
    if (isset($_FILES['item_image']) && $_FILES['item_image']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $filename = $_FILES['item_image']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $new_filename = uniqid() . '.' . $ext;
            $destination = __DIR__ . '/../../img/' . $new_filename;
            
            if (move_uploaded_file($_FILES['item_image']['tmp_name'], $destination)) {
                $img = $new_filename;
            } else {
                $msg = "<div class='alert alert-error'>Failed to move uploaded file. Check directory permissions.</div>";
            }
        } else {
            $msg = "<div class='alert alert-error'>Invalid image format. Only JPG, PNG, GIF allowed.</div>";
        }
    }

    $grant_id = isset($_POST['grant_id']) ? intval($_POST['grant_id']) : 0;
    
    if ($id > 0 && empty($msg)) {
        // Update
        $stmt = @$conn->prepare("UPDATE inventory SET item_name=?, category=?, price_per_unit=?, stock_quantity=?, status=?, item_image=?, bought_price=? WHERE item_id=?");
        if($stmt) {
            $stmt->bind_param("ssdisssi", $name, $cat, $price, $stock, $status, $img, $bought_price, $id);
            if($stmt->execute()) {
                if ($grant_id > 0) {
                    $c_stmt = @$conn->prepare("UPDATE change_requests SET status = 'completed' WHERE id = ?");
                    if ($c_stmt) {
                        $c_stmt->bind_param("i", $grant_id);
                        $c_stmt->execute();
                    }
                }
                $msg = "<div class='alert alert-success'><i class='fa-solid fa-circle-check me-2'></i>Item updated successfully." . ($grant_id > 0 ? " Authorized change request #REQ-" . str_pad($grant_id, 3, '0', STR_PAD_LEFT) . " marked as completed." : "") . "</div>";
            } else {
                $msg = "<div class='alert alert-error'>Error updating item.</div>";
            }
        }
    } else if (empty($msg)) {
        // Insert
        $stmt = @$conn->prepare("INSERT INTO inventory (item_name, category, price_per_unit, stock_quantity, status, item_image, bought_price) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if($stmt) {
            $stmt->bind_param("ssdisss", $name, $cat, $price, $stock, $status, $img, $bought_price);
            if($stmt->execute()) {
                $msg = "<div class='alert alert-success'>Item added successfully.</div>";
            } else {
                $msg = "<div class='alert alert-error'>Error adding item.</div>";
            }
        }
    }
}

// Compute Request Counts for sidebar badges
$req_counts = [
    'pendingTotal' => 0,
    'pendingQty' => 0,
    'pendingRate' => 0,
    'pendingCost' => 0
];
$c_res = @$conn->query("SELECT type, COUNT(*) as cnt FROM change_requests WHERE status = 'pending' GROUP BY type");
if ($c_res) {
    while ($cr = $c_res->fetch_assoc()) {
        $cnt = intval($cr['cnt']);
        $req_counts['pendingTotal'] += $cnt;
        if ($cr['type'] === 'quantity') $req_counts['pendingQty'] = $cnt;
        if ($cr['type'] === 'daily_rate') $req_counts['pendingRate'] = $cnt;
        if ($cr['type'] === 'cost_price') $req_counts['pendingCost'] = $cnt;
    }
}

// Check for active Super Admin Grant passed via grant_id
$active_grant = null;
if (isset($_GET['grant_id'])) {
    $gid = intval($_GET['grant_id']);
    $g_stmt = @$conn->prepare("SELECT * FROM change_requests WHERE id = ? AND status = 'approved'");
    if ($g_stmt) {
        $g_stmt->bind_param("i", $gid);
        $g_stmt->execute();
        $g_res = $g_stmt->get_result();
        if ($g_res && $g_res->num_rows > 0) {
            $active_grant = $g_res->fetch_assoc();
        }
    }
}

// Fetch Rental Inventory
$inv_query = "SELECT * FROM inventory ORDER BY item_id DESC";
$inv_result = @$conn->query($inv_query);

// Fetch item for edit
$edit_data = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $stmt = @$conn->prepare("SELECT * FROM inventory WHERE item_id = ?");
    if($stmt) {
        $stmt->bind_param("i", $edit_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $edit_data = $res->fetch_assoc();
        }
    }
}

// BLACKHORSE = 1234567890 encoding function
function encodePrice($price) {
    if ($price === null || $price === '') return 'N/A';
    if (!is_numeric($price)) return strtoupper($price); // Already encoded or letter input
    $key = 'BLACKHORSE'; // B=1,L=2,A=3,C=4,K=5,H=6,O=7,R=8,S=9,E=0
    $digits = str_split(str_replace('.', '', number_format($price, 2, '', '')));
    $encoded = '';
    foreach ($digits as $d) {
        $encoded .= $key[$d];
    }
    return $encoded;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MCATS | Inventory Management</title>
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
                    <div class="d-none d-md-block mb-4 text-center pb-3 border-bottom" style="border-color: var(--obsidian-border) !important;">
                        <img src="../../img/logo.png" style="width: 70px; filter: drop-shadow(0 4px 10px rgba(0,0,0,0.5));" class="mb-2" alt="MCATS Logo">
                        <h4 class="luxury-brand-title m-0">MCATS <?php echo ($_SESSION['role'] === 'super_admin') ? 'SA' : 'ADMIN'; ?></h4>
                        <small class="text-white-50" style="font-size: 0.725rem; letter-spacing: 0.1em;">TERMINAL SUITE</small>
                    </div>
                    <div class="nav flex-column">
                        <a href="<?php echo ($_SESSION['role'] === 'super_admin') ? 'sahome.php' : 'home.php'; ?>" class="luxury-nav-link"><i class="fa-solid fa-gauge"></i>Dashboard</a>
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
                        <a href="#inventorySubmenu" data-bs-toggle="collapse" class="luxury-nav-link active d-flex align-items-center" role="button" aria-expanded="true">
                            <i class="fa-solid fa-boxes-stacked"></i>
                            <span>Inventory</span>
                            <?php if ($req_counts['pendingTotal'] > 0): ?>
                                <span class="badge bg-warning text-dark rounded-pill ms-2" style="font-size: 0.65rem;"><?php echo $req_counts['pendingTotal']; ?></span>
                            <?php endif; ?>
                            <i class="fa-solid fa-chevron-down nav-arrow ms-auto"></i>
                        </a>
                        <div class="collapse show luxury-submenu" id="inventorySubmenu">
                            <a href="inventory.php" class="luxury-sub-link active">
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

                        <?php if ($_SESSION['role'] === 'super_admin'): ?>
                            <a href="inventory_stats.php" class="luxury-nav-link"><i class="fa-solid fa-chart-pie"></i>Inventory Stats</a>
                            <a href="sessions_report.php" class="luxury-nav-link"><i class="fa-solid fa-calendar-day"></i>Day Sessions</a>
                            <a href="reports.php" class="luxury-nav-link"><i class="fa-solid fa-file-invoice-dollar"></i>Reports</a>
                            <a href="manage_user.php" class="luxury-nav-link"><i class="fa-solid fa-users-gear"></i>User Accounts</a>
                        <?php endif; ?>

                        <a href="../../logout.php" class="luxury-nav-link logout"><i class="fa-solid fa-right-from-bracket"></i>Logout</a>
                    </div>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-2 pb-3 mb-4 border-bottom position-relative" style="border-color: var(--border-subtle) !important;">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="luxury-badge luxury-badge-gold"><i class="fa-solid fa-boxes-stacked"></i> Master Catalog</span>
                        </div>
                        <h1 class="h2 mb-0">Inventory Management</h1>
                        <div id="realtimeClock" class="text-muted small mt-1"></div>
                    </div>
                    <div class="d-flex align-items-center gap-3 mt-3 mt-md-0">
                        <a href="home.php" class="btn btn-luxury-outline">
                            <i class="fa-solid fa-arrow-left me-1"></i> Dashboard
                        </a>
                        <button id="themeToggle" class="theme-toggle-btn"><i class="fa-solid fa-moon me-1"></i> Dark Mode</button>
                    </div>
                </div>

                <?php if($msg) echo $msg; ?>

                <!-- Add/Edit Form -->
                <div class="luxury-card p-4 mb-5" id="itemForm">
                    <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom" style="border-color: var(--border-subtle) !important;">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; background: var(--gold-subtle-bg); color: var(--gold-primary);">
                                <i class="fa-solid <?php echo $edit_data ? 'fa-pen-ruler' : 'fa-plus'; ?> fs-5"></i>
                            </div>
                            <div>
                                <h4 class="mb-0 fw-bold"><?php echo $edit_data ? "Edit Item #" . $edit_data['item_id'] : "Register New Product"; ?></h4>
                                <small class="text-muted">Enter product specifications, stock levels, and secret cost code</small>
                            </div>
                        </div>
                        <?php if($edit_data): ?>
                            <a href="inventory.php" class="btn btn-sm btn-luxury-outline"><i class="fa-solid fa-xmark me-1"></i> Cancel Edit</a>
                        <?php endif; ?>
                    </div>

                    <?php if ($active_grant): ?>
                        <div class="alert alert-success d-flex align-items-center justify-content-between gap-3 mb-4 rounded-3 p-3 shadow-sm border border-success">
                            <div class="d-flex align-items-center gap-3">
                                <i class="fa-solid fa-circle-check fs-3 text-success"></i>
                                <div>
                                    <div class="fw-bold">Super Admin Change Grant Approved (#REQ-<?php echo str_pad($active_grant['id'], 3, '0', STR_PAD_LEFT); ?>)</div>
                                    <div class="small">You are authorized to update the <b><?php echo $active_grant['type'] === 'quantity' ? 'Stock Quantity' : ($active_grant['type'] === 'daily_rate' ? 'Daily Rate' : 'Cost Price'); ?></b> for this item. Approved Value: <span class="badge bg-success px-2"><?php echo htmlspecialchars($active_grant['requested_value']); ?></span></div>
                                </div>
                            </div>
                            <span class="badge bg-success text-white">Authorized</span>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="inventory.php" enctype="multipart/form-data">
                        <input type="hidden" name="item_id" value="<?php echo $edit_data['item_id'] ?? ''; ?>">
                        <?php if ($active_grant): ?>
                            <input type="hidden" name="grant_id" value="<?php echo $active_grant['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small text-muted">Item Name</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-transparent" style="border-color: var(--border-subtle); color: var(--gold-primary);"><i class="fa-solid fa-tag"></i></span>
                                    <input type="text" class="form-control luxury-input border-start-0" name="item_name" required value="<?php echo htmlspecialchars($edit_data['item_name'] ?? ''); ?>" placeholder="e.g. Makita Cordless Drill 18V">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small text-muted">Category</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-transparent" style="border-color: var(--border-subtle); color: var(--gold-primary);"><i class="fa-solid fa-layer-group"></i></span>
                                    <select class="form-select luxury-input border-start-0" name="category" required>
                                        <option value="Power Tools" <?php echo ($edit_data && $edit_data['category']=='Power Tools')?'selected':''; ?>>Power Tools (Retail Selling)</option>
                                        <option value="Hardware Goods" <?php echo ($edit_data && $edit_data['category']=='Hardware Goods')?'selected':''; ?>>Hardware Goods (Retail Selling)</option>
                                        <option value="Rental Items" <?php echo ($edit_data && $edit_data['category']=='Rental Items')?'selected':''; ?>>Rental Items (POS Rental)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row g-3 mb-4">
                            <?php 
                                $price_val = $edit_data['price_per_unit'] ?? '';
                                if ($active_grant && $active_grant['type'] === 'daily_rate') {
                                    $price_val = $active_grant['requested_value'];
                                }
                                $cost_val = $edit_data['bought_price'] ?? '';
                                if ($active_grant && $active_grant['type'] === 'cost_price') {
                                    $cost_val = $active_grant['requested_value'];
                                }
                                $stock_val = $edit_data['stock_quantity'] ?? '1';
                                if ($active_grant && $active_grant['type'] === 'quantity') {
                                    $stock_val = $active_grant['requested_value'];
                                }
                                $can_edit_stock = ($_SESSION['role'] === 'super_admin') || ($active_grant && $active_grant['type'] === 'quantity');
                            ?>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small text-muted">Selling / Daily Rate (LKR)</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-transparent fw-bold" style="border-color: var(--border-subtle); color: var(--gold-primary);">Rs.</span>
                                    <input type="number" step="0.01" class="form-control luxury-input border-start-0 <?php echo ($active_grant && $active_grant['type'] === 'daily_rate') ? 'border-success' : ''; ?>" name="price_per_unit" required value="<?php echo htmlspecialchars($price_val); ?>" placeholder="0.00">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small text-muted">
                                    Cost Price or Cipher <span class="luxury-badge luxury-badge-slate py-0 px-2 ms-1" style="font-size: 0.65rem;">BLACKHORSE 🔒</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-transparent" style="border-color: var(--border-subtle); color: var(--gold-primary);"><i class="fa-solid fa-key"></i></span>
                                    <input type="text" class="form-control luxury-input border-start-0 <?php echo ($active_grant && $active_grant['type'] === 'cost_price') ? 'border-success' : ''; ?>" name="bought_price" placeholder="Enter amount or letter code" value="<?php echo htmlspecialchars($cost_val); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small text-muted">Stock Quantity</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-transparent" style="border-color: var(--border-subtle); color: var(--gold-primary);"><i class="fa-solid fa-cubes-stacked"></i></span>
                                    <?php if($can_edit_stock): ?>
                                    <input type="number" class="form-control luxury-input border-start-0 <?php echo ($active_grant && $active_grant['type'] === 'quantity') ? 'border-success' : ''; ?>" name="stock_quantity" required value="<?php echo htmlspecialchars($stock_val); ?>">
                                    <?php else: ?>
                                    <input type="number" class="form-control luxury-input border-start-0 bg-secondary-subtle" name="stock_quantity" value="<?php echo htmlspecialchars($stock_val); ?>" readonly title="Only Super Admin or authorized request can change stock quantity">
                                    <?php endif; ?>
                                </div>
                                <?php if(!$can_edit_stock): ?>
                                <div class="form-text text-warning"><i class="fa-solid fa-lock me-1"></i>Editable by Super Admin or via approved Change Request.</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small text-muted">Initial Status</label>
                                <select class="form-select luxury-input" name="status">
                                    <option value="available" <?php echo ($edit_data && $edit_data['status']=='available')?'selected':''; ?>>Available in Stock</option>
                                    <option value="rented" <?php echo ($edit_data && $edit_data['status']=='rented')?'selected':''; ?>>Rented / Unavailable</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small text-muted">
                                    Product Image <?php if($edit_data && $edit_data['item_image']) echo "<span class='text-muted fw-normal'>(Leave empty to keep existing)</span>"; ?>
                                </label>
                                <input type="file" class="form-control luxury-input" name="item_image" accept="image/*">
                                <input type="hidden" name="existing_image" value="<?php echo htmlspecialchars($edit_data['item_image'] ?? 'placeholder.jpg'); ?>">
                            </div>
                        </div>

                        <div class="text-end pt-2">
                            <?php if($edit_data): ?>
                                <a href="inventory.php" class="btn btn-luxury-outline px-4 me-2">Cancel</a>
                            <?php endif; ?>
                            <button type="submit" name="save_item" class="btn btn-luxury-gold px-4">
                                <i class="fa-solid <?php echo $edit_data ? 'fa-floppy-disk' : 'fa-plus'; ?> me-1"></i>
                                <?php echo $edit_data ? "Save Item Changes" : "Create Item"; ?>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Inventory List -->
                <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                    <h4 class="fw-bold mb-0">Master Inventory Catalog</h4>
                    <div class="d-flex align-items-center gap-2">
                        <div class="input-group" style="max-width: 320px;">
                            <span class="input-group-text bg-transparent" style="border-color: var(--border-subtle);"><i class="fa-solid fa-magnifying-glass text-secondary"></i></span>
                            <input type="text" id="inventorySearch" class="form-control luxury-input border-start-0" placeholder="Search by name, category, status..." oninput="filterInventory()">
                        </div>
                        <span class="text-muted small">Real-time stock audit</span>
                    </div>
                </div>

                <div class="luxury-card overflow-hidden mb-5">
                    <div class="table-responsive">
                        <table class="luxury-table align-middle">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Image</th>
                                    <th>Product Name</th>
                                    <th>Category</th>
                                    <th>Rate (LKR)</th>
                                    <th class="text-center">Cost Code <i class="fa-solid fa-lock text-warning ms-1"></i></th>
                                    <th>Stock</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                if($inv_result && $inv_result->num_rows > 0) {
                                    while($row = $inv_result->fetch_assoc()):
                                ?>
                                <tr>
                                    <td class="text-muted fw-bold" style="font-family: var(--font-heading);">
                                        #<?php echo str_pad(htmlspecialchars($row['item_id']), 4, '0', STR_PAD_LEFT); ?>
                                    </td>
                                    <td>
                                        <?php if($row['item_image'] && $row['item_image'] !== 'default.png' && $row['item_image'] !== 'placeholder.jpg'): ?>
                                            <img src="../../img/<?php echo htmlspecialchars($row['item_image']); ?>" class="rounded-3 object-fit-cover shadow-sm" style="width: 44px; height: 44px; border: 1px solid var(--border-subtle);" alt="Product">
                                        <?php else: ?>
                                            <div class="rounded-3 d-flex align-items-center justify-content-center text-muted" style="width: 44px; height: 44px; background: var(--bg-surface-elevated); border: 1px solid var(--border-subtle);">
                                                <i class="fa-solid fa-image" style="font-size: 0.9rem;"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-main"><?php echo htmlspecialchars($row['item_name']); ?></div>
                                    </td>
                                    <td>
                                        <?php if($row['category'] == 'Rental Items'): ?>
                                            <span class="luxury-badge luxury-badge-gold">Rental Items</span>
                                        <?php elseif($row['category'] == 'Power Tools'): ?>
                                            <span class="luxury-badge luxury-badge-emerald">Power Tools</span>
                                        <?php else: ?>
                                            <span class="luxury-badge luxury-badge-slate">Hardware Goods</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-bold" style="font-family: var(--font-heading); color: var(--gold-primary);">
                                        Rs. <?php echo number_format($row['price_per_unit'], 2); ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge px-3 py-1 fw-bold" style="font-family: 'Courier New', monospace; letter-spacing: 2px; background: var(--bg-surface-elevated); color: var(--text-secondary); border: 1px solid var(--border-subtle);">
                                            <?php echo htmlspecialchars(isset($row['bought_price']) && $row['bought_price'] !== null ? encodePrice($row['bought_price']) : '-'); ?>
                                        </span>
                                    </td>
                                    <td class="fw-bold">
                                        <?php if($row['stock_quantity'] <= 3): ?>
                                            <span class="text-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i><?php echo htmlspecialchars($row['stock_quantity']); ?></span>
                                        <?php else: ?>
                                            <span><?php echo htmlspecialchars($row['stock_quantity']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($row['status'] == 'available'): ?>
                                            <span class="luxury-badge luxury-badge-emerald"><i class="fa-solid fa-check"></i> Available</span>
                                        <?php else: ?>
                                            <span class="luxury-badge luxury-badge-crimson"><i class="fa-solid fa-clock"></i> Rented</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="inventory.php?edit=<?php echo $row['item_id']; ?>#itemForm" class="btn btn-sm btn-luxury-outline py-1 px-2">
                                            <i class="fa-solid fa-pen-to-square"></i> Edit
                                        </a>
                                        <a href="inventory.php?delete=<?php echo $row['item_id']; ?>" class="btn btn-sm btn-outline-danger py-1 px-2 ms-1 rounded-2" onclick="return confirm('Are you sure you want to delete this item?');" style="border-width: 1px;">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php 
                                    endwhile; 
                                } else {
                                    echo "<tr><td colspan='9' class='text-center text-muted py-5'><i class='fa-solid fa-box-open d-block fs-1 mb-2 opacity-50'></i>No inventory items registered in catalog. Add one above!</td></tr>";
                                }
                                ?>
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
