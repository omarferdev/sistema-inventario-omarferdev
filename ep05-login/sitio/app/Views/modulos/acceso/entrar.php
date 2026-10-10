<?php
declare(strict_types=1);
/** @var string|null $error  el mensaje del servidor si se envió sin JavaScript */
use App\Core\Iconos;
use App\Core\Vista;
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<?= csp_meta() ?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Entrar · OmarFerDev</title>
<link rel="icon" href="<?= e(asset('assets/img/logo.png')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/acceso.css')) ?>">
<?= Vista::componente('fondo', ['parte' => 'hoja']) ?>
</head>
<body class="pagina-acceso">
<?= Vista::componente('fondo', ['parte' => 'capas']) ?>
<main class="acceso-tarjeta">
  <div class="acceso-marca">
    <img src="<?= e(asset('assets/img/logo.png')) ?>" alt="" width="64" height="64">
    <span>omarferdev<span class="acceso-cursor">_</span></span>
  </div>
  <h1>Entrar</h1>
  <p class="ayuda">Sistema de inventario. Entra con tu correo.</p>
  <form id="form-acceso" method="post" action="<?= e(url('login')) ?>" novalidate
        <?= $error !== null ? 'data-error="' . e(json_encode($error, JSON_UNESCAPED_UNICODE)) . '"' : '' ?>>
    <?= \App\Core\Csrf::campo() ?>
    <?php if ($error !== null): ?>
    <div class="acceso-error" role="alert" data-error-visible>
      <?php foreach ((array) $error as $m): ?><p><?= e($m) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="campo">
      <label for="correo">Correo</label>
      <div class="campo-icono">
        <?= Iconos::obtener('correo', 18, 'icono campo-icono-izq') ?>
        <input id="correo" name="correo" type="email" autocomplete="username" autocapitalize="none" spellcheck="false"
               inputmode="email" maxlength="254" placeholder="tu@correo.com" required autofocus>
      </div>
    </div>
    <div class="campo">
      <label for="password">Contraseña</label>
      <div class="campo-icono con-ojo">
        <?= Iconos::obtener('candado', 18, 'icono campo-icono-izq') ?>
        <input id="password" name="password" type="password" autocomplete="current-password" required data-mayus
               aria-describedby="password-mayus">
        <button type="button" class="campo-ojo" data-ver="password" aria-pressed="false" aria-controls="password"
                aria-label="Mostrar la contraseña" hidden>
          <?= Iconos::obtener('ojo', 20, 'icono ojo-ver') ?><?= Iconos::obtener('ojo-tachado', 20, 'icono ojo-ocultar') ?>
        </button>
      </div>
      <p class="campo-aviso" id="password-mayus" role="status"></p>
    </div>
    <button type="submit" class="btn btn-primario acceso-enviar" data-espera="Entrando…">
      <span class="btn-texto">Entrar</span><?= Iconos::obtener('flecha', 18) ?>
    </button>
  </form>
  <p class="acceso-pie">
    <button type="button" class="acceso-enlace" data-ui-abrir="modal-olvido" hidden>¿Olvidaste tu contraseña?</button>
    <noscript>¿Olvidaste tu contraseña? Pídele a quien administra el sistema que la restablezca.</noscript>
  </p>
</main>
<div class="modal-overlay" id="modal-olvido" role="dialog" aria-modal="true" aria-labelledby="modal-olvido-titulo"
     data-ui-informativo>
  <div class="modal">
    <div class="modal-cab">
      <span class="modal-icono info"><?= Iconos::obtener('llave', 18) ?></span>
      <h2 id="modal-olvido-titulo">¿Olvidaste tu contraseña?</h2>
    </div>
    <div class="modal-cuerpo">
      <p>Por seguridad, aquí no se restablece. Pídele a quien administra el sistema que la restablezca.</p>
      <p>Al entrar con la contraseña que te dé, el sistema te pedirá elegir una nueva.</p>
    </div>
    <div class="modal-pie">
      <button type="button" class="btn btn-primario" data-ui-cerrar="modal-olvido" data-ui-foco>Entendido</button>
    </div>
  </div>
</div>
<script src="<?= e(asset('assets/js/nucleo.js')) ?>"></script>
<script src="<?= e(asset('assets/js/acceso.js')) ?>"></script>
</body>
</html>
