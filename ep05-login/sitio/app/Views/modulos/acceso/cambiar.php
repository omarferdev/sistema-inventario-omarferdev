<?php
declare(strict_types=1);
/** @var list<string>|null $error  los motivos del servidor si se envió sin JavaScript */
/** @var int $minimo */
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
<title>Cambia tu contraseña · OmarFerDev</title>
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
  <h1>Cambia tu contraseña</h1>
  <p class="ayuda">Antes de usar el sistema, elige una contraseña nueva: al menos <?= e($minimo) ?> caracteres.
     Puedes usar una frase con espacios y cualquier símbolo.</p>
  <form id="form-acceso" method="post" action="<?= e(url('cambiar-password')) ?>" novalidate
        data-texto-duda="<?= e(\App\Services\AccesoService::MENSAJE_CAMBIO_INCIERTO) ?>"
        <?= $error !== null ? 'data-error="' . e(json_encode($error, JSON_UNESCAPED_UNICODE)) . '"' : '' ?>>
    <?= \App\Core\Csrf::campo() ?>
    <?php if ($error !== null): ?>
    <div class="acceso-error" role="alert" data-error-visible>
      <?php foreach ((array) $error as $m): ?><p><?= e($m) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="campo">
      <label for="nueva">Contraseña nueva</label>
      <div class="campo-icono con-ojo">
        <?= Iconos::obtener('candado', 18, 'icono campo-icono-izq') ?>
        <input id="nueva" name="nueva" type="password" autocomplete="new-password" required autofocus data-mayus
               data-minimo="<?= e($minimo) ?>" aria-describedby="nueva-ayuda nueva-mayus">
        <button type="button" class="campo-ojo" data-ver="nueva" aria-pressed="false" aria-controls="nueva"
                aria-label="Mostrar la contraseña nueva" hidden>
          <?= Iconos::obtener('ojo', 20, 'icono ojo-ver') ?><?= Iconos::obtener('ojo-tachado', 20, 'icono ojo-ocultar') ?>
        </button>
      </div>
      <p class="campo-ayuda" id="nueva-ayuda">Al menos <?= e($minimo) ?> caracteres.</p>
      <p class="campo-aviso" id="nueva-mayus" role="status"></p>
    </div>
    <div class="campo">
      <label for="confirma">Escríbela otra vez</label>
      <div class="campo-icono con-ojo">
        <?= Iconos::obtener('candado', 18, 'icono campo-icono-izq') ?>
        <input id="confirma" name="confirma" type="password" autocomplete="new-password" required data-mayus
               data-coincide-con="nueva" aria-describedby="confirma-ayuda confirma-mayus">
        <button type="button" class="campo-ojo" data-ver="confirma" aria-pressed="false" aria-controls="confirma"
                aria-label="Mostrar la confirmación" hidden>
          <?= Iconos::obtener('ojo', 20, 'icono ojo-ver') ?><?= Iconos::obtener('ojo-tachado', 20, 'icono ojo-ocultar') ?>
        </button>
      </div>
      <p class="campo-ayuda" id="confirma-ayuda"></p>
      <p class="campo-aviso" id="confirma-mayus" role="status"></p>
    </div>
    <p class="solo-lector" id="acceso-estado" role="status"></p>
    <button type="submit" class="btn btn-primario acceso-enviar" data-espera="Guardando…">
      <span class="btn-texto">Guardar y entrar</span><?= Iconos::obtener('flecha', 18) ?>
    </button>
  </form>
  <form method="post" action="<?= e(url('logout')) ?>" class="acceso-pie">
    <?= \App\Core\Csrf::campo() ?>
    <button type="submit" class="acceso-enlace">Cancelar y salir</button>
  </form>
</main>
<script src="<?= e(asset('assets/js/nucleo.js')) ?>"></script>
<script src="<?= e(asset('assets/js/acceso.js')) ?>"></script>
</body>
</html>
