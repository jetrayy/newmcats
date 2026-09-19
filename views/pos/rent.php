<?php
session_start();
include_once __DIR__ . '/../../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$msg = "";

// Check for active session
$active_session_query = $conn->query("SELECT id FROM cash_sessions WHERE status='open' AND DATE(opened_at) = CURDATE() ORDER BY id DESC LIMIT 1");
$active_session = $active_session_query->fetch_assoc();
if (!$active_session) {
    echo "<script>alert('No active day session! Please start a session from the Admin Dashboard first.'); window.location.href='../admin/home.php';</script>";
    exit();
}
$session_id = $active_session['id'];

// STAGE 1: Rent Out (Checkout Logic)
if (isset($_POST['rent_out'])) {
    $cart_data = json_decode($_POST['cart_data'], true);
    $nic = trim($_POST['nic'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $full_name = trim($_POST['customer_name'] ?? 'Walk-in Customer');
    $address = trim($_POST['address'] ?? '');
    $advance = floatval($_POST['advance']);
    
    // Calculate total from cart items
    $total_lkr = 0;
    foreach ($cart_data as $item) {
        $total_lkr += ($item['price'] * $item['qty']);
    }

    $free_eq_text = (isset($_POST['free_eq']) && $_POST['free_eq'] === 'yes') ? (trim($_POST['free_eq_desc']) ?: 'Included Free Equipment') : null;
    $received_amount = isset($_POST['received_amount']) ? floatval($_POST['received_amount']) : $advance;
    $balance_amount = $received_amount - $advance;

    @$conn->begin_transaction();
    try {
        // Upsert customer record
        $c_nic = !empty($nic) ? $nic : ('PH-' . $phone);
        $c_stmt = @$conn->prepare("INSERT INTO customers (nic_number, phone_number, full_name, address) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE phone_number=VALUES(phone_number), full_name=VALUES(full_name), address=VALUES(address)");
        if ($c_stmt) {
            $c_stmt->bind_param("ssss", $c_nic, $phone, $full_name, $address);
            $c_stmt->execute();
        }

        $sql = "INSERT INTO transactions (session_id, type, total_lkr, received_amount, balance_amount, advance_paid, free_equipment, status, customer_nic) VALUES (?, 'renting', ?, ?, ?, ?, ?, 'ongoing', ?)";
        $stmt = @$conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("iddddss", $session_id, $total_lkr, $received_amount, $balance_amount, $advance, $free_eq_text, $c_nic);
            $stmt->execute();
            $bill_number = $stmt->insert_id;
            
            $i_stmt = @$conn->prepare("INSERT INTO transaction_items (bill_number, item_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
            $u_stmt = @$conn->prepare("UPDATE inventory SET status = 'rented' WHERE item_id = ?");

            foreach ($cart_data as $item) {
                $item_id = $item['id'];
                $qty = $item['qty'];
                $price = $item['price'];
                
                $i_stmt->bind_param("iiid", $bill_number, $item_id, $qty, $price);
                $i_stmt->execute();

                $u_stmt->bind_param("i", $item_id);
                $u_stmt->execute();
            }

            // Clear selected customer from session after use
            unset($_SESSION['selected_customer']);
            
            @$conn->commit();
            echo "<script>window.location.href = 'print_bill.php?type=thermal_rent&bill_id=$bill_number';</script>";
            exit();
        }
    } catch (Exception $e) {
        @$conn->rollback();
        $msg = "<div class='alert alert-danger shadow-sm rounded-3 mt-3'><i class='fa-solid fa-triangle-exclamation me-2'></i>Error: " . $e->getMessage() . "</div>";
    }
}

// Fetch Rental Inventory
$items_query = "SELECT * FROM inventory WHERE category = 'Rental Items'";
$items_result = @$conn->query($items_query);

// Fetch Customers for Datalist
$cust_result = @$conn->query("SELECT * FROM customers ORDER BY full_name ASC");

// Read selected customer from session (set by select_customer.php)
$sel = $_SESSION['selected_customer'] ?? null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MCATS | POS</title>
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
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--luxury-body-bg); overflow-x: hidden; }
        .product-card {
            background: var(--luxury-card-bg);
            border: 1px solid var(--luxury-card-border);
            border-radius: var(--radius-lg);
            transition: all 0.28s cubic-bezier(0.16, 1, 0.3, 1);
            cursor: pointer;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }
        .product-card:hover:not(.unavailable) {
            transform: translateY(-4px);
            border-color: rgba(212, 175, 55, 0.5);
            box-shadow: var(--shadow-glow);
        }
        .product-card.unavailable {
            opacity: 0.55;
            filter: grayscale(80%);
            cursor: not-allowed;
        }
        .product-img-wrap {
            height: 145px;
            overflow: hidden;
            position: relative;
            background: rgba(0, 0, 0, 0.04);
        }
        .product-img {
            height: 100%;
            width: 100%;
            object-fit: cover;
            transition: transform 0.4s ease;
        }
        .product-card:hover:not(.unavailable) .product-img {
            transform: scale(1.06);
        }
        .cart-section {
            height: calc(100vh - 56px);
            overflow-y: auto;
            background: var(--luxury-card-bg);
            border-left: 1px solid var(--luxury-card-border);
            box-shadow: -8px 0 25px rgba(0, 0, 0, 0.04);
            position: sticky;
            top: 56px;
            z-index: 20;
        }
        .products-section {
            height: calc(100vh - 56px);
            overflow-y: auto;
        }
        .luxury-search {
            border-radius: var(--radius-full);
            background: var(--luxury-card-bg);
            border: 1px solid var(--luxury-card-border);
            padding: 0.75rem 1.25rem;
            transition: all 0.2s ease;
        }
        .luxury-search:focus-within {
            border-color: var(--gold-primary);
            box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.18);
        }
        .fly-item {
            position: absolute;
            z-index: 1050;
            transition: all 0.6s cubic-bezier(0.25, 0.8, 0.25, 1);
            pointer-events: none;
            border-radius: 50%;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.35);
        }
        .cart-item-row {
            background: var(--luxury-card-bg);
            border: 1px solid var(--luxury-card-border);
            border-radius: var(--radius-md);
            transition: all 0.2s ease;
        }
        .cart-item-row:hover {
            border-color: rgba(212, 175, 55, 0.35);
        }
        @media (max-width: 991.98px) {
            .products-section, .cart-section { height: auto; position: static; overflow-y: visible; }
            .cart-section { border-left: none; border-top: 1px solid var(--luxury-card-border); padding-bottom: 90px; }
        }
    </style>
