import express from 'express';
import session from 'express-session';
import path from 'path';
import fs from 'fs';
import { fileURLToPath } from 'url';
import { db } from './db.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const app = express();
const PORT = 3000;

// Set up view engine
app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'views'));

// Body parser middlewares
app.use(express.urlencoded({ extended: true, limit: '10mb' }));
app.use(express.json({ limit: '10mb' }));

// Trust reverse proxy for HTTPS / Cloud Run environment
app.set('trust proxy', 1);

// Static files
app.use('/css', express.static(path.join(__dirname, 'css')));
app.use('/img', express.static(path.join(__dirname, 'img')));
app.use(express.static(path.join(__dirname, 'public')));

// In-memory token store for iframe cross-site environments where 3rd-party cookies may be restricted
const authTokens = new Map(); // token -> { user_id, username, role, active_session_id, selected_customer, expiresAt }

// Session configuration
app.use(
  session({
    secret: 'mcats-luxury-secret-key-2026',
    resave: false,
    saveUninitialized: false,
    cookie: {
      maxAge: 24 * 60 * 60 * 1000,
      sameSite: 'none',
      secure: 'auto',
      partitioned: true
    }
  })
);

// Token & Session synchronization helper
function getAuthUser(req) {
  const token = req.query.auth_token || req.headers['x-auth-token'] || (req.body && req.body.auth_token) || (req.session && req.session.auth_token);
  if (token && authTokens.has(token)) {
    const data = authTokens.get(token);
    if (data.expiresAt > Date.now()) {
      if (req.session) {
        req.session.user_id = data.user_id;
        req.session.username = data.username;
        req.session.role = data.role;
        req.session.auth_token = token;
        if (data.active_session_id && !req.session.active_session_id) {
          req.session.active_session_id = data.active_session_id;
        }
        if (data.selected_customer && !req.session.selected_customer) {
          req.session.selected_customer = data.selected_customer;
        }
      }
      return { ...data, token };
    } else {
      authTokens.delete(token);
    }
  }

  if (req.session && req.session.user_id) {
    return {
      user_id: req.session.user_id,
      username: req.session.username,
      role: req.session.role,
      token: req.session.auth_token || ''
    };
  }
  return null;
}

// Redirect wrapper to preserve auth_token in iframe contexts
app.use((req, res, next) => {
  const origRedirect = res.redirect.bind(res);
  res.redirect = function (url) {
    const auth = getAuthUser(req);
    const token = (auth && auth.token) || req.query.auth_token || (req.session && req.session.auth_token);
    if (token && typeof url === 'string' && !url.includes('auth_token=') && !url.includes('/logout') && !url.includes('logout=1')) {
      const sep = url.includes('?') ? '&' : '?';
      url = url + sep + 'auth_token=' + encodeURIComponent(token);
    }
    return origRedirect(url);
  };
  next();
});

// Helper middleware to make session variables and token accessible in views
app.use((req, res, next) => {
  const auth = getAuthUser(req);
  res.locals.session = req.session || {};
  res.locals.authToken = auth ? auth.token : '';
  res.locals.user = auth ? {
    user_id: auth.user_id,
    username: auth.username || 'User',
    role: auth.role || 'admin'
  } : { user_id: 0, username: 'Administrator', role: 'admin' };

  res.locals.encodePrice = (price) => {
    if (price === null || price === undefined || price === '') return '—';
    const str = String(price).trim();
    if (isNaN(Number(str))) return str.toUpperCase();
    const map = { '1':'B', '2':'L', '3':'A', '4':'C', '5':'K', '6':'H', '7':'O', '8':'R', '9':'S', '0':'E' };
    return Math.round(Number(str)).toString().split('').map(d => map[d] || d).join('');
  };
  res.locals.db_flash = req.session && req.session.db_flash ? req.session.db_flash : null;
  if (req.session && req.session.db_flash) delete req.session.db_flash;

  // Persist session changes back into authTokens store at the end of the response
  res.on('finish', () => {
    const currentToken = (auth && auth.token) || req.query.auth_token || (req.session && req.session.auth_token);
    if (currentToken && authTokens.has(currentToken) && req.session) {
      const entry = authTokens.get(currentToken);
      entry.active_session_id = req.session.active_session_id;
      entry.selected_customer = req.session.selected_customer;
      entry.role = req.session.role || entry.role;
    }
  });

  next();
});

