/**
 * Main hotelpos browser UI.
 *
 * This file intentionally keeps rendering simple: it asks JSON endpoints for
 * data, renders Bootstrap tables/cards/forms, and sends mutations back through
 * HotelPOSApi. The backend remains the source of truth for validation,
 * permissions, billing, stock, and financial totals.
 */
(function () {
  const api = window.HotelPOSApi;
  const $ = (sel) => document.querySelector(sel);
  const money = (n) => `GHS ${Number(n || 0).toFixed(2)}`;
  const extrasSoldAmount = (summary) => money(summary?.total_amount);
  const esc = (v) => String(v ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const pad2 = (n) => String(n).padStart(2, '0');
  const isDateColumn = (column) => /(^|_)(date|at)$/.test(column);

  // Display all app dates as dd/mm/yy hh:mm while keeping API/database values unchanged.
  function formatDateTime(value, fallback = '') {
    if (value === null || value === undefined || value === '') return fallback;
    const raw = String(value).trim();
    const sqlMatch = raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::\d{2})?)?/);
    if (sqlMatch) {
      const [, year, month, day, hour = '00', minute = '00'] = sqlMatch;
      return `${day}/${month}/${year.slice(-2)} ${hour}:${minute}`;
    }

    const parsed = new Date(raw);
    if (!Number.isNaN(parsed.getTime())) {
      return `${pad2(parsed.getDate())}/${pad2(parsed.getMonth() + 1)}/${String(parsed.getFullYear()).slice(-2)} ${pad2(parsed.getHours())}:${pad2(parsed.getMinutes())}`;
    }

    return raw;
  }

  function tableCell(column, value) {
    return esc(isDateColumn(column) ? formatDateTime(value) : value);
  }
  let state = { user: null, rooms: [], extras: [], bookings: [], categories: [], bookingsTab: 'current', bookingHistory: [], bookingHistoryPage: 1, bookingHistoryPagination: null, users: [], stockTab: 'movements', stockMovements: [], stockMovementPage: 1, stockMovementPagination: null, auditLogs: [], auditPage: 1, auditPagination: null };
  let modal;

  // Initialize Bootstrap widgets, bind event handlers, restore the session,
  // and load the dashboard when the user is already authenticated.
  document.addEventListener('DOMContentLoaded', async () => {
    modal = new bootstrap.Modal($('#appModal'));
    bindShell();
    await hydrateSession();
    if (!$('#appView').classList.contains('d-none')) loadView('dashboard');
  });

  // Bind long-lived shell controls once. Dynamic table/form controls are bound
  // after each render because their DOM nodes are recreated.
  function bindShell() {
    $('#loginForm')?.addEventListener('submit', login);
    $('#forgotPasswordButton')?.addEventListener('click', forgotPasswordForm);
    $('#resetPasswordButton')?.addEventListener('click', resetPasswordForm);
    $('#logoutButton')?.addEventListener('click', logout);
    $('#menuButton')?.addEventListener('click', () => document.body.classList.toggle('sidebar-open'));
    document.querySelectorAll('#mainNav [data-view]').forEach(btn => btn.addEventListener('click', () => {
      document.querySelectorAll('#mainNav .nav-link').forEach(x => x.classList.remove('active'));
      btn.classList.add('active');
      loadView(btn.dataset.view);
      document.body.classList.remove('sidebar-open');
    }));
    $('[data-refresh="dashboard"]')?.addEventListener('click', () => loadDashboard());
    $('#newRoomForm')?.addEventListener('submit', createRoomInline);
    $('#newRoomForm')?.addEventListener('reset', () => showNewRoomStatus('', 'd-none'));
    $('#extraForm')?.addEventListener('submit', saveExtraInline);
    $('#extraForm')?.addEventListener('reset', resetExtraForm);
    $('#newStockButton')?.addEventListener('click', stockForm);
    $('#newExpenseButton')?.addEventListener('click', expenseForm);
    $('#userForm')?.addEventListener('submit', saveUserInline);
    $('#userForm')?.addEventListener('reset', resetUserForm);
    document.querySelectorAll('[data-stock-tab]').forEach(btn => btn.addEventListener('click', () => switchStockTab(btn.dataset.stockTab)));
    $('#stockMovementFilters')?.addEventListener('submit', e => { e.preventDefault(); state.stockMovementPage = 1; loadStock(); });
    $('#resetStockMovementFilters')?.addEventListener('click', resetStockMovementFilters);
    $('#auditFilters')?.addEventListener('submit', e => { e.preventDefault(); state.auditPage = 1; loadAudit(); });
    $('#resetAuditFilters')?.addEventListener('click', resetAuditFilters);
    document.querySelectorAll('[data-bookings-tab]').forEach(btn => btn.addEventListener('click', () => switchBookingsTab(btn.dataset.bookingsTab)));
    $('#bookingHistoryFilters')?.addEventListener('submit', e => { e.preventDefault(); state.bookingHistoryPage = 1; loadBookingHistory(); });
    $('#resetBookingHistoryFilters')?.addEventListener('click', resetBookingHistoryFilters);
  }
  // Ask the server whether this browser already has a valid session.
  async function hydrateSession() {
    try {
      const data = await api.get('/me');
      if (data.csrf) api.setCsrf(data.csrf);
      if (data.user) setUser(data.user);
    } catch (_) {}
  }

  // Login is AJAX-based so validation/auth failures can appear in place.
  async function login(e) {
    e.preventDefault();
    const body = Object.fromEntries(new FormData(e.currentTarget).entries());
    try {
      const data = await api.post('/login', body);
      api.setCsrf(data.csrf);
      setUser(data.user);
      $('#loginView').classList.add('d-none');
      $('#appView').classList.remove('d-none');
      loadView('dashboard');
    } catch (err) {
      $('#loginError').textContent = err.message;
      $('#loginError').classList.remove('d-none');
    }
  }

  async function logout() {
    await api.post('/logout', {});
    location.reload();
  }

  // Forgot password is shown before login. It asks for email only, then the
  // backend creates a time-limited reset link and sends/logs it.
  function forgotPasswordForm() {
    const currentEmail = $('#loginForm input[name="email"]')?.value || '';
    openForm('Forgot Password', `<form class="row g-3">
      <div class="col-12"><label class="form-label">Email</label><input name="email" type="email" class="form-control" value="${esc(currentEmail)}" required></div>
      <div class="col-12"><div class="alert d-none" id="forgotPasswordStatus"></div></div>
      <div class="col-12"><button class="btn btn-primary w-100">Send reset link</button></div>
    </form>`, async e => {
      e.preventDefault();
      const status = $('#forgotPasswordStatus');
      status.className = 'alert d-none';
      status.textContent = '';
      try {
        const body = Object.fromEntries(new FormData(e.target).entries());
        await api.post('/password/forgot', body);
        status.className = 'alert alert-success';
        status.textContent = 'If that email belongs to an active account, a reset link has been sent.';
        $('#loginForm input[name="email"]').value = body.email;
      } catch (err) {
        status.className = 'alert alert-danger';
        status.textContent = err.message;
      }
    });
  }
  // Reset password is available after login from the top bar. It asks for the
  // current password so an unattended logged-in session cannot silently change it.
  function resetPasswordForm() {
    openForm('Reset Password', `<form class="row g-3">
      <div class="col-12"><label class="form-label">Current Password</label><input name="current_password" type="password" class="form-control" required></div>
      <div class="col-12"><label class="form-label">New Password</label><input name="password" type="password" class="form-control" minlength="8" required></div>
      <div class="col-12"><label class="form-label">Confirm Password</label><input name="password_confirm" type="password" class="form-control" minlength="8" required></div>
      <div class="col-12"><div class="alert d-none" id="resetPasswordStatus"></div></div>
      <div class="col-12"><button class="btn btn-primary w-100">Reset password</button></div>
    </form>`, async e => {
      e.preventDefault();
      const status = $('#resetPasswordStatus');
      status.className = 'alert d-none';
      status.textContent = '';
      try {
        const body = Object.fromEntries(new FormData(e.target).entries());
        await api.post('/password/reset', body);
        status.className = 'alert alert-success';
        status.textContent = 'Password reset successfully.';
        e.target.reset();
      } catch (err) {
        status.className = 'alert alert-danger';
        status.textContent = err.message;
      }
    });
  }
  // Update the visible identity and hide navigation items the role cannot use.
  // Server-side role guards still enforce the real security boundary.
  function setUser(user) {
    state.user = user;
    $('#currentUser').textContent = user.name || '';
    $('#currentRole').textContent = user.role || '';
    const manager = ['administrator', 'manager', 'auditor'].includes(user.role);
    const admin = user.role === 'administrator';
    document.querySelectorAll('.manager-only').forEach(el => el.classList.toggle('d-none', !manager));
    document.querySelectorAll('.admin-only').forEach(el => el.classList.toggle('d-none', !admin));
  }

  function showPanel(name) {
    document.querySelectorAll('[data-panel]').forEach(panel => panel.classList.toggle('d-none', panel.dataset.panel !== name));
  }

  async function loadView(name) {
    showPanel(name);
    const loaders = { dashboard: loadDashboard, rooms: loadRooms, bookings: loadBookings, extras: loadExtras, stock: loadStock, payments: loadPayments, expenses: loadExpenses, reports: loadReports, users: loadUsers, audit: loadAudit };
    await loaders[name]?.();
  }

  // Load reception-safe summary data and render the dashboard cards/chart.
  async function loadDashboard() {
    const d = await api.get('/dashboard');
    $('#dashboardCards').innerHTML = [
      ['Rooms', d.rooms.total, 'rooms'],
      ['Occupied', d.rooms.occupied, 'occupied'],
      ['Vacant', d.rooms.vacant, 'vacant'],
      ['Extras Sold Today', extrasSoldAmount(d.extras_sold_today), 'extras'],
      ['Today Revenue', money(d.today_revenue), 'revenue']
    ].map(([label, value, tone]) => `<div class="col-12 col-md"><div class="metric metric-${tone}"><div class="metric-label">${label}</div><div class="value">${value}</div></div></div>`).join('');
    $('#recentActivity').innerHTML = table(d.recent_activity || [], ['created_at', 'action', 'entity', 'user_name']);
    renderRevenue(d.daily_revenue || []);
    renderMonthlyExtras(d.extras_sold_month || []);
  }

  let revenueChart;
  function renderRevenue(rows) {
    const canvas = $('#revenueChart');
    if (!canvas || !window.Chart) return;

    const labels = rows.map(r => formatDateTime(r.day));
    const values = rows.map(r => Number(r.total || 0));

    if (revenueChart) revenueChart.destroy();
    revenueChart = new Chart(canvas, {
      type: 'line',
      data: {
        labels,
        datasets: [{
          label: 'Revenue',
          data: values,
          borderColor: '#0d6efd',
          backgroundColor: 'rgba(13, 110, 253, .12)',
          borderWidth: 2,
          pointRadius: 2,
          pointHoverRadius: 5,
          fill: true,
          tension: .25
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
          y: { beginAtZero: true, ticks: { callback: value => money(value) } },
          x: { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 } }
        },
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: ctx => `Revenue: ${money(ctx.parsed.y)}` } }
        }
      }
    });
  }


  let extrasMonthChart;
  function renderMonthlyExtras(rows) {
    const canvas = $('#extrasMonthChart');
    if (!canvas || !window.Chart) return;

    const visibleRows = rows.slice(0, 12);
    const labels = visibleRows.map(r => r.extra_name);
    const quantities = visibleRows.map(r => Number(r.total_qty || 0));
    const amounts = visibleRows.map(r => Number(r.total_amount || 0));

    if (extrasMonthChart) extrasMonthChart.destroy();
    extrasMonthChart = new Chart(canvas, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: 'Quantity sold',
          data: quantities,
          backgroundColor: '#198754',
          borderColor: '#146c43',
          borderWidth: 1,
          borderRadius: 4
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          x: { beginAtZero: true, ticks: { precision: 0 } },
          y: { ticks: { autoSkip: false } }
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: ctx => `Quantity: ${Number(ctx.parsed.x || 0).toLocaleString(undefined, { maximumFractionDigits: 2 })}`,
              afterLabel: ctx => `Sales: ${money(amounts[ctx.dataIndex])}`
            }
          }
        }
      }
    });
  }
  async function loadRooms() {
    const d = await api.get('/rooms');
    state.rooms = d.rooms || [];
    renderRooms();
  }

  function renderRooms() {
    const canEdit = ['administrator', 'manager'].includes(state.user?.role);
    if (!state.rooms.length) {
      $('#roomsList').innerHTML = '<div class="empty-state">No rooms found.</div>';
      return;
    }

    $('#roomsList').innerHTML = `<table class="table table-sm table-striped app-table rooms-table"><thead><tr><th>Name</th><th>Rate</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>${state.rooms.map(room => `<tr><td>${esc(room.name)}</td><td>${money(room.rate)}</td><td><span class="badge ${roomStatusClass(room.status)}">${esc(room.status)}</span></td><td class="text-end"><button class="btn btn-sm btn-outline-secondary" data-room-view="${room.id}">View</button>${canEdit ? ` <button class="btn btn-sm btn-outline-primary" data-room-edit="${room.id}">Edit</button>` : ''}</td></tr>`).join('')}</tbody></table>`;
    document.querySelectorAll('[data-room-view]').forEach(btn => btn.addEventListener('click', () => viewRoom(btn.dataset.roomView)));
    document.querySelectorAll('[data-room-edit]').forEach(btn => btn.addEventListener('click', () => roomForm(roomById(btn.dataset.roomEdit))));
  }

  function roomById(id) {
    return state.rooms.find(room => Number(room.id) === Number(id));
  }

  function roomStatusClass(status) {
    return ({ vacant: 'text-bg-success', occupied: 'text-bg-primary', dirty: 'text-bg-warning', maintenance: 'text-bg-danger' })[status] || 'text-bg-secondary';
  }

  function focusNewRoomForm() {
    const form = $('#newRoomForm');
    form?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    form?.querySelector('input[name="name"]')?.focus();
  }

  function showNewRoomStatus(message, className = 'd-none') {
    const status = $('#newRoomStatus');
    if (!status) return;
    status.className = `alert ${className}`;
    status.textContent = message;
  }

  function resetNewRoomForm(event) {
    event.currentTarget.classList.remove('was-validated');
    showNewRoomStatus('', 'd-none');
  }

  function validateNewRoomForm(form) {
    const data = Object.fromEntries(new FormData(form).entries());
    const errors = [];
    if (!String(data.name || '').trim()) errors.push('Room name is required.');
    if (!String(data.type || '').trim()) errors.push('Room type is required.');
    if (String(data.rate || '').trim() === '') errors.push('Rate is required.');
    if (String(data.rate || '').trim() !== '' && Number(data.rate) < 0) errors.push('Rate cannot be negative.');
    return { data, errors };
  }

  async function createRoomInline(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const submit = form.querySelector('button[type="submit"]');
    const { data, errors } = validateNewRoomForm(form);

    form.classList.add('was-validated');
    if (errors.length) {
      showNewRoomStatus(errors.join(' '), 'alert-danger');
      return;
    }

    showNewRoomStatus('', 'd-none');
    submit.disabled = true;
    submit.textContent = 'Saving...';
    try {
      await api.post('/rooms/save', data);
      form.reset();
      form.classList.remove('was-validated');
      showNewRoomStatus('Room saved successfully.', 'alert-success');
      await loadRooms();
    } catch (err) {
      showNewRoomStatus(err.message, 'alert-danger');
    } finally {
      submit.disabled = false;
      submit.textContent = 'Save Room';
    }
  }


  function viewRoom(id) {
    const room = roomById(id);
    if (!room) return;
    const canEdit = ['administrator', 'manager'].includes(state.user?.role);
    const isActive = Number(room.active) === 1;
    const counted = Number(room.occupancy_counted) === 1;
    openForm('Room Details', `<form class="room-profile">
      <div class="room-profile-hero">
        <div>
          <div class="room-profile-label">${esc(room.type)} room</div>
          <h3>${esc(room.name)}</h3>
        </div>
        <span class="badge ${roomStatusClass(room.status)} room-status-pill">${esc(room.status)}</span>
      </div>
      <div class="room-profile-rate">
        <span>Current rate</span>
        <strong>${money(room.rate)}</strong>
      </div>
      <div class="room-detail-grid">
        <div class="room-detail-tile"><span>Type</span><strong>${esc(room.type)}</strong></div>
        <div class="room-detail-tile"><span>Availability</span><strong>${esc(room.status)}</strong></div>
        <div class="room-detail-tile"><span>Active</span><strong>${isActive ? 'Yes' : 'No'}</strong></div>
        <div class="room-detail-tile"><span>Counts in occupancy</span><strong>${counted ? 'Yes' : 'No'}</strong></div>
        <div class="room-detail-tile room-detail-wide"><span>Created</span><strong>${formatDateTime(room.created_at, 'Not recorded')}</strong></div>
        <div class="room-detail-tile room-detail-wide"><span>Last updated</span><strong>${formatDateTime(room.updated_at, 'Not updated yet')}</strong></div>
      </div>
      <div class="room-profile-footer">
        ${canEdit ? `<button class="btn btn-primary" type="button" data-room-edit-from-view="${room.id}">Edit room</button>` : ''}
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button>
      </div>
    </form>`, e => e.preventDefault());
    $('[data-room-edit-from-view]')?.addEventListener('click', () => roomForm(room));
  }
  function switchBookingsTab(tab, shouldLoadHistory = true) {
    state.bookingsTab = tab === 'history' ? 'history' : 'current';
    document.querySelectorAll('[data-bookings-tab]').forEach(btn => btn.classList.toggle('active', btn.dataset.bookingsTab === state.bookingsTab));
    document.querySelectorAll('[data-bookings-panel]').forEach(panel => panel.classList.toggle('d-none', panel.dataset.bookingsPanel !== state.bookingsTab));
    if (shouldLoadHistory && state.bookingsTab === 'history') loadBookingHistory();
  }

  // Bookings include service-computed totals so the UI does not duplicate billing logic.
  async function loadBookings() {
    switchBookingsTab(state.bookingsTab || 'current', false);
    const roomsData = await api.get('/rooms');
    state.rooms = roomsData.rooms || [];

    const d = await api.get('/bookings?status=active&per_page=50');
    state.bookings = d.bookings || [];
    renderBookingRoomPicker();
    renderCurrentBookings();
    if (state.bookingsTab === 'history') await loadBookingHistory();
  }

  function renderCurrentBookings() {
    if (!state.bookings.length) {
      $('#bookingsList').innerHTML = '<div class="text-secondary py-3">No current stays. Choose a vacant room above to check in a guest.</div>';
      return;
    }
    $('#bookingsList').innerHTML = `<table class="table table-sm"><thead><tr><th>Guest</th><th>Room</th><th>Check-in</th><th>Nights</th><th>Total</th><th>Balance</th><th></th></tr></thead><tbody>${state.bookings.map(b => `<tr><td>${esc(b.guest_name)}</td><td>${esc(b.room_name)}</td><td>${formatDateTime(b.checkin_at)}</td><td>${b.totals?.nights ?? ''}</td><td>${money(b.totals?.grand_total)}</td><td>${money(b.totals?.balance)}</td><td class="text-end"><button class="btn btn-sm btn-outline-secondary" data-booking-view="${b.id}">View</button> <button class="btn btn-sm btn-outline-primary" data-pay="${b.id}">Pay</button> <button class="btn btn-sm btn-danger" data-checkout="${b.id}">Checkout</button> <button class="btn btn-sm btn-outline-secondary" data-extra="${b.id}">Extra</button></td></tr>`).join('')}</tbody></table>`;
    document.querySelectorAll('[data-booking-view]').forEach(b => b.addEventListener('click', () => viewBooking(b.dataset.bookingView)));
    document.querySelectorAll('[data-pay]').forEach(b => b.addEventListener('click', () => paymentForm(b.dataset.pay)));
    document.querySelectorAll('[data-checkout]').forEach(b => b.addEventListener('click', () => checkout(b.dataset.checkout)));
    document.querySelectorAll('[data-extra]').forEach(b => b.addEventListener('click', () => bookingExtraForm(b.dataset.extra)));
  }
  function bookingHistoryQuery() {
    const form = $('#bookingHistoryFilters');
    const data = form ? Object.fromEntries(new FormData(form).entries()) : {};
    const params = new URLSearchParams();
    params.set('status', data.status && data.status !== 'all' ? data.status : 'previous');
    params.set('page', String(state.bookingHistoryPage || 1));
    params.set('per_page', '10');
    ['search', 'from', 'to'].forEach(key => {
      if (data[key]) params.set(key, data[key]);
    });
    return params.toString();
  }

  async function loadBookingHistory() {
    const d = await api.get(`/bookings?${bookingHistoryQuery()}`);
    state.bookingHistory = d.bookings || [];
    state.bookingHistoryPagination = d.pagination || { page: 1, pages: 1, total: 0 };
    renderBookingHistory();
  }

  function renderBookingHistory() {
    const rows = state.bookingHistory;
    const pagination = state.bookingHistoryPagination || { page: 1, pages: 1, total: 0 };
    if (!rows.length) {
      $('#bookingHistoryList').innerHTML = '<div class="text-secondary py-3">No previous bookings match these filters.</div>';
    } else {
      $('#bookingHistoryList').innerHTML = `<table class="table table-sm table-striped"><thead><tr><th>Guest</th><th>Room</th><th>Status</th><th>Check-in</th><th>Checkout</th><th>Total</th><th>Paid</th><th></th></tr></thead><tbody>${rows.map(b => `<tr><td>${esc(b.guest_name)}</td><td>${esc(b.room_name)}</td><td><span class="badge ${b.status === 'checked_out' ? 'text-bg-success' : 'text-bg-secondary'}">${esc(b.status)}</span></td><td>${formatDateTime(b.checkin_at)}</td><td>${formatDateTime(b.checkout_at)}</td><td>${money(b.totals?.grand_total)}</td><td>${money(b.totals?.paid_total)}</td><td class="text-end"><button class="btn btn-sm btn-outline-secondary" data-history-booking-view="${b.id}">View</button></td></tr>`).join('')}</tbody></table>`;
    }
    $('#bookingHistoryPager').innerHTML = `<div class="d-flex align-items-center justify-content-between gap-3"><div class="text-secondary">${pagination.total || 0} booking${Number(pagination.total || 0) === 1 ? '' : 's'} &middot; Page ${pagination.page || 1} of ${pagination.pages || 1}</div><div class="btn-group"><button class="btn btn-outline-secondary btn-sm" data-history-page="prev" ${(pagination.page || 1) <= 1 ? 'disabled' : ''}>Previous</button><button class="btn btn-outline-secondary btn-sm" data-history-page="next" ${(pagination.page || 1) >= (pagination.pages || 1) ? 'disabled' : ''}>Next</button></div></div>`;
    document.querySelectorAll('[data-history-booking-view]').forEach(btn => btn.addEventListener('click', () => viewHistoryBooking(btn.dataset.historyBookingView)));
    document.querySelectorAll('[data-history-page]').forEach(btn => btn.addEventListener('click', () => {
      const page = state.bookingHistoryPagination?.page || 1;
      state.bookingHistoryPage = btn.dataset.historyPage === 'next' ? page + 1 : page - 1;
      loadBookingHistory();
    }));
  }

  function viewBookingRecord(booking, title = 'Booking Details') {
    openForm(title, `<form class="room-profile">
      <div class="room-profile-hero">
        <div>
          <div class="room-profile-label">${esc(booking.room_name)} booking</div>
          <h3>${esc(booking.guest_name)}</h3>
        </div>
        <span class="badge ${booking.status === 'checked_out' ? 'text-bg-success' : 'text-bg-secondary'} room-status-pill">${esc(booking.status)}</span>
      </div>
      <div class="room-detail-grid">
        <div class="room-detail-tile"><span>Check-in</span><strong>${formatDateTime(booking.checkin_at)}</strong></div>
        <div class="room-detail-tile"><span>Checkout</span><strong>${formatDateTime(booking.checkout_at, 'Not checked out')}</strong></div>
        <div class="room-detail-tile"><span>Total</span><strong>${money(booking.totals?.grand_total)}</strong></div>
        <div class="room-detail-tile"><span>Paid</span><strong>${money(booking.totals?.paid_total)}</strong></div>
        <div class="room-detail-tile"><span>Contact</span><strong>${esc(booking.contact || 'Not recorded')}</strong></div>
        <div class="room-detail-tile"><span>Nationality</span><strong>${esc(booking.nationality || 'Not recorded')}</strong></div>
      </div>
      <div class="booking-extras-panel"><div class="booking-extras-heading"><h4>Extras Added</h4><span>${money(booking.totals?.extras_total)}</span></div>${bookingExtrasList(booking)}</div>
      <div class="booking-extras-panel"><div class="booking-extras-heading"><h4>Payments</h4><span>${money(booking.totals?.paid_total)}</span></div>${bookingPaymentsList(booking)}</div>
      <div class="room-profile-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button></div>
    </form>`, e => e.preventDefault());
  }
  function viewHistoryBooking(id) {
    const booking = state.bookingHistory.find(item => Number(item.id) === Number(id));
    if (!booking) return;
    viewBookingRecord(booking, 'Booking Details');
  }

  function resetBookingHistoryFilters() {
    $('#bookingHistoryFilters')?.reset();
    state.bookingHistoryPage = 1;
    loadBookingHistory();
  }

  function resetStockMovementFilters() {
    $('#stockMovementFilters')?.reset();
    state.stockMovementPage = 1;
    loadStock();
  }

  function resetAuditFilters() {
    $('#auditFilters')?.reset();
    state.auditPage = 1;
    loadAudit();
  }
  function renderBookingRoomPicker() {
    const activeRooms = state.rooms.filter(room => Number(room.active) === 1);
    const availableCount = activeRooms.filter(room => room.status === 'vacant').length;
    const occupiedCount = activeRooms.filter(room => room.status === 'occupied').length;
    const cards = activeRooms.map(room => bookingRoomCard(room)).join('');
    $('#bookingRoomPicker').innerHTML = `<div class="booking-room-board">
      <div class="booking-board-heading">
        <div>
          <strong>${availableCount} vacant &middot; ${occupiedCount} occupied</strong>
        </div>
        <div class="checkin-legend"><span class="legend-dot available"></span>Vacant <span class="legend-dot occupied"></span>Occupied <span class="legend-dot unavailable"></span>Unavailable</div>
      </div>
      <div class="booking-room-grid">${cards || '<div class="text-secondary py-3">No active rooms found.</div>'}</div>
    </div>`;
    document.querySelectorAll('[data-room-checkin]').forEach(btn => btn.addEventListener('click', () => checkinForm(btn.dataset.roomCheckin)));
    document.querySelectorAll('[data-room-booking-view]').forEach(btn => btn.addEventListener('click', () => viewBooking(btn.dataset.roomBookingView)));
    document.querySelectorAll('[data-room-booking-checkout]').forEach(btn => btn.addEventListener('click', () => checkout(btn.dataset.roomBookingCheckout)));
  }

  function bookingRoomCard(room) {
    const booking = activeBookingForRoom(room.id);
    const occupied = room.status === 'occupied';
    const vacant = room.status === 'vacant';
    const disabled = !vacant && !occupied;
    const statusClass = roomStatusClass(room.status);
    const actions = occupied && booking
      ? `<div class="booking-room-actions two-actions"><button type="button" class="btn btn-outline-secondary" data-room-booking-view="${booking.id}">View</button><button type="button" class="btn btn-danger" data-room-booking-checkout="${booking.id}">Check-Out</button></div>`
      : vacant
        ? `<div class="booking-room-actions"><button type="button" class="btn btn-primary" data-room-checkin="${room.id}">Check-In</button></div>`
        : `<div class="booking-room-actions"><button type="button" class="btn btn-outline-secondary" disabled>Unavailable</button></div>`;

    return `<article class="booking-room-card ${disabled ? 'is-disabled' : ''}">
      <div class="booking-room-top"><div><h3>${esc(room.name)}</h3><p>${esc(room.type)} &mdash; ${money(room.rate)}</p></div><span class="badge ${statusClass}">${esc(room.status)}</span></div>
      ${booking ? `<div class="booking-room-guest">${esc(booking.guest_name)}</div>` : ''}
      ${actions}
    </article>`;
  }

  function activeBookingForRoom(roomId) {
    return state.bookings.find(booking => Number(booking.room_id) === Number(roomId) && booking.status === 'active');
  }
  function bookingExtrasList(booking) {
    const extras = booking.totals?.extras || [];
    if (!extras.length) {
      return '<div class="booking-extra-empty">No extras added yet.</div>';
    }
    return `<div class="booking-extra-list">${extras.map(extra => `<div class="booking-extra-row"><div><strong>${esc(extra.description || 'Extra')}</strong><span>${Number(extra.qty || 0).toLocaleString(undefined, { maximumFractionDigits: 2 })} &times; ${money(extra.unit_price)}</span></div><strong>${money(extra.line_total)}</strong></div>`).join('')}</div>`;
  }
  function bookingPaymentsList(booking) {
    const payments = booking.totals?.payments || [];
    if (!payments.length) {
      return '<div class="booking-extra-empty">No payments recorded yet.</div>';
    }
    return `<div class="booking-extra-list">${payments.map(payment => `<div class="booking-extra-row"><div><strong>${esc(payment.method || 'Payment')}</strong><span>${formatDateTime(payment.created_at)}${payment.note ? ` &middot; ${esc(payment.note)}` : ''}</span></div><strong>${money(payment.amount)}</strong></div>`).join('')}</div>`;
  }

  function checkoutSummary(booking) {
    const totals = booking.totals || {};
    return `<div class="checkout-summary-grid">
      <div class="checkout-total-card"><span>Room</span><strong>${money(totals.room_total)}</strong><small>${totals.nights || 0} night${Number(totals.nights || 0) === 1 ? '' : 's'} at ${money(totals.rate_per_night)}</small></div>
      <div class="checkout-total-card"><span>Extras</span><strong>${money(totals.extras_total)}</strong><small>${(totals.extras || []).length} line${(totals.extras || []).length === 1 ? '' : 's'}</small></div>
      <div class="checkout-total-card"><span>Paid</span><strong>${money(totals.paid_total)}</strong><small>${(totals.payments || []).length} payment${(totals.payments || []).length === 1 ? '' : 's'}</small></div>
      <div class="checkout-total-card balance-card ${Number(totals.balance || 0) > 0 ? 'has-balance' : 'is-clear'}"><span>Balance</span><strong>${money(totals.balance)}</strong><small>${Number(totals.balance || 0) > 0 ? 'Payment required' : 'Ready for checkout'}</small></div>
    </div>`;
  }

  function viewBooking(id, message = null) {
    const booking = state.bookings.find(item => Number(item.id) === Number(id));
    if (!booking) return;
    openForm('Booking Details', `<form class="room-profile">
      ${message ? `<div class="alert alert-success mb-0">${esc(message)}</div>` : ''}
      <div class="room-profile-hero">
        <div>
          <div class="room-profile-label">${esc(booking.room_name)} booking</div>
          <h3>${esc(booking.guest_name)}</h3>
        </div>
        <span class="badge text-bg-primary room-status-pill">${esc(booking.status)}</span>
      </div>
      <div class="room-detail-grid">
        <div class="room-detail-tile"><span>Check-in</span><strong>${formatDateTime(booking.checkin_at)}</strong></div>
        <div class="room-detail-tile"><span>Nights</span><strong>${esc(booking.totals?.nights ?? '')}</strong></div>
        <div class="room-detail-tile"><span>Total</span><strong>${money(booking.totals?.grand_total)}</strong></div>
        <div class="room-detail-tile"><span>Balance</span><strong>${money(booking.totals?.balance)}</strong></div>
        <div class="room-detail-tile"><span>Contact</span><strong>${esc(booking.contact || 'Not recorded')}</strong></div>
        <div class="room-detail-tile"><span>Nationality</span><strong>${esc(booking.nationality || 'Not recorded')}</strong></div>
      </div>
      <div class="booking-extras-panel"><div class="booking-extras-heading"><h4>Extras Added</h4><span>${money(booking.totals?.extras_total)}</span></div>${bookingExtrasList(booking)}</div>
      <div class="room-profile-footer"><button class="btn btn-outline-primary" type="button" data-booking-detail-pay="${booking.id}">Pay</button><button class="btn btn-outline-secondary" type="button" data-booking-detail-extra="${booking.id}">Add Extra</button><button class="btn btn-danger" type="button" data-booking-detail-checkout="${booking.id}">Check-Out</button><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button></div>
    </form>`, e => e.preventDefault());
    $('[data-booking-detail-pay]')?.addEventListener('click', () => paymentForm(booking.id));
    $('[data-booking-detail-extra]')?.addEventListener('click', () => bookingExtraForm(booking.id, true));
    $('[data-booking-detail-checkout]')?.addEventListener('click', () => checkout(booking.id));
  }
  async function promptRoomSelection() {
    if (!state.rooms.length) {
      const roomsData = await api.get('/rooms');
      state.rooms = roomsData.rooms || [];
      renderBookingRoomPicker();
    }
    $('#bookingRoomPicker')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    $('#bookingRoomPicker .booking-room-board')?.classList.add('pulse-attention');
    window.setTimeout(() => $('#bookingRoomPicker .booking-room-board')?.classList.remove('pulse-attention'), 900);
  }
  async function loadExtras() {
    const d = await api.get('/extras');
    state.extras = d.extras || [];
    renderExtras();
  }

  async function loadStock() {
    switchStockTab(state.stockTab || 'movements', false);
    const d = await api.get(`/stock?${stockMovementQuery()}`);
    state.extras = d.extras || [];
    state.stockMovements = d.movements || [];
    state.stockMovementPagination = d.pagination || { page: 1, pages: 1, total: 0 };
    renderStockFilterOptions();
    renderStockInventory();
    renderStockMovements();
  }

  async function loadPayments() { const d = await api.get('/payments'); $('#paymentsList').innerHTML = table(d.payments || [], ['created_at', 'guest_name', 'room_name', 'method', 'amount', 'note', 'voided_at']); }
  async function loadExpenses() { const d = await api.get('/expenses'); state.categories = d.categories || []; $('#expensesList').innerHTML = table(d.expenses || [], ['expense_date', 'category_name', 'method', 'amount', 'vendor', 'description', 'voided_at']); }
  async function loadReports() { const d = await api.get('/reports/summary'); $('#reportsPanel').innerHTML = `<div class="row g-3"><div class="col"><div class="metric"><div>Revenue</div><div class="value">${money(d.revenue)}</div></div></div><div class="col"><div class="metric"><div>Expenses</div><div class="value">${money(d.expenses)}</div></div></div><div class="col"><div class="metric"><div>Net Income</div><div class="value">${money(d.net_income)}</div></div></div></div><h5 class="mt-4">Payments by Method</h5>${table(d.payments_by_method || [], ['method', 'total'])}`; }

  async function loadUsers() {
    const d = await api.get('/users');
    state.users = d.users || [];
    renderUsers();
  }

  async function loadAudit() {
    const d = await api.get(`/audit?${auditQuery()}`);
    state.auditLogs = d.logs || [];
    state.auditPagination = d.pagination || { page: 1, pages: 1, total: 0 };
    renderAudit();
  }

  function renderExtras() {
    if (!state.extras.length) {
      $('#extrasList').innerHTML = '<div class="empty-state">No extras found. Add the first sale item from the form.</div>';
      return;
    }
    $('#extrasList').innerHTML = `<table class="table table-sm table-striped app-table"><thead><tr><th>Name</th><th>Price</th><th>Stock</th><th class="text-end">Actions</th></tr></thead><tbody>${state.extras.map(extra => `<tr><td>${esc(extra.name)}</td><td>${money(extra.price)}</td><td>${Number(extra.stock_qty || 0).toLocaleString(undefined, { maximumFractionDigits: 2 })}</td><td class="text-end"><button class="btn btn-sm btn-outline-secondary" data-extra-view="${extra.id}">View</button> <button class="btn btn-sm btn-outline-primary" data-extra-edit="${extra.id}">Edit</button></td></tr>`).join('')}</tbody></table>`;
    document.querySelectorAll('[data-extra-view]').forEach(btn => btn.addEventListener('click', () => viewExtra(btn.dataset.extraView)));
    document.querySelectorAll('[data-extra-edit]').forEach(btn => btn.addEventListener('click', () => editExtraInline(btn.dataset.extraEdit)));
  }

  function switchStockTab(tab, shouldLoad = true) {
    state.stockTab = tab === 'inventory' ? 'inventory' : 'movements';
    document.querySelectorAll('[data-stock-tab]').forEach(btn => btn.classList.toggle('active', btn.dataset.stockTab === state.stockTab));
    document.querySelectorAll('[data-stock-panel]').forEach(panel => panel.classList.toggle('d-none', panel.dataset.stockPanel !== state.stockTab));
    if (shouldLoad) loadStock();
  }

  function stockMovementQuery() {
    const form = $('#stockMovementFilters');
    const data = form ? Object.fromEntries(new FormData(form).entries()) : {};
    const params = new URLSearchParams();
    params.set('page', String(state.stockMovementPage || 1));
    params.set('per_page', '10');
    ['search', 'extra_id', 'movement_type', 'from', 'to'].forEach(key => { if (data[key]) params.set(key, data[key]); });
    return params.toString();
  }

  function renderStockFilterOptions() {
    const select = $('#stockFilterExtra');
    if (!select) return;
    const selected = select.value;
    select.innerHTML = '<option value="">All extras</option>' + state.extras.map(extra => `<option value="${extra.id}">${esc(extra.name)}</option>`).join('');
    select.value = selected;
  }

  function renderStockInventory() {
    const rows = state.extras || [];
    $('#stockInventoryList').innerHTML = rows.length ? `<table class="table table-sm table-striped app-table"><thead><tr><th>Name</th><th>Stock Qty</th></tr></thead><tbody>${rows.map(extra => `<tr><td>${esc(extra.name)}</td><td>${Number(extra.stock_qty || 0).toLocaleString(undefined, { maximumFractionDigits: 2 })}</td></tr>`).join('')}</tbody></table>` : '<div class="empty-state">No active inventory items found.</div>';
  }

  function renderStockMovements() {
    const rows = state.stockMovements || [];
    const pagination = state.stockMovementPagination || { page: 1, pages: 1, total: 0 };
    $('#stockMovementList').innerHTML = rows.length ? `<table class="table table-sm table-striped app-table"><thead><tr><th>Date</th><th>Extra</th><th>Type</th><th>Qty</th><th>Note</th><th>User</th></tr></thead><tbody>${rows.map(row => `<tr><td>${formatDateTime(row.created_at)}</td><td>${esc(row.extra_name)}</td><td>${stockMovementBadge(row.movement_type)}</td><td>${Number(row.qty || 0).toLocaleString(undefined, { maximumFractionDigits: 2 })}</td><td>${esc(row.note || '')}</td><td>${esc(row.user_name || '')}</td></tr>`).join('')}</tbody></table>` : '<div class="empty-state">No stock movements match these filters.</div>';
    renderPager('#stockMovementPager', pagination, 'movement-page');
    document.querySelectorAll('[data-movement-page]').forEach(btn => btn.addEventListener('click', () => {
      const page = state.stockMovementPagination?.page || 1;
      state.stockMovementPage = btn.dataset.movementPage === 'next' ? page + 1 : page - 1;
      loadStock();
    }));
  }

  function renderUsers() {
    if (!state.users.length) {
      $('#usersList').innerHTML = '<div class="empty-state">No users found.</div>';
      return;
    }
    $('#usersList').innerHTML = `<table class="table table-sm table-striped app-table"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>${state.users.map(user => `<tr><td>${esc(user.name)}</td><td>${esc(user.email)}</td><td class="text-capitalize">${esc(user.role)}</td><td>${statusBadge(Number(user.active) === 1 ? 'Active' : 'Inactive', Number(user.active) === 1 ? 'success' : 'secondary')}</td><td class="text-end"><button class="btn btn-sm btn-outline-primary" data-user-edit="${user.id}">Edit</button></td></tr>`).join('')}</tbody></table>`;
    document.querySelectorAll('[data-user-edit]').forEach(btn => btn.addEventListener('click', () => editUserInline(btn.dataset.userEdit)));
  }

  function auditQuery() {
    const form = $('#auditFilters');
    const data = form ? Object.fromEntries(new FormData(form).entries()) : {};
    const params = new URLSearchParams();
    params.set('page', String(state.auditPage || 1));
    params.set('per_page', '10');
    ['search', 'entity', 'action', 'from', 'to'].forEach(key => { if (data[key]) params.set(key, data[key]); });
    return params.toString();
  }

  function renderAudit() {
    const rows = state.auditLogs || [];
    const pagination = state.auditPagination || { page: 1, pages: 1, total: 0 };
    $('#auditList').innerHTML = rows.length ? `<table class="table table-sm table-striped app-table"><thead><tr><th>Date</th><th>User</th><th>Action</th><th>Entity</th><th>Entity ID</th></tr></thead><tbody>${rows.map(row => `<tr><td>${formatDateTime(row.created_at)}</td><td>${esc(row.user_name || 'System')}</td><td>${esc(row.action)}</td><td>${esc(row.entity)}</td><td>${esc(row.entity_id || '')}</td></tr>`).join('')}</tbody></table>` : '<div class="empty-state">No audit logs match these filters.</div>';
    renderPager('#auditPager', pagination, 'audit-page');
    document.querySelectorAll('[data-audit-page]').forEach(btn => btn.addEventListener('click', () => {
      const page = state.auditPagination?.page || 1;
      state.auditPage = btn.dataset.auditPage === 'next' ? page + 1 : page - 1;
      loadAudit();
    }));
  }

  function renderPager(target, pagination, dataName) {
    const el = $(target);
    if (!el) return;
    el.innerHTML = `<div class="d-flex align-items-center justify-content-between gap-3"><div class="text-secondary">${pagination.total || 0} record${Number(pagination.total || 0) === 1 ? '' : 's'} &middot; Page ${pagination.page || 1} of ${pagination.pages || 1}</div><div class="btn-group"><button class="btn btn-outline-secondary btn-sm" data-${dataName}="prev" ${(pagination.page || 1) <= 1 ? 'disabled' : ''}>Previous</button><button class="btn btn-outline-secondary btn-sm" data-${dataName}="next" ${(pagination.page || 1) >= (pagination.pages || 1) ? 'disabled' : ''}>Next</button></div></div>`;
  }

  function statusBadge(label, tone) {
    return `<span class="badge text-bg-${tone}">${esc(label)}</span>`;
  }

  function stockMovementBadge(type) {
    const tone = ({ in: 'success', return: 'success', out: 'primary', adjustment: 'warning', waste: 'danger' })[type] || 'secondary';
    return statusBadge(type || 'unknown', tone);
  }
  // Generic table renderer for simple admin/reference lists.
  function table(rows, cols) {
    if (!rows.length) return '<div class="empty-state">No records found.</div>';
    return `<table class="table table-sm table-striped app-table"><thead><tr>${cols.map(c => `<th>${c.replaceAll('_', ' ')}</th>`).join('')}</tr></thead><tbody>${rows.map(r => `<tr>${cols.map(c => `<td>${tableCell(c, r[c])}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
  }

  // Shared modal form wrapper used by the small CRUD/workflow forms below.
  function openForm(title, html, onSubmit) {
    $('#modalTitle').textContent = title;
    $('#modalBody').innerHTML = html;
    $('#modalBody form')?.addEventListener('submit', onSubmit);
    modal.show();
  }

  function roomForm(room = null) {
    const editing = Boolean(room?.id);
    const statusOptions = ['vacant', 'occupied', 'dirty', 'maintenance'].map(status => `<option value="${status}" ${room?.status === status ? 'selected' : ''}>${status}</option>`).join('');
    openForm(editing ? 'Edit Room' : 'New Room', `<form class="row g-3">
      ${editing ? `<input type="hidden" name="id" value="${room.id}">` : ''}
      <div class="col-md-6"><label class="form-label">Name</label><input name="name" class="form-control" value="${esc(room?.name || '')}" required></div>
      <div class="col-md-6"><label class="form-label">Type</label><input name="type" class="form-control" value="${esc(room?.type || '')}" required></div>
      <div class="col-md-4"><label class="form-label">Rate</label><input name="rate" type="number" step="0.01" class="form-control" value="${esc(room?.rate || '')}" required></div>
      <div class="col-md-4"><label class="form-label">Status</label><select name="status" class="form-select" ${editing ? '' : 'disabled'}>${statusOptions}</select></div>
      <div class="col-md-4"><label class="form-label">Active</label><select name="active" class="form-select"><option value="1" ${Number(room?.active ?? 1) === 1 ? 'selected' : ''}>Yes</option><option value="0" ${Number(room?.active ?? 1) === 0 ? 'selected' : ''}>No</option></select></div>
      <div class="col-md-6"><label class="form-label">Occupancy Counted</label><select name="occupancy_counted" class="form-select"><option value="1" ${Number(room?.occupancy_counted ?? 1) === 1 ? 'selected' : ''}>Yes</option><option value="0" ${Number(room?.occupancy_counted ?? 1) === 0 ? 'selected' : ''}>No</option></select></div>
      <div class="col-12"><button class="btn btn-primary">Save</button></div>
    </form>`, async e => {
      e.preventDefault();
      const body = Object.fromEntries(new FormData(e.target).entries());
      await api.post('/rooms/save', body);
      if (editing && body.status && body.status !== room.status) {
        await api.post('/rooms/status', { id: room.id, status: body.status });
      }
      modal.hide();
      loadRooms();
    });
  }
  async function checkinForm(roomId) {
    if (!state.rooms.length) {
      const roomsData = await api.get('/rooms');
      state.rooms = roomsData.rooms || [];
    }
    const room = roomById(roomId);
    if (!room || room.status !== 'vacant' || Number(room.active) !== 1) {
      alert('Select a vacant room from the availability row before checking in a guest.');
      return;
    }

    openForm('Check In Guest', `<form class="checkin-flow checkin-arrival">
      <input type="hidden" name="room_id" value="${room.id}" required>
      <div class="checkin-arrival-hero">
        <div>
          <span>Selected room</span>
          <h3>${esc(room.name)}</h3>
          <p>${esc(room.type)} &middot; ${money(room.rate)} per night</p>
        </div>
        <span class="badge text-bg-success room-status-pill">Vacant</span>
      </div>
      <div class="checkin-form-section">
        <div class="checkin-section-title"><span>1</span><strong>Guest details</strong></div>
        <div class="row g-3">
          <div class="col-md-7"><label class="form-label">Guest Name <span class="text-danger">*</span></label><input name="guest_name" class="form-control form-control-lg" autocomplete="name" required></div>
          <div class="col-md-5"><label class="form-label">Contact</label><input name="contact" class="form-control form-control-lg" autocomplete="tel"></div>
          <div class="col-md-4"><label class="form-label">Gender</label><select name="gender" class="form-select"><option>Male</option><option>Female</option><option>Other</option></select></div>
          <div class="col-md-4"><label class="form-label">Nationality</label><select name="nationality" class="form-select" autocomplete="country-name"><option value="Ghana" selected>Ghana</option><option value="Nigeria">Nigeria</option><option value="Togo">Togo</option><option value="Benin">Benin</option><option value="Cote d'Ivoire">Cote d'Ivoire</option><option value="Burkina Faso">Burkina Faso</option><option value="United Kingdom">United Kingdom</option><option value="United States">United States</option><option value="Other">Other</option></select></div>
          <div class="col-md-4"><label class="form-label">Arrival time</label><input class="form-control" value="Now" disabled></div>
        </div>
      </div>
      <div class="checkin-form-section">
        <div class="checkin-section-title"><span>2</span><strong>Deposit</strong></div>
        <div class="row g-3 align-items-end">
          <div class="col-md-5"><label class="form-label">Amount Paid</label><input name="amount_paid" type="number" step="0.01" min="0" class="form-control form-control-lg" value="0"></div>
          <div class="col-md-4"><label class="form-label">Method</label><select name="method" class="form-select form-select-lg"><option value="cash">Cash</option><option value="momo">MoMo</option><option value="card">Card</option></select></div>
          <div class="col-md-3"><div class="checkin-rate-note"><span>Night rate</span><strong>${money(room.rate)}</strong></div></div>
        </div>
      </div>
      <div class="alert d-none" id="checkinStatus"></div>
      <div class="checkin-actions"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary btn-lg">Confirm check-in</button></div>
    </form>`, async e => {
      e.preventDefault();
      const status = $('#checkinStatus');
      status.className = 'alert d-none';
      status.textContent = '';
      const submit = e.target.querySelector('button[type="submit"], .checkin-actions .btn-primary');
      submit.disabled = true;
      submit.textContent = 'Checking in...';
      try {
        const response = await api.post('/checkin', Object.fromEntries(new FormData(e.target).entries()));
        status.className = 'alert alert-success';
        status.textContent = 'Guest checked in successfully.';
        await loadBookings();
        window.setTimeout(() => viewBooking(response.booking_id, 'Guest checked in successfully.'), 350);
      } catch (err) {
        status.className = 'alert alert-danger';
        status.textContent = err.message;
        submit.disabled = false;
        submit.textContent = 'Confirm check-in';
      }
    });
    $('#modalBody input[name="guest_name"]')?.focus();
  }
  function extraById(id) {
    return state.extras.find(extra => Number(extra.id) === Number(id));
  }

  function showExtraStatus(message, className = 'd-none') {
    const status = $('#extraStatus');
    if (!status) return;
    status.className = `alert ${className}`;
    status.textContent = message;
  }

  function resetExtraForm(event) {
    const form = event.currentTarget;
    window.setTimeout(() => {
      form.querySelector('[name="id"]').value = '';
      $('#extraFormTitle').textContent = 'New Extra';
      $('#extraSubmitButton').textContent = 'Save Extra';
      form.querySelector('[name="active"]').value = '1';
      form.querySelector('[name="stock_tracked"]').value = '1';
      form.classList.remove('was-validated');
      showExtraStatus('', 'd-none');
    });
  }

  function editExtraInline(id) {
    const extra = extraById(id);
    const form = $('#extraForm');
    if (!extra || !form) return;
    form.querySelector('[name="id"]').value = extra.id;
    form.querySelector('[name="name"]').value = extra.name || '';
    form.querySelector('[name="price"]').value = Number(extra.price || 0).toFixed(2);
    form.querySelector('[name="stock_tracked"]').value = String(Number(extra.stock_tracked ?? 1));
    form.querySelector('[name="active"]').value = String(Number(extra.active ?? 1));
    $('#extraFormTitle').textContent = 'Edit Extra';
    $('#extraSubmitButton').textContent = 'Update Extra';
    showExtraStatus('Editing extra. Save changes or clear to start a new one.', 'alert-info');
    form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    form.querySelector('[name="name"]').focus();
  }

  function viewExtra(id) {
    const extra = extraById(id);
    if (!extra) return;
    openForm('Extra Details', `<form class="room-profile">
      <div class="room-profile-hero"><div><div class="room-profile-label">Extra</div><h3>${esc(extra.name)}</h3></div>${statusBadge(Number(extra.active) === 1 ? 'Active' : 'Inactive', Number(extra.active) === 1 ? 'success' : 'secondary')}</div>
      <div class="room-profile-rate"><span>Current price</span><strong>${money(extra.price)}</strong></div>
      <div class="room-detail-grid">
        <div class="room-detail-tile"><span>Stock quantity</span><strong>${Number(extra.stock_qty || 0).toLocaleString(undefined, { maximumFractionDigits: 2 })}</strong></div>
        <div class="room-detail-tile"><span>Stock tracked</span><strong>${Number(extra.stock_tracked) === 1 ? 'Yes' : 'No'}</strong></div>
        <div class="room-detail-tile"><span>Created</span><strong>${formatDateTime(extra.created_at, 'Not recorded')}</strong></div>
        <div class="room-detail-tile"><span>Last updated</span><strong>${formatDateTime(extra.updated_at, 'Not updated yet')}</strong></div>
      </div>
      <div class="room-profile-footer"><button class="btn btn-primary" type="button" data-extra-edit-from-view="${extra.id}">Edit extra</button><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button></div>
    </form>`, e => e.preventDefault());
    $('[data-extra-edit-from-view]')?.addEventListener('click', () => { modal.hide(); editExtraInline(extra.id); });
  }

  async function saveExtraInline(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const submit = $('#extraSubmitButton');
    const data = Object.fromEntries(new FormData(form).entries());
    const errors = [];
    if (!String(data.name || '').trim()) errors.push('Extra name is required.');
    if (String(data.price || '').trim() === '' || Number(data.price) < 0) errors.push('Enter a valid non-negative price.');
    form.classList.add('was-validated');
    if (errors.length) {
      showExtraStatus(errors.join(' '), 'alert-danger');
      return;
    }
    submit.disabled = true;
    submit.textContent = data.id ? 'Updating...' : 'Saving...';
    try {
      await api.post('/extras/save', data);
      form.querySelector('[name="id"]').value = '';
      form.querySelector('[name="name"]').value = '';
      form.querySelector('[name="price"]').value = '';
      form.querySelector('[name="stock_tracked"]').value = '1';
      form.querySelector('[name="active"]').value = '1';
      $('#extraFormTitle').textContent = 'New Extra';
      $('#extraSubmitButton').textContent = 'Save Extra';
      form.classList.remove('was-validated');
      showExtraStatus(data.id ? 'Extra updated successfully.' : 'Extra saved successfully.', 'alert-success');
      await loadExtras();
    } catch (err) {
      showExtraStatus(err.message, 'alert-danger');
    } finally {
      submit.disabled = false;
      submit.textContent = $('#extraFormTitle').textContent === 'Edit Extra' ? 'Update Extra' : 'Save Extra';
    }
  }
  async function stockForm() { await loadExtras(); const options = state.extras.map(x => `<option value="${x.id}">${esc(x.name)}</option>`).join(''); openForm('Record Stock Movement', `<form class="row g-3"><div class="col-md-6"><label class="form-label">Extra</label><select name="extra_id" class="form-select">${options}</select></div><div class="col-md-3"><label class="form-label">Type</label><select name="movement_type" class="form-select"><option value="in">In</option><option value="out">Out</option><option value="adjustment">Adjustment</option><option value="return">Return</option><option value="waste">Waste</option></select></div><div class="col-md-3"><label class="form-label">Qty</label><input name="qty" type="number" step="0.01" class="form-control" required></div><div class="col-12"><label class="form-label">Note</label><input name="note" class="form-control"></div><div class="col-12"><button class="btn btn-primary">Save</button></div></form>`, async e => { e.preventDefault(); await api.post('/stock/movement', Object.fromEntries(new FormData(e.target).entries())); modal.hide(); loadStock(); }); }
  async function expenseForm() { await loadExpenses(); const options = state.categories.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join(''); openForm('New Expense', `<form class="row g-3"><div class="col-md-4"><label class="form-label">Date</label><input name="expense_date" type="date" class="form-control" required></div><div class="col-md-4"><label class="form-label">Category</label><select name="category_id" class="form-select">${options}</select></div><div class="col-md-4"><label class="form-label">Method</label><select name="method" class="form-select"><option value="cash">Cash</option><option value="momo">MoMo</option><option value="card">Card</option><option value="bank">Bank</option><option value="other">Other</option></select></div><div class="col-md-4"><label class="form-label">Amount</label><input name="amount" type="number" step="0.01" class="form-control" required></div><div class="col-md-4"><label class="form-label">Vendor</label><input name="vendor" class="form-control"></div><div class="col-md-4"><label class="form-label">Reference</label><input name="reference_no" class="form-control"></div><div class="col-12"><label class="form-label">Description</label><input name="description" class="form-control"></div><div class="col-12"><button class="btn btn-primary">Save</button></div></form>`, async e => { e.preventDefault(); await api.post('/expenses/save', Object.fromEntries(new FormData(e.target).entries())); modal.hide(); loadExpenses(); }); }
  function userById(id) {
    return state.users.find(user => Number(user.id) === Number(id));
  }

  function showUserStatus(message, className = 'd-none') {
    const status = $('#userStatus');
    if (!status) return;
    status.className = `alert ${className}`;
    status.textContent = message;
  }

  function resetUserForm(event) {
    const form = event.currentTarget;
    window.setTimeout(() => {
      form.querySelector('[name="id"]').value = '';
      form.querySelector('[name="password"]').required = true;
      $('#userPasswordRequired').classList.remove('d-none');
      $('#userFormTitle').textContent = 'New User';
      $('#userSubmitButton').textContent = 'Save User';
      form.querySelector('[name="role"]').value = 'reception';
      form.querySelector('[name="active"]').value = '1';
      form.classList.remove('was-validated');
      showUserStatus('', 'd-none');
    });
  }

  function editUserInline(id) {
    const user = userById(id);
    const form = $('#userForm');
    if (!user || !form) return;
    form.querySelector('[name="id"]').value = user.id;
    form.querySelector('[name="name"]').value = user.name || '';
    form.querySelector('[name="email"]').value = user.email || '';
    form.querySelector('[name="role"]').value = user.role || 'reception';
    form.querySelector('[name="active"]').value = String(Number(user.active ?? 1));
    form.querySelector('[name="password"]').value = '';
    form.querySelector('[name="password"]').required = false;
    $('#userPasswordRequired').classList.add('d-none');
    $('#userFormTitle').textContent = 'Edit User';
    $('#userSubmitButton').textContent = 'Update User';
    showUserStatus('Editing user. Leave password blank to keep the current password.', 'alert-info');
    form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    form.querySelector('[name="name"]').focus();
  }

  async function saveUserInline(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const submit = $('#userSubmitButton');
    const data = Object.fromEntries(new FormData(form).entries());
    const errors = [];
    if (!String(data.name || '').trim()) errors.push('Name is required.');
    if (!String(data.email || '').trim()) errors.push('Email is required.');
    if (!data.id && !String(data.password || '').trim()) errors.push('Password is required for new users.');
    if (data.password && String(data.password).length < 8) errors.push('Password must be at least 8 characters.');
    form.classList.add('was-validated');
    if (errors.length) {
      showUserStatus(errors.join(' '), 'alert-danger');
      return;
    }
    submit.disabled = true;
    submit.textContent = data.id ? 'Updating...' : 'Saving...';
    try {
      await api.post('/users/save', data);
      form.querySelector('[name="id"]').value = '';
      form.querySelector('[name="name"]').value = '';
      form.querySelector('[name="email"]').value = '';
      form.querySelector('[name="role"]').value = 'reception';
      form.querySelector('[name="active"]').value = '1';
      form.querySelector('[name="password"]').value = '';
      form.querySelector('[name="password"]').required = true;
      $('#userPasswordRequired').classList.remove('d-none');
      $('#userFormTitle').textContent = 'New User';
      $('#userSubmitButton').textContent = 'Save User';
      form.classList.remove('was-validated');
      showUserStatus(data.id ? 'User updated successfully.' : 'User saved successfully.', 'alert-success');
      await loadUsers();
    } catch (err) {
      showUserStatus(err.message, 'alert-danger');
    } finally {
      submit.disabled = false;
      submit.textContent = $('#userFormTitle').textContent === 'Edit User' ? 'Update User' : 'Save User';
    }
  }
  async function paymentForm(bookingId, returnTo = null) {
    const booking = state.bookings.find(item => Number(item.id) === Number(bookingId));
    const balance = Number(booking?.totals?.balance || 0);
    openForm('Record Payment', `<form class="row g-3">
      <input type="hidden" name="booking_id" value="${bookingId}">
      ${balance > 0 ? `<div class="col-12"><div class="alert alert-info mb-0">Outstanding balance: <strong>${money(balance)}</strong></div></div>` : ''}
      <div class="col-md-6"><label class="form-label">Amount</label><input name="amount" type="number" step="0.01" min="0.01" class="form-control" value="${balance > 0 ? balance.toFixed(2) : ''}" required></div>
      <div class="col-md-6"><label class="form-label">Method</label><select name="method" class="form-select"><option value="cash">Cash</option><option value="momo">MoMo</option><option value="card">Card</option><option value="bank">Bank</option><option value="other">Other</option></select></div>
      <div class="col-12"><label class="form-label">Note</label><input name="note" class="form-control"></div>
      <div class="col-12"><div class="alert d-none" id="paymentStatus"></div></div>
      <div class="col-12 d-flex justify-content-end gap-2"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save payment</button></div>
    </form>`, async e => {
      e.preventDefault();
      const status = $('#paymentStatus');
      status.className = 'alert d-none';
      status.textContent = '';
      try {
        await api.post('/payments/record', Object.fromEntries(new FormData(e.target).entries()));
        status.className = 'alert alert-success';
        status.textContent = 'Payment recorded successfully.';
        await loadBookings();
        if (returnTo === 'checkout') {
          window.setTimeout(() => checkout(bookingId), 350);
        } else {
          window.setTimeout(() => modal.hide(), 500);
        }
      } catch (err) {
        status.className = 'alert alert-danger';
        status.textContent = err.message;
      }
    });
  }
  async function bookingExtraForm(bookingId, returnToDetails = false) {
    await loadExtras();
    const options = state.extras.filter(x => Number(x.active) === 1).map(x => `<option value="${x.id}">${esc(x.name)} - ${money(x.price)}</option>`).join('');
    openForm('Add Extra', `<form class="row g-3">
      <input type="hidden" name="booking_id" value="${bookingId}">
      <div class="col-md-8"><label class="form-label">Extra</label><select name="extra_id" class="form-select">${options}</select></div>
      <div class="col-md-4"><label class="form-label">Qty</label><input name="qty" type="number" step="0.01" min="0.01" value="1" class="form-control"></div>
      <div class="col-12"><div class="alert d-none" id="bookingExtraStatus"></div></div>
      <div class="col-12 d-flex justify-content-end gap-2"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Add extra</button></div>
    </form>`, async e => {
      e.preventDefault();
      const status = $('#bookingExtraStatus');
      status.className = 'alert d-none';
      status.textContent = '';
      try {
        await api.post('/bookings/extra', Object.fromEntries(new FormData(e.target).entries()));
        status.className = 'alert alert-success';
        status.textContent = 'Extra added successfully.';
        await loadBookings();
        if (returnToDetails) {
          window.setTimeout(() => viewBooking(bookingId, 'Extra added successfully.'), 350);
        } else {
          window.setTimeout(() => modal.hide(), 500);
        }
      } catch (err) {
        status.className = 'alert alert-danger';
        status.textContent = err.message;
      }
    });
  }
  async function checkout(bookingId) {
    const booking = state.bookings.find(item => Number(item.id) === Number(bookingId));
    if (!booking) return;
    const balance = Number(booking.totals?.balance || 0);
    const canOverride = ['administrator', 'manager'].includes(state.user?.role);
    const paymentAction = balance > 0 ? `<button class="btn btn-primary" type="button" data-checkout-pay="${booking.id}">Record payment</button>` : '';
    const overrideAction = balance > 0 && canOverride ? `<button class="btn btn-outline-danger" type="button" data-checkout-override="${booking.id}">Manager override</button>` : '';
    const completeAction = balance <= 0 ? `<button class="btn btn-danger" type="button" data-checkout-complete="${booking.id}">Complete checkout</button>` : '';

    openForm('Checkout Review', `<form class="checkout-review">
      <div class="room-profile-hero checkout-hero">
        <div>
          <div class="room-profile-label">${esc(booking.room_name)} checkout</div>
          <h3>${esc(booking.guest_name)}</h3>
        </div>
        <span class="badge ${balance > 0 ? 'text-bg-warning' : 'text-bg-success'} room-status-pill">${balance > 0 ? 'Balance due' : 'Ready'}</span>
      </div>
      ${checkoutSummary(booking)}
      ${balance > 0 ? `<div class="alert alert-warning mb-0">Outstanding balance must be settled before checkout. Record a payment to continue.${canOverride ? ' Manager override is available if approved.' : ''}</div>` : `<div class="alert alert-success mb-0">Balance is clear. Review the bill, then complete checkout to release the room.</div>`}
      <div class="checkout-ledger-grid">
        <div class="booking-extras-panel"><div class="booking-extras-heading"><h4>Extras</h4><span>${money(booking.totals?.extras_total)}</span></div>${bookingExtrasList(booking)}</div>
        <div class="booking-extras-panel"><div class="booking-extras-heading"><h4>Payments</h4><span>${money(booking.totals?.paid_total)}</span></div>${bookingPaymentsList(booking)}</div>
      </div>
      <div class="alert d-none" id="checkoutStatus"></div>
      <div class="room-profile-footer">${paymentAction}${overrideAction}${completeAction}<button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button></div>
    </form>`, e => e.preventDefault());

    $('[data-checkout-pay]')?.addEventListener('click', () => paymentForm(booking.id, 'checkout'));
    $('[data-checkout-complete]')?.addEventListener('click', () => completeCheckout(booking.id, false));
    $('[data-checkout-override]')?.addEventListener('click', () => completeCheckout(booking.id, true));
  }

  async function completeCheckout(bookingId, allowOverride) {
    const status = $('#checkoutStatus');
    if (status) {
      status.className = 'alert d-none';
      status.textContent = '';
    }
    try {
      await api.post('/checkout', { booking_id: bookingId, allow_override: allowOverride ? 1 : 0 });
      if (status) {
        status.className = 'alert alert-success';
        status.textContent = 'Checkout completed and room released.';
      }
      await loadBookings();
      window.setTimeout(() => modal.hide(), 700);
    } catch (err) {
      if (status) {
        status.className = 'alert alert-danger';
        status.textContent = err.message;
      } else {
        alert(err.message);
      }
    }
  }
})();

