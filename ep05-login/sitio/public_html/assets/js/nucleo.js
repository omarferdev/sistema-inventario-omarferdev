/*
 * nucleo.js — el núcleo de JavaScript de OmarFerDev (08-oct-2026). Se carga en TODAS las vistas, también en la de
 * entrar. Crea su propio DOM al primer uso (ninguna vista tiene que incluir un parcial) y es el ÚNICO dueño del Esc.
 *
 *   OFDEV.alerta(mensaje, modo)   modo: 'error' (rojo) · 'exito' (verde) · 'aviso' (ámbar, en duda). Un modal.
 *   OFDEV.alerta.conAviso         true: este núcleo sabe pintar el ámbar.
 *   OFDEV.confirmar(opts, alOk)   opts: {titulo, mensaje, textoOk, tipo: 'peligro'|'normal'}. El POST va en alOk.
 *   OFDEV.abrir(id) · OFDEV.cerrar(id) · OFDEV.registrarCierre(id, fn)   (fn solo limpia; corre en todo cierre)
 *     abrir lleva el foco a [data-ui-foco] (o al primer botón) y cerrar lo devuelve a donde estaba.
 *     Un modal con data-ui-informativo se cierra también con Enter, como la alerta.
 *   OFDEV.post(url, formData)     fetch con X-Requested-With; una respuesta perdida vuelve como {exito:false, perdida:true}.
 *
 * Reglas: un modal se cierra con la ✕, Cancelar o Esc, ⛔ nunca
 * con un clic fuera; el Esc cierra solo el de arriba (por z-index computado); el Enter que cierra una alerta se
 * CONSUME, para que no reenvíe el formulario que tenía el foco; y el Tab da la vuelta dentro del modal de arriba.
 */