// Auto-inject client-side iframe session persistence script into all rendered HTML pages
app.use((req, res, next) => {
  const originalSend = res.send.bind(res);
  res.send = function (body) {
    if (typeof body === 'string' && body.includes('</body>') && res.locals.authToken) {
      const token = res.locals.authToken;
      const script = `
<script>
(function() {
  var token = ${JSON.stringify(token)};
  if (token) {
    try { localStorage.setItem('mcats_auth_token', token); } catch(e){}
    function applyToken() {
      document.querySelectorAll('a[href^="/views/"], a[href^="/index"], a[href^="/api/"], a[href^="/logout"]').forEach(function(a) {
        if (a.href && !a.href.includes('auth_token=')) {
          var sep = a.href.includes('?') ? '&' : '?';
          a.href = a.href + sep + 'auth_token=' + encodeURIComponent(token);
        }
      });
      document.querySelectorAll('form').forEach(function(f) {
        if (!f.querySelector('input[name="auth_token"]')) {
          var inp = document.createElement('input');
          inp.type = 'hidden';
          inp.name = 'auth_token';
          inp.value = token;
          f.appendChild(inp);
        }
      });
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', applyToken);
    } else {
      applyToken();
    }
  }
})();
</script>`;
      body = body.replace('</body>', script + '</body>');
    }
    return originalSend(body);
  };
  next();
});

// Authentication middleware
function requireAuth(req, res, next) {
  const auth = getAuthUser(req);
  if (!auth) {
    if (req.xhr || (req.headers.accept && req.headers.accept.includes('application/json'))) {
      return res.status(401).json({ error: 'Unauthorized' });
    }
    return res.redirect('/views/index.php');
  }
  next();
}

function requireSuperAdmin(req, res, next) {
  const auth = getAuthUser(req);
  if (!auth || auth.role !== 'super_admin') {
    if (req.xhr || (req.headers.accept && req.headers.accept.includes('application/json'))) {
      return res.status(403).json({ error: 'Forbidden' });
    }
    return res.redirect('/views/index.php');
  }
  next();
}

// -------------------------------------------------------------
// Authentication & Login Routes
// -------------------------------------------------------------
const renderLogin = (req, res) => {
  // If user requested explicit logout, do not auto-redirect
  if (req.query.logout) {
    const err = req.session ? (req.session.login_error || '') : '';
    if (req.session && req.session.login_error) delete req.session.login_error;
    return res.render('index', { errorMessage: err, error_message: err });
  }

  const auth = getAuthUser(req);
  if (auth) {
    if (auth.role === 'super_admin') {
      return res.redirect(`/views/admin/sahome.php?auth_token=${auth.token}`);
    }
    return res.redirect(`/views/admin/home.php?auth_token=${auth.token}`);
  }

  const err = (req.session && req.session.login_error) || '';
  if (req.session && req.session.login_error) delete req.session.login_error;
  res.render('index', { errorMessage: err, error_message: err });
};

app.get('/', renderLogin);
app.get('/index.php', renderLogin);
app.get('/views/index.php', renderLogin);

const handleLogin = (req, res) => {
  const username = (req.body.username || '').trim();
  const password = (req.body.password || '').trim();
  const user = db.findUserByUsername(username);

  const isValidPassword = user && (user.password === password || user.password === req.body.password);

  if (user && isValidPassword) {
    const token = 'tok_' + Math.random().toString(36).substring(2) + Date.now().toString(36);
    authTokens.set(token, {
      user_id: user.user_id,
      username: user.username,
      role: user.role,
      active_session_id: null,
      selected_customer: null,
      expiresAt: Date.now() + 24 * 60 * 60 * 1000
    });

    if (req.session) {
      req.session.user_id = user.user_id;
      req.session.username = user.username;
      req.session.role = user.role;
      req.session.auth_token = token;
    }

    const redirectUrl = user.role === 'super_admin'
      ? `/views/admin/sahome.php?auth_token=${token}`
      : `/views/admin/home.php?auth_token=${token}`;

    if (req.xhr || (req.headers.accept && req.headers.accept.includes('application/json')) || req.body.is_ajax) {
      return res.json({
        success: true,
        redirect: redirectUrl,
        token: token,
        role: user.role,
        username: user.username
      });
    }

    return res.redirect(redirectUrl);
  } else {
    const errMsg = 'Invalid credentials provided. Please check username and password.';
    if (req.session) {
      req.session.login_error = errMsg;
    }
    if (req.xhr || (req.headers.accept && req.headers.accept.includes('application/json')) || req.body.is_ajax) {
      return res.status(401).json({ success: false, error: errMsg });
    }
    return res.redirect('/views/index.php');
  }
};

app.post('/', handleLogin);
app.post('/login', handleLogin);
app.post('/index.php', handleLogin);
app.post('/views/index.php', handleLogin);
app.post('/api/login', handleLogin);

