<?php

declare(strict_types=1);

/**
 * Authentication API controller. Handles login/logout/session introspection for the AJAX frontend.
 */

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Validator;
use App\Services\AuditService;

final class AuthController extends Controller
{
    public function me(Request $request): void
    {
        $this->ok('Current session.', [
            'user' => $this->auth->user(),
            'csrf' => $this->csrf->token(),
        ]);
    }

    public function login(Request $request): void
    {
        $input = $request->input();
        $errors = Validator::required($input, ['email', 'password']);
        if ($errors) {
            $this->fail('Validation failed.', $errors, 422);
            return;
        }

        $user = $this->db->fetch(
            'SELECT * FROM users WHERE email = ? AND active = 1 LIMIT 1',
            [trim((string)$input['email'])]
        );

        if (!$user || !password_verify((string)$input['password'], $user['password_hash'])) {
            $this->fail('Invalid email or password.', ['email' => 'invalid_credentials'], 401);
            return;
        }

        $this->auth->login($user);
        $this->ok('Logged in.', ['user' => $this->auth->user(), 'csrf' => $this->csrf->token()]);
    }

    public function forgotPassword(Request $request): void
    {
        $input = $request->input();
        $errors = Validator::required($input, ['email']);
        $email = trim((string)($input['email'] ?? ''));

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($errors) {
            $this->fail('Validation failed.', $errors, 422);
            return;
        }

        $user = $this->db->fetch('SELECT id,email,name FROM users WHERE email = ? AND active = 1 LIMIT 1', [$email]);
        if ($user) {
            $link = $this->createResetLink((int)$user['id']);
            $this->sendResetLink((string)$user['email'], (string)$user['name'], $link);
            (new AuditService($this->db))->log(null, 'auth.password_reset_link_sent', 'user', (int)$user['id'], [], ['email' => $email]);
        }

        // Return the same message whether the account exists to avoid exposing users.
        $this->ok('If that email belongs to an active account, a reset link has been sent.');
    }

    public function resetWithToken(Request $request): void
    {
        $input = $request->input();
        $errors = $this->validateNewPassword($input, ['token', 'password', 'password_confirm']);
        $token = (string)($input['token'] ?? '');
        if ($errors) {
            $this->fail('Validation failed.', $errors, 422);
            return;
        }

        $tokenHash = hash('sha256', $token);
        $row = $this->db->fetch(
            'SELECT pr.*, u.email
             FROM password_resets pr JOIN users u ON u.id = pr.user_id
             WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > UTC_TIMESTAMP()
             LIMIT 1',
            [$tokenHash]
        );
        if (!$row) {
            $this->fail('This reset link is invalid or expired.', ['token' => 'invalid'], 422);
            return;
        }

        $this->db->transaction(function () use ($row, $input): void {
            $this->updatePassword((int)$row['user_id'], (string)$input['password']);
            $this->db->execute('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE id = ?', [(int)$row['id']]);
        });
        (new AuditService($this->db))->log(null, 'auth.password_reset_with_link', 'user', (int)$row['user_id'], [], ['email' => $row['email']]);
        $this->ok('Password reset successfully. You can now sign in with the new password.');
    }

    public function resetPassword(Request $request): void
    {
        $sessionUser = $this->auth->requireUser();
        $input = $request->input();
        $errors = $this->validateNewPassword($input, ['current_password', 'password', 'password_confirm']);

        $user = $this->db->fetch('SELECT id,email,password_hash FROM users WHERE id = ? AND active = 1 LIMIT 1', [(int)$sessionUser['id']]);
        if (!$user) {
            $this->fail('Current account was not found.', ['user' => 'not_found'], 404);
            return;
        }
        if (!password_verify((string)($input['current_password'] ?? ''), $user['password_hash'])) {
            $errors['current_password'] = 'Current password is incorrect.';
        }
        if ($errors) {
            $this->fail('Validation failed.', $errors, 422);
            return;
        }

        $this->updatePassword((int)$user['id'], (string)$input['password']);
        (new AuditService($this->db))->log((int)$sessionUser['id'], 'auth.password_reset', 'user', (int)$user['id'], [], ['email' => $user['email']]);
        $this->ok('Password reset successfully. Use the new password next time you sign in.');
    }

    public function logout(Request $request): void
    {
        $this->auth->logout();
        $this->ok('Logged out.');
    }

    private function createResetLink(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $this->db->execute(
            'INSERT INTO password_resets(user_id, token_hash, expires_at, created_at)
             VALUES(?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR), UTC_TIMESTAMP())',
            [$userId, $tokenHash]
        );

        $base = rtrim((string)($this->config['app']['public_url'] ?? ''), '/');
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/hotelpos/public/api/index.php')), '/');
            $publicDir = preg_replace('#/api$#', '', $scriptDir) ?: '/hotelpos/public';
            $base = $scheme . '://' . $host . $publicDir;
        }

        return $base . '/reset-password.php?token=' . urlencode($token);
    }

    private function sendResetLink(string $email, string $name, string $link): void
    {
        $subject = 'hotelpos password reset';
        $body = "Hello {$name},\n\nUse this link to reset your hotelpos password. The link expires in 1 hour:\n{$link}\n\nIf you did not request this, ignore this email.";
        $from = (string)($this->config['app']['mail_from'] ?? 'no-reply@hotelpos.local');
        $headers = 'From: ' . $from;

        $sent = false;
        if (function_exists('mail')) {
            $sent = @mail($email, $subject, $body, $headers);
        }

        // XAMPP commonly has no SMTP server configured. Log the link so the
        // reset flow can be tested locally even when mail() returns false.
        $logPath = dirname(__DIR__, 2) . '/storage/logs/password_resets.log';
        $line = sprintf("[%s] to=%s sent=%s link=%s%s", gmdate('c'), $email, $sent ? 'yes' : 'no', $link, PHP_EOL);
        file_put_contents($logPath, $line, FILE_APPEND);
    }

    private function validateNewPassword(array $input, array $required): array
    {
        $errors = Validator::required($input, $required);
        $password = (string)($input['password'] ?? '');
        $confirm = (string)($input['password_confirm'] ?? '');

        if ($password !== '' && strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }
        if ($password !== $confirm) {
            $errors['password_confirm'] = 'Passwords do not match.';
        }
        return $errors;
    }

    private function updatePassword(int $userId, string $password): void
    {
        $this->db->execute(
            'UPDATE users SET password_hash = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $userId]
        );
    }
}
