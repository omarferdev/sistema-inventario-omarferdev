<?php
declare(strict_types=1);

/*
 * config/acceso.php — las cifras del acceso, en UN solo sitio (08-oct-2026).
 *
 * Decididas por Omar el 07 y el 08-oct.
 * Las leen la sesión, el límite de intentos, la política de contraseñas, el Service de acceso y cron/crear_admin.php.
 * ⛔ Ninguna de estas cifras se escribe en otro archivo: se lee de aquí.
 */

defined('OFDEV') || exit;

return [
    'sesion' => [
        // __Host-: el navegador no deja que otro sitio del dominio padre ponga una cookie con este nombre.
        'nombre'          => '__Host-OFDEV_SID',
        'inactividad_seg' => 30 * 60,   // 30 min sin uso
        'vida_seg'        => 10 * 3600, // 10 h como máximo; también es gc_maxlifetime (el hosting trae 1440)
        'sid_length'      => 32,        // × 5 bits = 160 bits (ASVS 7.2.3 pide 128; el hosting trae 26)
        'sid_bits'        => 5,
        'samesite'        => 'Lax',
    ],
    'intentos' => [
        // Por cuenta, SOLO si la misma red también falló: así nadie bloquea a otro desde fuera. El bloqueo es la
        // VENTANA deslizante: dura mientras los fallos sigan dentro de los 15 min (las cifras «_bloqueo» aparte que
        // había aquí no las leía nadie, y se quitaron).
        'cuenta_ip_fallos'   => 5,
        'cuenta_ip_ventana'  => 15 * 60,
        'ip_fallos'          => 20,
        'ip_ventana'         => 15 * 60,
        // Decisión de Omar (08-oct): frenar sin bloquear. Con 10 fallos o más a una MISMA cuenta en 15 min, desde
        // cualquier red, cada intento a esa cuenta espera 200 ms por fallo de más, hasta 3 s.
        'cuenta_freno_umbral'  => 10,
        'cuenta_freno_ventana' => 15 * 60,
        'cuenta_freno_paso_ms' => 200,
        'cuenta_freno_max_ms'  => 3000,
    ],
    'password' => [
        'min_caracteres' => 12,         // Sin reglas de composición (ASVS 6.2.1 y 6.2.5)
        'max_bytes'      => 1024,       // Tope antes de calcular la huella; lo que pase se rechaza, ⛔ no se recorta
        'algoritmo'      => PASSWORD_ARGON2ID, // Medido el 08-oct: existe en los dos PHP del servidor (8.3 web, 8.2 CLI)
        'opciones'       => ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1], // 19 MiB, 2 pasadas: 45 ms
        // La lista local de contraseñas comunes y filtradas (ASVS 6.2.4 y 6.2.12; decisión de Omar, 08-oct).
        // La lista sale de SecLists (AVISOS.md del episodio); si falta el archivo, la política falla
        // cerrada: no deja fijar ninguna contraseña.
        'lista_comunes'  => __DIR__ . '/contrasenas-comunes.txt',
    ],
    'pantalla' => [
        // El fondo de entrar y de cambiar la contraseña (decisión de Omar, 08-oct): 'estrellas' o 'ninguno'.
        // Las estrellas salen de un generador con semilla fija (fondo-estrellas.css); su cielo, de los tokens de
        // acceso.css (--cielo-arriba, --cielo-abajo, --estrella, --estrella-acento).
        'fondo' => 'estrellas',
    ],
    'bitacora' => [
        // La purga (cron/purgar_accesos.php; decisión de Omar, 08-oct): las entradas, los fallos, los bloqueos y las
        // salidas viven 90 días; los cambios de cuenta (alta, contraseña, sesión cortada), 365. El script tiene un
        // piso fijo de 90 días: con una cifra menor, se niega.
        'purga_dias'        => 90,
        'purga_dias_cuenta' => 365,
        'purga_lote'        => 5000,
        'agente_max' => 255,            // el largo de accesos.agente
    ],
];