// Auth verification endpoint
app.get('/api/auth/check', (req, res) => {
  const auth = getAuthUser(req);
  if (auth) {
    const redirectUrl = auth.role === 'super_admin'
      ? `/views/admin/sahome.php?auth_token=${auth.token}`
      : `/views/admin/home.php?auth_token=${auth.token}`;
    return res.json({ authenticated: true, user: auth, redirect: redirectUrl });
  }
  res.json({ authenticated: false });
});

// Logout
const handleLogout = (req, res) => {
  const auth = getAuthUser(req);
  if (auth && auth.token) {
    authTokens.delete(auth.token);
  }
  if (req.session) {
    req.session.destroy(() => {
      res.redirect('/views/index.php?logout=1');
    });
  } else {
    res.redirect('/views/index.php?logout=1');
  }
};

app.get('/logout.php', handleLogout);
app.get('/logout', handleLogout);

// -------------------------------------------------------------
// Super Admin Dashboard & User Management
// -------------------------------------------------------------
const renderSaHome = (req, res) => {
  const totalSales = db.getTotalSales();
  const todaySales = db.getTodaySales();
  const users = db.getAllUsers();
  const pendingRequests = db.getChangeRequests({ status: 'pending' });

  res.render('admin/sahome', {
    totalSales,
    todaySales,
    users,
    pendingRequests,
    pendingTotal: db.getPendingRequestsCount(),
    pendingQty: db.getPendingRequestsCount('quantity'),
    pendingRate: db.getPendingRequestsCount('daily_rate'),
    pendingCost: db.getPendingRequestsCount('cost_price'),
    approvedTotal: db.getApprovedRequestsCount()
  });
};

app.get('/views/admin/sahome.php', requireSuperAdmin, renderSaHome);
app.get('/views/admin/sahome', requireSuperAdmin, renderSaHome);

// Manage User (Add / Edit / Delete)
app.get('/views/admin/manage_user.php', requireSuperAdmin, (req, res) => {
  const isAdding = !req.query.id;
  let editData = null;

  if (req.query.delete) {
    const delId = Number(req.query.delete);
    // Protect super admin account from deleting self
    if (delId !== req.session.user_id) {
      db.deleteUser(delId);
    }
    return res.redirect('/views/admin/sahome.php');
  }

  if (!isAdding) {
    editData = db.findUserById(req.query.id);
    if (!editData) {
      return res.redirect('/views/admin/sahome.php');
    }
  }

  res.render('admin/manage_user', {
    isAdding,
    editData: editData || {},
    errorMessage: req.session.manage_user_error || ''
  });
  delete req.session.manage_user_error;
});

app.post('/views/admin/manage_user.php', requireSuperAdmin, (req, res) => {
  const { add_user, edit_user, username, password, role, user_id } = req.body;

  if (add_user) {
    if (!username || !password) {
      req.session.manage_user_error = 'Username and password are required.';
      return res.redirect('/views/admin/manage_user.php');
    }
    if (db.findUserByUsername(username)) {
      req.session.manage_user_error = 'Username already exists.';
      return res.redirect('/views/admin/manage_user.php');
    }
    db.addUser(username, password, role || 'admin');
    return res.redirect('/views/admin/sahome.php');
  }

  if (edit_user && user_id) {
    db.updateUser(user_id, { role, password });
    return res.redirect('/views/admin/sahome.php');
  }

  res.redirect('/views/admin/sahome.php');
});

// Reports
app.get('/views/admin/reports.php', requireSuperAdmin, (req, res) => {
  const transactions = db.getAllTransactions();
  res.render('admin/reports', { transactions });
});
app.get('/views/admin/reports', requireSuperAdmin, (req, res) => {
  res.redirect('/views/admin/reports.php');
});

// Sessions Report
app.get('/views/admin/sessions_report.php', requireSuperAdmin, (req, res) => {
  const sessions = db.getAllSessions();
  res.render('admin/sessions_report', { sessions });
});
app.get('/views/admin/sessions_report', requireSuperAdmin, (req, res) => {
  res.redirect('/views/admin/sessions_report.php');
});

// Session Payment Summary
app.get('/views/admin/session_payment_summary.php', requireSuperAdmin, (req, res) => {
  const sessionId = Number(req.query.id);
  const session = db.cash_sessions.find(s => s.id === sessionId);

  if (!session) {
    return res.status(404).send('Session not found.');
  }

  const cashier = db.findUserById(session.user_id);
  const sessionData = {
    ...session,
    username: cashier ? cashier.username : 'Unknown'
  };

  const sessionTxs = db.transactions.filter(t => t.session_id === sessionId);
  const salesTotal = sessionTxs
    .filter(t => t.type === 'selling')
    .reduce((sum, t) => sum + (Number(t.received_amount || 0) - Number(t.balance_amount || 0)), 0);

  const rentsTotal = sessionTxs
    .filter(t => t.type === 'renting')
    .reduce((sum, t) => sum + (Number(t.received_amount || 0) - Number(t.balance_amount || 0)), 0);

  const totalPosRevenue = salesTotal + rentsTotal;
  const expectedCash = Number(session.opening_balance || 0) + totalPosRevenue;

  res.render('admin/session_payment_summary', {
    sessionId,
    sessionData,
    salesTotal,
    rentsTotal,
    expectedCash
  });
});

