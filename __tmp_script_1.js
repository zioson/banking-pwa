 blocks.
     'unsafe-eval' has been REMOVED — dispatchSearchAction() uses a whitelist dispatch table, NOT eval/Function.
     frame-ancestors and strict-transport-security MUST be set via HTTP headers (see backend/router.php)
     since browsers ignore these directives when delivered via <meta> elements. -->
<meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline' blob: https://cdn.sheetjs.com https://unpkg.com https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data: blob: https:; font-src 'self' https://fonts.gstatic.com; connect-src 'self' https:; object-src 'none'; base-uri 'self'; form-action 'self';">
<meta http-equiv="X-Content-Type-Options" content="nosniff">
<meta name="referrer" content="strict-origin-when-cross-origin">
<title>Atlas Bank Enterprise Operations Console</title>
<style>
/* ========== CSS CUSTOM PROPERTIES ========== */
:root{
  --bg:#070e18;--bg2:#0b1628;--panel:#0f1d31;--panel2:#142440;
  --line:rgba(156,175,201,.14);--line2:rgba(156,175,201,.22);
  --text:#edf4ff;--text2:#d3e2fa;--muted:#8a9fc0;--soft:#6b82a3;
  --primary:#58b7ff;--primary-dim:rgba(88,183,255,.12);
  --accent:#67e8b5;--accent-dim:rgba(103,232,181,.12);
  --warn:#ffbe69;--warn-dim:rgba(255,190,105,.12);
  --danger:#ff6b86;--danger-dim:rgba(255,107,134,.12);
  --success:#4fd79f;--success-dim:rgba(79,215,159,.12);
  --shadow:0 20px 60px rgba(0,0,0,.35);
  --radius:16px;--radius-lg:22px;
  --side:272px;--header:60px;
  --font:Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
  --z-modal:100;--z-toast:200;--z-dropdown:90;
}

/* ========== RESET & BASE ========== */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth;-webkit-text-size-adjust:100%}
body{font-family:var(--font);background:var(--bg);color:var(--text);line-height:1.6;overflow-x:hidden}
a{color:inherit;text-decoration:none}
button,input,select,textarea{font:inherit;color:inherit}
input,select,textarea{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:10px 14px;outline:none;transition:.2s}
input:focus,select:focus,textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-dim)}
input::placeholder{color:var(--soft)}
select{cursor:pointer;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%238a9fc0' viewBox='0 0 16 16'%3E%3Cpath d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;padding-right:32px}
button{cursor:pointer;border:none;background:none}
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--line2);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:var(--muted)}

