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
    secret: process.env.SESSION_SECRET || 'mcats-luxury-secret-key-2026',
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
  const token = req.query.auth_token || req.query['amp;auth_token'] || req.headers['x-auth-token'] || (req.body && req.body.auth_token) || (req.session && req.session.auth_token);
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
    const token = (auth && auth.token) || req.query.auth_token || (req.body && req.body.auth_token) || (req.session && req.session.auth_token);
    if (token && typeof url === 'string' && !url.includes('auth_token=') && !url.includes('/logout') && !url.includes('logout=1')) {
      const hashIdx = url.indexOf('#');
      let hash = '';
      let base = url;
      if (hashIdx !== -1) {
        hash = url.substring(hashIdx);
        base = url.substring(0, hashIdx);
      }
      const sep = base.includes('?') ? '&' : '?';
      url = base + sep + 'auth_token=' + encodeURIComponent(token) + hash;
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
    const currentToken = (auth && auth.token) || req.query.auth_token || (req.body && req.body.auth_token) || (req.session && req.session.auth_token);
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
    function appendAuthToken(url, tok) {
      if (!url || !tok || url.includes('auth_token=')) return url;
      var hashIdx = url.indexOf('#');
      var hash = '';
      var base = url;
      if (hashIdx !== -1) {
        hash = url.substring(hashIdx);
        base = url.substring(0, hashIdx);
      }
      var sep = base.includes('?') ? '&' : '?';
      return base + sep + 'auth_token=' + encodeURIComponent(tok) + hash;
    }
    function applyToken() {
      document.querySelectorAll('a[href]').forEach(function(a) {
        var href = a.getAttribute('href');
        if (href && (href.startsWith('/views/') || href.startsWith('/index') || href.startsWith('/api/') || href.startsWith('/logout')) && !href.includes('auth_token=')) {
          a.setAttribute('href', appendAuthToken(href, token));
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
    // Global click listener to guarantee all navigation carries auth_token before hash
    document.addEventListener('click', function(e) {
      var a = e.target.closest('a');
      if (a) {
        var href = a.getAttribute('href');
        var activeToken = token || (function(){ try { return localStorage.getItem('mcats_auth_token'); }catch(e){ return null; } })();
        if (activeToken && href && (href.startsWith('/views/') || href.startsWith('/index') || href.startsWith('/api/') || href.startsWith('/logout')) && !href.includes('auth_token=')) {
          a.setAttribute('href', appendAuthToken(href, activeToken));
        }
      }
    }, true);
    // Global form submission listener to guarantee auth_token is attached
    document.addEventListener('submit', function(e) {
      var f = e.target;
      var activeToken = token || (function(){ try { return localStorage.getItem('mcats_auth_token'); }catch(e){ return null; } })();
      if (activeToken && !f.querySelector('input[name="auth_token"]')) {
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'auth_token';
        inp.value = activeToken;
        f.appendChild(inp);
      }
    }, true);
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

    if (req.path.startsWith('/api/') || req.xhr || (req.headers.accept && req.headers.accept.includes('application/json')) || (req.body && req.body.is_ajax)) {
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
    if (req.path.startsWith('/api/') || req.xhr || (req.headers.accept && req.headers.accept.includes('application/json')) || (req.body && req.body.is_ajax)) {
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
  let msg = req.session.req_msg || '';
  delete req.session.req_msg;

  const totalSales = db.getTotalSales();
  const todaySales = db.getTodaySales();
  const users = db.getAllUsers();
  const pendingRequests = db.getChangeRequests({ status: 'pending' });

  res.render('admin/sahome', {
    msg,
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
app.post('/api/db/reload', requireSuperAdmin, (req, res) => {
  req.session.db_flash = { type: 'warning', text: 'Database live reload from file has been deactivated for database integrity and security.' };
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
  const auth = getAuthUser(req);
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
  const editId = req.query.edit || req.query['amp;edit'];
  if (editId) {
    editData = db.getItemById(editId);
  }

  // Check for authorized change request grant
  let activeGrant = null;
  const grantId = req.query.grant_id || req.query['amp;grant_id'] || req.query.req_id || req.query['amp;req_id'];
  if (grantId) {
    const g = db.getChangeRequestById(grantId);
    if (g && g.status === 'approved') {
      activeGrant = g;
      if (!editData) {
        editData = db.getItemById(g.item_id);
      }
    } else if (g && (g.status === 'cancelled' || g.status === 'rejected')) {
      const typeLabel = g.type === 'quantity' ? 'Stock Quantity' : (g.type === 'daily_rate' ? 'Selling Price' : 'Cost Cipher');
      msg = `<div class='alert alert-warning d-flex align-items-center gap-2 shadow-sm mb-3'>` +
        `<i class='fa-solid fa-ban fs-5 text-danger'></i>` +
        `<span>Request #REQ-${String(g.id).padStart(3, '0')} was <strong>cancelled by Super Admin</strong>. Item ${typeLabel} remains unchanged at original value.</span>` +
        `</div>` + msg;
      if (!editData) {
        editData = db.getItemById(g.item_id);
      }
    }
  }

  const items = db.getAllInventory();

  res.render('admin/inventory', {
    msg,
    items,
    isSa: Boolean(auth && auth.role === 'super_admin'),
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
  const { item_id, item_name, category, price_per_unit, stock_quantity, status, existing_image, bought_price, item_image, item_image_data, grant_id, change_reason } = req.body;
  
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

  // Security check: if non-super_admin edits restricted fields without approved grant
  if (!isSa && isUpdate) {
    const existing = db.getItemById(item_id);
    if (existing) {
      const origStock = Number(existing.stock_quantity);
      const origPrice = Number(existing.price_per_unit);
      const origCode = String(existing.bought_price || '').trim();

      const stockDiff = finalStock !== origStock;
      const priceDiff = Math.abs(finalPrice - origPrice) > 0.001;
      const codeDiff = (String(finalBoughtPrice || '').trim() !== origCode);

      const unauthorized = [];
      if (stockDiff && (!activeGrant || activeGrant.type !== 'quantity')) {
        unauthorized.push({ type: 'quantity', requestedValue: finalStock, current: origStock, name: 'Stock Quantity' });
      }
      if (priceDiff && (!activeGrant || activeGrant.type !== 'daily_rate')) {
        unauthorized.push({ type: 'daily_rate', requestedValue: finalPrice, current: origPrice, name: 'Daily Rate' });
      }
      if (codeDiff && (!activeGrant || activeGrant.type !== 'cost_price')) {
        unauthorized.push({ type: 'cost_price', requestedValue: finalBoughtPrice || '', current: origCode, name: 'Cost Price/Cipher' });
      }

      if (unauthorized.length > 0) {
        // Revert unauthorized fields to existing baseline
        if (stockDiff && (!activeGrant || activeGrant.type !== 'quantity')) finalStock = origStock;
        if (priceDiff && (!activeGrant || activeGrant.type !== 'daily_rate')) finalPrice = origPrice;
        if (codeDiff && (!activeGrant || activeGrant.type !== 'cost_price')) finalBoughtPrice = existing.bought_price;

        // Auto-create categorized requests for the unauthorized edits
        const autoCreated = [];
        const reqReason = change_reason || 'Submitted via Item Edit Form';
        for (const unauth of unauthorized) {
          try {
            const cr = db.createChangeRequest({
              type: unauth.type,
              itemId: item_id,
              requestedValue: unauth.requestedValue,
              reason: reqReason,
              requestedBy: auth ? auth.username : 'admin'
            });
            autoCreated.push(cr);
          } catch (e) {
            console.error('[Inventory] Failed to auto-create request:', e);
          }
        }

        // Save authorized non-restricted fields (name, category, status, image, and any granted field)
        db.saveItem({
          id: item_id,
          name: item_name ? String(item_name).trim() : existing.item_name,
          category,
          price: finalPrice,
          stock: finalStock,
          status: status || existing.status,
          image: assignedImage,
          boughtPrice: finalBoughtPrice
        });

        if (activeGrant) {
          db.completeChangeRequest(activeGrant.id, auth ? auth.username : 'admin');
        }

        req.session.inv_msg = `<div class='alert alert-warning d-flex align-items-center justify-content-between gap-3 shadow-sm'>` +
          `<div class='d-flex align-items-center gap-2'>` +
          `<i class='fa-solid fa-lock fs-5 text-warning'></i> ` +
          `<span>Direct edit of restricted field(s) (${unauthorized.map(u => u.name).join(', ')}) requires Super Admin approval. <strong>${autoCreated.length} categorized request(s)</strong> have been forwarded to Super Admin. General details (Name/Image) saved.</span>` +
          `</div>` +
          `<a href='/views/admin/requests.php' class='btn btn-sm btn-warning text-nowrap'>View Requests</a>` +
          `</div>`;

        return res.redirect('/views/admin/inventory.php');
      }
    }
  }

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

// API: Submit Multiple/Categorized Change Requests from single Request Button
app.post('/api/requests/bulk', requireAuth, (req, res) => {
  const auth = getAuthUser(req);
  const { item_id, requests_json, reason, redirect_to } = req.body;
  const username = auth ? auth.username : 'admin';

  let requestsArray = [];
  try {
    requestsArray = typeof requests_json === 'string' ? JSON.parse(requests_json) : (requests_json || []);
  } catch (e) {
    requestsArray = [];
  }

  if (!Array.isArray(requestsArray) || requestsArray.length === 0) {
    req.session.inv_msg = `<div class='alert alert-warning'>No valid changes detected to submit.</div>`;
    return res.redirect(redirect_to || '/views/admin/inventory.php');
  }

  const created = [];
  const errors = [];

  for (const reqItem of requestsArray) {
    try {
      const newReq = db.createChangeRequest({
        type: reqItem.type,
        itemId: Number(item_id),
        requestedValue: String(reqItem.requested_value).trim(),
        reason: String(reason || '').trim(),
        requestedBy: username
      });
      created.push(newReq);
    } catch (err) {
      errors.push(err.message);
    }
  }

  if (created.length > 0) {
    const item = db.getItemById(item_id);
    const itemName = item ? item.item_name : 'Item';
    const typeNames = created.map(c => {
      if (c.type === 'quantity') return `Quantity (${c.requested_value})`;
      if (c.type === 'daily_rate') return `Daily Rate (Rs. ${c.requested_value})`;
      if (c.type === 'cost_price') return `Cost Code (${c.requested_value})`;
      return c.type;
    }).join(', ');

    req.session.inv_msg = `<div class='alert alert-success d-flex align-items-center justify-content-between gap-3 shadow-sm'>` +
      `<div class='d-flex align-items-center gap-2'>` +
      `<i class='fa-solid fa-circle-check fs-5 text-success'></i> ` +
      `<span>Submitted <strong>${created.length} categorized request(s)</strong> for <strong>"${itemName}"</strong> to Super Admin: ${typeNames}. Once approved, you can apply them.</span>` +
      `</div>` +
      `<a href='/views/admin/requests.php' class='btn btn-sm btn-outline-success text-nowrap'>View Requests</a>` +
      `</div>`;
  } else if (errors.length > 0) {
    req.session.inv_msg = `<div class='alert alert-danger'>Failed to submit requests: ${errors.join(', ')}</div>`;
  }

  res.redirect(redirect_to || '/views/admin/inventory.php');
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

// API: Super Admin Cancel / Reject Change Request
app.post(['/api/requests/cancel', '/api/requests/reject'], requireSuperAdmin, (req, res) => {
  const auth = getAuthUser(req);
  const { request_id, reason, notes, redirect_to } = req.body;
  const cancelReason = (reason || notes || 'Cancelled by Super Admin').trim();

  try {
    const { req: updated, item } = db.cancelChangeRequest(request_id, auth.username, cancelReason);
    const typeLabel = updated.type === 'quantity' ? 'Stock Quantity' : (updated.type === 'daily_rate' ? 'Daily Rate' : 'Cost Cipher');
    const origVal = item
      ? (updated.type === 'quantity' ? item.stock_quantity : (updated.type === 'daily_rate' ? `Rs. ${Number(item.price_per_unit).toFixed(2)}` : item.bought_price))
      : updated.current_value;

    req.session.req_msg = `<div class='alert alert-warning d-flex align-items-center justify-content-between gap-3 shadow-sm'>` +
      `<div class='d-flex align-items-center gap-2'>` +
      `<i class='fa-solid fa-ban fs-5 text-danger'></i> ` +
      `<span>Request #REQ-${String(updated.id).padStart(3, '0')} for <strong>"${updated.item_name}"</strong> was <b>cancelled</b> by Super Admin. Item ${typeLabel} strictly keeps its unchanged value: <strong>${origVal}</strong>.</span>` +
      `</div>` +
      `</div>`;
  } catch (err) {
    req.session.req_msg = `<div class='alert alert-danger'><b>Cancellation Failed:</b> ${err.message}</div>`;
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

    let subtotalLkr = 0;
    cartData.forEach(item => {
      subtotalLkr += Number(item.price || 0) * Number(item.qty || 1);
    });

    const rawDiscType = String(req.body.discount_type || 'none').toLowerCase();
    const discountType = ['percentage', 'fixed'].includes(rawDiscType) ? rawDiscType : 'none';
    let discountValue = parseFloat(req.body.discount_value) || 0;
    if (discountValue < 0) discountValue = 0;

    let discountAmount = 0;
    if (discountType === 'percentage') {
      if (discountValue > 100) discountValue = 100;
      discountAmount = Math.round((subtotalLkr * (discountValue / 100)) * 100) / 100;
    } else if (discountType === 'fixed') {
      discountAmount = Math.min(subtotalLkr, discountValue);
    } else {
      discountValue = 0;
      discountAmount = 0;
    }

    const netTotalLkr = Math.max(0, subtotalLkr - discountAmount);

    const receivedAmount = (req.body.received_amount !== undefined && req.body.received_amount !== '')
      ? parseFloat(req.body.received_amount)
      : netTotalLkr;
    const balanceAmount = receivedAmount - netTotalLkr;
    const freeEqText = (req.body.free_eq === 'yes') ? (String(req.body.free_eq_desc || '').trim() || 'Included Free Equipment') : null;

    const tx = db.createTransaction({
      sessionId: activeSession.id,
      customerNic: null,
      type: 'selling',
      subtotalLkr,
      discountType,
      discountValue,
      discountAmount,
      totalLkr: netTotalLkr,
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
  let payLaterTxs = db.transactions.filter(t => t.type === 'renting' && t.status === 'pay_later');

  if (dateFilter !== 'all') {
    rentals = rentals.filter(t => t.transaction_date && t.transaction_date.startsWith(dateFilter));
    payLaterTxs = payLaterTxs.filter(t => t.transaction_date && t.transaction_date.startsWith(dateFilter));
  }

  const populatedRentals = rentals.map(r => {
    const fullTx = db.getTransaction(r.bill_number);
    const customer = (fullTx && fullTx.customer) ? fullTx.customer : (r.customer_nic ? db.customers.find(c => c.nic_number === r.customer_nic) : null);
    return {
      ...(fullTx || r),
      c_name: customer ? customer.full_name : 'Walk-in Customer',
      c_phone: customer ? customer.phone_number : ''
    };
  }).sort((a, b) => b.bill_number - a.bill_number);

  const populatedPayLater = payLaterTxs.map(p => {
    const fullTx = db.getTransaction(p.bill_number);
    const customer = (fullTx && fullTx.customer) ? fullTx.customer : (p.customer_nic ? db.customers.find(c => c.nic_number === p.customer_nic) : null);
    const totalCharge = Number(p.total_lkr || 0);
    const totalReceived = Number(p.received_amount || 0);
    const pendingDue = Math.max(0, totalCharge - totalReceived);
    return {
      ...(fullTx || p),
      c_name: customer ? customer.full_name : 'Walk-in Customer',
      c_phone: customer ? customer.phone_number : '',
      c_nic: p.customer_nic || '',
      pending_due: pendingDue,
      paid_so_far: totalReceived
    };
  }).sort((a, b) => b.bill_number - a.bill_number);

  let filteredRentals = populatedRentals;
  let matchingPayLater = [];

  if (searchFilter) {
    const cleanSearch = searchFilter.replace(/^#/, '').toLowerCase().trim();
    filteredRentals = populatedRentals.filter(r => {
      const cName = String(r.c_name || '').toLowerCase();
      const cNic = String(r.customer_nic || '').toLowerCase();
      const cPhone = String(r.c_phone || '').toLowerCase();
      const notes = String(r.notes || '').toLowerCase();
      const billStr = String(r.bill_number);

      return billStr === cleanSearch || billStr.includes(cleanSearch) || cName.includes(cleanSearch) || cNic.includes(cleanSearch) || cPhone.includes(cleanSearch) || notes.includes(cleanSearch);
    });

    matchingPayLater = populatedPayLater.filter(p => {
      const cName = String(p.c_name || '').toLowerCase();
      const cNic = String(p.customer_nic || p.c_nic || '').toLowerCase();
      const cPhone = String(p.c_phone || '').toLowerCase();
      const notes = String(p.notes || '').toLowerCase();
      const billStr = String(p.bill_number);

      return billStr === cleanSearch || billStr.includes(cleanSearch) || cName.includes(cleanSearch) || cNic.includes(cleanSearch) || cPhone.includes(cleanSearch) || notes.includes(cleanSearch);
    });
  }

  res.render('pos/return_items', {
    rentals: filteredRentals,
    payLaterBills: matchingPayLater,
    allPayLaterTotal: populatedPayLater.length,
    dateFilter,
    searchFilter: req.query.search || '',
    paymentSuccess: req.query.payment_success || null
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
    msg: (trans.status === 'returned' || trans.status === 'pay_later') ? "<div class='alert alert-warning shadow-sm rounded-3 mt-3'><i class='fa-solid fa-triangle-exclamation me-2'></i>This rental has already been checked in (Status: " + trans.status + ").</div>" : ''
  });
});

app.post('/views/pos/return_checkout.php', requireAuth, (req, res) => {
  const billId = Number(req.query.bill_id || req.body.bill_id);
  const cashReceived = parseFloat(req.body.cash_received) || 0;
  const daysPerItem = req.body.days || {};
  const discount = parseFloat(req.body.discount) || 0;
  const isPayLater = req.body.is_pay_later === '1' || req.body.is_pay_later === 'true' || req.body.is_pay_later === 'on' || req.body.action_type === 'pay_later';
  const dueDate = req.body.pay_later_due_date || '';
  const payLaterNotes = req.body.pay_later_notes || '';

  db.processRentalReturn(billId, daysPerItem, cashReceived, {
    discount,
    isPayLater,
    dueDate,
    payLaterNotes
  });

  if (isPayLater) {
    return res.redirect(`/views/pos/print_bill.php?type=thermal_pay_later&bill_id=${billId}`);
  }
  res.redirect(`/views/pos/print_bill.php?type=a4&bill_id=${billId}`);
});

// Dedicated Pay Later Directory
app.get('/views/pos/pay_later.php', requireAuth, (req, res) => {
  const dateFilter = req.query.date || 'all';
  const rawSearch = String(req.query.search || '').trim();
  const rawBill = String(req.query.bill_no || '').trim();
  const cleanSearch = rawSearch.replace(/^#/, '').toLowerCase().trim();
  const cleanBill = rawBill.replace(/^#/, '').toLowerCase().trim();
  const statusFilter = req.query.status || 'pending'; // 'pending', 'settled', 'all'

  // Retrieve transactions that are either active pay_later or have pay_later history
  let allPayLater = db.transactions.filter(t => t.type === 'renting' && (t.status === 'pay_later' || (t.notes && t.notes.includes('Pay Later'))));

  if (dateFilter !== 'all') {
    allPayLater = allPayLater.filter(t => t.transaction_date && t.transaction_date.startsWith(dateFilter));
  }

  const populated = allPayLater.map(p => {
    const fullTx = db.getTransaction(p.bill_number);
    const customer = (fullTx && fullTx.customer) ? fullTx.customer : (p.customer_nic ? db.customers.find(c => c.nic_number === p.customer_nic) : null);
    const totalCharge = Number(p.total_lkr || 0);
    const totalReceived = Number(p.received_amount || 0);
    const pendingDue = Math.max(0, totalCharge - totalReceived);
    const isSettled = pendingDue <= 0.001 || p.status === 'returned';

    return {
      ...(fullTx || p),
      c_name: customer ? customer.full_name : 'Walk-in Customer',
      c_phone: customer ? customer.phone_number : '',
      c_nic: p.customer_nic || '',
      c_address: customer ? customer.address : '',
      total_charge: totalCharge,
      advance_paid: Number(p.advance_paid || 0),
      total_paid: totalReceived,
      pending_due: pendingDue,
      is_settled: isSettled
    };
  }).sort((a, b) => b.bill_number - a.bill_number);

  // Apply Status Filter
  let filtered = populated;
  if (statusFilter === 'pending') {
    filtered = populated.filter(p => !p.is_settled);
  } else if (statusFilter === 'settled') {
    filtered = populated.filter(p => p.is_settled);
  }

  // Apply Dedicated Unique Bill ID Filter (e.g. #12345 or 12345)
  if (cleanBill) {
    filtered = filtered.filter(p => {
      const billStr = String(p.bill_number);
      return billStr === cleanBill || billStr.includes(cleanBill);
    });
  }

  // Apply General Search Filter (Supports #12345, customer name, NIC, phone)
  if (cleanSearch) {
    filtered = filtered.filter(p => {
      const cName = String(p.c_name || '').toLowerCase();
      const cNic = String(p.c_nic || '').toLowerCase();
      const cPhone = String(p.c_phone || '').toLowerCase();
      const notes = String(p.notes || '').toLowerCase();
      const billStr = String(p.bill_number);

      return billStr === cleanSearch || billStr.includes(cleanSearch) || cName.includes(cleanSearch) || cNic.includes(cleanSearch) || cPhone.includes(cleanSearch) || notes.includes(cleanSearch);
    });
  }

  const totalOutstanding = populated.filter(p => !p.is_settled).reduce((sum, p) => sum + p.pending_due, 0);
  const pendingCount = populated.filter(p => !p.is_settled).length;
  const settledCount = populated.filter(p => p.is_settled).length;

  res.render('pos/pay_later', {
    bills: filtered,
    totalOutstanding,
    pendingCount,
    settledCount,
    totalCount: populated.length,
    statusFilter,
    dateFilter,
    searchFilter: rawSearch,
    billFilter: rawBill,
    paymentSuccess: req.query.payment_success || null,
    paidBillId: req.query.bill_id || null,
    paidAmount: req.query.paid || null,
    returnedPayLater: req.query.pay_later || null
  });
});

// Process Pay Later Settlement Collection
app.post('/views/pos/pay_later_settle.php', requireAuth, (req, res) => {
  const billId = Number(req.body.bill_id);
  const paymentAmount = parseFloat(req.body.payment_amount) || 0;
  const paymentMethod = req.body.payment_method || 'Cash';
  const paymentNotes = req.body.payment_notes || '';
  const redirectTo = req.body.redirect_to || '';

  let updatedTx = null;
  if (billId && paymentAmount > 0) {
    updatedTx = db.recordPayLaterPayment(billId, paymentAmount, paymentMethod, paymentNotes);
  } else {
    updatedTx = db.getTransaction(billId);
  }

  const isFullyPaid = updatedTx ? (Number(updatedTx.received_amount || 0) >= Number(updatedTx.total_lkr || 0)) : false;

  // If redirect specifically requested (and not A4 print), redirect there
  if (redirectTo && !redirectTo.includes('print_bill')) {
    const separator = redirectTo.includes('?') ? '&' : '?';
    return res.redirect(`${redirectTo}${separator}payment_success=1&bill_id=${billId}&paid=${paymentAmount}&paid_all=${isFullyPaid ? 1 : 0}`);
  }

  // Open A4 bill in new page with full details; if fully paid, auto-print triggers
  return res.redirect(`/views/pos/print_bill.php?type=a4&bill_id=${billId}&payment_success=1&paid=${paymentAmount}&paid_all=${isFullyPaid ? 1 : 0}`);
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

  const totalCharge = Number(txn.total_lkr || 0);
  const totalReceived = Number(txn.received_amount || 0);
  const pendingDue = Math.max(0, totalCharge - totalReceived);
  const paidAll = req.query.paid_all === '1' || (req.query.payment_success === '1' && pendingDue <= 0.001);

  res.render('pos/print_bill', {
    txn,
    customer,
    items: txn.items,
    type,
    billId,
    dateFormatted,
    pendingDue,
    paidAll,
    paymentSuccess: req.query.payment_success || null,
    paidAmount: req.query.paid || null,
    collect: req.query.collect || null
  });
});

// -------------------------------------------------------------
// Fallback 404 & Error Handler
// -------------------------------------------------------------
app.use((req, res, next) => {
  res.status(404).redirect('/views/index.php');
});

// Global error handling middleware
app.use((err, req, res, next) => {
  console.error('Unhandled server error:', err);
  if (req.xhr || (req.headers.accept && req.headers.accept.includes('application/json')) || req.path.startsWith('/api/')) {
    return res.status(500).json({ error: 'Internal Server Error', message: err.message });
  }
  res.status(500).send(`
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <title>MCATS | Server Error</title>
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
      <link rel="stylesheet" href="/css/luxury.css">
    </head>
    <body class="d-flex align-items-center justify-content-center min-vh-100 p-4" style="background: #070a12; color: #fff;">
      <div class="card p-5 border-0 shadow-lg text-center" style="max-width: 480px; background: #0b1120; border: 1px solid rgba(255,255,255,0.1) !important; border-radius: 1rem;">
        <h3 class="text-warning mb-3 fw-bold">System Notice</h3>
        <p class="text-white-50 small mb-4">${err.message || 'An unexpected operational error occurred.'}</p>
        <a href="/views/index.php" class="btn btn-luxury-gold fw-bold px-4 py-2 rounded-pill">Return to Dashboard</a>
      </div>
    </body>
    </html>
  `);
});

// Start Server
app.listen(PORT, '0.0.0.0', () => {
  console.log(`MCATS POS Server running on http://0.0.0.0:${PORT}`);
});