// Inventory Stats Analytics
app.get('/views/admin/inventory_stats.php', requireSuperAdmin, (req, res) => {
  const revenueLabels = [];
  const revenueData = [];

  for (let i = 6; i >= 0; i--) {
    const d = new Date();
    d.setDate(d.getDate() - i);
    const dateStr = d.toISOString().split('T')[0];
    const label = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    revenueLabels.push(label);

    const dayTotal = db.transactions
      .filter(t => t.transaction_date.startsWith(dateStr) && t.status !== 'cancelled')
      .reduce((sum, t) => sum + (Number(t.received_amount || 0) - Number(t.balance_amount || 0)), 0);

    revenueData.push(dayTotal);
  }

  const itemLabels = [];
  const stockData = [];
  const salesData = [];

  const allItems = db.getAllInventory();
  for (const item of allItems) {
    const name = item.item_name.length > 15 ? item.item_name.substring(0, 15) + '...' : item.item_name;
    itemLabels.push(name);
    stockData.push(Number(item.stock_quantity || 0));

    const totalSold = db.transaction_items
      .filter(ti => ti.item_id === item.item_id)
      .reduce((sum, ti) => sum + Number(ti.quantity || 0), 0);

    salesData.push(totalSold);
  }

  res.render('admin/inventory_stats', {
    revenueLabels,
    revenueData,
    itemLabels,
    stockData,
    salesData
  });
});

// -------------------------------------------------------------
// Database Synchronization & Management Routes
// -------------------------------------------------------------
app.post('/api/db/reload', requireAuth, (req, res) => {
  try {
    db.loadFromSqlFile();
    req.session.db_flash = { type: 'success', text: 'Database state successfully reloaded from db.sql!' };
  } catch (err) {
    req.session.db_flash = { type: 'danger', text: 'Failed to reload db.sql: ' + err.message };
  }
  const referer = req.get('Referer') || '/views/admin/sahome.php';
  res.redirect(referer);
});

app.get('/api/db/export', requireAuth, (req, res) => {
  db.saveToSqlFile();
  const sqlPath = path.join(__dirname, 'db.sql');
  res.download(sqlPath, 'mcats_database.sql');
});

// -------------------------------------------------------------
// Admin Cashier Dashboard & Sessions
// -------------------------------------------------------------
const renderAdminHome = (req, res) => {
  if (req.session.role === 'super_admin') {
    return res.redirect('/views/admin/sahome.php');
  }

  const todayTotal = db.getTodaySales();
  const activeSession = db.getActiveSession();
  const auth = getAuthUser(req);
  const pendingRequests = db.getChangeRequests({ status: 'pending' });
  const approvedRequests = db.getApprovedRequestsForAdmin(auth ? auth.username : null);

  if (activeSession) {
    req.session.active_session_id = activeSession.id;
  } else {
    delete req.session.active_session_id;
  }

  res.render('admin/home', {
    today_total: todayTotal,
    todayTotal: todayTotal,
    active_session: activeSession || null,
    activeSession: activeSession || null,
    pendingRequests,
    approvedRequests,
    pendingTotal: db.getPendingRequestsCount(),
    pendingQty: db.getPendingRequestsCount('quantity'),
    pendingRate: db.getPendingRequestsCount('daily_rate'),
    pendingCost: db.getPendingRequestsCount('cost_price'),
    approvedTotal: db.getApprovedRequestsCount()
  });
};

app.get('/views/admin/home.php', requireAuth, renderAdminHome);
app.get('/views/admin/home', requireAuth, renderAdminHome);

// Open Day Session
app.post('/views/admin/home.php', requireAuth, (req, res) => {
  const openingBalance = parseFloat(req.body.opening_balance) || 0;
  const session = db.openSession(req.session.user_id, openingBalance);
  req.session.active_session_id = session.id;
  res.redirect('/views/admin/home.php');
});

// End Day Session
app.get('/views/admin/end_session.php', requireAuth, (req, res) => {
  const activeSession = db.getActiveSession();
  if (activeSession) {
    db.closeSession(activeSession.id, 0);
  }
  delete req.session.active_session_id;
  req.session.destroy(() => {
    res.redirect('/views/index.php');
  });
});