/* ========== LOGIN SCREEN ========== */
.login-screen{display:none;align-items:center;justify-content:center;min-height:100vh;background:radial-gradient(ellipse at 20% 50%,rgba(88,183,255,.08),transparent 60%),radial-gradient(ellipse at 80% 20%,rgba(103,232,181,.06),transparent 50%),var(--bg)}
.login-card{width:100%;max-width:420px;padding:40px;border-radius:var(--radius-lg);border:1px solid var(--line);background:linear-gradient(180deg,var(--panel),var(--bg2));box-shadow:var(--shadow)}
.login-card .brand-mark{width:56px;height:56px;border-radius:18px;display:grid;place-items:center;background:linear-gradient(135deg,var(--primary),var(--accent));color:var(--bg);font-weight:900;font-size:1.3rem;margin-bottom:24px}
.login-card h1{font-size:1.3rem;margin-bottom:4px}
.login-card p.subtitle{color:var(--muted);font-size:.88rem;margin-bottom:28px}
.form-group{margin-bottom:18px}
.form-group label{display:block;font-size:.78rem;color:var(--soft);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px}
.form-group input{width:100%}
.form-error{color:var(--danger);font-size:.82rem;margin-top:6px;display:none}
.form-error.show{display:block}
.login-card .login-btn{width:100%;padding:13px;border-radius:12px;font-weight:700;font-size:.95rem;color:var(--bg);background:linear-gradient(135deg,var(--accent),#a9ffe1);margin-top:8px;transition:.2s}
.login-card .login-btn:hover{opacity:.9;transform:translateY(-1px)}
.login-card .login-btn:disabled{opacity:.5;cursor:not-allowed;transform:none}
.login-footer{margin-top:20px;text-align:center;color:var(--muted);font-size:.82rem}
.hidden{display:none!important}

/* ========== APP SHELL ========== */
.app-shell{display:grid;grid-template-columns:var(--side) 1fr;grid-template-rows:var(--header) 1fr;min-height:100vh}
.app-shell.no-sidebar{grid-template-columns:1fr}

/* ========== SIDEBAR ========== */
.sidebar{grid-row:1/3;padding:18px 14px;border-right:1px solid var(--line);background:linear-gradient(180deg,rgba(11,22,40,.97),rgba(7,14,24,.9));backdrop-filter:blur(20px);overflow-y:auto;display:flex;flex-direction:column;position:sticky;top:0;height:100vh}
.sidebar-brand{display:flex;gap:12px;align-items:center;padding:8px 8px 18px;border-bottom:1px solid var(--line);margin-bottom:14px}
.sidebar-brand .mark{width:42px;height:42px;border-radius:14px;display:grid;place-items:center;background:linear-gradient(135deg,var(--primary),var(--accent));color:var(--bg);font-weight:900;font-size:1.1rem;flex-shrink:0}
.sidebar-brand h1{font-size:.92rem;line-height:1.3}
.sidebar-brand small{color:var(--muted);font-size:.76rem}
.sidebar-section{margin-bottom:8px}
.sidebar-section-title{padding:10px 12px 6px;font-size:.68rem;color:var(--soft);letter-spacing:.14em;text-transform:uppercase}
.nav-link{display:flex;gap:10px;align-items:center;padding:10px 12px;border-radius:12px;color:var(--muted);font-size:.88rem;font-weight:500;transition:.15s;border:1px solid transparent;margin-bottom:2px;position:relative}
.nav-link:hover{background:rgba(255,255,255,.04);color:var(--text2)}
.nav-link.active{background:var(--primary-dim);color:var(--primary);border-color:rgba(88,183,255,.15)}
.nav-link .nav-icon{width:32px;height:32px;border-radius:10px;background:rgba(255,255,255,.04);display:grid;place-items:center;font-size:.9rem;flex-shrink:0}
.nav-link .nav-badge{margin-left:auto;background:var(--danger);color:#fff;font-size:.68rem;font-weight:700;padding:2px 7px;border-radius:999px}
.sidebar-footer{margin-top:auto;padding-top:14px;border-top:1px solid var(--line)}
.sidebar-footer .mini-stat{display:flex;justify-content:space-between;padding:8px 10px;font-size:.8rem;color:var(--muted);border-radius:10px}
.sidebar-footer .mini-stat strong{color:var(--text2)}
.sidebar-collapse-btn{display:none;position:fixed;top:12px;left:12px;z-index:80;width:40px;height:40px;border-radius:12px;background:var(--panel);border:1px solid var(--line);color:var(--text);font-size:1.2rem}

/* ========== HEADER ========== */
.header{padding:0 24px;display:flex;align-items:center;gap:16px;border-bottom:1px solid var(--line);background:rgba(11,22,40,.9);backdrop-filter:blur(16px);position:sticky;top:0;z-index:50}
.header-breadcrumb{display:flex;align-items:center;gap:6px;font-size:.84rem;color:var(--muted)}
.header-breadcrumb span.bc-sep{color:var(--soft)}
.header-breadcrumb span.bc-current{color:var(--text2)}
.header-search{flex:1;max-width:480px;margin:0 auto;display:flex;align-items:center;gap:10px;padding:9px 14px;border-radius:12px;border:1px solid var(--line);background:rgba(15,29,49,.6)}
.header-search input{flex:1;border:none;background:transparent;padding:0;font-size:.88rem}
.header-search input::placeholder{color:var(--soft)}
.header-actions{display:flex;align-items:center;gap:8px}
.hdr-btn{width:38px;height:38px;border-radius:12px;display:grid;place-items:center;color:var(--muted);transition:.15s;position:relative}
.hdr-btn:hover{background:rgba(255,255,255,.06);color:var(--text)}
.hdr-btn .badge-dot{position:absolute;top:7px;right:7px;width:8px;height:8px;border-radius:50%;background:var(--danger);border:2px solid var(--bg2)}
.branch-selector{display:flex;align-items:center;gap:6px;padding:8px 12px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.03);font-size:.82rem;color:var(--muted);cursor:pointer;position:relative}
.branch-selector:hover{background:rgba(255,255,255,.06)}
.branch-selector strong{color:var(--text2)}
.branch-selector .branch-dropdown{display:none;position:absolute;top:calc(100% + 6px);left:0;min-width:240px;max-height:320px;overflow-y:auto;background:var(--panel);border:1px solid var(--line2);border-radius:var(--radius);box-shadow:var(--shadow);z-index:var(--z-dropdown);padding:6px}
.branch-selector .branch-dropdown.open{display:block}
.branch-dropdown-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;color:var(--muted);font-size:.84rem;cursor:pointer;transition:.15s}
.branch-dropdown-item:hover{background:rgba(255,255,255,.06);color:var(--text2)}
.branch-dropdown-item.active{background:var(--primary-dim);color:var(--primary);font-weight:600}
.branch-dropdown-item.active::before{content:'\2713';font-size:.7rem;margin-right:2px}
.branch-dropdown-item .bdi-icon{width:28px;height:28px;border-radius:8px;display:grid;place-items:center;font-size:.85rem;flex-shrink:0;background:rgba(255,255,255,.04)}
.branch-dropdown-item .bdi-info{line-height:1.3}
.branch-dropdown-item .bdi-info small{display:block;font-size:.72rem;color:var(--soft)}
.branch-locked .branch-chevron-hide{display:none}
.session-timer{font-size:.76rem;color:var(--soft);white-space:nowrap}
.user-menu{display:flex;align-items:center;gap:8px;padding:6px 12px 6px 6px;border-radius:12px;cursor:pointer;transition:.15s}
.user-menu:hover{background:rgba(255,255,255,.05)}
.user-avatar{width:32px;height:32px;border-radius:10px;background:linear-gradient(135deg,var(--primary),var(--accent));display:grid;place-items:center;color:var(--bg);font-weight:800;font-size:.82rem;overflow:hidden;position:relative;background-size:cover;background-position:center}
.prof-avatar-wrap{position:relative;width:64px;height:64px;flex-shrink:0;cursor:pointer}
.prof-avatar-overlay{position:absolute;inset:0;border-radius:50%;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .2s;z-index:2;cursor:pointer}
.prof-avatar-wrap:hover .prof-avatar-overlay{opacity:1}
.prof-avatar-overlay svg{color:#fff;pointer-events:none}
.user-menu-info{line-height:1.3}
.user-menu-info .name{font-size:.82rem;font-weight:600}
.user-menu-info .role{font-size:.72rem;color:var(--muted)}

/* ========== MAIN CONTENT ========== */
.main-content{padding:24px;overflow-y:auto}
.view{display:none}
.view.active{display:block}
.view-header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:20px}
.view-header h2{font-size:1.3rem}
.view-header p{color:var(--muted);font-size:.88rem;margin-top:4px;max-width:60ch}

/* ========== BUTTONS ========== */
.btn{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border-radius:11px;font-weight:600;font-size:.85rem;transition:.15s;white-space:nowrap}
.btn-primary{background:linear-gradient(135deg,var(--accent),#a9ffe1);color:var(--bg)}
.btn-primary:hover{opacity:.9}
.btn-secondary{background:rgba(255,255,255,.07);border:1px solid var(--line)}
.btn-secondary:hover{background:rgba(255,255,255,.1)}
.btn-danger{background:var(--danger-dim);border:1px solid rgba(255,107,134,.2);color:var(--danger)}
.btn-danger:hover{background:rgba(255,107,134,.2)}
.btn-warn{background:var(--warn-dim);border:1px solid rgba(255,190,105,.2);color:var(--warn)}
.btn-warn:hover{background:rgba(255,190,105,.2)}
.btn-ghost{color:var(--muted);padding:8px 10px}
.btn-ghost:hover{color:var(--text);background:rgba(255,255,255,.05)}
.btn-sm{padding:6px 12px;font-size:.8rem;border-radius:9px}
.btn-icon{width:36px;height:36px;padding:0;justify-content:center;border-radius:10px}

/* ========== CARDS & PANELS ========== */
.card{padding:20px;border-radius:var(--radius);border:1px solid var(--line);background:linear-gradient(180deg,rgba(15,29,49,.9),rgba(11,20,34,.85));box-shadow:var(--shadow)}
.card-header{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:16px}
.card-header h3{font-size:1.05rem}
.card-header p{color:var(--muted);font-size:.86rem;margin-top:3px;line-height:1.5}
.card-grid{display:grid;gap:18px}
.card-grid-2{grid-template-columns:repeat(2,1fr)}
.card-grid-3{grid-template-columns:repeat(3,1fr)}
.card-grid-4{grid-template-columns:repeat(4,1fr)}

/* ========== KPI CARDS ========== */
.kpi-card{padding:18px;border-radius:var(--radius);border:1px solid var(--line);background:rgba(255,255,255,.03)}
.kpi-card .kpi-label{display:block;font-size:.74rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em}
.kpi-card .kpi-value{display:block;margin-top:6px;font-size:1.6rem;font-weight:800}
.kpi-card .kpi-sub{display:block;margin-top:6px;font-size:.82rem;color:var(--muted)}
.kpi-card .kpi-change{display:inline-flex;align-items:center;gap:4px;font-size:.78rem;font-weight:600;margin-top:4px}
.kpi-up{color:var(--success)}
.kpi-down{color:var(--danger)}

/* ========== STATUS PILLS ========== */
.pill{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:999px;font-size:.76rem;font-weight:600}
.pill-pending{background:var(--primary-dim);color:var(--primary)}
.pill-approved,.pill-posted,.pill-active{background:var(--success-dim);color:var(--success)}
.pill-rejected,.pill-failed,.pill-denied,.pill-restricted{background:var(--danger-dim);color:var(--danger)}
.pill-review,.pill-delinquent,.pill-delinquency,.pill-warning{background:var(--warn-dim);color:var(--warn)}
.pill-absorbed{background:rgba(120,120,140,.12);color:#888;font-style:italic}
.pill-low{background:var(--success-dim);color:var(--success)}
.pill-medium{background:var(--warn-dim);color:var(--warn)}
.pill-high{background:var(--danger-dim);color:var(--danger)}
.pill-salary{background:rgba(88,183,255,.12);color:#58b7ff}
.pill-current{background:rgba(103,232,181,.12);color:#67e8b5}
.pill-savings{background:rgba(255,190,105,.12);color:#ffbe69}
.pill-frozen{background:rgba(120,140,255,.12);color:#8a9cff}
.pill-dormant{background:rgba(255,255,255,.06);color:var(--muted)}

/* ========== TABLES ========== */
.table-container{overflow:auto;border:1px solid var(--line);border-radius:var(--radius)}
table{width:100%;border-collapse:collapse;min-width:900px}
thead th{position:sticky;top:0;background:var(--panel);color:var(--text2);text-align:left;padding:12px 14px;font-size:.76rem;text-transform:uppercase;letter-spacing:.06em;font-weight:600;border-bottom:1px solid var(--line2);white-space:nowrap}
tbody td{padding:12px 14px;border-bottom:1px solid var(--line);font-size:.86rem;vertical-align:top}
tbody tr{transition:.1s}
tbody tr:hover{background:rgba(255,255,255,.03)}
.td-sub{display:block;color:var(--muted);font-size:.78rem;margin-top:2px}
.td-mono{font-family:'Geist Mono',monospace;font-size:.82rem}
.td-credit{color:var(--success);font-weight:700}
.td-debit{color:var(--danger);font-weight:700}
.td-actions{display:flex;gap:4px}
.empty-state{padding:48px 24px;text-align:center;color:var(--muted)}
.empty-state p{font-size:.9rem;margin-top:8px}

/* ========== FILTERS ========== */
.filters-bar{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px;align-items:flex-end}
.filter-field{display:grid;gap:4px}
.filter-field label{font-size:.72rem;color:var(--soft);text-transform:uppercase;letter-spacing:.06em}
.filter-field input,.filter-field select{padding:8px 12px;font-size:.84rem;min-width:140px}

/* ========== PAGINATION ========== */
.pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:14px;flex-wrap:wrap}
.pagination-info{font-size:.84rem;color:var(--muted)}
.pagination-btns{display:flex;gap:4px}
.pagination-btns button{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;font-size:.82rem;color:var(--muted);border:1px solid var(--line);transition:.15s}
.pagination-btns button:hover{background:rgba(255,255,255,.06);color:var(--text)}
.pagination-btns button.active{background:var(--primary-dim);color:var(--primary);border-color:rgba(88,183,255,.2)}
.pagination-btns button:disabled{opacity:.3;cursor:not-allowed}

/* ========== DETAIL DRAWER ========== */
.drawer-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:var(--z-modal);opacity:0;pointer-events:none;transition:.2s}
.drawer-overlay.open{opacity:1;pointer-events:auto}
.drawer{position:fixed;top:0;right:-520px;width:520px;max-width:100vw;height:100vh;background:var(--bg2);border-left:1px solid var(--line);z-index:calc(var(--z-modal) + 1);transition:.3s;overflow-y:auto;box-shadow:-8px 0 40px rgba(0,0,0,.3)}
.drawer.open{right:0}
.drawer-header{position:sticky;top:0;background:var(--bg2);padding:18px 20px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;z-index:1}
.drawer-header h3{font-size:1.05rem}
.drawer-body{padding:20px}
.drawer-section{margin-bottom:24px}
.drawer-section h4{font-size:.82rem;color:var(--soft);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px}
.detail-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--line);font-size:.88rem}
.detail-row .label{color:var(--muted)}
.detail-row .value{font-weight:600;text-align:right;max-width:60%}
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media (max-width: 600px) { .detail-grid { grid-template-columns: 1fr; } }
.detail-grid .detail-item{padding:12px;border-radius:12px;background:rgba(255,255,255,.03);border:1px solid var(--line)}
.detail-grid .detail-item .di-label{font-size:.72rem;color:var(--soft);text-transform:uppercase;letter-spacing:.06em}
.detail-grid .detail-item .di-value{font-size:1rem;font-weight:700;margin-top:4px}

/* ========== MODAL ========== */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:var(--z-modal);display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:.2s}
.modal-overlay.open{opacity:1;pointer-events:auto}
.modal{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius-lg);padding:28px;width:90%;max-width:520px;max-height:90vh;overflow-y:auto;box-shadow:var(--shadow)}
.modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.modal-header h3{font-size:1.1rem}
.modal-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:24px;padding-top:16px;border-top:1px solid var(--line)}

/* ========== TOAST ========== */
.toast-container{position:fixed;top:16px;right:16px;z-index:var(--z-toast);display:flex;flex-direction:column;gap:8px;pointer-events:none}
.toast{padding:14px 18px;border-radius:12px;border:1px solid var(--line);background:var(--panel);box-shadow:var(--shadow);display:flex;align-items:flex-start;gap:10px;min-width:320px;max-width:440px;pointer-events:auto;animation:slideIn .3s ease}
.toast.toast-success{border-left:3px solid var(--success)}
.toast.toast-error{border-left:3px solid var(--danger)}
.toast.toast-warning{border-left:3px solid var(--warn)}
.toast.toast-info{border-left:3px solid var(--primary)}
.toast-icon{font-size:1.1rem;flex-shrink:0;margin-top:1px}
.toast-body{flex:1}
.toast-title{font-size:.86rem;font-weight:600}
.toast-msg{font-size:.8rem;color:var(--muted);margin-top:2px}
.toast-close{color:var(--soft);font-size:.9rem;flex-shrink:0;cursor:pointer}
@keyframes slideIn{from{opacity:0;transform:translateX(40px)}to{opacity:1;transform:translateX(0)}}
@keyframes slideOut{from{opacity:1;transform:translateX(0)}to{opacity:0;transform:translateX(40px)}}

/* ========== NOTIFICATION DROPDOWN ========== */
.notif-dropdown{position:fixed;top:var(--header);right:60px;width:380px;max-height:480px;background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);z-index:var(--z-dropdown);overflow:hidden;opacity:0;pointer-events:none;transform:translateY(-8px);transition:.2s}
.notif-dropdown.open{opacity:1;pointer-events:auto;transform:translateY(0)}
.notif-dropdown-header{padding:14px 16px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center}
.notif-dropdown-header h4{font-size:.92rem}
.notif-list{overflow-y:auto;max-height:380px}
.notif-item{padding:12px 16px;border-bottom:1px solid var(--line);cursor:pointer;transition:.1s}
.notif-item:hover{background:rgba(255,255,255,.03)}
.notif-item.unread{background:rgba(88,183,255,.04)}
.notif-item .ni-title{font-size:.86rem;font-weight:600;margin-bottom:2px}
.notif-item .ni-body{font-size:.8rem;color:var(--muted);line-height:1.45}
.notif-item .ni-time{font-size:.72rem;color:var(--soft);margin-top:4px}

