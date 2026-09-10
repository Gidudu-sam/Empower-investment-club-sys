<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 — Page Not Found</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="bg-light py-5">
<div class="container" style="max-width:560px">
    <div class="text-center mb-4">
        <i class="bi bi-exclamation-circle display-1 text-primary d-block mb-2"></i>
        <h1 class="display-4 fw-bold mb-1">404</h1>
        <p class="lead text-muted">The page you're looking for doesn't exist.</p>
    </div>

    <?php if (defined('APP_ENV') && APP_ENV === 'development'): ?>
    <div class="card border-warning mb-4">
        <div class="card-header bg-warning bg-opacity-25 fw-semibold small">
            <i class="bi bi-bug me-1"></i> Debug Info (development mode only)
        </div>
        <div class="card-body small font-monospace">
            <strong>Requested page:</strong>
            <?= htmlspecialchars($_GET['page'] ?? '(empty)') ?>
            <br>
            <strong>Full URL:</strong>
            <?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="text-center">
        <a href="<?= defined('APP_URL') ? APP_URL : '/' ?>/index.php?page=dashboard"
           class="btn btn-primary me-2">
            <i class="bi bi-speedometer2 me-1"></i> Dashboard
        </a>
        <a href="<?= defined('APP_URL') ? APP_URL : '/' ?>/index.php?page=members"
           class="btn btn-outline-primary">
            <i class="bi bi-people me-1"></i> Members
        </a>
    </div>
</div>
</body>
</html>