// -------------------------------------------------------------
// Inventory Management
// -------------------------------------------------------------
app.get('/views/admin/inventory.php', requireAuth, (req, res) => {
  let msg = req.session.inv_msg || '';
  delete req.session.inv_msg;

  // Handle Delete
  if (req.query.delete) {
    const delId = Number(req.query.delete);
    try {
      db.deleteItem(delId);
      req.session.inv_msg = "<div class='alert alert-success'>Item deleted successfully.</div>";
    } catch (e) {
      req.session.inv_msg = "<div class='alert alert-error'><b>Deletion Blocked:</b> " + e.message + "</div>";
    }
    return res.redirect('/views/admin/inventory.php');
  }

  let editData = null;
  if (req.query.edit) {
    editData = db.getItemById(req.query.edit);
  }

  // Check for authorized change request grant
  let activeGrant = null;
  const grantId = req.query.grant_id || req.query.req_id;
  if (grantId) {
    const g = db.getChangeRequestById(grantId);
    if (g && g.status === 'approved') {
      activeGrant = g;
      if (!editData) {
        editData = db.getItemById(g.item_id);
      }
    }
  }

  const items = db.getAllInventory();

  res.render('admin/inventory', {
    msg,
    items,
    edit_data: editData,
    editData: editData,
    activeGrant: activeGrant,
    pendingTotal: db.getPendingRequestsCount(),
    pendingQty: db.getPendingRequestsCount('quantity'),
    pendingRate: db.getPendingRequestsCount('daily_rate'),
    pendingCost: db.getPendingRequestsCount('cost_price'),
    approvedTotal: db.getApprovedRequestsCount()
  });
});

app.post('/views/admin/inventory.php', requireAuth, (req, res) => {
  const auth = getAuthUser(req);
  const isSa = auth && auth.role === 'super_admin';
  const { item_id, item_name, category, price_per_unit, stock_quantity, status, existing_image, bought_price, item_image, item_image_data, grant_id } = req.body;
  
  // Image handling: save uploaded base64 image or keep chosen filename
  let assignedImage = item_image || existing_image || 'default.png';
  if (item_image_data && typeof item_image_data === 'string' && item_image_data.startsWith('data:image/')) {
    try {
      const matches = item_image_data.match(/^data:image\/([a-zA-Z0-9+]+);base64,(.+)$/);
      if (matches && matches.length === 3) {
        let ext = matches[1].toLowerCase() === 'jpeg' ? 'jpg' : matches[1].toLowerCase();
        if (ext.includes('+')) ext = ext.split('+')[0];
        const buffer = Buffer.from(matches[2], 'base64');
        const imgName = `item_${Date.now()}_${Math.floor(Math.random() * 1000)}.${ext}`;
        const imgDir = path.join(__dirname, 'img');
        if (!fs.existsSync(imgDir)) {
          fs.mkdirSync(imgDir, { recursive: true });
        }
        fs.writeFileSync(path.join(imgDir, imgName), buffer);
        assignedImage = imgName;
      }
    } catch (imgErr) {
      console.error('[Inventory] Error saving uploaded image file:', imgErr);
    }
  }

  // Active change request verification
  let activeGrant = null;
  if (grant_id) {
    const g = db.getChangeRequestById(grant_id);
    if (g && g.status === 'approved' && Number(g.item_id) === Number(item_id)) {
      activeGrant = g;
    }
  }

  let finalStock = parseInt(stock_quantity, 10);
  if (isNaN(finalStock) || finalStock < 0) finalStock = 0;
  let finalPrice = parseFloat(price_per_unit);
  if (isNaN(finalPrice) || finalPrice < 0) finalPrice = 0;
  let finalBoughtPrice = (bought_price !== undefined && bought_price !== null && String(bought_price).trim() !== '') ? String(bought_price).trim() : null;

  const isUpdate = Boolean(item_id && Number(item_id) > 0);
  const savedItem = db.saveItem({
    id: item_id,
    name: item_name ? String(item_name).trim() : 'Unnamed Item',
    category,
    price: finalPrice,
    stock: finalStock,
    status: status || 'available',
    image: assignedImage,
    boughtPrice: finalBoughtPrice
  });

  // Mark change request completed if authorized grant was used
  if (activeGrant) {
    db.completeChangeRequest(activeGrant.id, auth ? auth.username : 'admin');
  }

  let successMsg = `<div class='alert alert-success d-flex align-items-center justify-content-between gap-3 shadow-sm'>` +
    `<div class='d-flex align-items-center gap-2'>` +
    `<i class='fa-solid fa-circle-check fs-5 text-success'></i> ` +
    `<span>Item <strong>${savedItem.item_name}</strong> (#${String(savedItem.item_id).padStart(4, '0')}) ${isUpdate ? 'updated' : 'added'} successfully! (Stock: <strong>${savedItem.stock_quantity}</strong>, Rate: <strong>Rs. ${Number(savedItem.price_per_unit).toFixed(2)}</strong>)</span>` +
    `</div>`;
  if (activeGrant) {
    successMsg += `<span class='badge bg-success'>Request #REQ-${String(activeGrant.id).padStart(3, '0')} Completed</span>`;
  }
  successMsg += `</div>`;
  req.session.inv_msg = successMsg;
  res.redirect('/views/admin/inventory.php');
});