/* ========== USER DROPDOWN ========== */
.user-dropdown{position:fixed;top:var(--header);right:16px;width:260px;background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);z-index:var(--z-dropdown);opacity:0;pointer-events:none;transform:translateY(-8px);transition:.2s}
.user-dropdown.open{opacity:1;pointer-events:auto;transform:translateY(0)}
.user-dropdown-header{padding:16px;border-bottom:1px solid var(--line);display:flex;gap:12px;align-items:center}
.user-dropdown-header .ud-avatar{width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,var(--primary),var(--accent));display:grid;place-items:center;color:var(--bg);font-weight:800;overflow:hidden;position:relative;background-size:cover;background-position:center}
.user-dropdown-header .ud-name{font-weight:600;font-size:.9rem}
.user-dropdown-header .ud-role{font-size:.78rem;color:var(--muted)}
.user-dropdown-menu{padding:6px}
.user-dropdown-menu button{display:flex;align-items:center;gap:10px;width:100%;padding:9px 12px;border-radius:10px;font-size:.86rem;color:var(--muted);transition:.1s;text-align:left}
.user-dropdown-menu button:hover{background:rgba(255,255,255,.05);color:var(--text)}

/* ========== CHARTS ========== */
.chart-wrap{padding:16px;border-radius:var(--radius);border:1px solid var(--line);background:rgba(255,255,255,.02)}
.chart-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.chart-header h4{font-size:.92rem}
.chart-header .chart-note{font-size:.8rem;color:var(--muted)}
canvas{width:100%;height:auto;display:block}
.legend{display:flex;gap:16px;margin-top:10px;flex-wrap:wrap}
.legend span{display:inline-flex;align-items:center;gap:6px;font-size:.8rem;color:var(--muted)}
.legend .sw{width:10px;height:10px;border-radius:50%;flex-shrink:0}

/* ========== TIMELINE ========== */
.timeline{position:relative;padding-left:24px}
.timeline::before{content:'';position:absolute;left:8px;top:4px;bottom:4px;width:2px;background:var(--line)}
.timeline-item{position:relative;padding-bottom:18px}
.timeline-item::before{content:'';position:absolute;left:-20px;top:6px;width:12px;height:12px;border-radius:50%;border:2px solid var(--primary);background:var(--bg2)}
.timeline-item .tl-title{font-size:.86rem;font-weight:600}
.timeline-item .tl-body{font-size:.82rem;color:var(--muted);margin-top:2px}
.timeline-item .tl-time{font-size:.74rem;color:var(--soft);margin-top:4px}

/* ========== PROGRESS BAR ========== */
.progress-bar{height:6px;background:rgba(255,255,255,.06);border-radius:3px;overflow:hidden}
.progress-bar .progress-fill{height:100%;border-radius:3px;transition:.3s}

/* ========== CHECKLIST ========== */
.checklist{display:grid;gap:8px}
.check-item{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.02);font-size:.86rem}
.check-item .check-icon{width:24px;height:24px;border-radius:8px;display:grid;place-items:center;font-size:.75rem;flex-shrink:0}
.check-pass{background:var(--success-dim);color:var(--success)}
.check-fail{background:var(--danger-dim);color:var(--danger)}
.check-pending{background:var(--primary-dim);color:var(--primary)}
.check-waived{background:var(--warn-dim);color:var(--warn)}

/* ========== STAT BLOCKS ========== */
.stat-row{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px}
.stat-block{padding:14px 18px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.03);min-width:160px;flex:1}
.stat-block .sb-label{font-size:.76rem;color:var(--soft);text-transform:uppercase;letter-spacing:.06em}
.stat-block .sb-value{font-size:1.3rem;font-weight:800;margin-top:4px}

/* ========== TABS ========== */
.tabs{display:flex;gap:2px;border-bottom:1px solid var(--line);margin-bottom:18px}
.tab-btn{padding:10px 18px;font-size:.86rem;color:var(--muted);font-weight:500;border-bottom:2px solid transparent;transition:.15s}
.tab-btn:hover{color:var(--text2)}
.tab-btn.active{color:var(--primary);border-bottom-color:var(--primary)}

/* ========== SKELETON ========== */
.skeleton{background:linear-gradient(90deg,rgba(255,255,255,.04) 25%,rgba(255,255,255,.08) 50%,rgba(255,255,255,.04) 75%);background-size:200% 100%;animation:shimmer 1.5s infinite;border-radius:8px}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
.skeleton-text{height:14px;margin-bottom:8px}
.skeleton-text.w-3{width:75%}
.skeleton-text.w-2{width:50%}
.skeleton-heading{height:22px;width:40%;margin-bottom:14px}
.skeleton-card{height:100px;border-radius:var(--radius)}

/* ========== LOADING SPINNER ========== */
.spinner{width:20px;height:20px;border:2px solid var(--line);border-top-color:var(--primary);border-radius:50%;animation:spin .6s linear infinite;display:inline-block}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes pulse-dot{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.7)}}

/* ========== SUMMARY BAR ========== */
.summary-bar{display:flex;gap:10px;flex-wrap:wrap;margin:12px 0 16px}
.summary-item{padding:8px 14px;border-radius:10px;background:rgba(255,255,255,.03);border:1px solid var(--line);font-size:.82rem;color:var(--muted)}
.summary-item strong{color:var(--text2)}

/* ========== RESPONSIVE ========== */
@media(max-width:1200px){
  .card-grid-3,.card-grid-4{grid-template-columns:repeat(2,1fr)}
  .drawer{width:440px}
}
@media(max-width:1024px){
  .app-shell{grid-template-columns:1fr}
  .sidebar{position:fixed;left:-280px;z-index:70;transition:.3s;width:var(--side);box-shadow:4px 0 30px rgba(0,0,0,.4)}
  .sidebar.mobile-open{left:0}
  .sidebar-collapse-btn{display:grid}
  .drawer{width:100%}
  .notif-dropdown{right:8px;width:calc(100vw - 16px)}
}
@media(max-width:768px){
  .main-content{padding:16px}
  .card-grid-2,.card-grid-3,.card-grid-4{grid-template-columns:1fr}
  .header-search{display:none}
  .stat-row{flex-direction:column}
  .filters-bar{flex-direction:column}
  .filter-field input,.filter-field select{min-width:100%}
  .view-header{flex-direction:column}
}

/* ========== DEPOSIT / WITHDRAW ========== */
.txn-type-selector{display:flex;gap:6px;margin-bottom:14px}
.txn-type-btn{flex:1;padding:12px;border-radius:12px;text-align:center;font-weight:700;font-size:.9rem;border:2px solid var(--line);background:rgba(255,255,255,.03);color:var(--muted);transition:.15s;cursor:pointer}
.txn-type-btn:hover{background:rgba(255,255,255,.06);color:var(--text)}
.txn-type-btn.active-deposit{border-color:var(--success);background:var(--success-dim);color:var(--success)}
.txn-type-btn.active-withdraw{border-color:var(--danger);background:var(--danger-dim);color:var(--danger)}
.balance-preview{padding:14px;border-radius:12px;background:rgba(255,255,255,.04);border:1px solid var(--line);margin-bottom:14px}
.balance-preview .bp-label{font-size:.72rem;color:var(--soft);text-transform:uppercase;letter-spacing:.06em}
.balance-preview .bp-value{font-size:1.3rem;font-weight:800;margin-top:4px}
.balance-preview .bp-after{font-size:.82rem;color:var(--muted);margin-top:4px}

/* ========== STATEMENT ========== */
.statement-header{text-align:center;padding:20px 0;border-bottom:2px solid var(--line2);margin-bottom:16px}
.statement-header h3{font-size:1.1rem;font-weight:800;letter-spacing:.06em}
.statement-header p{color:var(--muted);font-size:.82rem;margin-top:4px}
.statement-meta{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px;padding:14px;border-radius:12px;background:rgba(255,255,255,.03);border:1px solid var(--line)}
.statement-meta .sm-label{font-size:.72rem;color:var(--soft);text-transform:uppercase;letter-spacing:.06em}
.statement-meta .sm-value{font-size:.92rem;font-weight:600;margin-top:2px}
.statement-totals{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin:14px 0}
.statement-totals .st-box{padding:10px 14px;border-radius:10px;background:rgba(255,255,255,.03);border:1px solid var(--line);text-align:center}
.statement-totals .st-label{font-size:.72rem;color:var(--soft);text-transform:uppercase;letter-spacing:.04em}
.statement-totals .st-value{font-size:1.1rem;font-weight:700;margin-top:4px}
.statement-row{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--line);font-size:.86rem;align-items:center}
.statement-row:last-child{border-bottom:none}
.statement-row .sr-date{min-width:80px;color:var(--muted);font-size:.8rem}
.statement-row .sr-desc{flex:1;margin:0 14px}
.statement-row .sr-ref{color:var(--soft);font-size:.78rem;font-family:'Geist Mono',monospace}
.statement-row .sr-amount{min-width:100px;text-align:right;font-weight:700}
.statement-row .sr-balance{min-width:100px;text-align:right;font-size:.84rem;color:var(--muted)}