(function () {
    'use strict';

    var cierres = {};
    var cerrando = {};
    var previos = {};

    function abiertos() {
        return Array.prototype.slice.call(document.querySelectorAll('.modal-overlay.abierto'));
    }

    function cima() {
        var lista = abiertos();
        var mejor = null;
        var zMejor = -Infinity;
        lista.forEach(function (el) {
            var z = parseInt(window.getComputedStyle(el).zIndex, 10) || 0;
            if (z >= zMejor) { zMejor = z; mejor = el; }   // a igualdad, el último del DOM
        });
        return mejor;
    }

    /* Al abrir, el foco entra al modal ([data-ui-foco] o su primer botón) y al cerrar vuelve a donde estaba. */
    function abrir(id) {
        var el = document.getElementById(id);
        if (!el) { return; }
        if (!el.classList.contains('abierto')) { previos[id] = document.activeElement; }
        el.classList.add('abierto');
        var foco = el.querySelector('[data-ui-foco]') || el.querySelector('button');
        if (foco) { foco.focus({ preventScroll: true }); }
    }

    function cerrar(id) {
        var el = document.getElementById(id);
        if (!el || cerrando[id]) { return; }
        cerrando[id] = true;
        el.classList.remove('abierto');          // primero se cierra, luego se limpia
        var previo = previos[id];
        previos[id] = null;
        try {
            if (previo && previo.isConnected && typeof previo.focus === 'function' && !cima()) {
                previo.focus({ preventScroll: true });
            }
            if (typeof cierres[id] === 'function') { cierres[id](); }
        } finally {
            cerrando[id] = false;
        }
    }

    function registrarCierre(id, fn) { cierres[id] = fn; }

    var ENFOCABLES = 'button:not([disabled]), [href], input:not([disabled]):not([type="hidden"]), select:not([disabled]),'
        + ' textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    /* Lo que el Tab puede alcanzar dentro de un modal: lo enfocable que además se ve. */
    function enfocables(el) {
        return Array.prototype.filter.call(el.querySelectorAll(ENFOCABLES), function (x) {
            return !x.hidden && x.getClientRects().length > 0;
        });
    }

    function crearModal(id, capaAlta) {
        var el = document.getElementById(id);
        if (el) { return el; }
        el = document.createElement('div');
        el.className = 'modal-overlay' + (capaAlta ? ' modal-capa-alta' : '');
        el.id = id;
        el.setAttribute('role', 'dialog');
        el.setAttribute('aria-modal', 'true');
        el.innerHTML = '<div class="modal"><div class="modal-cab"><span class="modal-icono" aria-hidden="true"></span>'
            + '<h2></h2></div><div class="modal-cuerpo"></div><div class="modal-pie"></div></div>';
        document.body.appendChild(el);
        return el;
    }

    function boton(texto, clase, alPulsar) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn ' + clase;
        b.textContent = texto;
        b.addEventListener('click', alPulsar);
        return b;
    }

    var TITULOS = { error: 'No se pudo', exito: 'Listo', aviso: 'Revisa esto' };
    var ICONOS = { error: '!', exito: '✓', aviso: '?' };

    /* Un mensaje (texto) o varios (arreglo, en lista). Siempre como texto: nunca innerHTML con datos. */
    function alerta(mensaje, modo) {
        var m = (modo === 'exito' || modo === 'aviso') ? modo : 'error';
        var el = crearModal('ofdev-alerta', true);
        el.querySelector('.modal-icono').className = 'modal-icono ' + m;
        el.querySelector('.modal-icono').textContent = ICONOS[m];
        el.querySelector('h2').textContent = TITULOS[m];
        var cuerpo = el.querySelector('.modal-cuerpo');
        cuerpo.textContent = '';
        if (Array.isArray(mensaje)) {
            var ul = document.createElement('ul');
            ul.className = 'modal-lista';
            mensaje.forEach(function (t) { var li = document.createElement('li'); li.textContent = String(t); ul.appendChild(li); });
            cuerpo.appendChild(ul);
        } else {
            var p = document.createElement('p');
            p.textContent = String(mensaje);
            cuerpo.appendChild(p);
        }
        var pie = el.querySelector('.modal-pie');
        pie.textContent = '';
        var ok = boton('Aceptar', 'btn-primario', function () { cerrar('ofdev-alerta'); });
        pie.appendChild(ok);
        abrir('ofdev-alerta');
        ok.focus({ preventScroll: true });
    }
    alerta.conAviso = true;

    function confirmar(opts, alOk) {
        var o = opts || {};
        var el = crearModal('ofdev-confirmar', true);
        el.querySelector('.modal-icono').className = 'modal-icono ' + (o.tipo === 'peligro' ? 'error' : 'aviso');
        el.querySelector('.modal-icono').textContent = '?';
        el.querySelector('h2').textContent = String(o.titulo || '¿Continuar?');
        var cuerpo = el.querySelector('.modal-cuerpo');
        cuerpo.textContent = '';
        var p = document.createElement('p');
        p.textContent = String(o.mensaje || '');
        cuerpo.appendChild(p);
        var pie = el.querySelector('.modal-pie');
        pie.textContent = '';
        pie.appendChild(boton('Cancelar', '', function () { cerrar('ofdev-confirmar'); }));
        // La clase se asigna en las dos ramas: el botón nuevo no hereda la de una llamada anterior.
        pie.appendChild(boton(String(o.textoOk || 'Aceptar'), o.tipo === 'peligro' ? 'btn-peligro' : 'btn-primario', function () {
            cerrar('ofdev-confirmar');
            if (typeof alOk === 'function') { alOk(); }
        }));
        abrir('ofdev-confirmar');
        pie.lastChild.focus({ preventScroll: true });
    }

    var PERDIDA = { exito: false, perdida: true, mensaje: 'No hubo respuesta del servidor. Revisa tu conexión e intenta de nuevo.' };

    /* Con un tope de 30 s; si se corta, la respuesta cuenta como perdida (no se sabe qué pasó). */
    function post(url, datos) {
        var ctrl = typeof AbortController === 'function' ? new AbortController() : null;
        var reloj = ctrl ? setTimeout(function () { ctrl.abort(); }, 30000) : null;
        return fetch(url, {
            method: 'POST',
            body: datos,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            signal: ctrl ? ctrl.signal : undefined
        }).then(function (r) {
            return r.json().catch(function () {
                return ctrl && ctrl.signal.aborted ? PERDIDA : { exito: false, mensaje: 'Respuesta inesperada del servidor.' };
            });
        }).catch(function () {
            return PERDIDA;
        }).then(function (res) {
            if (reloj) { clearTimeout(reloj); }
            return res;
        });
    }

    /* El Esc y el Enter: un solo oyente, en CAPTURA, antes que los de cualquier módulo. */
    document.addEventListener('keydown', function (e) {
        var arriba = cima();
        if (!arriba) { return; }
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            cerrar(arriba.id);
        } else if (e.key === 'Enter') {
            // Se consume: si no, ese Enter reenvía el formulario que estaba debajo.
            // La alerta y un modal informativo ([data-ui-informativo]) se cierran con él; en los demás, el Enter solo
            // vale dentro del modal (un botón con el foco hace su clic) y nunca llega a lo de abajo.
            if (arriba.id === 'ofdev-alerta' || arriba.hasAttribute('data-ui-informativo')) {
                e.preventDefault();
                e.stopPropagation();
                cerrar(arriba.id);
            } else if (!arriba.contains(document.activeElement)) {
                e.preventDefault();
                e.stopPropagation();
            }
        } else if (e.key === 'Tab') {
            // El Tab da la vuelta DENTRO del modal de arriba: no llega a los campos de la página de atrás.
            var lista = enfocables(arriba);
            var actual = document.activeElement;
            var dentro = arriba.contains(actual);
            if (lista.length === 0) {
                e.preventDefault();
            } else if (e.shiftKey && (!dentro || actual === lista[0])) {
                e.preventDefault();
                lista[lista.length - 1].focus();
            } else if (!e.shiftKey && (!dentro || actual === lista[lista.length - 1])) {
                e.preventDefault();
                lista[0].focus();
            }
        }
    }, true);

    /* Abrir y cerrar sin JS del módulo, por delegación (sobrevive a que una parte de la página se repinte). */
    document.addEventListener('click', function (e) {
        var c = e.target.closest('[data-ui-cerrar]');
        if (c) { cerrar(c.getAttribute('data-ui-cerrar')); return; }
        var a = e.target.closest('[data-ui-abrir]');
        if (a) { abrir(a.getAttribute('data-ui-abrir')); }
    });

    window.OFDEV = Object.freeze({
        alerta: alerta, confirmar: confirmar, abrir: abrir, cerrar: cerrar,
        registrarCierre: registrarCierre, post: post
    });
}());
