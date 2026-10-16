<?php
/** @var \App\Core\View $this */
$user = auth_user();
$unread = $user ? app(\App\Services\Notifications\NotificationService::class)->unreadCount((int) $user['id']) : 0;
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="JOB NIGER : trouvez des missions rémunérées ou recrutez rapidement des prestataires au Niger.">
  <meta name="theme-color" content="#0B7A5C">
  <title><?= e(($title ?? '') !== '' ? $title . ' — JOB NIGER' : 'JOB NIGER') ?></title>
  <link rel="icon" href="<?= e(asset('images/favicon.svg')) ?>" type="image/svg+xml">
  <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<a class="visually-hidden-focusable position-absolute p-2 bg-white" href="#contenu">Aller au contenu</a>
<?= $this->partial('partials/nav', ['user' => $user, 'unread' => $unread]) ?>
<main id="contenu">
  <?= $this->partial('partials/flash') ?>
  <?= $this->section('content') ?>
</main>
<?= $this->partial('partials/footer') ?>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
