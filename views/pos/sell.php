<?php
session_start();
include_once __DIR__ . '/../../db.php';

// Security: Allow admin or super_admin
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$error_msg = "";
$success_msg = "";

// Check for active session
$active_session_query = $conn->query("SELECT id FROM cash_sessions WHERE status='open' AND DATE(opened_at) = CURDATE() ORDER BY id DESC LIMIT 1");
$active_session = $active_session_query->fetch_assoc();
if (!$active_session) {
    echo "<script>alert('No active day session! Please start a session from the Admin Dashboard first.'); window.location.href='../admin/home.php';</script>";
    exit();
}
$session_id = $active_session['id'];

// Handle Checkout
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['checkout'])) {
    $cart_data = json_decode($_POST['cart_data'], true);
    
    if (empty($cart_data)) {
        $error_msg = "Cart is empty!";
    } else {
        // Calculate total
        $total_lkr = 0;
        foreach ($cart_data as $item) {
            $total_lkr += ($item['price'] * $item['qty']);
        }
        
        $received_amount = isset($_POST['received_amount']) ? floatval($_POST['received_amount']) : $total_lkr;
        $balance_amount = $received_amount - $total_lkr;
        $free_eq_text = (isset($_POST['free_eq']) && $_POST['free_eq'] === 'yes') ? (trim($_POST['free_eq_desc']) ?: 'Included Free Equipment') : null;

        $conn->begin_transaction();
        try {
            // Insert Transaction (walk-in, no customer details)
            $stmt = $conn->prepare("INSERT INTO transactions (session_id, type, total_lkr, received_amount, balance_amount, free_equipment, status) VALUES (?, 'selling', ?, ?, ?, ?, 'completed')");
            $stmt->bind_param("iddds", $session_id, $total_lkr, $received_amount, $balance_amount, $free_eq_text);
            $stmt->execute();
            $bill_number = $stmt->insert_id;
            
            // Insert Items
            $item_stmt = $conn->prepare("INSERT INTO transaction_items (bill_number, item_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
            
            foreach ($cart_data as $item) {
                $item_id = $item['id'];
                $qty = $item['qty'];
                $price = $item['price'];
                
                $item_stmt->bind_param("iiid", $bill_number, $item_id, $qty, $price);
                $item_stmt->execute();
            }
            
            $conn->commit();
            // Redirect to print instead of staying and blinking
            echo "<script>window.location.href = 'print_bill.php?type=thermal&bill_id=$bill_number';</script>";
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Error processing checkout: " . $e->getMessage();
        }
    }
}

// Fetch Saleable Inventory
$items_query = "SELECT * FROM inventory WHERE category != 'Rental Items'";
$items_result = @$conn->query($items_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MCATS | Selling</title>
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
        .product-card:hover {
            transform: translateY(-4px);
            border-color: rgba(212, 175, 55, 0.5);
            box-shadow: var(--shadow-glow);
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
        .product-card:hover .product-img {
            transform: scale(1.06);
        }
        .cart-section {
            height: 100vh;
            overflow-y: auto;
            background: var(--luxury-card-bg);
            border-left: 1px solid var(--luxury-card-border);
            box-shadow: -8px 0 25px rgba(0, 0, 0, 0.04);
            position: sticky;
            top: 0;
            z-index: 20;
        }
        .products-section {
            height: 100vh;
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

    <div class="container-fluid p-0">
        <div class="row g-0">
            <!-- Left Side: Catalog Products -->
            <div class="col-lg-8 products-section p-3 p-md-4">
                <!-- Header -->
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary-subtle gap-3">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge text-uppercase" style="background: rgba(212, 175, 55, 0.15); color: var(--gold-primary); font-size: 0.72rem; letter-spacing: 0.1em; font-weight: 700;">Retail Channel</span>
                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 small px-2 py-0.5">Session #<?php echo $session_id; ?> Active</span>
                        </div>
                        <h2 class="mb-0 fw-bold d-flex align-items-center gap-2" style="font-family: 'Outfit', sans-serif;">
                            <i class="fa-solid fa-bag-shopping" style="color: var(--gold-primary);"></i>
                            Selling
                        </h2>
                        <div id="realtimeClock" class="text-secondary small mt-1"></div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
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

                <!-- Search Bar -->
                <div class="luxury-search d-flex align-items-center mb-4 shadow-sm">
                    <i class="fa-solid fa-magnifying-glass text-secondary me-3 fs-5"></i>
                    <input type="text" id="itemSearch" class="form-control border-0 p-0 shadow-none bg-transparent fs-6" placeholder="Search catalog by item name, SKU, or keyword..." onkeyup="filterItems()">
                </div>

                <?php if($success_msg): ?>
                    <div class="alert alert-success border-0 shadow-sm rounded-4 d-flex align-items-center gap-2"><i class="fa-solid fa-check-circle"></i><?php echo $success_msg; ?></div>
                <?php endif; ?>
                <?php if($error_msg): ?>
                    <div class="alert alert-danger border-0 shadow-sm rounded-4 d-flex align-items-center gap-2"><i class="fa-solid fa-triangle-exclamation"></i><?php echo $error_msg; ?></div>
                <?php endif; ?>

                <!-- Product Catalog Grid -->
                <div class="row g-3" id="productGrid">
                    <?php 
                    if($items_result && $items_result->num_rows > 0) {
                        while($item = $items_result->fetch_assoc()) {
                            $id = $item['item_id'] ?? rand(100,999);
                            $name = $item['item_name'] ?? 'Unknown Item';
                            $price = $item['price_per_unit'] ?? 0;
                            $stock = $item['quantity'] ?? 0;
                            $img = !empty($item['item_image']) ? '../../img/' . $item['item_image'] : '../../img/placeholder.jpg';
                            ?>
                            <div class="col-6 col-sm-4 col-md-3 col-xl-2">
                                <div class="card h-100 product-card" data-id="<?php echo $id; ?>" data-name="<?php echo htmlspecialchars($name); ?>" data-price="<?php echo $price; ?>">
                                    <div class="product-img-wrap">
                                        <img src="<?php echo htmlspecialchars($img); ?>" loading="lazy" alt="Product" class="product-img">
                                        <?php if($stock <= 3): ?>
                                            <span class="position-absolute top-0 start-0 m-2 badge bg-danger" style="font-size: 0.65rem;">Stock: <?php echo $stock; ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="card-body p-2 d-flex flex-column justify-content-between text-center">
                                        <div>
                                            <h6 class="card-title fw-bold mb-1 text-truncate" title="<?php echo htmlspecialchars($name); ?>" style="font-size: 0.86rem;"><?php echo htmlspecialchars($name); ?></h6>
                                            <div class="fw-bold text-nowrap mb-2" style="color: var(--gold-primary); font-size: 0.92rem;">
                                                Rs. <?php echo number_format($price, 2); ?>
                                            </div>
                                        </div>
                                        <button class="btn btn-luxury-gold btn-sm w-100 fw-bold rounded-pill" onclick="addToCart(this)">
                                            <i class="fa-solid fa-plus me-1"></i> Add
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php
                        }
                    } else {
                        echo '<div class="col-12"><div class="p-5 text-center rounded-4 border border-secondary-subtle" style="background: var(--luxury-card-bg);"><i class="fa-solid fa-box-open fs-1 d-block mb-3" style="color: var(--gold-primary); opacity: 0.6;"></i><h5 class="fw-bold">No Retail Items Found</h5><p class="text-secondary small mb-0">Register merchandise in Inventory to populate the selling catalog.</p></div></div>';
                    }
                    ?>
                </div>
            </div>

            <!-- Right Side: Executive Cart Register -->
            <div class="col-lg-4 cart-section p-3 p-md-4 d-flex flex-column">
                <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom border-secondary-subtle">
                    <div>
                        <span class="badge text-uppercase" style="background: rgba(212, 175, 55, 0.15); color: var(--gold-primary); font-size: 0.68rem; letter-spacing: 0.1em; font-weight: 700;">Checkout Register</span>
                        <h4 class="mb-0 fw-bold" style="font-family: 'Outfit', sans-serif;">Active Order</h4>
                    </div>
                    <div class="position-relative d-inline-flex p-2 rounded-3" style="background: rgba(212, 175, 55, 0.1);">
                        <i id="cartIcon" class="fa-solid fa-basket-shopping fs-5" style="color: var(--gold-primary);"></i>
                        <span id="cartCount" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.65rem; display:none;">0</span>
                    </div>
                </div>
                
                <!-- Cart Scrollable List -->
                <div class="flex-grow-1 overflow-auto rounded-4 p-2 mb-3" style="background: rgba(0, 0, 0, 0.03); border: 1px dashed var(--luxury-card-border); max-height: calc(100vh - 440px);" id="cartItemsContainer">
                    <div class="text-center text-secondary py-5">
                        <i class="fa-solid fa-basket-shopping fs-1 d-block mb-2 opacity-25"></i>
                        <span class="small fw-semibold">Order basket is empty</span>
                    </div>
                </div>

                <!-- Live Subtotal -->
                <div class="p-3 rounded-4 mb-3" style="background: rgba(212, 175, 55, 0.06); border: 1px solid rgba(212, 175, 55, 0.2);">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-secondary text-uppercase small" style="letter-spacing: 0.05em;">Total Amount</span>
                        <span class="fs-4 fw-bold" id="cartTotal" style="color: var(--gold-primary); font-family: 'Outfit', sans-serif;">Rs. 0.00</span>
                    </div>
                </div>

                <!-- Payment Form -->
                <form method="POST" id="checkoutForm" class="mt-auto">
                    <div class="card border-0 rounded-4 mb-3 p-3" style="background: var(--luxury-card-bg); border: 1px solid var(--luxury-card-border) !important;">
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-secondary text-uppercase mb-1" style="letter-spacing: 0.05em;">Cash Tendered (LKR)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-transparent border-secondary-subtle fw-bold" style="color: var(--gold-primary);">Rs.</span>
                                <input type="number" id="cashReceived" name="received_amount" min="0" step="0.01" class="form-control border-secondary-subtle fw-bold fs-5" placeholder="0.00" oninput="calculateBalance()">
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center p-2 rounded-3 mb-2" style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25);">
                            <span class="small fw-bold text-success">Change / Balance:</span>
                            <span id="cashBalance" class="fw-bold fs-6 text-success">Rs. 0.00</span>
                        </div>

                        <div class="form-check form-switch pt-2 border-top border-secondary-subtle mt-1">
                            <input class="form-check-input" type="checkbox" name="free_eq" value="yes" id="freeEq" onchange="document.getElementById('freeEqDesc').style.display = this.checked ? 'block' : 'none';">
                            <label class="form-check-label small fw-semibold" for="freeEq">Include Free Gift / Equipment</label>
                        </div>
                        <input type="text" name="free_eq_desc" id="freeEqDesc" class="form-control form-control-sm mt-2 border-secondary-subtle" placeholder="Description of complimentary item..." style="display:none;">
                    </div>

                    <input type="hidden" name="cart_data" id="cartDataInput">
                    <button type="submit" name="checkout" class="btn btn-luxury-gold btn-lg w-100 fw-bold shadow rounded-pill py-3 d-flex align-items-center justify-content-center gap-2" id="checkoutBtn" disabled>
                        <i class="fa-solid fa-check-double"></i> Complete Sale & Print
                    </button>
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
            const cards = document.querySelectorAll('.product-card');
            cards.forEach(card => {
                const title = card.getAttribute('data-name').toLowerCase();
                const wrapper = card.parentElement;
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
            const checkoutBtn = document.getElementById('checkoutBtn');
            const cartCountBadge = document.getElementById('cartCount');

            container.innerHTML = '';
            let total = 0;
            let totalItems = 0;
            let cartArray = [];

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
                        <div class="text-secondary small">Rs. ${item.price.toFixed(2)} × ${item.qty}</div>
                    </div>
                    <div class="d-flex align-items-center gap-1">
                        <button class="btn btn-sm btn-outline-secondary py-0 px-2 fw-bold rounded" onclick="changeQty('${id}', -1)">-</button>
                        <span class="fw-bold mx-1 text-center" style="min-width: 22px;">${item.qty}</span>
                        <button class="btn btn-sm btn-outline-secondary py-0 px-2 fw-bold rounded" onclick="changeQty('${id}', 1)">+</button>
                    </div>
                `;
                container.appendChild(itemDiv);
            }

            if (cartArray.length === 0) {
                container.innerHTML = '<div class="text-center text-secondary py-5"><i class="fa-solid fa-basket-shopping fs-1 d-block mb-2 opacity-25"></i><span class="small fw-semibold">Order basket is empty</span></div>';
                cartCountBadge.style.display = 'none';
            } else {
                cartCountBadge.style.display = 'inline-block';
                cartCountBadge.innerText = totalItems;
            }

            totalEl.innerText = 'Rs. ' + total.toFixed(2);
            cartDataInput.value = JSON.stringify(cartArray);
            checkoutBtn.disabled = cartArray.length === 0;
            
            calculateBalance();
        }

        function calculateBalance() {
            const rawTotalStr = document.getElementById('cartTotal').innerText.replace('Rs. ', '');
            const total = parseFloat(rawTotalStr) || 0;
            const received = parseFloat(document.getElementById('cashReceived').value) || 0;
            const balance = received - total;
            
            const balEl = document.getElementById('cashBalance');
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

        function updateClock() {
            const now = new Date();
            const dateStr = now.toLocaleDateString('en-US', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
            const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
            const clockEl = document.getElementById('realtimeClock');
            if (clockEl) clockEl.innerText = dateStr + ' • ' + timeStr;
        }
        setInterval(updateClock, 1000);
        updateClock();
    </script>
</body>
</html>