/* ========== RECEIPT / PAYSLIP PRINT ========== */
.receipt-container,.payslip-container{max-width:380px;margin:0 auto;font-size:.86rem;line-height:1.5}
.receipt-container .receipt-header,.payslip-container .payslip-header{text-align:center;margin-bottom:14px}
.receipt-bank-name,.payslip-bank-name{font-size:1.3rem;font-weight:900;letter-spacing:.1em;color:#111}
.receipt-bank-sub,.payslip-bank-sub{font-size:.78rem;color:#666;margin-top:2px}
.receipt-type{font-size:.9rem;font-weight:700;text-transform:uppercase;letter-spacing:.15em;margin-top:8px;color:#333;padding:6px 16px;border:2px solid #333;border-radius:6px;display:inline-block}
.receipt-section{margin:10px 0}
.receipt-row{display:flex;justify-content:space-between;padding:4px 0;font-size:.84rem}
.receipt-row .rr-label{color:#666}
.receipt-row .rr-value{font-weight:600;text-align:right;max-width:60%}
.receipt-divider{height:1px;background:repeating-linear-gradient(90deg,#333,#333 4px,transparent 4px,transparent 8px);margin:8px 0}

/* ========== BANK BRANDING SETTINGS ========== */
.branding-preview{padding:30px;border-radius:var(--radius-lg);border:1px solid var(--line);background:linear-gradient(180deg,#fff,#f8fafc);margin-bottom:20px;position:relative;overflow:hidden}
/* ── Enterprise Policy Dashboard ── */
.policy-dash-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:20px}
.policy-dash-stat{padding:16px;border-radius:var(--radius-md);border:1px solid var(--line);background:var(--card)}
.policy-dash-stat .pds-value{font-size:1.6rem;font-weight:800;line-height:1.2}
.policy-dash-stat .pds-label{font-size:.78rem;color:var(--muted);margin-top:2px}
.policy-sev-low{color:#67e8b5;border-color:rgba(103,232,181,.3)}.policy-sev-low .pds-value{color:#67e8b5}
.policy-sev-medium{color:#58b7ff;border-color:rgba(88,183,255,.3)}.policy-sev-medium .pds-value{color:#58b7ff}
.policy-sev-high{color:#fbbf24;border-color:rgba(251,191,36,.3)}.policy-sev-high .pds-value{color:#fbbf24}
.policy-sev-critical{color:#ff6b86;border-color:rgba(255,107,134,.3)}.policy-sev-critical .pds-value{color:#ff6b86}
.policy-card-sev{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;vertical-align:middle}
.policy-card-sev-low{background:#67e8b5}.policy-card-sev-medium{background:#58b7ff}.policy-card-sev-high{background:#fbbf24}.policy-card-sev-critical{background:#ff6b86}
.policy-revision-timeline{position:relative;padding-left:24px}
.policy-revision-timeline::before{content:'';position:absolute;left:8px;top:0;bottom:0;width:2px;background:var(--line)}
.policy-rev-item{position:relative;margin-bottom:16px;padding:12px 16px;border-radius:var(--radius-md);background:var(--bg-dim);border:1px solid var(--line)}
.policy-rev-item::before{content:'';position:absolute;left:-20px;top:16px;width:10px;height:10px;border-radius:50%;background:var(--primary);border:2px solid var(--bg-dim)}
.policy-rev-item.latest::before{background:var(--accent);box-shadow:0 0 0 3px rgba(103,232,181,.3)}
.branding-preview::before{content:'';position:absolute;top:0;left:0;right:0;height:6px;background:linear-gradient(90deg,var(--primary),var(--accent))}
.branding-preview .bp-letterhead{display:flex;align-items:center;gap:20px;padding-bottom:16px;border-bottom:2px solid #1a365d;margin-bottom:16px}
.branding-preview .bp-logo{width:64px;height:64px;border-radius:16px;display:grid;place-items:center;font-weight:900;font-size:1.6rem;color:#fff;background:linear-gradient(135deg,#1a365d,#2563eb);flex-shrink:0;overflow:hidden}
.branding-preview .bp-logo img{width:100%;height:100%;object-fit:cover}
.branding-preview .bp-bank-name{font-size:1.4rem;font-weight:900;color:#1a365d;letter-spacing:.06em}
.branding-preview .bp-tagline{font-size:.82rem;color:#4a5568;margin-top:2px}
.branding-preview .bp-details{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;font-size:.78rem;color:#4a5568;line-height:1.5}
.branding-preview .bp-details span{display:flex;align-items:center;gap:6px}
.branding-preview .bp-doc-bar{margin-top:14px;padding:10px 16px;background:#f0f4f8;border-radius:8px;font-size:.78rem;color:#1a365d;text-align:center;letter-spacing:.06em}
.branding-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.branding-form-grid .form-group.full-width{grid-column:1/-1}
.branding-section-title{font-size:.82rem;color:var(--soft);text-transform:uppercase;letter-spacing:.1em;margin-bottom:14px;padding-bottom:8px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:8px}
.branding-section-title span{font-size:.9rem}
.branding-logo-upload{display:flex;align-items:center;gap:18px;padding:20px;border:2px dashed var(--line);border-radius:var(--radius);text-align:center;cursor:pointer;transition:.2s}
.branding-logo-upload:hover{border-color:var(--primary);background:var(--primary-dim)}
.branding-logo-upload .blu-preview{width:80px;height:80px;border-radius:16px;display:grid;place-items:center;background:linear-gradient(135deg,var(--primary),var(--accent));color:var(--bg);font-weight:900;font-size:1.6rem;overflow:hidden;flex-shrink:0}
.branding-logo-upload .blu-preview img{width:100%;height:100%;object-fit:cover}
.branding-logo-upload .blu-text{font-size:.86rem;color:var(--muted)}
.branding-logo-upload .blu-text strong{display:block;color:var(--text2);font-size:.92rem;margin-bottom:4px}
.branding-color-row{display:flex;align-items:center;gap:12px}
.branding-color-row input[type="color"]{width:42px;height:42px;border:2px solid var(--line);border-radius:10px;cursor:pointer;padding:2px;background:var(--panel)}
.branding-color-row input[type="text"]{flex:1}

/* ========== PRINT ========== */
@media print{
  @page{size:A4;margin:10mm 12mm}
  @page:first{margin-top:8mm}
  /* ── Hide entire app shell — only print areas should be visible ── */
  .app-shell,.sidebar,.header,.btn,.drawer,.modal,.toast-container,.notif-dropdown,.user-dropdown,.sidebar-collapse-btn,.filters-bar,.pagination,.search-overlay,.view{display:none!important}
  .main-content{display:none!important}
  body > *:not([id$="PrintArea"]):not(#_isolatedPrintArea):not(#expensePrintArea):not(.dynamic-print-style) { display: none !important; }
  body{background:#fff;color:#111;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .card{box-shadow:none;border:1px solid #ddd;background:#fff}
  table{min-width:auto;font-size:10px}
  thead th{background:#f5f5f5;color:#333}
  #statementPrintArea.printing{display:block!important;color:#111}
  #statementPrintArea.printing .statement-header{border-bottom-color:#333}
  #statementPrintArea.printing h3{color:#111}
  #statementPrintArea.printing p,#statementPrintArea.printing .sm-label,#statementPrintArea.printing .sr-date,#statementPrintArea.printing .sr-ref,#statementPrintArea.printing .sr-balance{color:#666}
  #statementPrintArea.printing .statement-meta,#statementPrintArea.printing .statement-totals .st-box{background:#f9f9f9;border-color:#ddd}
  #statementPrintArea.printing .statement-row{border-bottom-color:#eee}
  #statementPrintArea.printing .sr-amount{color:#111}
  #statementPrintArea.printing .td-credit{color:#16a34a!important}
  #statementPrintArea.printing .td-debit{color:#dc2626!important}
  #receiptPrintArea.printing{display:block!important;color:#111}
  #receiptPrintArea.printing .receipt-container,#receiptPrintArea.printing .payslip-container{max-width:100%}
  #receiptPrintArea.printing .receipt-bank-name,#receiptPrintArea.printing .payslip-bank-name{color:#111}
  #receiptPrintArea.printing .receipt-type{color:#333;border-color:#333}
  #receiptPrintArea.printing .receipt-row .rr-label{color:#666}
  #receiptPrintArea.printing .receipt-row .rr-value{color:#111}
  #receiptPrintArea.printing .receipt-divider{border-top:1px dashed #999;background:none;height:auto}
  #profitLossPrintArea.printing{display:block!important;color:#111}
  #profitLossPrintArea.printing .pl-header{text-align:center;margin-bottom:20px}
  #profitLossPrintArea.printing .pl-section{margin:16px 0}
  #profitLossPrintArea.printing .pl-row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #ddd;font-size:12px}
  #profitLossPrintArea.printing .pl-row.total{font-weight:800;border-top:2px solid #111}
  #profitLossPrintArea.printing .pl-table{width:100%;border-collapse:collapse;font-size:11px}
  #profitLossPrintArea.printing .pl-table th{background:#f5f5f5;padding:6px 10px;text-align:left;border:1px solid #ddd}
  #profitLossPrintArea.printing .pl-table td{padding:6px 10px;border:1px solid #ddd}
  #documentPrintArea.printing{display:block!important;color:#111;background:#fff;padding:0!important}
  #documentPrintArea.printing .ent-doc{max-width:100%;box-shadow:none;border:none;border-radius:0;overflow:visible}
  /* ── A4-optimised header: compress vertical padding ── */
  #documentPrintArea.printing .ent-doc-header{border-bottom-width:2px;padding:12px 16px 8px}
  #documentPrintArea.printing .ent-doc-header .edh-left{gap:10px}
  #documentPrintArea.printing .ent-doc-header .edh-logo{width:40px;height:40px;border-radius:8px;font-size:1.1rem}
  #documentPrintArea.printing .ent-doc-header .edh-bank-name{font-size:1.1rem}
  #documentPrintArea.printing .ent-doc-header .edh-bank-sub{font-size:.7rem}
  #documentPrintArea.printing .ent-doc-header .edh-right{font-size:.7rem}
  #documentPrintArea.printing .ent-doc-header .edh-right .edh-doc-title{font-size:.82rem}
  /* ── Body: tight margins for maximum content per page ── */
  #documentPrintArea.printing .ent-doc-body{padding:10px 16px 6px}
  #documentPrintArea.printing .ent-doc-grid{gap:6px;margin-bottom:8px}
  #documentPrintArea.printing .ent-doc-field{padding:6px 8px;border-radius:4px}
  #documentPrintArea.printing .ent-doc-field .edf-label{font-size:.62rem;margin-bottom:1px}
  #documentPrintArea.printing .ent-doc-field .edf-value{font-size:.82rem}
  /* ── Sections: compact spacing ── */
  #documentPrintArea.printing .ent-doc-section{break-inside:auto;margin-bottom:8px}
  #documentPrintArea.printing .ent-doc-section-title{padding:6px 10px;margin-bottom:6px;font-size:.74rem;border-radius:4px}
  /* ── Table: compact rows, keep header with first data row ── */
  #documentPrintArea.printing .ent-doc-table{font-size:10px;margin-bottom:6px}
  #documentPrintArea.printing .ent-doc-table thead th{background:#1a365d!important;padding:5px 6px;font-size:.66rem;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  #documentPrintArea.printing .ent-doc-table tbody td{padding:4px 6px;border-bottom:1px solid #ddd;color:#000;font-size:10px}
  #documentPrintArea.printing .ent-doc-table thead{break-after:avoid}
  #documentPrintArea.printing .ent-doc-table tbody tr{break-inside:avoid}
  /* ── Summary rows: compact ── */
  #documentPrintArea.printing .ent-doc-total-row{padding:5px 8px;margin-bottom:4px;font-size:.8rem;border-radius:4px;break-inside:avoid}
  #documentPrintArea.printing .ent-doc-total-row .edtr-value{color:#000}
  #documentPrintArea.printing .ent-doc-grand{padding:8px 12px;border-radius:6px;font-size:.92rem;margin:6px 0;background:#1a365d!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;break-inside:avoid}
  #documentPrintArea.printing .ent-doc-net{padding:8px 12px;border-radius:6px;font-size:.92rem;margin:6px 0;border-width:1.5px!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;break-inside:avoid}
  /* ── Earnings / Deductions / Employer rows: compact ── */
  #documentPrintArea.printing .ent-doc-earnings-row{padding:4px 8px;font-size:.8rem;flex-wrap:wrap}
  #documentPrintArea.printing .ent-doc-deductions-row{padding:4px 8px;font-size:.8rem;flex-wrap:wrap}
  #documentPrintArea.printing .ent-doc-employer-row{padding:4px 8px;font-size:.8rem;flex-wrap:wrap}
  #documentPrintArea.printing .eddr-value{color:#000!important}
  #documentPrintArea.printing .eder-value{color:#000!important}
  /* ── Certification + Signatures + Footer: keep together on last page ── */
  #documentPrintArea.printing .ent-doc-cert{padding:8px 10px;margin:6px 0;font-size:.76rem;border-radius:4px;break-inside:avoid}
  #documentPrintArea.printing .ent-doc-signatures{margin-top:10px;padding-top:8px;gap:4px;break-inside:avoid;page-break-inside:avoid}
  #documentPrintArea.printing .ent-doc-sig .eds-line{border-top:1px solid #000;width:140px;margin:16px auto 4px}
  #documentPrintArea.printing .ent-doc-sig .eds-name{font-size:.8rem;color:#000}
  #documentPrintArea.printing .ent-doc-sig .eds-role{font-size:.68rem}
  #documentPrintArea.printing .ent-doc-footer{padding:8px 16px;margin-top:6px;font-size:.64rem;border-top:1px solid #ccc;color:#555;break-inside:avoid;page-break-inside:avoid}
  /* ── Anti-extra-page: collapse all print area containers ── */
  html,body{margin:0!important;padding:0!important;overflow:visible!important;height:auto!important}
  #documentPrintArea,#statementPrintArea,#receiptPrintArea,#profitLossPrintArea{margin:0!important;padding:0!important;border:none!important;box-shadow:none!important;max-height:none!important;overflow:visible!important}
  #_isolatedPrintArea{margin:0!important;overflow:visible!important}
  /* ── Force color output ── */
  #documentPrintArea.printing .edt-credit{color:#111!important}
  #documentPrintArea.printing .edt-debit{color:#111!important}
  #documentPrintArea.printing .edt-mono{color:#333!important}
  #documentPrintArea.printing .edh-logo{background:#1a365d!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  #documentPrintArea.printing .edh-bank-name{color:#1a365d!important}
  #documentPrintArea.printing .edh-doc-title{color:#1a365d!important}
  #expensePrintArea{display:block!important;color:#111;background:#fff}
  /* ── Isolated print area enterprise styling ── */
  #_isolatedPrintArea .ent-doc{max-width:100%;box-shadow:none;border:none;border-radius:0;overflow:visible}
  #_isolatedPrintArea .ent-doc-header{border-bottom-width:2px;padding:12px 16px 8px}
  #_isolatedPrintArea .ent-doc-header .edh-left{gap:10px}
  #_isolatedPrintArea .ent-doc-header .edh-logo{width:40px;height:40px;border-radius:8px;font-size:1.1rem}
  #_isolatedPrintArea .ent-doc-header .edh-bank-name{font-size:1.1rem}
  #_isolatedPrintArea .ent-doc-header .edh-bank-sub{font-size:.7rem}
  #_isolatedPrintArea .ent-doc-header .edh-right{font-size:.7rem}
  #_isolatedPrintArea .ent-doc-header .edh-right .edh-doc-title{font-size:.82rem}
  #_isolatedPrintArea .ent-doc-body{padding:10px 16px 6px}
  #_isolatedPrintArea .ent-doc-grid{gap:6px;margin-bottom:8px}
  #_isolatedPrintArea .ent-doc-field{padding:6px 8px;border-radius:4px}
  #_isolatedPrintArea .ent-doc-field .edf-label{font-size:.62rem;margin-bottom:1px}
  #_isolatedPrintArea .ent-doc-field .edf-value{font-size:.82rem}
  #_isolatedPrintArea .ent-doc-section{break-inside:auto;margin-bottom:8px}
  #_isolatedPrintArea .ent-doc-section-title{padding:6px 10px;margin-bottom:6px;font-size:.74rem;border-radius:4px}
  #_isolatedPrintArea .ent-doc-table{font-size:10px;margin-bottom:6px}
  #_isolatedPrintArea .ent-doc-table thead th{background:#1a365d!important;padding:5px 6px;font-size:.66rem;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  #_isolatedPrintArea .ent-doc-table tbody td{padding:4px 6px;border-bottom:1px solid #ddd;color:#000;font-size:10px}
  #_isolatedPrintArea .ent-doc-table thead{break-after:avoid}
  #_isolatedPrintArea .ent-doc-table tbody tr{break-inside:avoid}
  #_isolatedPrintArea .ent-doc-total-row{padding:5px 8px;margin-bottom:4px;font-size:.8rem;border-radius:4px;break-inside:avoid}
  #_isolatedPrintArea .ent-doc-total-row .edtr-value{color:#000}
  #_isolatedPrintArea .ent-doc-grand{padding:8px 12px;border-radius:6px;font-size:.92rem;margin:6px 0;background:#1a365d!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;break-inside:avoid}
  #_isolatedPrintArea .ent-doc-cert{padding:8px 10px;margin:6px 0;font-size:.76rem;border-radius:4px;break-inside:avoid}
  #_isolatedPrintArea .ent-doc-signatures{margin-top:10px;padding-top:8px;gap:4px;break-inside:avoid;page-break-inside:avoid}
  #_isolatedPrintArea .ent-doc-sig .eds-line{border-top:1px solid #000;width:140px;margin:16px auto 4px}
  #_isolatedPrintArea .ent-doc-sig .eds-name{font-size:.8rem;color:#000}
  #_isolatedPrintArea .ent-doc-sig .eds-role{font-size:.68rem}
  #_isolatedPrintArea .ent-doc-footer{padding:8px 16px;margin-top:6px;font-size:.64rem;border-top:1px solid #ccc;color:#555;break-inside:avoid;page-break-inside:avoid}
  #_isolatedPrintArea .edt-credit{color:#111!important}
  #_isolatedPrintArea .edt-debit{color:#111!important}
  #_isolatedPrintArea .edt-mono{color:#333!important}
  #_isolatedPrintArea .edh-logo{background:#1a365d!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  #_isolatedPrintArea .edh-bank-name{color:#1a365d!important}
  #_isolatedPrintArea .edh-doc-title{color:#1a365d!important}
}

/* ========== AUDIT CENTER ========== */
.audit-score-ring{width:140px;height:140px;border-radius:50%;display:grid;place-items:center;position:relative;margin:0 auto}
.audit-score-ring svg{position:absolute;inset:0;transform:rotate(-90deg)}
.audit-score-ring circle{fill:none;stroke-width:8}
.audit-score-ring .ring-bg{stroke:rgba(255,255,255,.08)}
.audit-score-ring .ring-fill{stroke:var(--accent);stroke-linecap:round;transition:stroke-dashoffset .6s ease}
.audit-score-ring .score-text{font-size:2rem;font-weight:900;z-index:1}
.audit-score-ring .score-label{font-size:.72rem;color:var(--muted);z-index:1;text-align:center;margin-top:2px}
.finding-card{padding:14px;border-radius:14px;border:1px solid var(--line);background:rgba(255,255,255,.03);margin-bottom:10px}
.finding-card .fc-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.finding-card .fc-severity{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em}
.audit-check-item{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.02);margin-bottom:6px;font-size:.86rem}
.audit-check-item .ac-icon{width:28px;height:28px;border-radius:8px;display:grid;place-items:center;font-size:.78rem;flex-shrink:0}
.branch-card{padding:18px;border-radius:var(--radius);border:1px solid var(--line);background:rgba(255,255,255,.03)}
.branch-card .bc-name{font-size:1rem;font-weight:700;margin-bottom:4px}
.branch-card .bc-code{font-size:.78rem;color:var(--muted)}
.op-balance-box{padding:24px;border-radius:var(--radius-lg);background:linear-gradient(135deg,rgba(88,183,255,.08),rgba(103,232,181,.06));border:1px solid rgba(88,183,255,.15);text-align:center}
.op-balance-box .ob-label{font-size:.78rem;color:var(--muted);text-transform:uppercase;letter-spacing:.1em}
.op-balance-box .ob-value{font-size:2.4rem;font-weight:900;margin-top:8px;color:var(--accent)}
.op-balance-box .ob-sub{font-size:.84rem;color:var(--muted);margin-top:6px}
.expense-cat-bar{display:flex;align-items:center;gap:12px;margin-bottom:8px}
.expense-cat-bar .ecb-label{min-width:160px;font-size:.82rem;color:var(--text2)}  /* ★ FIX (EXP-004): Widened from 120px to 160px to accommodate "(Approved)" suffix */
.expense-cat-bar .ecb-track{flex:1;height:8px;border-radius:4px;background:rgba(255,255,255,.06);overflow:hidden}
.expense-cat-bar .ecb-fill{height:100%;border-radius:4px;transition:.3s}
.expense-cat-bar .ecb-value{min-width:80px;text-align:right;font-size:.84rem;font-weight:700}
.audit-section-title{font-size:.82rem;color:var(--soft);text-transform:uppercase;letter-spacing:.1em;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--line)}
.risk-indicator{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:999px;font-size:.78rem;font-weight:700}
.ri-low{background:var(--success-dim);color:var(--success)}
.ri-medium{background:var(--warn-dim);color:var(--warn)}
.ri-high{background:var(--danger-dim);color:var(--danger)}
.ri-critical{background:rgba(255,107,134,.2);color:#ff4466}

/* ========== SEARCH OVERLAY ========== */
.search-overlay{position:fixed;inset:0;background:rgba(7,14,24,.92);z-index:200;display:flex;flex-direction:column;opacity:0;pointer-events:none;transition:.2s}
.search-overlay.open{opacity:1;pointer-events:auto}
.search-overlay-header{padding:20px 24px;border-bottom:1px solid var(--line)}
.search-overlay-input{width:100%;max-width:720px;margin:0 auto;display:block;padding:14px 18px;font-size:1.1rem;background:var(--panel);border:1px solid var(--line);border-radius:14px;color:var(--text);outline:none}
.search-overlay-input:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-dim)}
.search-overlay-body{flex:1;overflow-y:auto;padding:16px 24px}
.search-overlay-results{max-width:900px;margin:0 auto}
.search-category{margin-bottom:18px}
.search-category-header{display:flex;align-items:center;gap:10px;padding:8px 12px;font-size:.82rem;color:var(--soft);text-transform:uppercase;letter-spacing:.1em;cursor:pointer;border-radius:10px;transition:.1s}
.search-category-header:hover{background:rgba(255,255,255,.04);color:var(--text2)}
.search-category-count{background:var(--primary-dim);color:var(--primary);padding:2px 8px;border-radius:999px;font-size:.72rem;font-weight:700}
.search-result-item{display:flex;align-items:center;gap:14px;padding:10px 14px;border-radius:12px;cursor:pointer;transition:.1s;border:1px solid transparent}
.search-result-item:hover{background:rgba(255,255,255,.04);border-color:var(--line)}
.search-result-item.active{background:var(--primary-dim);border-color:rgba(88,183,255,.2)}
.search-result-icon{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;font-size:.9rem;flex-shrink:0}
.search-result-body{flex:1;min-width:0}
.search-result-title{font-size:.88rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.search-result-sub{font-size:.78rem;color:var(--muted);margin-top:2px}
.search-result-meta{font-size:.72rem;color:var(--soft);white-space:nowrap}
.search-highlight{background:rgba(88,183,255,.25);color:var(--primary);padding:0 2px;border-radius:3px}
.search-recent{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px}
.search-recent-tag{padding:5px 12px;border-radius:999px;font-size:.8rem;color:var(--muted);background:rgba(255,255,255,.04);cursor:pointer;border:1px solid var(--line);transition:.1s}
.search-recent-tag:hover{background:rgba(255,255,255,.08);color:var(--text2)}
.search-empty{text-align:center;padding:60px 20px;color:var(--muted)}
.search-empty .se-icon{font-size:3rem;margin-bottom:14px}
.search-kbd-hint{display:flex;gap:12px;justify-content:center;margin-top:12px;font-size:.76rem;color:var(--soft)}
.search-kbd-hint kbd{padding:2px 6px;border-radius:4px;background:rgba(255,255,255,.06);border:1px solid var(--line);font-family:inherit}

/* ========== TOGGLE SWITCH ========== */
.toggle-switch{position:relative;display:inline-block;width:44px;height:24px;flex-shrink:0}
.toggle-switch input{opacity:0;width:0;height:0}
.toggle-slider{position:absolute;cursor:pointer;inset:0;background:rgba(255,255,255,.12);border:1px solid var(--line);border-radius:24px;transition:.2s}
.toggle-slider::before{content:'';position:absolute;height:18px;width:18px;left:3px;bottom:2px;background:var(--muted);border-radius:50%;transition:.2s}
.toggle-switch input:checked+.toggle-slider{background:var(--primary-dim);border-color:rgba(88,183,255,.3)}
.toggle-switch input:checked+.toggle-slider::before{transform:translateX(20px);background:var(--primary)}

/* ========== ENTERPRISE DOCUMENT STYLES ========== */
.ent-doc{max-width:800px;margin:0 auto;background:#fff;color:#111;font-size:13px;line-height:1.6;font-family:'Inter',ui-sans-serif,system-ui,sans-serif;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06),0 8px 24px rgba(0,0,0,.04)}
.ent-doc-header{display:flex;justify-content:space-between;align-items:flex-start;padding:24px 30px 16px;border-bottom:3px solid #1a365d}
.ent-doc-header .edh-left{display:flex;align-items:center;gap:16px}
.ent-doc-header .edh-logo{width:56px;height:56px;border-radius:14px;background:linear-gradient(135deg,#1a365d,#2563eb);color:#fff;display:grid;place-items:center;font-weight:900;font-size:1.4rem;letter-spacing:.04em}
.ent-doc-header .edh-bank-name{font-size:1.3rem;font-weight:900;letter-spacing:.06em;color:#1a365d}
.ent-doc-header .edh-bank-sub{font-size:.78rem;color:#4a5568}
.ent-doc-header .edh-right{text-align:right;font-size:.78rem;color:#4a5568}
.ent-doc-header .edh-right .edh-doc-title{font-size:.95rem;font-weight:700;color:#1a365d;text-transform:uppercase;letter-spacing:.12em}
.ent-doc-body{padding:20px 30px}
.ent-doc-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:18px}
.ent-doc-field{padding:10px 14px;border-radius:8px;background:#f7fafc;border:1px solid #e2e8f0}
.ent-doc-field .edf-label{font-size:.68rem;color:#718096;text-transform:uppercase;letter-spacing:.08em;margin-bottom:2px}
.ent-doc-field .edf-value{font-size:.92rem;font-weight:700;color:#1a202c}
.ent-doc-section{margin-bottom:20px}
.ent-doc-section-title{font-size:.82rem;font-weight:700;color:#1a365d;text-transform:uppercase;letter-spacing:.1em;padding:10px 14px;background:#edf2f7;border-radius:8px;border-left:4px solid #2563eb;margin-bottom:10px}
.ent-doc-table{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:12px}
.ent-doc-table thead th{background:#1a365d;color:#fff;padding:10px 12px;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;font-weight:600}
.ent-doc-table tbody td{padding:9px 12px;border-bottom:1px solid #e2e8f0;vertical-align:top}
.ent-doc-table tbody tr:hover{background:#f7fafc}
.ent-doc-table .edt-credit{color:#16a34a;font-weight:700}
.ent-doc-table .edt-debit{color:#dc2626;font-weight:700}
.ent-doc-table .edt-mono{font-family:'Courier New',monospace;font-size:11px;color:#4a5568}
.ent-doc-total-row{display:flex;justify-content:space-between;padding:10px 14px;background:#f0f4f8;border-radius:8px;margin-bottom:8px;font-size:.88rem}
.ent-doc-total-row .edtr-label{color:#4a5568;font-weight:600}
.ent-doc-total-row .edtr-value{font-weight:800;color:#1a202c}
.ent-doc-grand{display:flex;justify-content:space-between;padding:14px 18px;background:#1a365d;color:#fff;border-radius:10px;font-size:1.05rem;font-weight:800;margin:14px 0}
.ent-doc-net{display:flex;justify-content:space-between;padding:14px 18px;background:#f0fdf4;border:2px solid #16a34a;border-radius:10px;font-size:1.05rem;font-weight:800;color:#16a34a;margin:14px 0}
.ent-doc-cert{padding:14px 18px;background:#fffbeb;border:1px solid #f59e0b;border-radius:8px;font-size:.82rem;color:#92400e;margin:16px 0;line-height:1.7}
.ent-doc-signatures{display:flex;justify-content:space-between;margin-top:30px;padding-top:20px}
.ent-doc-sig{text-align:center;flex:1}
.ent-doc-sig .eds-line{border-top:1px solid #1a365d;width:200px;margin:30px auto 8px}
.ent-doc-sig .eds-name{font-size:.88rem;font-weight:700;color:#1a202c}
.ent-doc-sig .eds-role{font-size:.78rem;color:#718096}
.ent-doc-footer{padding:14px 30px;border-top:1px solid #e2e8f0;font-size:.72rem;color:#718096;text-align:center;line-height:1.7;margin-top:20px}
.ent-doc-watermark{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-30deg);font-size:4rem;font-weight:900;color:rgba(0,0,0,.03);pointer-events:none;letter-spacing:.2em;z-index:0}
.ent-doc-divider{height:1px;background:#e2e8f0;margin:14px 0}
.ent-doc-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:.7rem;font-weight:700}
.ent-doc-badge-credit{background:#dcfce7;color:#16a34a}
.ent-doc-badge-debit{background:#fee2e2;color:#dc2626}
.ent-doc-earnings-row{display:flex;justify-content:space-between;padding:9px 14px;border-bottom:1px solid #f0f4f8;font-size:.88rem}
.ent-doc-earnings-row:hover{background:#f7fafc}
.ent-doc-deductions-row{display:flex;justify-content:space-between;padding:9px 14px;border-bottom:1px solid #f0f4f8;font-size:.88rem}
.ent-doc-deductions-row .eddr-value{color:#dc2626;font-weight:600}
.ent-doc-employer-row{display:flex;justify-content:space-between;padding:9px 14px;border-bottom:1px solid #f0f4f8;font-size:.88rem}
.ent-doc-employer-row .eder-value{color:#2563eb;font-weight:600}

@media print{
  @page{size:A4;margin:10mm 12mm}
  /* ── Ensure ONLY print areas are visible ── */
  body > *:not([id$="PrintArea"]):not(#_isolatedPrintArea):not(#expensePrintArea):not(.dynamic-print-style) { display: none !important; }
  #documentPrintArea{display:block!important;color:#111;background:#fff;padding:0!important;margin:0!important}
  #documentPrintArea .ent-doc{max-width:100%;box-shadow:none;border:none;border-radius:0;overflow:visible}
  #documentPrintArea .ent-doc-header{border-bottom-width:2px;padding:12px 16px 8px}
  #documentPrintArea .ent-doc-body{padding:10px 16px 6px}
  #documentPrintArea .ent-doc-section{break-inside:auto;margin-bottom:8px}
  #documentPrintArea .ent-doc-table thead th{background:#1a365d!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  #documentPrintArea .ent-doc-table tbody td{padding:4px 6px;border-bottom:1px solid #ddd;font-size:10px}
  #documentPrintArea .ent-doc-total-row{padding:5px 8px;font-size:.8rem;break-inside:avoid}
  #documentPrintArea .ent-doc-grand,#documentPrintArea .ent-doc-net{break-inside:avoid}
  #documentPrintArea .ent-doc-cert{padding:8px 10px;margin:6px 0;font-size:.76rem;break-inside:avoid}
  #documentPrintArea .ent-doc-signatures{margin-top:10px;padding-top:8px;gap:4px;break-inside:avoid;page-break-inside:avoid}
  #documentPrintArea .ent-doc-sig .eds-line{width:140px;margin:16px auto 4px}
  #documentPrintArea .ent-doc-footer{padding:8px 16px;margin-top:6px;font-size:.64rem;break-inside:avoid;page-break-inside:avoid}
  #documentPrintArea .ent-doc-earnings-row,#documentPrintArea .ent-doc-deductions-row,#documentPrintArea .ent-doc-employer-row{padding:4px 8px;font-size:.8rem}
  /* ── Anti-extra-page ── */
  html,body{margin:0!important;padding:0!important;overflow:visible!important;height:auto!important}
  #documentPrintArea,#_isolatedPrintArea{border:none!important;box-shadow:none!important;max-height:none!important;overflow:visible!important}
  /* ── Enterprise document print rules for both print areas ── */
  #documentPrintArea .ent-doc,#_isolatedPrintArea .ent-doc{max-width:100%;box-shadow:none;border:none;border-radius:0;overflow:visible}
  #documentPrintArea .ent-doc-header,#_isolatedPrintArea .ent-doc-header{border-bottom-width:2px;padding:12px 16px 8px}
  #documentPrintArea .ent-doc-header .edh-left,#_isolatedPrintArea .ent-doc-header .edh-left{gap:10px}
  #documentPrintArea .ent-doc-header .edh-logo,#_isolatedPrintArea .ent-doc-header .edh-logo{width:40px;height:40px;border-radius:8px;font-size:1.1rem}
  #documentPrintArea .ent-doc-header .edh-bank-name,#_isolatedPrintArea .ent-doc-header .edh-bank-name{font-size:1.1rem}
  #documentPrintArea .ent-doc-header .edh-bank-sub,#_isolatedPrintArea .ent-doc-header .edh-bank-sub{font-size:.7rem}
  #documentPrintArea .ent-doc-header .edh-right,#_isolatedPrintArea .ent-doc-header .edh-right{font-size:.7rem}
  #documentPrintArea .ent-doc-header .edh-right .edh-doc-title,#_isolatedPrintArea .ent-doc-header .edh-right .edh-doc-title{font-size:.82rem}
  #documentPrintArea .ent-doc-body,#_isolatedPrintArea .ent-doc-body{padding:10px 16px 6px}
  #documentPrintArea .ent-doc-grid,#_isolatedPrintArea .ent-doc-grid{gap:6px;margin-bottom:8px}
  #documentPrintArea .ent-doc-field,#_isolatedPrintArea .ent-doc-field{padding:6px 8px;border-radius:4px}
  #documentPrintArea .ent-doc-field .edf-label,#_isolatedPrintArea .ent-doc-field .edf-label{font-size:.62rem;margin-bottom:1px}
  #documentPrintArea .ent-doc-field .edf-value,#_isolatedPrintArea .ent-doc-field .edf-value{font-size:.82rem}
  #documentPrintArea .ent-doc-section,#_isolatedPrintArea .ent-doc-section{break-inside:auto;margin-bottom:8px}
  #documentPrintArea .ent-doc-section-title,#_isolatedPrintArea .ent-doc-section-title{padding:6px 10px;margin-bottom:6px;font-size:.74rem;border-radius:4px}
  #documentPrintArea .ent-doc-total-row,#_isolatedPrintArea .ent-doc-total-row{padding:5px 8px;margin-bottom:4px;font-size:.8rem;border-radius:4px;break-inside:avoid}
  #documentPrintArea .ent-doc-total-row .edtr-value,#_isolatedPrintArea .ent-doc-total-row .edtr-value{color:#000}
  #documentPrintArea .ent-doc-grand,#_isolatedPrintArea .ent-doc-grand{padding:8px 12px;border-radius:6px;font-size:.92rem;margin:6px 0;background:#1a365d!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;break-inside:avoid}
  #documentPrintArea .ent-doc-net,#_isolatedPrintArea .ent-doc-net{padding:8px 12px;border-radius:6px;font-size:.92rem;margin:6px 0;border-width:1.5px!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;break-inside:avoid}
  #documentPrintArea .ent-doc-cert,#_isolatedPrintArea .ent-doc-cert{padding:8px 10px;margin:6px 0;font-size:.76rem;break-inside:avoid}
  #documentPrintArea .ent-doc-signatures,#_isolatedPrintArea .ent-doc-signatures{margin-top:10px;padding-top:8px;gap:4px;break-inside:avoid;page-break-inside:avoid}
  #documentPrintArea .ent-doc-sig .eds-line,#_isolatedPrintArea .ent-doc-sig .eds-line{width:140px;margin:16px auto 4px}
  #documentPrintArea .ent-doc-footer,#_isolatedPrintArea .ent-doc-footer{padding:8px 16px;margin-top:6px;font-size:.64rem;break-inside:avoid;page-break-inside:avoid}
  #documentPrintArea .ent-doc-earnings-row,#_isolatedPrintArea .ent-doc-earnings-row,#documentPrintArea .ent-doc-deductions-row,#_isolatedPrintArea .ent-doc-deductions-row,#documentPrintArea .ent-doc-employer-row,#_isolatedPrintArea .ent-doc-employer-row{padding:4px 8px;font-size:.8rem}
}

/* ========== LUCIDE ICON SYSTEM ========== */
.nav-icon svg,.nav-icon i[data-lucide]{width:18px;height:18px;stroke-width:1.8}
.hdr-btn svg{width:18px;height:18px;stroke-width:1.8}
.kpi-card svg,.kpi-card i[data-lucide]{width:22px;height:22px;stroke-width:1.8}
.toast-icon svg{width:20px;height:20px;stroke-width:2}
.btn svg{width:16px;height:16px;stroke-width:2;vertical-align:-3px}
.btn-icon svg{width:18px;height:18px;stroke-width:2}
.search-overlay-body svg,.search-overlay-body i[data-lucide]{width:20px;height:20px;stroke-width:1.8}
.prof-tab svg{width:16px;height:16px;stroke-width:2;vertical-align:-2px}
.user-dropdown-menu button svg,.user-dropdown-menu button i[data-lucide]{width:18px;height:18px;stroke-width:1.8;vertical-align:-3px;margin-right:4px}
.sidebar-collapse-btn svg{width:20px;height:20px;stroke-width:2}
.branch-selector svg{width:16px;height:16px;stroke-width:1.8;vertical-align:-2px;margin-right:2px}
.session-item-icon svg,.session-item-icon i[data-lucide]{width:20px;height:20px;stroke-width:1.8}
.activity-icon svg,.activity-icon i[data-lucide]{width:18px;height:18px;stroke-width:1.8}
.header-search svg{width:18px;height:18px;stroke-width:1.8;color:var(--soft)}

/* ========== ENTERPRISE LOADING SCREEN ========== */
.atlas-loader{position:fixed;inset:0;z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center;background:var(--bg);transition:opacity .6s cubic-bezier(.4,0,.2,1),visibility .6s}
.atlas-loader.done{opacity:0;visibility:hidden;pointer-events:none}
.loader-bg{position:absolute;inset:0;overflow:hidden}
.loader-bg svg{position:absolute;inset:0;width:100%;height:100%}
.loader-node{fill:none;stroke:var(--primary);stroke-width:.4;opacity:.25;animation:nodeDrift 20s linear infinite}
.loader-node:nth-child(2){stroke:var(--accent);animation-duration:25s;animation-delay:-5s}
.loader-node:nth-child(3){stroke:var(--warn);stroke-width:.3;opacity:.15;animation-duration:30s;animation-delay:-10s}
@keyframes nodeDrift{0%{transform:translate(0,0)}25%{transform:translate(10px,-15px)}50%{transform:translate(-5px,10px)}75%{transform:translate(-15px,-5px)}100%{transform:translate(0,0)}}
.loader-content{position:relative;z-index:1;display:flex;flex-direction:column;align-items:center;gap:32px}
.loader-brand{position:relative;width:80px;height:80px;display:grid;place-items:center}
.loader-brand-mark{width:56px;height:56px;border-radius:16px;display:grid;place-items:center;font-size:1.5rem;font-weight:900;color:#fff;background:linear-gradient(135deg,var(--primary),var(--accent));box-shadow:0 0 40px rgba(88,183,255,.3),0 0 80px rgba(88,183,255,.1);animation:brandPulse 2.4s ease-in-out infinite}
@keyframes brandPulse{0%,100%{box-shadow:0 0 40px rgba(88,183,255,.3),0 0 80px rgba(88,183,255,.1);transform:scale(1)}50%{box-shadow:0 0 60px rgba(88,183,255,.5),0 0 120px rgba(103,232,181,.2);transform:scale(1.04)}}
.loader-ring{position:absolute;inset:0;border-radius:50%;border:1px solid transparent;border-top-color:var(--primary);animation:ringRotate 2s linear infinite;opacity:.6}
.loader-ring:nth-child(2){inset:-8px;border-top-color:var(--accent);animation-duration:3s;animation-direction:reverse;opacity:.35}
.loader-ring:nth-child(3){inset:-16px;border-top-color:var(--warn);animation-duration:4.5s;opacity:.2}
@keyframes ringRotate{to{transform:rotate(360deg)}}
.loader-orbit{position:absolute;inset:-24px;border-radius:50%;animation:orbitSpin 8s linear infinite}
.loader-orbit::before,.loader-orbit::after{content:'';position:absolute;width:4px;height:4px;border-radius:50%;background:var(--primary);box-shadow:0 0 8px var(--primary)}
.loader-orbit::before{top:0;left:50%;transform:translateX(-50%)}
.loader-orbit::after{bottom:0;left:50%;transform:translateX(-50%);background:var(--accent);box-shadow:0 0 8px var(--accent)}
@keyframes orbitSpin{to{transform:rotate(360deg)}}
.loader-text{text-align:center}
.loader-text h1{font-size:1.1rem;font-weight:600;letter-spacing:.02em;color:var(--text);margin-bottom:6px}
.loader-text .stage{font-size:.82rem;color:var(--muted);min-height:1.4em;transition:opacity .3s}
.loader-text .stage.changing{opacity:0}
.loader-bar-wrap{width:240px;height:2px;border-radius:2px;background:var(--line);overflow:hidden;position:relative}
.loader-bar{height:100%;width:0%;border-radius:2px;background:linear-gradient(90deg,var(--primary),var(--accent));transition:width .8s cubic-bezier(.4,0,.2,1);position:relative}
.loader-bar::after{content:'';position:absolute;right:0;top:-1px;width:20px;height:4px;border-radius:4px;background:var(--accent);box-shadow:0 0 12px var(--accent),0 0 24px rgba(103,232,181,.3);filter:blur(1px)}
.loader-bar.indeterminate{width:30%!important;transition:none!important;animation:indeterminate 1.8s cubic-bezier(.4,0,.6,1) infinite}
@keyframes indeterminate{0%{transform:translateX(-100%)}100%{transform:translateX(430%)}}
.loader-meta{display:flex;gap:24px;font-size:.72rem;color:var(--soft)}
.loader-meta span{display:flex;align-items:center;gap:5px}
.loader-dot{width:5px;height:5px;border-radius:50%;display:inline-block}
.loader-dot.secure{background:var(--success);box-shadow:0 0 6px var(--success)}
.loader-dot.active{background:var(--primary);box-shadow:0 0 6px var(--primary);animation:dotBlink 1.5s ease-in-out infinite}
@keyframes dotBlink{0%,100%{opacity:.4}50%{opacity:1}}
@media(prefers-reduced-motion:reduce){
  .loader-brand-mark,.loader-ring,.loader-orbit,.loader-node,.loader-dot.active{animation:none!important}
  .atlas-loader{transition-duration:.15s!important}
  .loader-bar.indeterminate{animation:none!important;width:60%!important}
}
</style>
</head>
<body>

<!-- ==================== ENTERPRISE LOADING SCREEN ==================== -->
<div id="atlasLoader" class="atlas-loader">
  <div class="loader-bg">
    <svg viewBox="0 0 800 600" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg">
      <g class="loader-node"><line x1="120" y1="80" x2="280" y2="200"/><line x1="280" y1="200" x2="200" y2="380"/><line x1="200" y1="380" x2="400" y2="320"/><line x1="400" y1="320" x2="520" y2="480"/><line x1="520" y1="480" x2="680" y2="340"/><line x1="680" y1="340" x2="600" y2="120"/><line x1="600" y1="120" x2="400" y2="80"/><line x1="400" y1="80" x2="120" y2="80"/><circle cx="120" cy="80" r="2.5" fill="var(--primary)" opacity=".5"/><circle cx="280" cy="200" r="2" fill="var(--primary)" opacity=".4"/><circle cx="200" cy="380" r="2.5" fill="var(--primary)" opacity=".3"/><circle cx="400" cy="320" r="2" fill="var(--primary)" opacity=".5"/><circle cx="520" cy="480" r="2.5" fill="var(--primary)" opacity=".35"/><circle cx="680" cy="340" r="2" fill="var(--primary)" opacity=".4"/><circle cx="600" cy="120" r="2.5" fill="var(--primary)" opacity=".3"/><circle cx="400" cy="80" r="2" fill="var(--primary)" opacity=".45"/></g>
      <g class="loader-node"><line x1="80" y1="250" x2="300" y2="120"/><line x1="300" y1="120" x2="500" y2="200"/><line x1="500" y1="200" x2="720" y2="150"/><line x1="720" y1="150" x2="650" y2="450"/><line x1="650" y1="450" x2="350" y2="520"/><line x1="350" y1="520" x2="100" y2="450"/><line x1="100" y1="450" x2="80" y2="250"/><circle cx="80" cy="250" r="2" fill="var(--accent)" opacity=".4"/><circle cx="300" cy="120" r="2.5" fill="var(--accent)" opacity=".35"/><circle cx="500" cy="200" r="2" fill="var(--accent)" opacity=".5"/><circle cx="720" cy="150" r="2.5" fill="var(--accent)" opacity=".3"/><circle cx="650" cy="450" r="2" fill="var(--accent)" opacity=".4"/><circle cx="350" cy="520" r="2.5" fill="var(--accent)" opacity=".35"/><circle cx="100" cy="450" r="2" fill="var(--accent)" opacity=".5"/></g>
      <g class="loader-node"><line x1="160" y1="520" x2="440" y2="440"/><line x1="440" y1="440" x2="560" y2="260"/><line x1="560" y1="260" x2="740" y2="500"/><circle cx="160" cy="520" r="2" fill="var(--warn)" opacity=".3"/><circle cx="440" cy="440" r="2.5" fill="var(--warn)" opacity=".25"/><circle cx="560" cy="260" r="2" fill="var(--warn)" opacity=".35"/><circle cx="740" cy="500" r="2.5" fill="var(--warn)" opacity=".2"/></g>
    </svg>
  </div>
  <div class="loader-content">
    <div class="loader-brand">
      <div class="loader-ring"></div>
      <div class="loader-ring"></div>
      <div class="loader-ring"></div>
      <div class="loader-orbit"></div>
      <div class="loader-brand-mark">A</div>
    </div>
    <div class="loader-text">
      <h1>Atlas Bank Enterprise</h1>
      <div class="stage" id="loaderStage">Initializing secure session</div>
    </div>
    <div class="loader-bar-wrap">
      <div class="loader-bar indeterminate" id="loaderBar"></div>
    </div>
    <div class="loader-meta">
      <span><span class="loader-dot secure"></span> TLS 1.3 Encrypted</span>
      <span><span class="loader-dot active" id="loaderConnDot"></span> <span id="loaderConnLabel">Connecting</span></span>
    </div>
  </div>
</div>
<!-- ★ Inline branding injector — runs BEFORE all other JS (~20K lines).
     Reads cached branding from localStorage and patches the loader DOM instantly
     so the user never sees the default "A" / "Atlas Bank" flash. -->
<script>
(function(){
  try {
    var c = localStorage.getItem('atlas_branding');
    if (!c) return;
    var b = JSON.parse(c);
    var m = document.querySelector('.loader-brand-mark');
    var n = document.querySelector('.loader-text h1');
    if (m) {
      if (b.logo) m.innerHTML = '<img src="'+b.logo+'" style="width:100%;height:100%;object-fit:cover;border-radius:16px" alt="Logo">';
      else m.textContent = (b.bankName||'A').charAt(0);
      if (b.primaryColor && !b.logo) m.style.background = 'linear-gradient(135deg,'+b.primaryColor+','+(b.accentColor||'#67e8b5')+')';
    }
    if (n) n.textContent = (b.bankNameShort||b.bankName||'Atlas Bank') + ' Enterprise';
    if (b.primaryColor) {
      document.querySelectorAll('.loader-ring').forEach(function(r,i){
        r.style.borderTopColor = i===0 ? b.primaryColor : (i===1 ? (b.accentColor||'#67e8b5') : '#ffbe69');
      });
    }
  } catch(e){}
})();
