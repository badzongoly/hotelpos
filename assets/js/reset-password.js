/**
 * Reset password page script. This is loaded only from reset-password.php,
 * which receives the password reset token from the emailed link.
 */
(function () {
  const api = window.HotelPOSApi;
  const form = document.getElementById('resetTokenForm');
  const status = document.getElementById('resetTokenStatus');

  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    status.className = 'alert d-none';
    status.textContent = '';

    try {
      const body = Object.fromEntries(new FormData(form).entries());
      await api.post('/password/reset-token', body);
      status.className = 'alert alert-success';
      status.textContent = 'Password reset successfully. You can now sign in.';
      form.reset();
    } catch (error) {
      status.className = 'alert alert-danger';
      status.textContent = error.message;
    }
  });
})();
