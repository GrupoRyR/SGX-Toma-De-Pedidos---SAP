/*
 * Service worker de Pedidos SEGUREX.
 *
 * Alcance deliberadamente corto. SEGUREX decidio no construir modo offline
 * completo: quedarse sin senal "pasa muy poco", y guardar el catalogo entero en
 * el telefono con sincronizacion posterior es de las partes mas caras y mas
 * fragiles del proyecto.
 *
 * Lo que si hace, que es lo que el asesor nota todos los dias:
 *  - la interfaz abre sin esperar a la red;
 *  - si se cae la senal, aparece una pantalla que lo explica en vez del error
 *    del navegador.
 *
 * Lo que NO hace, a proposito: no guarda datos de pedidos ni de clientes, y no
 * responde nunca una peticion de escritura desde el cache. El numero de pedido
 * lo asigna siempre el servidor -- era el error numero uno de la app vieja.
 */

/*
 * La version sale del `?v=` con que la pagina registra este archivo, y ese
 * valor es el hash del manifiesto de Vite. O sea: cambia sola cada vez que se
 * despliega algo que toque la interfaz, y no cambia cuando no.
 *
 * Se hace asi porque la alternativa era subir un numero a mano en cada
 * despliegue, y eso se olvida. El dia que se olvidara, los asesores seguirian
 * viendo la interfaz vieja sin manera de enterarse.
 */
const VERSION = new URL(self.location.href).searchParams.get('v') || 'dev';
const CACHE = `segurex-${VERSION}`;

// Lo minimo para que la aplicacion se dibuje: el icono, el manifiesto y la
// pantalla de sin conexion. Los assets con hash de Vite se guardan solos al
// pasar por aqui la primera vez.
const BASICOS = [
    '/sin-conexion',
    '/pwa/manifest.webmanifest',
    '/pwa/icono-192.png',
    // El navegador lo pide solo, aunque nadie lo enlace. Sin guardarlo, cada
    // pantalla sin senal deja un error rojo en la consola que no significa nada
    // y despista cuando se esta buscando uno de verdad.
    '/favicon.ico',
];

self.addEventListener('install', (evento) => {
    evento.waitUntil(
        caches.open(CACHE)
            .then((cache) => cache.addAll(BASICOS))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (evento) => {
    // Al publicar una version nueva se borran los caches viejos. Sin esto, un
    // asesor podria quedarse con la interfaz de hace tres despliegues.
    evento.waitUntil(
        caches.keys()
            .then((nombres) => Promise.all(
                nombres.filter((n) => n !== CACHE).map((n) => caches.delete(n))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (evento) => {
    const peticion = evento.request;

    // Solo se toca lo que se lee, del mismo origen. Todo lo demas -- POST de
    // Livewire, la API del robot, cualquier otro dominio -- va derecho a la red.
    if (peticion.method !== 'GET' || new URL(peticion.url).origin !== self.location.origin) {
        return;
    }

    const url = new URL(peticion.url);

    // Nada de datos en el cache: ni pedidos, ni clientes, ni sesion.
    if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/auth/') || url.pathname === '/salir') {
        return;
    }

    // Archivos estaticos: primero el cache. Vite les pone hash en el nombre, asi
    // que un archivo cacheado nunca es la version equivocada.
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/pwa/')) {
        evento.respondWith(
            caches.match(peticion).then((guardado) => guardado || traerYGuardar(peticion))
        );

        return;
    }

    // Paginas: primero la red, porque los datos tienen que ser los de ahora. El
    // cache es solo la red de seguridad cuando no hay senal.
    if (peticion.mode === 'navigate') {
        evento.respondWith(
            fetch(peticion).catch(() => caches.match('/sin-conexion'))
        );
    }
});

function traerYGuardar(peticion) {
    return fetch(peticion).then((respuesta) => {
        if (respuesta.ok) {
            const copia = respuesta.clone();
            caches.open(CACHE).then((cache) => cache.put(peticion, copia));
        }

        return respuesta;
    });
}