// -------------------------------------------------------------
// Change Requests Routes (Dedicated Pages & API)
// -------------------------------------------------------------
const renderChangeRequestsPage = (pageType) => (req, res) => {
  let msg = req.session.req_msg || '';
  delete req.session.req_msg;

  const items = db.getAllInventory();
  const allRequests = db.getChangeRequests();
  const requests = pageType === 'all' ? allRequests : db.getChangeRequests({ type: pageType });

  res.render('admin/requests', {
    pageType,
    msg,
    items,
    requests,
    counts: {
      pendingTotal: db.getPendingRequestsCount(),
      pendingQty: db.getPendingRequestsCount('quantity'),
      pendingRate: db.getPendingRequestsCount('daily_rate'),
      pendingCost: db.getPendingRequestsCount('cost_price'),
      approvedTotal: db.getApprovedRequestsCount()
    }
  });
};

app.get('/views/admin/requests_qty.php', requireAuth, renderChangeRequestsPage('quantity'));
app.get('/views/admin/requests_rate.php', requireAuth, renderChangeRequestsPage('daily_rate'));
app.get('/views/admin/requests_cost.php', requireAuth, renderChangeRequestsPage('cost_price'));
app.get('/views/admin/requests.php', requireAuth, renderChangeRequestsPage('all'));

// API: Submit New Change Request
app.post('/api/requests/create', requireAuth, (req, res) => {
  const auth = getAuthUser(req);
  const { type, item_id, requested_value, reason, redirect_to } = req.body;

  try {
    const newReq = db.createChangeRequest({
      type,
      itemId: item_id,
      requestedValue: requested_value,
      reason,
      requestedBy: auth ? auth.username : 'admin'
    });
    req.session.req_msg = `<div class='alert alert-success'>Change Request #REQ-${String(newReq.id).padStart(3, '0')} for "${newReq.item_name}" submitted to Super Admin for approval.</div>`;
  } catch (err) {
    req.session.req_msg = `<div class='alert alert-danger'><b>Request Failed:</b> ${err.message}</div>`;
  }

  const defaultRedirect = `/views/admin/requests_${type === 'quantity' ? 'qty' : (type === 'daily_rate' ? 'rate' : 'cost')}.php`;
  res.redirect(redirect_to || defaultRedirect);
});

// API: Super Admin Approve Change Request
app.post('/api/requests/approve', requireSuperAdmin, (req, res) => {
  const auth = getAuthUser(req);
  const { request_id, notes, redirect_to } = req.body;

  try {
    const updated = db.approveChangeRequest(request_id, auth.username, notes);
    req.session.req_msg = `<div class='alert alert-success'><i class='fa-solid fa-circle-check me-2'></i>Request #REQ-${String(updated.id).padStart(3, '0')} has been <b>approved</b>! The admin now receives a linked button to edit that asked item detail.</div>`;
  } catch (err) {
    req.session.req_msg = `<div class='alert alert-danger'><b>Approval Failed:</b> ${err.message}</div>`;
  }

  res.redirect(redirect_to || '/views/admin/requests.php');
});

// API: Super Admin Reject Change Request
app.post('/api/requests/reject', requireSuperAdmin, (req, res) => {
  const auth = getAuthUser(req);
  const { request_id, notes, redirect_to } = req.body;

  try {
    const updated = db.rejectChangeRequest(request_id, auth.username, notes);
    req.session.req_msg = `<div class='alert alert-warning'><i class='fa-solid fa-triangle-exclamation me-2'></i>Request #REQ-${String(updated.id).padStart(3, '0')} was rejected.</div>`;
  } catch (err) {
    req.session.req_msg = `<div class='alert alert-danger'><b>Rejection Failed:</b> ${err.message}</div>`;
  }

  res.redirect(redirect_to || '/views/admin/requests.php');
});

// -------------------------------------------------------------
// POS Selling
// -------------------------------------------------------------
app.get('/views/pos/sell.php', requireAuth, (req, res) => {
  const activeSession = db.getActiveSession();
  if (!activeSession) {
    return res.send("<script>alert('No active day session! Please start a session from the Admin Dashboard first.'); window.location.href='/views/admin/home.php';</script>");
  }

  const items = db.getSaleableInventory();
  res.render('pos/sell', {
    items,
    sessionId: activeSession.id,
    errorMsg: '',
    successMsg: ''
  });
});

