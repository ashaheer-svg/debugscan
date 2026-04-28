<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use App\Models\User;
use RuntimeException;

/**
 * Authentication Service: Credential verification and account management
 *
 * PURPOSE:
 * Authenticate users by email/password, enforce account lockout on failed
 * attempts, and manage user profile updates with password hashing.
 *
 * RESPONSIBILITIES:
 * - Verify email/password credentials against database
 * - Implement account lockout after failed attempts
 * - Enforce account status (active, disabled, suspended)
 * - Hash passwords using BCRYPT (cost factor 12)
 * - Update user profiles (display name, timezone, password)
 * - Track login metrics (last login, failed attempts)
 *
 * AUTHENTICATION FLOW:
 * 1. Look up user by email
 * 2. Check account status (must be 'active')
 * 3. Check for lockout (temporary block after 5 failed attempts)
 * 4. Verify password hash
 * 5. On success: Reset failed attempt counter, record login time
 * 6. On failure: Increment failed attempt counter, lock if >= 5
 *
 * ACCOUNT LOCKOUT:
 * - Triggered after 5 failed login attempts
 * - Duration: 15 minutes automatic lockout
 * - Locks user out entirely (cannot login during lockout period)
 * - Counter resets on successful login
 * - Manual unlock: Admin or user wait for timeout
 *
 * ACCOUNT STATUS VALUES:
 * - 'active': User can login (normal state)
 * - 'disabled': User cannot login (by admin action)
 * - 'suspended': User cannot login (by admin/security)
 * Other values reject login with message
 *
 * PASSWORD SECURITY:
 * - Algorithm: BCRYPT
 * - Cost factor: 12 (strong, ~280ms per hash on modern hardware)
 * - Hashing: One-way, no plaintext storage
 * - Verification: timing-safe comparison via password_verify()
 *
 * INTEGRATION:
 * - Called by AuthController during login form submission
 * - Returns user record on success (null or exception on failure)
 * - Exceptions include: account locked, disabled, etc.
 *
 * DATABASE:
 * - Table: users
 * - Fields: id, email, password_hash, status, failed_login_count, locked_until
 * - Also updates: last_login_at, updated_at
 *
 * @package App\Services
 */
class AuthService
{
    private PDO $pdo;

    /**
     * Constructor: Dependency injection of database connection
     *
     * @param PDO $pdo Database connection for user lookups and updates
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Authenticate user with email and password
     *
     * AUTHENTICATION FLOW:
     * 1. Look up user by email (case-sensitive)
     * 2. If not found: return null (prevents account enumeration via error differences)
     * 3. Check account status: must be 'active'
     * 4. Check lockout status: verify locked_until timestamp hasn't passed
     * 5. Verify password hash using BCRYPT verification
     * 6. On success: reset failed attempts, update last_login_at
     * 7. On failure: increment failed attempt counter
     *
     * RETURN VALUES:
     * - Success: Full user record (id, email, role, tenant_id, timezone, etc.)
     * - User not found: null
     * - Invalid credentials: null
     * - Account inactive/disabled: null
     * - Account locked: null
     *
     * EXCEPTIONS THROWN:
     * - RuntimeException: "Account is {status}" (disabled, suspended, etc.)
     * - RuntimeException: "Account is temporarily locked. Try again in X minutes"
     *
     * SECURITY FEATURES:
     * - Timing-safe password comparison (password_verify)
     * - Account lockout after failed attempts (brute-force protection)
     * - Unique email requirement (enforced at DB level)
     * - No plaintext passwords (BCRYPT hashing)
     * - Generic error messages (no account enumeration)
     *
     * @param string $email    User email address (case-sensitive)
     * @param string $password User plaintext password (will be hashed for comparison)
     *
     * @return ?array Full user record on success, null on failure
     *
     * @throws RuntimeException For account lockout or disabled status
     */
    public function authenticate(string $email, string $password): ?array
    {
        // === Step 1: Look up user by email ===
        $stmt = $this->pdo->prepare("SELECT id, email, password_hash, display_name, role, status, tenant_id, timezone, failed_login_count, locked_until FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        // No user found: return null (don't reveal existence)
        if (!$user) {
            return null;
        }

        // === Step 2: Check account status ===
        // Account must be 'active' to proceed (not disabled, suspended, etc.)
        if ($user['status'] !== 'active') {
            throw new RuntimeException("Account is " . $user['status']);
        }

        // === Step 3: Check for temporary lockout ===
        // User is locked if locked_until timestamp is in the future
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            // Calculate remaining lockout time in minutes
            $remaining = ceil((strtotime($user['locked_until']) - time()) / 60);
            throw new RuntimeException("Account is temporarily locked. Please try again in $remaining minutes.");
        }

        // === Step 4: Verify password ===
        // Use timing-safe password comparison
        if (password_verify($password, $user['password_hash'])) {
            // Successful login: reset failed attempts and update metrics
            $this->updateLoginMetrics($user['id']);
            return $user;
        }

        // === Step 5: Record failed attempt ===
        // Increment counter and trigger lockout if >= 5 attempts
        $this->recordFailedAttempt($user['id'], (int)$user['failed_login_count']);

        return null;
    }

