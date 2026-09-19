
<?php
session_start();
include_once __DIR__ . '/../../db.php'; // Database connection

// SECURITY: Only allow Super Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../../index.php");
    exit();
}

$error_message = "";
$success_message = "";

// Handle Delete GET Request
if (isset($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);
    // Prevent self-deletion
    if ($delete_id != $_SESSION['user_id']) {
        $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $delete_id);
        if ($stmt->execute()) {
            header("Location: sahome.php?msg=deleted");
            exit();
        } else {
            $error_message = "Error deleting user.";
        }
    } else {
        $error_message = "You cannot delete your own account.";
    }
}

// Handle Add User POST Request
if (isset($_POST['add_user'])) {
    $uname = $_POST['username'];
    $pass = $_POST['password'];
    $role = $_POST['role'];

    // Check if username exists
    $check = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
    $check->bind_param("s", $uname);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $error_message = "Username already exists!";
    } else {
        $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $uname, $pass, $role);
        if ($stmt->execute()) {
            header("Location: sahome.php?msg=added");
            exit();
        } else {
            $error_message = "Error creating user.";
        }
    }
}

// Handle Edit User POST Request
if (isset($_POST['edit_user'])) {
    $edit_id = intval($_POST['user_id']);
    $pass = $_POST['password'];
    $role = $_POST['role'];

    // Only update if password is provided, otherwise just update role
    if (!empty($pass)) {
        $stmt = $conn->prepare("UPDATE users SET password = ?, role = ? WHERE user_id = ?");
        $stmt->bind_param("ssi", $pass, $role, $edit_id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET role = ? WHERE user_id = ?");
        $stmt->bind_param("si", $role, $edit_id);
    }
    
    if ($stmt->execute()) {
        header("Location: sahome.php?msg=edited");
        exit();
    } else {
        $error_message = "Error updating user.";
    }
}

// Fetch user data if editing
$edit_data = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows == 1) {
        $edit_data = $result->fetch_assoc();
    } else {
        header("Location: sahome.php");
        exit();
    }
}

$is_adding = isset($_GET['action']) && $_GET['action'] == 'add';
if (!$is_adding && !$edit_data && !isset($_GET['delete'])) {
    // If no valid parameter is passed, redirect to home
    header("Location: sahome.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MCATS | <?php echo $is_adding ? "Create User" : "Edit User"; ?></title>
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
                        <a href="requests.php" class="luxury-nav-link"><i class="fa-solid fa-shield-halved"></i>Requests Hub</a>
                        <a href="inventory_stats.php" class="luxury-nav-link"><i class="fa-solid fa-chart-pie"></i>Analytics</a>
                        <a href="reports.php" class="luxury-nav-link"><i class="fa-solid fa-file-invoice-dollar"></i>Reports</a>
                        <a href="manage_user.php?action=add" class="luxury-nav-link active"><i class="fa-solid fa-users-gear"></i>User Accounts</a>
                        <a href="../../logout.php" class="luxury-nav-link logout"><i class="fa-solid fa-right-from-bracket"></i>Logout</a>
                    </div>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-2 pb-3 mb-4 border-bottom position-relative" style="border-color: var(--border-subtle) !important;">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="luxury-badge luxury-badge-gold"><i class="fa-solid fa-crown"></i> Security & Access</span>
                        </div>
                        <h1 class="h2 mb-0"><?php echo $is_adding ? "Create Admin Account" : "Modify Credentials"; ?></h1>
                        <div id="realtimeClock" class="text-muted small mt-1"></div>
                    </div>
                    <div class="d-flex align-items-center gap-3 mt-3 mt-md-0">
                        <a href="sahome.php" class="btn btn-luxury-outline">
                            <i class="fa-solid fa-arrow-left me-1"></i> Back to Dashboard
                        </a>
                        <button id="themeToggle" class="theme-toggle-btn"><i class="fa-solid fa-moon me-1"></i> Dark Mode</button>
                    </div>
                </div>

                <?php if($error_message): ?>
                    <div class="alert alert-danger py-3 px-4 mb-4 rounded-3 d-flex align-items-center gap-3 border-0" style="background: rgba(239, 68, 68, 0.12); color: #ef4444;" role="alert">
                        <i class="fa-solid fa-triangle-exclamation fs-4"></i>
                        <span class="fw-semibold"><?php echo $error_message; ?></span>
                    </div>
                <?php endif; ?>

                <div class="row justify-content-center mt-4">
                    <div class="col-md-8 col-lg-6">
                        <div class="luxury-card p-4 p-md-5">
                            <div class="text-center mb-4">
                                <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 58px; height: 58px; background: var(--gold-subtle-bg); color: var(--gold-primary);">
                                    <i class="fa-solid <?php echo $is_adding ? 'fa-user-plus' : 'fa-user-gear'; ?> fs-3"></i>
                                </div>
                                <h4 class="fw-bold mb-1"><?php echo $is_adding ? "New Account Setup" : "Account Configuration"; ?></h4>
                                <p class="text-muted small">Specify username, credentials, and privilege role</p>
                            </div>

                            <form method="POST">
                                <?php if($is_adding): ?>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold small text-muted">Username</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-transparent" style="border-color: var(--border-subtle); color: var(--gold-primary);"><i class="fa-solid fa-user"></i></span>
                                            <input type="text" class="form-control luxury-input border-start-0" name="username" required placeholder="Enter username" autocomplete="off">
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <input type="hidden" name="user_id" value="<?php echo $edit_data['user_id']; ?>">
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold small text-muted">Username <span class="fw-normal text-muted">(Locked)</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-transparent" style="border-color: var(--border-subtle); color: var(--text-muted);"><i class="fa-solid fa-user-lock"></i></span>
                                            <input type="text" class="form-control luxury-input border-start-0" value="<?php echo htmlspecialchars($edit_data['username']); ?>" readonly style="opacity: 0.7;">
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-semibold small text-muted">
                                        Password <?php echo !$is_adding ? "<span class='fw-normal'>(Leave empty to preserve existing)</span>" : ""; ?>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-transparent" style="border-color: var(--border-subtle); color: var(--gold-primary);"><i class="fa-solid fa-lock"></i></span>
                                        <input type="password" class="form-control luxury-input border-start-0" name="password" <?php echo $is_adding ? "required" : ""; ?> placeholder="<?php echo $is_adding ? 'Create strong password' : 'New password (optional)'; ?>" autocomplete="new-password">
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label fw-semibold small text-muted">Privilege Level</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-transparent" style="border-color: var(--border-subtle); color: var(--gold-primary);"><i class="fa-solid fa-shield-halved"></i></span>
                                        <select class="form-select luxury-input border-start-0" name="role" required>
                                            <option value="admin" <?php echo (isset($edit_data) && $edit_data['role'] == 'admin') ? 'selected' : ''; ?>>Admin (POS & Inventory)</option>
                                            <option value="super_admin" <?php echo (isset($edit_data) && $edit_data['role'] == 'super_admin') ? 'selected' : ''; ?>>Super Admin (Full System & User Control)</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="d-grid mt-4">
                                    <?php if($is_adding): ?>
                                        <button type="submit" name="add_user" class="btn btn-luxury-gold py-3 fw-bold">
                                            <i class="fa-solid fa-check me-2"></i> Register Account
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" name="edit_user" class="btn btn-luxury-gold py-3 fw-bold">
                                            <i class="fa-solid fa-floppy-disk me-2"></i> Save Account Updates
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
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
