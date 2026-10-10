<?php
declare(strict_types=1);
/** @var string $contenido  la vista del módulo, ya pintada */
/** @var string $titulo */
$__u = \App\Core\Sesion::usuario();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<?= csp_meta() ?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($titulo ?? 'OmarFerDev') ?> · OmarFerDev</title>
<link rel="icon" href="<?= e(asset('assets/img/logo.png')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body>
<header class="topbar">
  <div class="topbar-marca">
    <img src="<?= e(asset('assets/img/logo.png')) ?>" alt="" width="32" height="32">
    <span>omarferdev_</span>
  </div>
  <div class="topbar-usuario">
    <span><?= e($__u['nombre'] ?? '') ?></span>
    <form method="post" action="<?= e(url('logout')) ?>">
      <?= \App\Core\Csrf::campo() ?>
      <button type="submit" class="btn">Salir</button>
    </form>
  </div>
</header>
<main class="contenido">
<?= $contenido ?>
</main>
<script src="<?= e(asset('assets/js/nucleo.js')) ?>"></script>
</body>
</html>
