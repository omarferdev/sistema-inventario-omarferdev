<?php
declare(strict_types=1);
/** @var list<string>|null $error  los motivos del servidor si se envió sin JavaScript */
/** @var bool $hecho  true: la cuenta se creó (sin JavaScript) */
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
<title>Instalar · OmarFerDev</title>
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
<?php if ($hecho): ?>
  <h1>Listo</h1>
  <p class="ayuda"><?= e(\App\Services\AccesoService::MENSAJE_INSTALADO) ?></p>
  <p class="acceso-pie"><a class="btn btn-primario acceso-enviar" href="<?= e(url('login')) ?>">Ir a entrar</a></p>
<?php else: ?>
  <h1>Instalar</h1>
  <p class="ayuda">Crea la primera cuenta, la de Super administrador. La clave de instalación es la que pusiste en
     tu <code>env.php</code>. Después de esto, esta página se apaga sola.</p>
  <form id="form-acceso" method="post" action="<?= e(url('instalar')) ?>" novalidate
        <?= $error !== null ? 'data-error="' . e(json_encode($error, JSON_UNESCAPED_UNICODE)) . '"' : '' ?>>
    <?= \App\Core\Csrf::campo() ?>
    <?php if ($error !== null): ?>
    <div class="acceso-error" role="alert" data-error-visible>
      <?php foreach ((array) $error as $m): ?><p><?= e($m) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="campo">
      <label for="clave">Clave de instalación</label>
      <div class="campo-icono con-ojo">
        <?= Iconos::obtener('llave', 18, 'icono campo-icono-izq') ?>
        <input id="clave" name="clave" type="password" autocomplete="off" spellcheck="false" required autofocus
               data-mayus aria-describedby="clave-mayus">
        <button type="button" class="campo-ojo" data-ver="clave" aria-pressed="false" aria-controls="clave"
                aria-label="Mostrar la clave de instalación" hidden>
          <?= Iconos::obtener('ojo', 20, 'icono ojo-ver') ?><?= Iconos::obtener('ojo-tachado', 20, 'icono ojo-ocultar') ?>
        </button>
      </div>
      <p class="campo-aviso" id="clave-mayus" role="status"></p>
    </div>
    <div class="campo">
      <label for="nombre">Tu nombre</label>
      <div class="campo-icono">
        <?= Iconos::obtener('persona', 18, 'icono campo-icono-izq') ?>
        <input id="nombre" name="nombre" type="text" autocomplete="name" maxlength="100" required>
      </div>
    </div>
    <div class="campo">
      <label for="correo">Correo</label>
      <div class="campo-icono">
        <?= Iconos::obtener('correo', 18, 'icono campo-icono-izq') ?>
        <input id="correo" name="correo" type="email" autocomplete="username" autocapitalize="none" spellcheck="false"
               inputmode="email" maxlength="254" placeholder="tu@correo.com" required>
      </div>
    </div>
    <div class="campo">
      <label for="nueva">Contraseña</label>
      <div class="campo-icono con-ojo">
        <?= Iconos::obtener('candado', 18, 'icono campo-icono-izq') ?>
        <input id="nueva" name="nueva" type="password" autocomplete="new-password" required data-mayus
               data-minimo="<?= e($minimo) ?>" aria-describedby="nueva-ayuda nueva-mayus">
        <button type="button" class="campo-ojo" data-ver="nueva" aria-pressed="false" aria-controls="nueva"
                aria-label="Mostrar la contraseña" hidden>
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
    <button type="submit" class="btn btn-primario acceso-enviar" data-espera="Creando…">
      <span class="btn-texto">Crear la cuenta</span><?= Iconos::obtener('flecha', 18) ?>
    </button>
  </form>
<?php endif; ?>
</main>
<script src="<?= e(asset('assets/js/nucleo.js')) ?>"></script>
<script src="<?= e(asset('assets/js/acceso.js')) ?>"></script>
</body>
</html>