</head>
<body>

    <datalist id="customerList">
        <?php if($cust_result && $cust_result->num_rows > 0): ?>
            <?php while($cust = $cust_result->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($cust['nic_number']); ?>"><?php echo htmlspecialchars($cust['full_name'] . ' (' . $cust['phone_number'] . ')'); ?></option>
                <option value="<?php echo htmlspecialchars($cust['phone_number']); ?>"><?php echo htmlspecialchars($cust['full_name'] . ' (NIC: ' . $cust['nic_number'] . ')'); ?></option>
            <?php endwhile; ?>
        <?php endif; ?>
    </datalist>

    <!-- Global Navigation Bar -->
    <?php include_once __DIR__ . '/../includes/navbar.php'; ?>

    <div class="container-fluid p-0">
        <div class="row g-0">
            <!-- Left Side: Rental Fleet -->
            <div class="col-lg-7 col-xl-8 products-section p-3 p-md-4">
                <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary-subtle flex-wrap gap-2">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge text-uppercase" style="background: rgba(212, 175, 55, 0.15); color: var(--gold-primary); font-size: 0.72rem; letter-spacing: 0.1em; font-weight: 700;">Rental Fleet</span>
                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 small px-2 py-0.5">Session #<?php echo $session_id; ?> Active</span>
                        </div>
                        <h2 class="mb-0 fw-bold d-flex align-items-center gap-2" style="font-family: 'Outfit', sans-serif;">
                            <i class="fa-solid fa-cart-flatbed" style="color: var(--gold-primary);"></i>
                            Rentals POS
                        </h2>
                        <div id="realtimeClock" class="text-secondary small mt-1"></div>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <!-- Item Return Desk Quick Access -->
                        <a href="return_items.php" class="btn btn-outline-info rounded-pill btn-sm shadow-sm px-3 fw-semibold d-inline-flex align-items-center gap-1">
                            <i class="fa-solid fa-rotate-left"></i>
                            <span>Return Desk</span>
                        </a>
                        <!-- Pay Later Quick Access -->
                        <a href="pay_later.php" class="btn btn-outline-warning rounded-pill btn-sm shadow-sm px-3 fw-semibold d-inline-flex align-items-center gap-1">
                            <i class="fa-solid fa-hand-holding-dollar"></i>
                            <span>Pay Later</span>
                        </a>
                        <button id="themeToggle" class="btn btn-outline-secondary rounded-pill btn-sm d-none d-md-flex align-items-center gap-1 shadow-sm px-3">
                            <i class="fa-solid fa-moon"></i> <span>Dark Mode</span>
                        </button>
                        <a href="../admin/home.php" class="btn btn-outline-secondary rounded-pill btn-sm shadow-sm px-3 fw-semibold">
                            <i class="fa-solid fa-arrow-left me-1"></i> Hub
                        </a>
                    </div>
                </div>

                <!-- Theme Toggle Mobile -->
                <div class="d-md-none mb-3">
                    <button id="themeToggleMobile" class="btn btn-outline-secondary rounded-pill btn-sm w-100">
                        <i class="fa-solid fa-moon me-1"></i> Toggle Dark/Light Mode
                    </button>
                </div>

                <!-- Search Input -->
                <div class="luxury-search d-flex align-items-center mb-4 shadow-sm">
                    <i class="fa-solid fa-magnifying-glass text-secondary me-3 fs-5"></i>
                    <input type="text" id="itemSearch" class="form-control border-0 p-0 shadow-none bg-transparent fs-6" placeholder="Search rental gear by item name, model, or code..." onkeyup="filterItems()">
                </div>

                <?php if($msg) echo $msg; ?>

                <!-- Rental Gear Grid -->
                <div class="row g-3 mt-2" id="productGrid">
                    <?php 
                    if($items_result && $items_result->num_rows > 0) {
                        while($item = $items_result->fetch_assoc()) {
                            $id = $item['item_id'] ?? rand(100,999);
                            $name = $item['item_name'] ?? 'Rental Item';
                            $price = $item['price_per_unit'] ?? 0;
                            $status = $item['status'] ?? 'available';
                            $img = !empty($item['item_image']) ? '../../img/' . $item['item_image'] : '../../img/placeholder.jpg';
                            $is_avail = ($status == 'available' || $status == '');
                            $class = $is_avail ? "" : "unavailable";
                            ?>
                            <div class="col-6 col-sm-4 col-md-4 col-xl-3 product-wrapper">
                                <div class="card h-100 product-card <?php echo $class; ?>" data-id="<?php echo $id; ?>" data-name="<?php echo htmlspecialchars($name); ?>" data-price="<?php echo $price; ?>">
                                    <div class="product-img-wrap">
                                        <div class="position-absolute top-0 end-0 p-2 z-1">
                                            <span class="badge rounded-pill <?php echo $is_avail ? 'bg-success' : 'bg-danger'; ?> shadow-sm" style="font-size: 0.65rem;">
                                                <?php echo $is_avail ? 'Available' : 'Rented Out'; ?>
                                            </span>
                                        </div>
                                        <img src="<?php echo htmlspecialchars($img); ?>" loading="lazy" alt="Rental" class="product-img" onerror="this.onerror=null; this.src='../../img/placeholder.jpg';">
                                    </div>
                                    <div class="card-body p-2 d-flex flex-column justify-content-between text-center">
                                        <div>
                                            <h6 class="card-title fw-bold mb-1 text-truncate" title="<?php echo htmlspecialchars($name); ?>" style="font-size: 0.86rem;"><?php echo htmlspecialchars($name); ?></h6>
                                            <div class="fw-bold text-nowrap mb-2" style="color: var(--gold-primary); font-size: 0.95rem;">
                                                Rs. <?php echo number_format($price, 2); ?>
                                            </div>
                                        </div>
                                        <?php if($is_avail): ?>
                                            <button class="btn btn-luxury-gold btn-sm w-100 fw-bold rounded-pill" onclick="addToCart(this)">
                                                <i class="fa-solid fa-plus me-1"></i> Add to POS
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-secondary btn-sm w-100 fw-bold disabled border-0 rounded-pill opacity-50">On Lease</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php
                        }
                    } else {
                        echo '<div class="col-12"><div class="p-5 text-center rounded-4 border border-secondary-subtle" style="background: var(--luxury-card-bg);"><i class="fa-solid fa-camera-retro fs-1 d-block mb-3" style="color: var(--gold-primary); opacity: 0.6;"></i><h5 class="fw-bold">No Rental Gear Registered</h5><p class="text-secondary small mb-0">Add rental equipment in Inventory with category "Rental Items".</p></div></div>';
                    }
                    ?>
                </div>
            </div>

            <!-- Right Side: Forms & Rental Register -->
            <div class="col-lg-5 col-xl-4 cart-section p-3 p-md-4 d-flex flex-column">
                <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom border-secondary-subtle">
                    <div>
                        <span class="badge text-uppercase" style="background: rgba(212, 175, 55, 0.15); color: var(--gold-primary); font-size: 0.68rem; letter-spacing: 0.1em; font-weight: 700;">Rental Docket</span>
                        <h4 class="mb-0 fw-bold" style="font-family: 'Outfit', sans-serif;">Selected Gear</h4>
                    </div>
                    <div class="position-relative d-inline-flex p-2 rounded-3" style="background: rgba(212, 175, 55, 0.1);">
                        <i id="cartIcon" class="fa-solid fa-cart-flatbed fs-5" style="color: var(--gold-primary);"></i>
                        <span id="cartCount" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.65rem; display:none;">0</span>
                    </div>
                </div>
                
                <!-- Selected Items Box -->
                <div class="overflow-auto rounded-4 p-2 mb-3" id="cartItemsContainer" style="background: rgba(0, 0, 0, 0.03); border: 1px dashed var(--luxury-card-border); min-height: 130px; max-height: 180px;">
                    <div class="text-center text-secondary py-4">
                        <i class="fa-regular fa-clipboard fs-3 d-block mb-1 opacity-25"></i>
                        <span class="small fw-semibold">No equipment added yet</span>
                    </div>
                </div>

                <!-- Total Valuation -->
                <div class="p-3 rounded-4 mb-3" style="background: rgba(212, 175, 55, 0.06); border: 1px solid rgba(212, 175, 55, 0.2);">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-secondary text-uppercase small" style="letter-spacing: 0.05em;">Equipment Daily Value</span>
                        <span class="fs-5 fw-bold" id="cartTotal" style="color: var(--gold-primary); font-family: 'Outfit', sans-serif;">Rs. 0.00</span>
                    </div>
                </div>

                <!-- Customer Details & Rental Payment Form -->
                <form method="POST" id="rentForm" class="mt-auto">
                    <!-- Customer Information Card -->
                    <div class="card border-0 rounded-4 mb-3 p-3" style="background: var(--luxury-card-bg); border: 1px solid var(--luxury-card-border) !important;">
                        <div class="d-flex justify-content-between align-items-center border-bottom border-secondary-subtle pb-2 mb-3">
                            <h6 class="fw-bold mb-0 d-flex align-items-center gap-2">
                                <i class="fa-regular fa-id-card" style="color: var(--gold-primary);"></i>
                                Customer Dossier
                            </h6>
                            <a href="select_customer.php" class="btn btn-luxury-gold btn-sm rounded-pill px-3 fw-bold" style="font-size: 0.76rem;">
                                <i class="fa-solid fa-users me-1"></i> Search Directory
                            </a>
                        </div>
                        
                        <?php if($sel): ?>
                        <div class="p-2 mb-3 rounded-3 d-flex align-items-center gap-2" style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3);">
                            <i class="fa-solid fa-circle-check text-success"></i>
                            <span class="small fw-bold text-success">Loaded: <?php echo htmlspecialchars($sel['full_name']); ?></span>
                        </div>
                        <?php endif; ?>

                        <div class="row g-2 mb-2">
                            <div class="col-12">
                                <input type="text" name="customer_name" class="form-control form-control-sm" placeholder="Full Client Name *" required value="<?php echo htmlspecialchars($sel['full_name'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <input type="text" name="nic" class="form-control form-control-sm" placeholder="NIC / Passport" value="<?php echo htmlspecialchars($sel['nic_number'] ?? ''); ?>">
                            </div>
                            <div class="col-6">
                                <input type="text" name="phone" class="form-control form-control-sm" placeholder="Phone Number *" required value="<?php echo htmlspecialchars($sel['phone_number'] ?? ''); ?>">
                            </div>
                        </div>
                        <input type="text" name="address" class="form-control form-control-sm mb-2" placeholder="Residential / Business Address" value="<?php echo htmlspecialchars($sel['address'] ?? ''); ?>">
                        
                        <div class="form-check form-switch mt-1">
                            <input class="form-check-input" type="checkbox" name="free_eq" value="yes" id="freeEq" onchange="document.getElementById('freeEqDesc').style.display = this.checked ? 'block' : 'none';">
                            <label class="form-check-label small fw-semibold" for="freeEq">Include Free Auxiliary Accessories</label>
                        </div>
                        <input type="text" name="free_eq_desc" id="freeEqDesc" class="form-control form-control-sm mt-2 border-secondary-subtle" placeholder="Cables, filters, straps given complimentary..." style="display:none;">
                    </div>

                    <!-- Payment & Advance Card -->
                    <div class="card border-0 rounded-4 mb-3 p-3" style="background: var(--luxury-card-bg); border: 1px solid var(--luxury-card-border) !important;">
                        <h6 class="fw-bold mb-3 border-bottom border-secondary-subtle pb-2 d-flex align-items-center gap-2">
                            <i class="fa-solid fa-money-bill-wave" style="color: var(--gold-primary);"></i>
                            Deposit & Advance Terms
                        </h6>
                        
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label fw-bold small text-secondary mb-1">Advance (LKR)</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-transparent border-secondary-subtle fw-bold" style="color: var(--gold-primary);">Rs.</span>
                                    <input type="number" name="advance" id="advancePayment" class="form-control fw-bold" min="0" placeholder="0.00" value="0.00" oninput="calculateRentBalance()">
                                </div>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-bold small text-secondary mb-1">Cash Received</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-transparent border-secondary-subtle fw-bold" style="color: var(--gold-primary);">Rs.</span>
                                    <input type="number" name="received_amount" id="rentReceived" class="form-control fw-bold" min="0" placeholder="0.00" oninput="calculateRentBalance()">
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center mt-2 p-2 rounded-3" style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25);">
                            <span class="small fw-bold text-success">Advance Change:</span>
                            <span id="rentBalance" class="fw-bold fs-6 text-success">Rs. 0.00</span>
                        </div>
                    </div>

                    <input type="hidden" name="cart_data" id="cartDataInput">
                    <button type="submit" name="rent_out" class="btn btn-luxury-gold btn-lg w-100 fw-bold shadow rounded-pill py-3 d-flex align-items-center justify-content-center gap-2 mb-2" id="btnRent" disabled>
                        <i class="fa-solid fa-signature"></i> Authorize POS Agreement & Print
                    </button>
                    
                    <a href="return_items.php" class="btn btn-outline-secondary btn-sm w-100 fw-bold rounded-pill py-2 shadow-sm d-flex align-items-center justify-content-center gap-2">
                        <i class="fa-solid fa-rotate-left" style="color: var(--gold-primary);"></i> Equipment Returns Desk
                    </a>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Theme Toggle
        const toggleBtn = document.getElementById('themeToggle');
        const toggleBtnMobile = document.getElementById('themeToggleMobile');
        const html = document.documentElement;

        function updateThemeBtns(isDark) {
            const icon = isDark ? '<i class="fa-solid fa-sun text-warning"></i> <span>Light Mode</span>' : '<i class="fa-solid fa-moon"></i> <span>Dark Mode</span>';
            if (toggleBtn) toggleBtn.innerHTML = icon;
            if (toggleBtnMobile) toggleBtnMobile.innerHTML = icon;
        }

        if (localStorage.getItem('theme') === 'dark') {
            html.setAttribute('data-bs-theme', 'dark');
            updateThemeBtns(true);
        }

        function toggleTheme() {
            if (html.getAttribute('data-bs-theme') === 'dark') {
                html.setAttribute('data-bs-theme', 'light');
                localStorage.setItem('theme', 'light');
                updateThemeBtns(false);
            } else {
                html.setAttribute('data-bs-theme', 'dark');
                localStorage.setItem('theme', 'dark');
                updateThemeBtns(true);
            }
        }

        if (toggleBtn) toggleBtn.addEventListener('click', toggleTheme);
        if (toggleBtnMobile) toggleBtnMobile.addEventListener('click', toggleTheme);

        // Item Search Filter
        function filterItems() {
            const query = document.getElementById('itemSearch').value.toLowerCase();
            const wrappers = document.querySelectorAll('.product-wrapper');
            wrappers.forEach(wrapper => {
                const title = wrapper.querySelector('.card-title').innerText.toLowerCase();
                if (title.includes(query)) {
                    wrapper.style.display = 'block';
                } else {
                    wrapper.style.display = 'none';
                }
            });
        }

        let cart = {};

        function addToCart(btn) {
            const card = btn.closest('.product-card');
            const id = card.getAttribute('data-id');
            const name = card.getAttribute('data-name');
            const price = parseFloat(card.getAttribute('data-price'));
            const img = card.querySelector('.product-img');

            if (cart[id]) {
                cart[id].qty += 1;
            } else {
                cart[id] = { name: name, price: price, qty: 1, id: id };
            }
            updateCartUI();
            flyToCart(img);
        }

        function changeQty(id, change) {
            if (cart[id]) {
                cart[id].qty += change;
                if (cart[id].qty <= 0) {
                    delete cart[id];
                }
                updateCartUI();
            }
        }

        function updateCartUI() {
            const container = document.getElementById('cartItemsContainer');
            const totalEl = document.getElementById('cartTotal');
            const cartDataInput = document.getElementById('cartDataInput');
            const btnRent = document.getElementById('btnRent');
            const advanceInput = document.getElementById('advancePayment');
            const cartCountBadge = document.getElementById('cartCount');

            container.innerHTML = '';
            let total = 0;
            let totalItems = 0;
            let cartArray = [];

            const keys = Object.keys(cart);
            if(keys.length === 0) {
                container.innerHTML = '<div class="text-center text-secondary py-4"><i class="fa-regular fa-clipboard fs-3 d-block mb-1 opacity-25"></i><span class="small fw-semibold">No equipment added yet</span></div>';
                cartCountBadge.style.display = 'none';
            } else {
                cartCountBadge.style.display = 'inline-block';
            }

            for (const id in cart) {
                const item = cart[id];
                total += item.price * item.qty;
                totalItems += item.qty;
                cartArray.push(item);

                const itemDiv = document.createElement('div');
                itemDiv.className = 'cart-item-row d-flex justify-content-between align-items-center p-2 mb-2 shadow-sm';
                itemDiv.innerHTML = `
                    <div class="flex-grow-1 overflow-hidden me-2">
                        <div class="fw-bold text-truncate small">${item.name}</div>
                        <div class="text-secondary" style="font-size: 0.75rem;">Rs. ${item.price.toFixed(2)}</div>
                    </div>
                    <div class="d-flex align-items-center gap-1">
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 fw-bold rounded" style="font-size: 0.75rem;" onclick="changeQty('${id}', -1)">-</button>
                        <span class="fw-bold mx-1 text-center" style="min-width: 18px; font-size: 0.8rem;">${item.qty}</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 fw-bold rounded" style="font-size: 0.75rem;" onclick="changeQty('${id}', 1)">+</button>
                    </div>
                `;
                container.appendChild(itemDiv);
            }
            
            cartCountBadge.innerText = totalItems;
            totalEl.innerText = 'Rs. ' + total.toFixed(2);
            cartDataInput.value = JSON.stringify(cartArray);
            btnRent.disabled = cartArray.length === 0;
            
            if(cartArray.length > 0 && (advanceInput.value === '' || advanceInput.value === '0.00')) {
                advanceInput.value = total; 
            }
            calculateRentBalance();
        }

        function flyToCart(imgElement) {
            const cartIcon = document.getElementById('cartIcon');
            if (!imgElement || !cartIcon) return;
            
            const clone = imgElement.cloneNode(true);
            const rect = imgElement.getBoundingClientRect();
            
            clone.className = 'fly-item';
            clone.style.width = '80px';
            clone.style.height = '80px';
            clone.style.left = rect.left + 'px';
            clone.style.top = rect.top + 'px';
            
            document.body.appendChild(clone);
            const cartRect = cartIcon.getBoundingClientRect();
            
            setTimeout(() => {
                clone.style.transform = `translate(${cartRect.left - rect.left}px, ${cartRect.top - rect.top}px) scale(0.15)`;
                clone.style.opacity = '0';
            }, 10);
            
            setTimeout(() => {
                clone.remove();
                cartIcon.classList.add('fa-bounce');
                setTimeout(() => {
                    cartIcon.classList.remove('fa-bounce');
                }, 700);
            }, 550);
        }

        function calculateRentBalance() {
            const due = parseFloat(document.getElementById('advancePayment').value) || 0;
            const received = parseFloat(document.getElementById('rentReceived').value) || 0;
            const balance = received - due;
            
            const balEl = document.getElementById('rentBalance');
            if (received > 0 && balance >= 0) {
                balEl.innerText = 'Rs. ' + balance.toFixed(2);
                balEl.className = 'fw-bold fs-6 text-success';
            } else if (received > 0 && balance < 0) {
                balEl.innerText = '(Short: Rs. ' + Math.abs(balance).toFixed(2) + ')';
                balEl.className = 'fw-bold fs-6 text-danger';
            } else {
                balEl.innerText = 'Rs. 0.00';
                balEl.className = 'fw-bold fs-6 text-success';
            }
        }

        function updateClock() {
            const now = new Date();
            const dateStr = now.toLocaleDateString('en-US', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
            const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
            const clockEl = document.getElementById('realtimeClock');
            if (clockEl) clockEl.innerHTML = '<i class="fa-regular fa-clock me-1"></i> ' + dateStr + ' &bull; ' + timeStr;
        }
        setInterval(updateClock, 1000);
        updateClock();
    </script>
</body>
</html>