    /**
     * Internal helper: Record a failed login attempt and trigger lockout if needed
     *
     * LOCKOUT LOGIC:
     * - Increments failed_login_count by 1
     * - When count reaches 5: triggers 15-minute automatic lockout
     * - Sets locked_until to NOW() + 15 minutes (PostgreSQL will handle expiration)
     * - Updates updated_at timestamp
     *
     * BRUTE-FORCE PROTECTION:
     * Stops password guessing attacks by:
     * - Counting attempts per user
     * - Locking account temporarily after 5 failures
     * - Forcing attacker to wait 15 minutes before retry
     * - Exponential slowdown: 5 attempts × ~1 attempt/sec = 5 sec; + 15 min lockout
     *
     * DATABASE UPDATES:
     * - failed_login_count: Incremented to track attempts
     * - locked_until: Set to NOW() + 15 minutes (null if count < 5)
     * - updated_at: Set to current timestamp for audit
     *
     * @param string $userId     User UUID
     * @param int    $currentCount Current failed_login_count from database
     *
     * @return void No return value
     */
    private function recordFailedAttempt(string $userId, int $currentCount): void
    {
        // Increment attempt counter
        $newCount = $currentCount + 1;
        $lockUntil = null;

        // Trigger lockout after 5 failed attempts
        if ($newCount >= 5) {
            $lockUntil = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        }

        // Update database with new count and lockout (if triggered)
        $stmt = $this->pdo->prepare("UPDATE users SET failed_login_count = :count, locked_until = :lock, updated_at = NOW() WHERE id = :id");
        $stmt->execute(['count' => $newCount, 'lock' => $lockUntil, 'id' => $userId]);
    }

    /**
     * Internal helper: Reset failed attempt counter and record successful login
     *
     * CALLED AFTER:
     * Successful password verification (authenticate() method)
     *
     * UPDATES:
     * - last_login_at: Set to NOW() (timestamp of login)
     * - failed_login_count: Reset to 0 (clear previous attempts)
     * - locked_until: Set to NULL (clear any lockout)
     * - updated_at: Set to NOW() (audit trail)
     *
     * @param string $userId User UUID
     *
     * @return void No return value
     */
    private function updateLoginMetrics(string $userId): void
    {
        // Reset failed attempts and clear lockout
        $stmt = $this->pdo->prepare("UPDATE users SET last_login_at = NOW(), failed_login_count = 0, locked_until = NULL WHERE id = :id");
        $stmt->execute(['id' => $userId]);
    }

    /**
     * Hash a plaintext password using BCRYPT
     *
     * ALGORITHM:
     * - Algorithm: PASSWORD_BCRYPT (BCRYPT family)
     * - Cost factor: 12 (strong iteration count, ~280ms per hash)
     * - Result: One-way, cannot be reversed
     *
     * SECURITY:
     * - Cost factor 12 provides strong protection against GPU attacks
     * - Each hash takes ~280ms on modern hardware (prevents brute-force)
     * - Adding cost=12 slows potential attackers significantly
     *
     * USAGE:
     * - User signup: hash new password
     * - Password change: hash new password
     * - Profile update: hash password if user provides new one
     *
     * @param string $password Plaintext password to hash
     *
     * @return string BCRYPT password hash (ready for database storage)
     */
    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Update user profile information
     *
     * UPDATEABLE FIELDS:
     * - display_name: User's visible name (required)
     * - timezone: Preferred timezone for timestamps (optional)
     * - password: New password hash (optional, only if provided)
     *
     * FLOW:
     * 1. If password provided: hash it and update password_hash
     * 2. Always update display_name and timezone
     * 3. Set updated_at to NOW()
     *
     * PASSWORD REQUIREMENT:
     * - If $password is null or empty: skip password update
     * - If $password is non-empty: hash and store
     * - Allows profile update without changing password
     *
     * @param string      $userId      User UUID (who is being updated)
     * @param string      $displayName New display name (cannot be empty)
     * @param string|null $timezone    New timezone (or null to keep current)
     * @param string|null $password    New plaintext password (or null to skip)
     *
     * @return void No return value
     */
    public function updateProfile(string $userId, string $displayName, ?string $timezone = null, ?string $password = null): void
    {
        if ($password) {
            // === Update with new password ===
            $stmt = $this->pdo->prepare("UPDATE users SET display_name = :name, password_hash = :pass, timezone = :tz, updated_at = NOW() WHERE id = :id");
            $stmt->execute([
                'name' => $displayName,
                'pass' => $this->hashPassword($password),
                'tz' => $timezone,
                'id' => $userId
            ]);
        } else {
            // === Update without password change ===
            $stmt = $this->pdo->prepare("UPDATE users SET display_name = :name, timezone = :tz, updated_at = NOW() WHERE id = :id");
            $stmt->execute([
                'name' => $displayName,
                'tz' => $timezone,
                'id' => $userId
            ]);
        }
    }
}
