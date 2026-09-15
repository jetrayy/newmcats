<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

/* DATABASE CONNECTION */
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "mcats";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

/* IF USER ALREADY LOGGED IN */
if (isset($_SESSION['user_id'])) {
    // Direct them back to their specific home if they try to visit index.php while logged in
    if ($_SESSION['role'] == 'super_admin') {
        header("Location: views/admin/sahome.php");
    } else {
        header("Location: views/admin/home.php");
    }
    exit();
}

$error_message = "";

/* LOGIN PROCESS */
if (isset($_POST['submit'])) {
    $user_input = $_POST['username'];
    $pass_input = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM users WHERE username=?");
    $stmt->bind_param("s", $user_input);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();

        if ($pass_input == $user['password']) {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            // --- ROLE-BASED REDIRECT ---
            if ($user['role'] == 'super_admin') {
                header("Location: views/admin/sahome.php");
            } else {
                header("Location: views/admin/home.php");
            }
            exit();

        } else {
            $error_message = "Invalid Username or Password!";
        }
    } else {
        $error_message = "Invalid Username or Password!";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MCATS | Mahinda Constructions & ToolShop</title>
    <link rel="icon" type="image/x-icon" href="img/ico.ico">
    <!-- Instant theme apply — prevents flash -->
    <script>document.documentElement.setAttribute('data-bs-theme', localStorage.getItem('theme') || 'dark');</script>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Luxury Executive Design System -->
    <link rel="stylesheet" href="css/luxury.css">
    
    <style>
        body {
            margin: 0;
            overflow: hidden;
            height: 100vh;
            background-color: var(--obsidian-dark);
        }
        
        .left-panel {
            display: flex;
            justify-content: center;
            align-items: center;
            background: linear-gradient(180deg, #0b1120 0%, #070a12 100%);
            border-right: 1px solid rgba(255, 255, 255, 0.08);
            height: 100vh;
            overflow-y: auto;
            position: relative;
        }

        .left-panel::before {
            content: '';
            position: absolute;
            top: -20%;
            left: -20%;
            width: 70%;
            height: 70%;
            background: radial-gradient(circle, rgba(212, 175, 55, 0.08) 0%, transparent 70%);
            pointer-events: none;
        }

        .login-box {
            width: 100%;
            max-width: 420px;
            z-index: 2;
        }

        .logo-wrap {
            position: relative;
            display: inline-block;
        }

        .logo {
            width: 130px;
            filter: drop-shadow(0 10px 20px rgba(0, 0, 0, 0.5));
        }

        .brand-subtitle {
            font-size: 0.8rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--gold-primary);
            font-weight: 600;
        }

        .right-panel {
            background: linear-gradient(135deg, rgba(7, 10, 18, 0.85) 0%, rgba(11, 17, 32, 0.92) 100%), url('img/loginimg.jpg') center center/cover no-repeat;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 1rem;
            border-radius: 9999px;
            background: rgba(212, 175, 55, 0.12);
            border: 1px solid rgba(212, 175, 55, 0.3);
            color: var(--gold-primary);
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
        }
        
        @media (max-width: 768px) {
            body {
                overflow: auto;
            }
            .left-panel {
                min-height: 100vh;
                height: auto;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0 h-100">
            <!-- LEFT LOGIN AREA -->
            <div class="col-12 col-md-5 col-lg-4 left-panel p-4 p-md-5">
                <div class="login-box">
                    <div class="text-center mb-4">
                        <div class="logo-wrap mb-3">
                            <img src="img/logo.png" class="logo" alt="MCATS Logo">
                        </div>
                        <span class="brand-subtitle d-block mb-1">Mahinda Constructions & ToolShop</span>
                        <h2 class="text-white fw-bold mb-1">System Portal</h2>
                        <p class="text-muted small">Enter your credentials to access the terminal</p>
                    </div>

                    <?php if($error_message): ?>
                        <div class="alert alert-danger py-2 px-3 mb-4 rounded-3 d-flex align-items-center gap-2 border-0" style="background: rgba(239, 68, 68, 0.15); color: #fca5a5;" role="alert">
                            <i class="fa-solid fa-circle-exclamation"></i>
                            <span class="small fw-semibold"><?php echo $error_message; ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label text-white-50 small fw-semibold">Username</label>
                            <div class="input-group">
                                <span class="input-group-text bg-transparent border-end-0 text-muted" style="border-color: rgba(255,255,255,0.12);"><i class="fa-solid fa-user"></i></span>
                                <input type="text" class="form-control luxury-input border-start-0" name="username" placeholder="e.g. sa or admin" required style="background: rgba(255,255,255,0.03); color: #fff; border-color: rgba(255,255,255,0.12);">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label text-white-50 small fw-semibold">Password</label>
                            <div class="input-group">
                                <span class="input-group-text bg-transparent border-end-0 text-muted" style="border-color: rgba(255,255,255,0.12);"><i class="fa-solid fa-lock"></i></span>
                                <input type="password" class="form-control luxury-input border-start-0" name="password" placeholder="••••••••" required style="background: rgba(255,255,255,0.03); color: #fff; border-color: rgba(255,255,255,0.12);">
                            </div>
                        </div>

                        <button type="submit" name="submit" class="btn btn-luxury-gold btn-lg w-100 fw-bold">
                            Authenticate & Enter <i class="fa-solid fa-arrow-right ms-2"></i>
                        </button>
                    </form>

                    <div class="mt-5 text-center pt-3 border-top" style="border-color: rgba(255,255,255,0.08) !important;">
                        <span class="text-muted small d-block mb-1">
                            <i class="fa-solid fa-shield-halved me-1 text-warning"></i> Secure Terminal Access
                        </span>
                        <span class="text-white-50 small" style="font-size: 0.75rem;">140/2, Kandy Road, Rikillagaskada</span>
                    </div>
                </div>
            </div>

            <!-- RIGHT SHOWCASE -->
            <div class="col-md-7 col-lg-8 right-panel d-none d-md-flex p-5 text-white">
                <div style="max-width: 620px;">
                    <div class="hero-badge">
                        <i class="fa-solid fa-gem"></i> Executive Retail & Rental Suite
                    </div>
                    <h1 class="display-4 fw-bold mb-3" style="font-family: var(--font-heading); letter-spacing: -0.02em;">
                        Streamlined Operations for Hardware & Heavy Tools
                    </h1>
                    <p class="text-white-50 fs-5 mb-4" style="font-weight: 300; line-height: 1.6;">
                        Seamless cash session control, real-time inventory tracking, and swift selling & equipment rental lifecycles tailored for Mahinda Constructions.
                    </p>
                    <div class="d-flex gap-4 pt-2">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; background: rgba(212, 175, 55, 0.15); color: var(--gold-primary);">
                                <i class="fa-solid fa-cart-shopping"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold">Instant Selling</h6>
                                <small class="text-white-50">Thermal receipts</small>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; background: rgba(16, 185, 129, 0.15); color: #6ee7b7;">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold">POS Rentals</h6>
                                <small class="text-white-50">2-stage checkouts</small>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; background: rgba(59, 130, 246, 0.15); color: #93c5fd;">
                                <i class="fa-solid fa-boxes-stacked"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold">Inventory</h6>
                                <small class="text-white-50">Stock control</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>