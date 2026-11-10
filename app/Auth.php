<?php
/**
 * Stand-Alone Authentication, User Management & Session Controller
 * Secure Cloud Portal Stand-Alone v2.0.0
 */

if (!defined('SCP_APP_INIT')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

class SCP_Auth {
    private $db;
    private $currentUser = null;

    public function __construct() {
        $this->db = SCP_Database::getInstance();
        SCP_Security::initSession();
    }

    /**
     * Check if user is logged in
     */
    public function isLoggedIn() {
        return !empty($_SESSION['scp_user_id']) && ($this->getCurrentUser() !== null);
    }

    /**
     * Check if logged-in user is an Administrator
     */
    public function isAdmin() {
        $user = $this->getCurrentUser();
        return $user && ($user['role'] === 'admin');
    }

    /**
     * Get logged-in User ID
     */
    public function getCurrentUserId() {
        return isset($_SESSION['scp_user_id']) ? (int)$_SESSION['scp_user_id'] : 0;
    }

    /**
     * Get current user record from MySQL
     */
    public function getCurrentUser($forceRefresh = false) {
        if ($this->currentUser !== null && !$forceRefresh) {
            return $this->currentUser;
        }
        $userId = $this->getCurrentUserId();
        if ($userId <= 0 || !$this->db->isConfigured()) {
            return null;
        }
        $this->currentUser = $this->getUserById($userId);
        if (!$this->currentUser || $this->currentUser['status'] !== 'active') {
            $this->logout();
            return null;
        }
        return $this->currentUser;
    }

    /**
     * Get user by ID
     */
    public function getUserById($userId) {
        $stmt = $this->db->getConnection()->prepare("SELECT * FROM scp_users WHERE id = ? LIMIT 1");
        $stmt->execute(array((int)$userId));
        $user = $stmt->fetch();
        if ($user) {
            $user['display_name'] = trim($user['first_name'] . ' ' . $user['last_name']) ?: $user['email'];
        }
        return $user ?: null;
    }

    /**
     * Get user by Email
     */
    public function getUserByEmail($email) {
        $email = strtolower(trim($email));
        $stmt = $this->db->getConnection()->prepare("SELECT * FROM scp_users WHERE email = ? LIMIT 1");
        $stmt->execute(array($email));
        $user = $stmt->fetch();
        if ($user) {
            $user['display_name'] = trim($user['first_name'] . ' ' . $user['last_name']) ?: $user['email'];
        }
        return $user ?: null;
    }

    /**
     * Authenticate user with email and password
     */
    public function login($email, $password, $stripeService = null) {
        $email = strtolower(trim($email));
        $user  = $this->getUserByEmail($email);

        // Generic error message prevents account enumeration
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return array('error' => 'Invalid email address or password.');
        }

        if ($user['status'] !== 'active') {
            return array('error' => 'Your account has been suspended. Please contact support.');
        }

        // Regenerate session ID to prevent session fixation
        session_regenerate_id(true);
        $_SESSION['scp_user_id'] = (int)$user['id'];
        $_SESSION['scp_user_role'] = $user['role'];

        $stmt = $this->db->getConnection()->prepare("UPDATE scp_users SET last_login = NOW() WHERE id = ?");
        $stmt->execute(array($user['id']));

        $this->db->logActivity($user['id'], 'user_login', sprintf('User %s signed in.', $user['email']));

        // Auto-sync Stripe customer data if Stripe service provided
        if ($stripeService instanceof SCP_StripeService) {
            $stripeService->createOrGetCustomer($user['id']);
        }

        $this->currentUser = $this->getUserById($user['id']);
        return array('success' => true, 'user' => $this->currentUser);
    }

    /**
     * Register new client account and automatically sync existing Stripe customer data
     * (or create a new Stripe customer if they are not in Stripe yet)
     */
    public function register($email, $password, $firstName = '', $lastName = '', $company = '', $stripeService = null, $role = 'client', $extraProfile = array()) {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return array('error' => 'Please enter a valid email address.');
        }

        if (strlen($password) < 8) {
            return array('error' => 'Password must be at least 8 characters long.');
        }

        if ($this->getUserByEmail($email)) {
            return array('error' => 'An account is already registered with this email address.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $role = ($role === 'admin') ? 'admin' : 'client';

        $phone    = trim($extraProfile['phone'] ?? '');
        $addr1    = trim($extraProfile['address_1'] ?? '');
        $addr2    = trim($extraProfile['address_2'] ?? '');
        $city     = trim($extraProfile['city'] ?? '');
        $state    = trim($extraProfile['state'] ?? '');
        $postcode = trim($extraProfile['postcode'] ?? '');
        $country  = trim($extraProfile['country'] ?? 'US') ?: 'US';

        $stmt = $this->db->getConnection()->prepare(
            "INSERT INTO scp_users (email, password_hash, role, first_name, last_name, company, phone, address_1, address_2, city, state, postcode, country, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute(array(
            $email,
            $hash,
            $role,
            trim($firstName),
            trim($lastName),
            trim($company),
            $phone,
            $addr1,
            $addr2,
            $city,
            $state,
            $postcode,
            $country
        ));

        $userId = (int)$this->db->getConnection()->lastInsertId();
        $this->db->logActivity($userId, 'user_registered', sprintf('New %s account created for %s.', $role, $email));

        // Automatically detect if this client already exists in Stripe (importing their data)
        // OR if they are not in Stripe yet, immediately create their Customer record in Stripe!
        $stripeCustomerId = '';
        if ($stripeService instanceof SCP_StripeService) {
            $cid = $stripeService->createOrGetCustomer($userId);
            if (is_string($cid)) {
                $stripeCustomerId = $cid;
            }
        }

        return array(
            'success'            => true,
            'user_id'            => $userId,
            'stripe_customer_id' => $stripeCustomerId
        );
    }

    /**
     * Log user out
     */
    public function logout() {
        SCP_Security::initSession();
        $_SESSION = array();
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
        $this->currentUser = null;
    }

    /**
     * Update client profile and sync updated billing info to Stripe
     */
    public function updateProfile($userId, $data, $stripeService = null) {
        $user = $this->getUserById($userId);
        if (!$user) {
            return array('error' => 'User account not found.');
        }

        $email = isset($data['email']) ? strtolower(trim($data['email'])) : $user['email'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return array('error' => 'Please provide a valid email address.');
        }

        if ($email !== $user['email']) {
            $existing = $this->getUserByEmail($email);
            if ($existing && (int)$existing['id'] !== (int)$userId) {
                return array('error' => 'That email address is already in use by another account.');
            }
        }

        $stmt = $this->db->getConnection()->prepare(
            "UPDATE scp_users SET
                email = ?,
                first_name = ?,
                last_name = ?,
                company = ?,
                phone = ?,
                address_1 = ?,
                address_2 = ?,
                city = ?,
                state = ?,
                postcode = ?,
                country = ?
             WHERE id = ?"
        );

        $stmt->execute(array(
            $email,
            trim($data['first_name'] ?? ''),
            trim($data['last_name'] ?? ''),
            trim($data['company'] ?? ''),
            trim($data['phone'] ?? ''),
            trim($data['address_1'] ?? ''),
            trim($data['address_2'] ?? ''),
            trim($data['city'] ?? ''),
            trim($data['state'] ?? ''),
            trim($data['postcode'] ?? ''),
            trim($data['country'] ?? 'US') ?: 'US',
            (int)$userId
        ));

        // Ensure client exists in Stripe (creating them if not yet linked) and push updated contact/address to Stripe
        if ($stripeService instanceof SCP_StripeService) {
            if (empty($user['stripe_customer_id'])) {
                $stripeService->createOrGetCustomer($userId);
            }
            $stripeService->pushProfileToStripe($userId);
        }

        $this->db->logActivity($userId, 'profile_updated', 'Account contact and billing information updated.');
        $this->currentUser = $this->getUserById($userId);

        return array('success' => true);
    }

    /**
     * Change user password
     */
    public function changePassword($userId, $currentPassword, $newPassword) {
        $user = $this->getUserById($userId);
        if (!$user) {
            return array('error' => 'User not found.');
        }

        if (!password_verify($currentPassword, $user['password_hash'])) {
            return array('error' => 'Your current password is incorrect.');
        }

        if (strlen($newPassword) < 8) {
            return array('error' => 'New password must be at least 8 characters long.');
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $this->db->getConnection()->prepare("UPDATE scp_users SET password_hash = ? WHERE id = ?");
        $stmt->execute(array($newHash, (int)$userId));

        $this->db->logActivity($userId, 'password_changed', 'Account password changed.');
        return array('success' => true);
    }

    /**
     * Admin: Get all users
     */
    public function getAllUsers() {
        $stmt = $this->db->getConnection()->query("SELECT * FROM scp_users ORDER BY id DESC");
        $users = $stmt->fetchAll();
        foreach ($users as &$u) {
            $u['display_name'] = trim($u['first_name'] . ' ' . $u['last_name']) ?: $u['email'];
        }
        return $users;
    }
}
