<?php

$config = require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;

$auth = new Auth((int)$config['app']['session_idle_minutes'] * 60);
$csrf = new Csrf($config['app']['csrf_key']);
$user = $auth->user();
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
if ($base === '/' || $base === '\\') {
    $base = '';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf->token(), ENT_QUOTES); ?>">
  <meta name="api-base" content="<?php echo htmlspecialchars($base . '/api/index.php', ENT_QUOTES); ?>">
  <title>hotelpos</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($base); ?>/../assets/css/app.css">
</head>
<body>
<!-- Login view is shown when the PHP session has no authenticated user. -->
<div id="loginView" class="<?php echo $user ? 'd-none' : ''; ?>">
  <main class="login-shell">
    <section class="login-panel">
      <img src="<?php echo htmlspecialchars($base); ?>/../POS_v1/_review_tivoli/tivoli/img/Tivoli.png" alt="Tivoli Guesthouse" class="brand-mark">
      <form id="loginForm" class="vstack gap-3">
        <div><label class="form-label">Email</label><input class="form-control" type="email" name="email" autocomplete="username" required></div>
        <div><label class="form-label">Password</label><input class="form-control" type="password" name="password" autocomplete="current-password" required></div>
        <div class="alert alert-danger d-none" id="loginError"></div>
        <button class="btn btn-primary w-100">Sign in</button>
        <button class="btn btn-link w-100 text-decoration-none" type="button" id="forgotPasswordButton">Forgot password</button>
      </form>
    </section>
  </main>
</div>

