<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Hotspot — Installation</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 520px; margin: 3rem auto; padding: 0 1rem; background: #f0f4f8; }
    .card { background: #fff; border-radius: 12px; padding: 2rem; box-shadow: 0 4px 20px rgba(0,0,0,.08); }
    h1 { margin: 0 0 1.5rem; color: #1a56db; font-size: 1.4rem; }
    label { display: block; font-size: .85rem; font-weight: 600; color: #374151; margin-bottom: .3rem; }
    input { width: 100%; padding: .55rem .8rem; border: 1.5px solid #d1d5db; border-radius: 8px; font-size: .95rem; box-sizing: border-box; margin-bottom: 1rem; }
    button { background: #1a56db; color: #fff; border: none; border-radius: 8px; padding: .65rem 1.5rem; font-size: .95rem; font-weight: 600; cursor: pointer; width: 100%; }
    .alert { padding: .9rem 1rem; border-radius: 8px; margin-bottom: 1rem; }
    .success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
    .error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .warn    { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; font-weight: 600; }
    hr { border: none; border-top: 1px solid #f3f4f6; margin: 1.5rem 0; }
  </style>
</head>
<body>
<div class="card">
  <h1>Hotspot — First-time Setup</h1>

<?php
$configPath = __DIR__ . '/../config.php';

if (!file_exists($configPath)) {
    echo '<div class="alert error">config.php not found. Please copy config.example.php to config.php and fill in your database credentials first.</div>';
    exit;
}

require_once $configPath;
require_once __DIR__ . '/../lib/db.php';

$done = false;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminUser = trim($_POST['admin_username'] ?? '');
    $adminPass =      $_POST['admin_password'] ?? '';
    $adminConf =      $_POST['admin_confirm']  ?? '';

    if ($adminUser === '')              $errors[] = 'Admin username is required.';
    if (strlen($adminPass) < 6)        $errors[] = 'Password must be at least 6 characters.';
    if ($adminPass !== $adminConf)     $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        try {
            $pdo = db();

            // Create tables
            $sql = file_get_contents(__DIR__ . '/schema.sql');
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $q) {
                $pdo->exec($q);
            }

            // Insert admin (upsert)
            $hash = password_hash($adminPass, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare(
                'INSERT INTO admins (id, username, password_hash) VALUES (UUID(), ?, ?)
                 ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)'
            );
            $stmt->execute([$adminUser, $hash]);

            $done = true;
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

if ($done): ?>
  <div class="alert success">
    <strong>Installation complete!</strong><br>
    Admin account <strong><?= htmlspecialchars($_POST['admin_username']) ?></strong> is ready.<br><br>
    <strong>Important:</strong> delete the <code>setup/</code> folder from your server now.
  </div>
  <a href="../admin/login.php" style="display:block;text-align:center;margin-top:1rem;color:#1a56db;font-weight:600;">Go to Admin Login →</a>
<?php else: ?>

  <?php foreach ($errors as $e): ?>
    <div class="alert error"><?= htmlspecialchars($e) ?></div>
  <?php endforeach; ?>

  <div class="alert warn">Run this page only once. Delete the setup/ folder afterwards.</div>

  <form method="post">
    <label>Admin Username</label>
    <input type="text" name="admin_username" value="<?= htmlspecialchars($_POST['admin_username'] ?? '') ?>" required />
    <label>Admin Password (min 6 chars)</label>
    <input type="password" name="admin_password" required />
    <label>Confirm Password</label>
    <input type="password" name="admin_confirm" required />
    <button type="submit">Install</button>
  </form>

<?php endif; ?>
</div>
</body>
</html>