app.post('/views/pos/sell.php', requireAuth, (req, res) => {
  const activeSession = db.getActiveSession();
  if (!activeSession) {
    return res.send("<script>alert('No active day session! Please start a session from the Admin Dashboard first.'); window.location.href='/views/admin/home.php';</script>");
  }

  try {
    const cartData = JSON.parse(req.body.cart_data || '[]');
    if (!cartData || cartData.length === 0) {
      return res.redirect('/views/pos/sell.php');
    }

    let totalLkr = 0;
    cartData.forEach(item => {
      totalLkr += Number(item.price || 0) * Number(item.qty || 1);
    });

    const receivedAmount = req.body.received_amount ? parseFloat(req.body.received_amount) : totalLkr;
    const balanceAmount = receivedAmount - totalLkr;
    const freeEqText = (req.body.free_eq === 'yes') ? (String(req.body.free_eq_desc || '').trim() || 'Included Free Equipment') : null;

    const tx = db.createTransaction({
      sessionId: activeSession.id,
      customerNic: null,
      type: 'selling',
      totalLkr,
      receivedAmount,
      balanceAmount,
      freeEquipment: freeEqText,
      status: 'completed',
      items: cartData
    });

    return res.redirect(`/views/pos/print_bill.php?type=thermal&bill_id=${tx.bill_number}`);
  } catch (err) {
    console.error('Selling checkout error:', err);
    return res.redirect('/views/pos/sell.php');
  }
});

// -------------------------------------------------------------
// POS Renting
// -------------------------------------------------------------
app.get('/views/pos/rent.php', requireAuth, (req, res) => {
  const activeSession = db.getActiveSession();
  if (!activeSession) {
    return res.send("<script>alert('No active day session! Please start a session from the Admin Dashboard first.'); window.location.href='/views/admin/home.php';</script>");
  }

  const items = db.getRentalInventory();
  const customers = db.getAllCustomers();
  const selectedCustomer = req.session.selected_customer || null;

  res.render('pos/rent', {
    items,
    customers,
    selectedCustomer,
    sel: selectedCustomer,
    sessionId: activeSession.id,
    msg: ''
  });
});

app.post('/views/pos/rent.php', requireAuth, (req, res) => {
  const activeSession = db.getActiveSession();
  if (!activeSession) {
    return res.send("<script>alert('No active day session! Please start a session from the Admin Dashboard first.'); window.location.href='/views/admin/home.php';</script>");
  }

  try {
    const cartData = JSON.parse(req.body.cart_data || '[]');
    const nic = String(req.body.nic || '').trim();
    const phone = String(req.body.phone || '').trim();
    const fullName = String(req.body.customer_name || 'Walk-in Customer').trim();
    const address = String(req.body.address || '').trim();
    const advance = parseFloat(req.body.advance) || 0;

    let totalLkr = 0;
    cartData.forEach(item => {
      totalLkr += Number(item.price || 0) * Number(item.qty || 1);
    });

    const freeEqText = (req.body.free_eq === 'yes') ? (String(req.body.free_eq_desc || '').trim() || 'Included Free Equipment') : null;
    const receivedAmount = req.body.received_amount ? parseFloat(req.body.received_amount) : advance;
    const balanceAmount = receivedAmount - advance;

    const customer = db.upsertCustomer(nic, phone, fullName, address);

    const tx = db.createTransaction({
      sessionId: activeSession.id,
      customerNic: customer.nic_number,
      type: 'renting',
      totalLkr,
      receivedAmount,
      balanceAmount,
      advancePaid: advance,
      freeEquipment: freeEqText,
      status: 'ongoing',
      items: cartData
    });

    delete req.session.selected_customer;
    return res.redirect(`/views/pos/print_bill.php?type=thermal_rent&bill_id=${tx.bill_number}`);
  } catch (err) {
    console.error('Rental checkout error:', err);
    return res.redirect('/views/pos/rent.php');
  }
});

// Select Customer for Renting
app.get('/views/pos/select_customer.php', requireAuth, (req, res) => {
  const q = String(req.query.q || '').toLowerCase().trim();
  let customers = db.getAllCustomers();
  if (q) {
    customers = customers.filter(c =>
      c.nic_number.toLowerCase().includes(q) ||
      c.phone_number.toLowerCase().includes(q) ||
      c.full_name.toLowerCase().includes(q)
    );
  }

  res.render('pos/select_customer', {
    customers,
    search: req.query.q || ''
  });
});

app.post('/views/pos/select_customer.php', requireAuth, (req, res) => {
  const nic = req.body.select_nic;
  const customer = db.customers.find(c => c.nic_number === nic);
  if (customer) {
    req.session.selected_customer = customer;
  }
  res.redirect('/views/pos/rent.php');
});

