<?php
if (!defined('SCP_APP_INIT')) {
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo SCP_Security::e($pageTitle); ?></title>
    <link rel="icon" type="image/png" href="assets/favicon.png?v=<?php echo SCP_Security::e(SCP_VERSION); ?>" />
    <link rel="shortcut icon" type="image/png" href="assets/favicon.png?v=<?php echo SCP_Security::e(SCP_VERSION); ?>" />
    <link rel="apple-touch-icon" href="assets/favicon.png?v=<?php echo SCP_Security::e(SCP_VERSION); ?>" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="assets/css/app.css?v=<?php echo SCP_Security::e(SCP_VERSION); ?>" />
    <?php $recaptchaDomainReady = (!empty($recaptchaSiteKey) && in_array($page, array('login', 'register', 'contact'), true) && SCP_Security::isRecaptchaDomainVerified()); ?>
    <?php if ($recaptchaDomainReady): ?>
        <script src="https://www.google.com/recaptcha/enterprise.js?render=<?php echo SCP_Security::e($recaptchaSiteKey); ?>" async defer></script>
    <?php endif; ?>
</head>
<body class="scp-body">
    <!-- Top Site Navigation Bar -->
    <header class="scp-site-header">
        <div class="scp-container scp-header-inner">
            <a href="index.php?page=home" class="scp-brand-link">
                <?php $effectiveLogo = !empty($siteLogoUrl) ? $siteLogoUrl : (file_exists(SCP_ROOT_DIR . '/assets/logo.svg') ? 'assets/logo.svg' : 'assets/secure-cloud-portal-logo.svg'); ?>
                <img src="<?php echo SCP_Security::e($effectiveLogo); ?>" alt="<?php echo SCP_Security::e($companyName); ?>" class="scp-brand-logo" />
            </a>

            <nav class="scp-main-nav">
                <a href="index.php?page=home" class="scp-nav-link <?php echo $page === 'home' ? 'active' : ''; ?>">Home</a>
                <a href="index.php?page=home#domains-section" class="scp-nav-link">Domains</a>
                <a href="index.php?page=home#pricing-section" class="scp-nav-link">Products</a>
                <a href="index.php?page=about" class="scp-nav-link <?php echo $page === 'about' ? 'active' : ''; ?>">About Us</a>
                <a href="index.php?page=contact" class="scp-nav-link <?php echo $page === 'contact' ? 'active' : ''; ?>">Contact</a>
            </nav>

            <div class="scp-header-actions">
                <?php if ($auth->isLoggedIn()): ?>
                    <?php if ($auth->isAdmin()): ?>
                        <a href="index.php?page=admin" class="scp-btn <?php echo $page === 'admin' ? 'scp-btn-primary' : 'scp-btn-secondary'; ?> scp-btn-sm">
                            Admin Control Center
                        </a>
                    <?php endif; ?>
                    <a href="index.php?page=portal" class="scp-btn <?php echo $page === 'portal' ? 'scp-btn-primary' : 'scp-btn-secondary'; ?> scp-btn-sm">
                        Client Portal
                    </a>
                    <a href="index.php?page=logout" class="scp-btn scp-btn-outline-light scp-btn-sm">
                        Sign Out
                    </a>
                <?php else: ?>
                    <a href="index.php?page=login" class="scp-btn scp-btn-outline-light scp-btn-sm">
                        Client Sign In
                    </a>
                    <a href="index.php?page=register" class="scp-btn scp-btn-primary scp-btn-sm">
                        Get Started &rarr;
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>
    <main class="scp-main-content">