<!-- Main app shell. Individual panels are populated by assets/js/app.js using JSON APIs. -->
<div id="appView" class="<?php echo $user ? '' : 'd-none'; ?>">
  <!-- Sidebar navigation is role-filtered in JavaScript and enforced again by API controllers. -->
  <aside class="sidebar">
    <img src="<?php echo htmlspecialchars($base); ?>/../POS_v1/_review_tivoli/tivoli/img/Tivoli.png" alt="Tivoli" class="sidebar-logo">
    <nav class="nav flex-column nav-pills" id="mainNav">
      <button class="nav-link active" data-view="dashboard">Dashboard</button>
      <button class="nav-link" data-view="bookings">Bookings</button>
      <button class="nav-link manager-only" data-view="payments">Payments</button>
      <button class="nav-link manager-only" data-view="reports">Reports</button>
      <button class="nav-link" data-view="rooms">Rooms</button>
      <button class="nav-link" data-view="stock">Stock</button>
      <button class="nav-link" data-view="extras">Extras</button>
      <button class="nav-link manager-only" data-view="expenses">Expenses</button>
      <button class="nav-link admin-only" data-view="users">Users</button>
      <button class="nav-link manager-only" data-view="audit">Audit</button>
    </nav>
  </aside>

  <div class="content">
    <header class="topbar">
      <button class="btn btn-outline-secondary d-lg-none menu-toggle" id="menuButton" aria-label="Open menu">Menu</button>
      <div class="topbar-identity"><strong id="currentUser"><?php echo htmlspecialchars($user['name'] ?? ''); ?></strong><span class="text-secondary" id="currentRole"><?php echo htmlspecialchars($user['role'] ?? ''); ?></span></div>
      <div class="topbar-actions"><button class="btn btn-outline-primary btn-sm" id="resetPasswordButton">Reset password</button><button class="btn btn-outline-danger btn-sm" id="logoutButton">Logout</button></div>
    </header>

    <!-- Panels stay in the DOM and are shown/hidden as the user navigates. -->
    <main class="container-fluid py-4">
      <section data-panel="dashboard">
        <div class="page-header"><div><h2>Dashboard</h2><p>Today's rooms, sales, and recent activity.</p></div><button class="btn btn-outline-primary" data-refresh="dashboard">Refresh</button></div>
        <div class="row g-3" id="dashboardCards"></div>
        <div class="row g-3 mt-1">
          <div class="col-12 col-xl-7"><div class="surface chart-surface"><h5>Revenue</h5><div class="chart-box"><canvas id="revenueChart"></canvas></div><div class="extras-month-panel"><h5>Extras Sold This Month</h5><div class="extras-chart-box"><canvas id="extrasMonthChart"></canvas></div></div></div></div>
          <div class="col-12 col-xl-5"><div class="surface"><h5>Recent Activity</h5><div id="recentActivity"></div></div></div>
        </div>
      </section>
            <section class="d-none" data-panel="rooms">
        <div class="page-header"><div><h2>Rooms</h2></div></div>
        <div class="rooms-layout">
          <form id="newRoomForm" class="surface room-inline-form" novalidate>
            <div class="form-panel-heading"><h5>New Room</h5><span>Add rooms and service spaces without leaving the list.</span></div>
            <div class="row g-3 room-form-stack">
              <div class="col-12"><label class="form-label">Name <span class="text-danger">*</span></label><input name="name" class="form-control" required></div>
              <div class="col-12"><label class="form-label">Type <span class="text-danger">*</span></label><input name="type" class="form-control" required></div>
              <div class="col-12"><label class="form-label">Rate <span class="text-danger">*</span></label><input name="rate" type="number" step="0.01" min="0" class="form-control" required></div>
              <div class="col-12"><label class="form-label">Active</label><select name="active" class="form-select"><option value="1">Yes</option><option value="0">No</option></select></div>
              <div class="col-12"><label class="form-label">Occupancy Counted</label><select name="occupancy_counted" class="form-select"><option value="1">Yes</option><option value="0">No</option></select></div>
            </div>
            <div class="alert d-none" id="newRoomStatus"></div>
            <div class="room-inline-actions"><button class="btn btn-primary" type="submit">Save Room</button><button class="btn btn-outline-secondary" type="reset">Clear</button></div>
          </form>
          <div id="roomsList" class="table-responsive surface"></div>
        </div>
      </section>
      <section class="d-none" data-panel="bookings">
        <div class="page-header"><div><h2>Bookings</h2><p>Available rooms, current stays, and previous bookings.</p></div></div>
        <div class="booking-tabs mb-3" role="tablist" aria-label="Booking views">
          <button class="booking-tab active" type="button" data-bookings-tab="current">Current Stay</button>
          <button class="booking-tab" type="button" data-bookings-tab="history">Previous Bookings</button>
        </div>
        <div data-bookings-panel="current">
          <div id="bookingRoomPicker" class="mb-3"></div>
          <div id="bookingsList" class="table-responsive surface"></div>
        </div>
        <div class="d-none" data-bookings-panel="history">
          <form id="bookingHistoryFilters" class="surface booking-history-filters mb-3">
            <div><label class="form-label">Search</label><input name="search" class="form-control" placeholder="Guest, room, contact"></div>
            <div><label class="form-label">Status</label><select name="status" class="form-select"><option value="all">All previous</option><option value="checked_out">Checked out</option><option value="cancelled">Cancelled</option></select></div>
            <div><label class="form-label">From</label><input name="from" type="date" class="form-control"></div>
            <div><label class="form-label">To</label><input name="to" type="date" class="form-control"></div>
            <div class="booking-history-filter-actions"><button class="btn btn-primary">Apply</button><button class="btn btn-outline-secondary" type="button" id="resetBookingHistoryFilters">Reset</button></div>
          </form>
          <div id="bookingHistoryList" class="table-responsive surface"></div>
          <div id="bookingHistoryPager" class="booking-history-pager mt-3"></div>
        </div>
      </section>
      <section class="d-none" data-panel="extras">
        <div class="page-header"><div><h2>Extras</h2></div></div>
        <div class="entity-layout">
          <form id="extraForm" class="surface room-inline-form" novalidate>
            <input type="hidden" name="id" value="">
            <div class="form-panel-heading"><h5 id="extraFormTitle">New Extra</h5><span>Keep sale items clear, priced, and ready for booking charges.</span></div>
            <div class="row g-3 room-form-stack">
              <div class="col-12"><label class="form-label">Name <span class="text-danger">*</span></label><input name="name" class="form-control" required></div>
              <div class="col-12"><label class="form-label">Price <span class="text-danger">*</span></label><input name="price" type="number" step="0.01" min="0" class="form-control" required></div>
              <div class="col-12"><label class="form-label">Stock Tracked</label><select name="stock_tracked" class="form-select"><option value="1">Yes</option><option value="0">No</option></select></div>
              <div class="col-12"><label class="form-label">Status</label><select name="active" class="form-select"><option value="1">Active</option><option value="0">Inactive</option></select></div>
            </div>
            <div class="alert d-none" id="extraStatus"></div>
            <div class="room-inline-actions"><button class="btn btn-primary" type="submit" id="extraSubmitButton">Save Extra</button><button class="btn btn-outline-secondary" type="reset">Clear</button></div>
          </form>
          <div id="extrasList" class="table-responsive surface"></div>
        </div>
      </section>
      <section class="d-none" data-panel="stock">
        <div class="page-header"><div><h2>Stock</h2></div></div>
        <div class="booking-tabs mb-3" role="tablist" aria-label="Stock views">
          <button class="booking-tab active" type="button" data-stock-tab="movements">Movements</button>
          <button class="booking-tab" type="button" data-stock-tab="inventory">Inventory</button>
        </div>
        <div data-stock-panel="movements">
          <div class="entity-layout">
            <form id="stockMovementForm" class="surface room-inline-form" novalidate>
              <div class="form-panel-heading"><h5>Record Stock Movement</h5><span>Update stock the moment items arrive, leave, return, or are wasted.</span></div>
              <div class="row g-3 room-form-stack">
                <div class="col-12"><label class="form-label">Extra <span class="text-danger">*</span></label><select name="extra_id" class="form-select" id="stockMovementExtra" required><option value="">Select extra</option></select></div>
                <div class="col-12"><label class="form-label">Movement Type</label><select name="movement_type" class="form-select"><option value="in">In</option><option value="out">Out</option><option value="adjustment">Adjustment</option><option value="return">Return</option><option value="waste">Waste</option></select></div>
                <div class="col-12"><label class="form-label">Quantity <span class="text-danger">*</span></label><input name="qty" type="number" step="0.01" min="0.01" class="form-control" required></div>
                <div class="col-12"><label class="form-label">Note</label><input name="note" class="form-control" maxlength="255" placeholder="Optional reason or reference"></div>
              </div>
              <div class="alert d-none" id="stockMovementStatus"></div>
              <div class="room-inline-actions"><button class="btn btn-primary" type="submit" id="stockMovementSubmitButton">Save Movement</button><button class="btn btn-outline-secondary" type="reset">Clear</button></div>
            </form>
            <div>
              <form id="stockMovementFilters" class="surface booking-history-filters mb-3">
                <div><label class="form-label">Search</label><input name="search" class="form-control" placeholder="Item or note"></div>
                <div><label class="form-label">Extra</label><select name="extra_id" class="form-select" id="stockFilterExtra"><option value="">All extras</option></select></div>
                <div><label class="form-label">Type</label><select name="movement_type" class="form-select"><option value="">All types</option><option value="in">In</option><option value="out">Out</option><option value="adjustment">Adjustment</option><option value="return">Return</option><option value="waste">Waste</option></select></div>
                <div><label class="form-label">From</label><input name="from" type="date" class="form-control"></div>
                <div><label class="form-label">To</label><input name="to" type="date" class="form-control"></div>
                <div class="booking-history-filter-actions"><button class="btn btn-primary">Apply</button><button class="btn btn-outline-secondary" type="button" id="resetStockMovementFilters">Reset</button></div>
              </form>
              <div id="stockMovementList" class="table-responsive surface"></div>
              <div id="stockMovementPager" class="booking-history-pager mt-3"></div>
            </div>
          </div>
        </div>
        <div class="d-none" data-stock-panel="inventory"><div id="stockInventoryList" class="table-responsive surface"></div></div>
      </section>
      <section class="d-none" data-panel="payments"><div class="page-header"><div><h2>Payments</h2><p>Received payments and void status.</p></div></div><div id="paymentsList" class="table-responsive surface"></div></section>
      <section class="d-none" data-panel="expenses"><div class="page-header"><div><h2>Expenses</h2><p>Operating expenses and reporting categories.</p></div><button class="btn btn-primary" id="newExpenseButton">New Expense</button></div><div id="expensesList" class="table-responsive surface"></div></section>
      <section class="d-none" data-panel="reports">
        <div class="page-header"><div><h2>Reports</h2><p>Management analytics for revenue, cashflow, occupancy, inventory, staff activity, and audit issues.</p></div><div class="d-flex gap-2"><button class="btn btn-outline-secondary" type="button" id="printReportsButton">Print</button><button class="btn btn-primary" type="button" id="exportReportsButton">Export CSV</button></div></div>
        <form id="reportFilters" class="surface report-filters mb-3" data-default-range="month-to-date">
          <div><label class="form-label">Start</label><input name="start" type="date" class="form-control"></div>
          <div><label class="form-label">End</label><input name="end" type="date" class="form-control"></div>
          <div><label class="form-label">Room</label><select name="room_id" class="form-select" id="reportRoomFilter"><option value="">All rooms</option></select></div>
          <div><label class="form-label">Room Type</label><select name="room_type" class="form-select" id="reportRoomTypeFilter"><option value="">All types</option></select></div>
          <div><label class="form-label">Payment</label><select name="payment_method" class="form-select" id="reportPaymentFilter"><option value="">All methods</option></select></div>
          <div><label class="form-label">Category</label><select name="expense_category_id" class="form-select" id="reportCategoryFilter"><option value="">All categories</option></select></div>
          <div><label class="form-label">Staff</label><select name="staff_id" class="form-select" id="reportStaffFilter"><option value="">All staff</option></select></div>
          <div class="report-filter-actions"><button class="btn btn-primary">Apply</button><button class="btn btn-outline-secondary" type="button" id="resetReportFilters">Reset</button></div>
        </form>
        <div id="reportsPanel"></div>
      </section>
      <section class="d-none" data-panel="users">
        <div class="page-header"><div><h2>Users</h2><p>Staff access and role permissions.</p></div></div>
        <div class="entity-layout">
          <form id="userForm" class="surface room-inline-form" novalidate>
            <input type="hidden" name="id" value="">
            <div class="form-panel-heading"><h5 id="userFormTitle">New User</h5><span>Create staff access with the least role needed for the job.</span></div>
            <div class="row g-3 room-form-stack">
              <div class="col-12"><label class="form-label">Name <span class="text-danger">*</span></label><input name="name" class="form-control" required></div>
              <div class="col-12"><label class="form-label">Email <span class="text-danger">*</span></label><input name="email" type="email" class="form-control" required></div>
              <div class="col-12"><label class="form-label">Role</label><select name="role" class="form-select"><option value="reception">Reception</option><option value="manager">Manager</option><option value="administrator">Administrator</option><option value="auditor">Auditor</option></select></div>
              <div class="col-12"><label class="form-label">Status</label><select name="active" class="form-select"><option value="1">Active</option><option value="0">Inactive</option></select></div>
              <div class="col-12"><label class="form-label">Password <span class="text-danger" id="userPasswordRequired">*</span></label><input name="password" type="password" minlength="8" class="form-control" required></div>
            </div>
            <div class="alert d-none" id="userStatus"></div>
            <div class="room-inline-actions"><button class="btn btn-primary" type="submit" id="userSubmitButton">Save User</button><button class="btn btn-outline-secondary" type="reset">Clear</button></div>
          </form>
          <div id="usersList" class="table-responsive surface"></div>
        </div>
      </section>
      <section class="d-none" data-panel="audit">
        <div class="page-header"><div><h2>Audit Logs</h2><p>Operational and financial change history.</p></div></div>
        <form id="auditFilters" class="surface booking-history-filters mb-3">
          <div><label class="form-label">Search</label><input name="search" class="form-control" placeholder="Action, entity, user"></div>
          <div><label class="form-label">Entity</label><input name="entity" class="form-control" placeholder="booking, user, room"></div>
          <div><label class="form-label">Action</label><input name="action" class="form-control" placeholder="created, updated"></div>
          <div><label class="form-label">From</label><input name="from" type="date" class="form-control"></div>
          <div><label class="form-label">To</label><input name="to" type="date" class="form-control"></div>
          <div class="booking-history-filter-actions"><button class="btn btn-primary">Apply</button><button class="btn btn-outline-secondary" type="button" id="resetAuditFilters">Reset</button></div>
        </form>
        <div id="auditList" class="table-responsive surface"></div>
        <div id="auditPager" class="booking-history-pager mt-3"></div>
      </section>
    </main>
  </div>
</div>

<!-- Shared Bootstrap modal used for check-in, payments, stock movements, and CRUD forms. -->
<div class="modal fade" id="appModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="modalTitle"></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body" id="modalBody"></div></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="<?php echo htmlspecialchars($base); ?>/../assets/js/api.js"></script>
<script src="<?php echo htmlspecialchars($base); ?>/../assets/js/app.js"></script>
</body>
</html>