// -------------------------------------------------------------
// POS Active Leases & Return Items
// -------------------------------------------------------------
app.get('/views/pos/return_items.php', requireAuth, (req, res) => {
  const dateFilter = req.query.date || 'all';
  const searchFilter = String(req.query.search || '').toLowerCase().trim();

  let rentals = db.transactions.filter(t => t.type === 'renting' && t.status === 'ongoing');

  if (dateFilter !== 'all') {
    rentals = rentals.filter(t => t.transaction_date.startsWith(dateFilter));
  }

  if (searchFilter) {
    rentals = rentals.filter(t => {
      const customer = t.customer_nic ? db.customers.find(c => c.nic_number === t.customer_nic) : null;
      const cName = customer ? customer.full_name.toLowerCase() : '';
      const cNic = (t.customer_nic || '').toLowerCase();
      const cPhone = customer ? customer.phone_number.toLowerCase() : '';
      const notes = (t.notes || '').toLowerCase();

      return cName.includes(searchFilter) || cNic.includes(searchFilter) || cPhone.includes(searchFilter) || notes.includes(searchFilter);
    });
  }

  const populatedRentals = rentals.map(r => {
    const customer = r.customer_nic ? db.customers.find(c => c.nic_number === r.customer_nic) : null;
    return {
      ...r,
      c_name: customer ? customer.full_name : 'Walk-in Customer',
      c_phone: customer ? customer.phone_number : ''
    };
  }).sort((a, b) => b.bill_number - a.bill_number);

  res.render('pos/return_items', {
    rentals: populatedRentals,
    dateFilter,
    searchFilter: req.query.search || ''
  });
});

// Return Checkout (Settlement)
app.get('/views/pos/return_checkout.php', requireAuth, (req, res) => {
  const billId = Number(req.query.bill_id);
  if (!billId) {
    return res.redirect('/views/pos/return_items.php');
  }

  const trans = db.getTransaction(billId);
  if (!trans) {
    return res.status(404).send('Transaction not found.');
  }

  const items = trans.items.map(ti => ({
    ...ti,
    ti_id: ti.id
  }));

  const rentDate = new Date(trans.transaction_date);
  const nowDate = new Date();
  const diffTime = Math.abs(nowDate - rentDate);
  let defaultDays = Math.floor(diffTime / (1000 * 60 * 60 * 24));
  if (defaultDays === 0) defaultDays = 1;

  let totalHardwareValue = 0;
  items.forEach(item => {
    totalHardwareValue += Number(item.quantity || 0) * Number(item.unit_price || 0);
  });

  res.render('pos/return_checkout', {
    billId,
    trans: {
      ...trans,
      c_name: trans.customer ? trans.customer.full_name : 'Walk-in Customer',
      c_phone: trans.customer ? trans.customer.phone_number : ''
    },
    items,
    defaultDays,
    totalHardwareValue,
    msg: trans.status === 'returned' ? "<div class='alert alert-warning shadow-sm rounded-3 mt-3'><i class='fa-solid fa-triangle-exclamation me-2'></i>This rental has already been returned and closed.</div>" : ''
  });
});

app.post('/views/pos/return_checkout.php', requireAuth, (req, res) => {
  const billId = Number(req.query.bill_id || req.body.bill_id);
  const cashReceived = parseFloat(req.body.cash_received) || 0;
  const daysPerItem = req.body.days || {};

  db.processRentalReturn(billId, daysPerItem, cashReceived);
  res.redirect(`/views/pos/print_bill.php?type=a4&bill_id=${billId}`);
});

// -------------------------------------------------------------
// Invoice & Bill Printing
// -------------------------------------------------------------
app.get('/views/pos/print_bill.php', requireAuth, (req, res) => {
  const billId = Number(req.query.bill_id);
  const type = req.query.type || 'thermal';

  const txn = db.getTransaction(billId);
  if (!txn) {
    return res.status(404).send('Bill not found.');
  }

  const customer = txn.customer || (txn.customer_nic ? (db.customers && db.customers.find(c => String(c.nic_number) === String(txn.customer_nic))) : null);
  const d = new Date(txn.transaction_date);
  const dateFormatted = d.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' }) + ' ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });

  res.render('pos/print_bill', {
    txn,
    customer,
    items: txn.items,
    type,
    billId,
    dateFormatted
  });
});

// -------------------------------------------------------------
// Fallback 404 & Error Handler
// -------------------------------------------------------------
app.use((req, res) => {
  res.status(404).redirect('/views/index.php');
});

// Start Server
app.listen(PORT, '0.0.0.0', () => {
  console.log(`MCATS POS Server running on http://0.0.0.0:${PORT}`);
});
