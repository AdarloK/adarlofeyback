<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * AuthController
 *
 * Token-based (JWT access token + refresh token) authentication built on the
 * LavaLust API library.
 *
 *   POST /api/auth/register   create an account
 *   POST /api/auth/login      returns access_token + refresh_token
 *   POST /api/auth/refresh    exchange a refresh_token for new tokens
 *   POST /api/auth/logout     revoke a refresh_token
 *   GET  /api/auth/me         current user (requires Bearer token)
 */
class AuthController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        header('Content-Type: application/json; charset=utf-8');
        $this->call->library('api');
        $this->call->database();
    }

    /**
     * Raw JSON body (passwords must NOT be HTML-escaped by Api::body()).
     */
    private function raw_body(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $raw = trim((string) file_get_contents('php://input'));

        if ($raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                return $data;
            }

            if (preg_match_all('/["\']?([A-Za-z0-9_\-]+)["\']?\s*:\s*["\']?([^"\'\},]+)["\']?/', $raw, $matches, PREG_SET_ORDER)) {
                $parsed = [];
                foreach ($matches as $match) {
                    $key = trim($match[1]);
                    $parsed[$key] = trim($match[2]);
                }
                if (!empty($parsed)) {
                    return $parsed;
                }
            }

            if (stripos($contentType, 'application/x-www-form-urlencoded') !== false || str_contains($raw, '=')) {
                parse_str($raw, $data);
                if (is_array($data) && !empty($data)) {
                    return $data;
                }
            }
        }

        if (!empty($_POST)) {
            return $_POST;
        }

        return [];
    }

    private function client_ip(): string
    {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    private function public_user(array $user): array
    {
        return [
            'id'       => (int) $user['id'],
            'username' => $user['username'],
            'email'    => $user['email'],
            'role'     => $user['role'],
        ];
    }

    // ------------------------------------------------------------------
    // POST /api/auth/register
    // ------------------------------------------------------------------
    public function register()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('register_' . $this->client_ip(), 10, 60);

        $body     = $this->raw_body();
        $username = trim((string) ($body['username'] ?? ''));
        $email    = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        $errors = [];
        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
            $errors['username'] = 'Username must be 3-50 characters (letters, numbers, . _ -).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $errors['email'] = 'A valid email is required.';
        }
        if (strlen($password) < 6) {
            $errors['password'] = 'Password must be at least 6 characters.';
        }
        if ($errors) {
            $this->api->respond(['error' => 'Validation failed', 'status' => 422, 'errors' => $errors], 422);
        }

        try {
            $exists = $this->db->raw(
                'SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1',
                [$username, $email]
            )->fetch(PDO::FETCH_ASSOC);

            if ($exists) {
                $this->api->respond_error('Username or email is already taken.', 409);
            }

            $this->db->raw(
                'INSERT INTO users (username, email, password, role, is_active) VALUES (?, ?, ?, ?, 1)',
                [$username, $email, password_hash($password, PASSWORD_DEFAULT), 'user']
            );
        } catch (Throwable $e) {
            $this->api->respond_error('Could not create account.', 500);
        }

        $this->api->respond(['status' => 'success', 'message' => 'Account created. You can now log in.'], 201);
    }

    // ------------------------------------------------------------------
    // POST /api/auth/login
    // ------------------------------------------------------------------
    public function login()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('login_' . $this->client_ip(), 20, 60);

        $body       = $this->raw_body();
        $identifier = trim((string) ($body['username'] ?? $body['email'] ?? ''));
        $password   = (string) ($body['password'] ?? '');

        if ($identifier === '' || $password === '') {
            $this->api->respond_error('Username/email and password are required.', 422);
        }

        try {
            $user = $this->db->raw(
                'SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1',
                [$identifier, strtolower($identifier)]
            )->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $this->api->respond_error('Server error. Please try again.', 500);
        }

        if (!$user || !password_verify($password, $user['password'])) {
            $this->api->respond_error('Invalid username or password.', 401);
        }

        if ((int) $user['is_active'] !== 1) {
            $this->api->respond_error('This account is disabled.', 403);
        }

        $tokens = $this->api->issue_tokens([
            'id'     => $user['id'],
            'role'   => $user['role'],
            'scopes' => ['read', 'write', 'delete'],
        ]);

        $this->api->respond([
            'status'  => 'success',
            'message' => 'Login successful.',
            'user'    => $this->public_user($user),
            'tokens'  => $tokens,
        ]);
    }

    // ------------------------------------------------------------------
    // POST /api/auth/refresh
    // ------------------------------------------------------------------
    public function refresh()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('refresh_' . $this->client_ip(), 60, 60);

        $body  = $this->raw_body();
        $token = (string) ($body['refresh_token'] ?? '');

        if ($token === '') {
            $this->api->respond_error('refresh_token is required.', 422);
        }

        // Responds with new tokens (or an error) and exits.
        $this->api->refresh_access_token($token);
    }

    // ------------------------------------------------------------------
    // POST /api/auth/logout
    // ------------------------------------------------------------------
    public function logout()
    {
        $this->api->require_method('POST');

        $body  = $this->raw_body();
        $token = (string) ($body['refresh_token'] ?? '');

        if ($token !== '') {
            try {
                $this->api->revoke_refresh_token($token);
            } catch (Throwable $e) {
                // Ignore: logging out must always succeed on the client.
            }
        }

        $this->api->respond(['status' => 'success', 'message' => 'Logged out.']);
    }

    // ------------------------------------------------------------------
    // GET /api/auth/me
    // ------------------------------------------------------------------
    public function me()
    {
        $auth = $this->api->require_jwt();

        $user = $this->db->raw(
            'SELECT id, username, email, role FROM users WHERE id = ? LIMIT 1',
            [$auth['sub']]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $this->api->respond_error('Unauthorized', 401);
        }

        $this->api->respond(['status' => 'success', 'user' => $this->public_user($user)]);
    }
}
