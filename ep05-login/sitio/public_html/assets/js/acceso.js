/*
 * acceso.js — la pantalla de entrar y la de cambiar la contraseña (08-oct-2026; ayudas al escribir, 2.ª versión).
 * El formulario funciona sin JavaScript (POST normal); con él, se envía por OFDEV.post y el error sale en el modal del
 * núcleo, siempre con el texto que manda el servidor. Aquí viven además:
 *   el ojo de cada campo (alterna la contraseña visible), el aviso de Bloq Mayús ([data-mayus] → #<id>-mayus),
 *   el botón en espera (data-espera), el contador hacia el mínimo ([data-minimo] → #<id>-ayuda) y la coincidencia
 *   ([data-coincide-con] → #<id>-ayuda). Son AYUDAS: quien decide es el servidor (PoliticaPassword y el Service).
 * Los cambios de estado se anuncian una vez en #acceso-estado (role=status), no en cada tecla.
 */
(function () {
    'use strict';

    var form = document.getElementById('form-acceso');
    if (!form || !window.OFDEV) { return; }
    var enviar = form.querySelector('[type="submit"]');
    var textoBoton = enviar.querySelector('.btn-texto');
    var textoOriginal = textoBoton ? textoBoton.textContent : '';
    var estado = document.getElementById('acceso-estado');
    var enVuelo = false;

    function anunciar(texto) {
        if (estado) { estado.textContent = texto; }
    }

    // El ojo y el enlace del olvido llegan ocultos: sin JavaScript no harían nada (el olvido trae su <noscript>).
    // El ojo es un botón de alternar: su nombre no cambia y aria-pressed dice si se ve; la hoja cambia el dibujo.
    var ojos = Array.prototype.slice.call(document.querySelectorAll('[data-ver]'));
    function ponerOjo(b, mostrar) {
        var campo = document.getElementById(b.getAttribute('data-ver'));
        if (!campo) { return; }
        campo.type = mostrar ? 'text' : 'password';
        b.setAttribute('aria-pressed', mostrar ? 'true' : 'false');
    }
    ojos.forEach(function (b) {
        b.hidden = false;
        b.addEventListener('click', function () { ponerOjo(b, b.getAttribute('aria-pressed') !== 'true'); });
    });
    Array.prototype.forEach.call(document.querySelectorAll('.acceso-enlace[data-ui-abrir]'), function (b) {
        b.hidden = false;
    });

    // Bloq Mayús: solo se sabe al teclear (el navegador no lo dice antes); se borra al salir del campo.
    Array.prototype.forEach.call(form.querySelectorAll('[data-mayus]'), function (campo) {
        var aviso = document.getElementById(campo.id + '-mayus');
        if (!aviso) { return; }
        function revisar(e) {
            if (typeof e.getModifierState !== 'function') { return; }
            var texto = e.getModifierState('CapsLock') ? 'Bloq Mayús está activado.' : '';
            if (aviso.textContent !== texto) { aviso.textContent = texto; }
        }
        campo.addEventListener('keydown', revisar);
        campo.addEventListener('keyup', revisar);
        campo.addEventListener('blur', function () { aviso.textContent = ''; });
    });

    // El contador hacia el mínimo. Cuenta como el servidor (mb_strlen: puntos de código, no bytes).
    Array.prototype.forEach.call(form.querySelectorAll('[data-minimo]'), function (campo) {
        var minimo = parseInt(campo.getAttribute('data-minimo'), 10) || 0;
        var ayuda = document.getElementById(campo.id + '-ayuda');
        if (!ayuda || minimo < 1) { return; }
        var listoAntes = false;
        campo.addEventListener('input', function (e) {
            var n = Array.from(campo.value).length;
            var listo = n >= minimo;
            ayuda.textContent = n === 0 ? 'Al menos ' + minimo + ' caracteres.'
                : (listo ? '✓ ' + n + ' caracteres' : n + ' de ' + minimo + ' caracteres');
            ayuda.classList.toggle('ok', listo);
            if (listo !== listoAntes && e.isTrusted) {
                anunciar(listo ? 'Ya tiene los ' + minimo + ' caracteres.' : 'Le faltan caracteres.');
            }
            listoAntes = listo;
        });
    });

    // La coincidencia: se ve desde que la segunda tiene algo; se anuncia solo cuando pasa a coincidir.
    Array.prototype.forEach.call(form.querySelectorAll('[data-coincide-con]'), function (campo) {
        var otro = document.getElementById(campo.getAttribute('data-coincide-con'));
        var ayuda = document.getElementById(campo.id + '-ayuda');
        if (!otro || !ayuda) { return; }
        var antes = '';
        function comparar(e) {
            var ahora = campo.value === '' ? '' : (campo.value === otro.value ? 'si' : 'no');
            ayuda.textContent = ahora === 'si' ? '✓ Coinciden' : (ahora === 'no' ? 'Todavía no coinciden' : '');
            ayuda.classList.toggle('ok', ahora === 'si');
            if (ahora === 'si' && antes !== 'si' && e.isTrusted) { anunciar('Las dos coinciden.'); }
            antes = ahora;
        }
        campo.addEventListener('input', comparar);
        otro.addEventListener('input', comparar);
    });

    function esperando(si) {
        enviar.disabled = si;
        enviar.classList.toggle('cargando', si);
        form.setAttribute('aria-busy', si ? 'true' : 'false');
        if (textoBoton) { textoBoton.textContent = si ? (enviar.getAttribute('data-espera') || textoOriginal) : textoOriginal; }
    }

    // Un error que llegó sin JavaScript (el formulario se envió normal y volvió con su mensaje): la página lo trae
    // escrito (para quien no tiene JavaScript); con él, se oculta y sale en el modal.
    if (form.getAttribute('data-error')) {
        Array.prototype.forEach.call(form.querySelectorAll('[data-error-visible]'), function (el) { el.hidden = true; });
        OFDEV.alerta(JSON.parse(form.getAttribute('data-error')), 'error');
    }

    // Si el navegador devuelve esta página desde su caché (Atrás), el botón no se queda en «Entrando…».
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) {
            enVuelo = false;
            esperando(false);
        }
    });

    // Las contraseñas se borran y se vuelven a ocultar (una que estaba a la vista ya no es type=password).
    function limpiarSecretos() {
        ojos.forEach(function (b) { ponerOjo(b, false); });
        Array.prototype.forEach.call(form.querySelectorAll('input[type="password"], input[data-limpiar]'), function (c) {
            c.value = '';
            c.dispatchEvent(new Event('input'));   // el mismo evento que el usuario: el contador y la coincidencia se ponen al día
        });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (enVuelo) { return; }
        enVuelo = true;
        esperando(true);
        OFDEV.post(form.action, new FormData(form)).then(function (r) {
            if (r && r.exito && typeof r.ir === 'string') {
                if (typeof r.aviso === 'string') {
                    // El instalador: primero el aviso de «cuenta creada», y al cerrarlo, a entrar. La clave y las
                    // contraseñas no se quedan en la página mientras el aviso está abierto.
                    limpiarSecretos();
                    OFDEV.alerta(r.aviso, 'exito');
                    OFDEV.registrarCierre('ofdev-alerta', function () { window.location.assign(r.ir); });
                    return;
                }
                window.location.assign(r.ir);   // el botón se queda en espera hasta que llega la página siguiente
                return;
            }
            enVuelo = false;
            esperando(false);
            anunciar('');   // el último anuncio («coinciden») ya no es cierto
            limpiarSecretos();
            if (r && r.recargar) {
                OFDEV.alerta(r.mensaje, 'error');
                OFDEV.registrarCierre('ofdev-alerta', function () { window.location.reload(); });
                return;
            }
            // Un resultado en duda (el servidor no pudo confirmar) o una respuesta que no llegó ⛔ no es un error:
            // va en ámbar, y en cambiar la contraseña con el texto de qué hacer (data-texto-duda).
            var enDuda = !!(r && (r.incierto || r.perdida));
            var texto = enDuda && form.getAttribute('data-texto-duda') ? form.getAttribute('data-texto-duda')
                : ((r && r.mensaje) || 'No se pudo. Intenta de nuevo.');
            OFDEV.alerta(texto, enDuda ? 'aviso' : 'error');
        });
    });
}());
