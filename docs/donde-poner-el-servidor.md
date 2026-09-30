# ¿Dónde poner el servidor?

Hoy el servidor corre en una laptop con `php artisan serve` y los celulares se
conectan por el punto de acceso de un celular (`http://10.150.103.55:8000`).
Sirve para probar, pero para usarlo en la planta hay tres problemas:

1. **La IP cambia** cada vez que se reconecta el Wi-Fi o se apaga el punto de acceso.
2. **La conexión va sin cifrar** (`http`): en una red compartida se puede ver el
   token de sesión y los datos.
3. **Depende de que alguien abra XAMPP y la terminal**: si la PC se reinicia, no hay servidor.

La app ya aguanta días sin señal (guarda todo en el teléfono y envía después),
así que las dos opciones funcionan. La diferencia es **cuándo** llegan los datos a la planta.

---

## Opción A — PC de la planta (red local)

El servidor vive en una PC fija de la planta. Los acopiadores registran en el
campo sin señal y **envían al llegar a la planta** por el Wi-Fi de ahí.

| A favor | En contra |
|---|---|
| Sin costo mensual | Los datos llegan recién cuando el acopiador vuelve a la planta |
| No depende de internet | El admin solo ve los datos desde la planta |
| Los datos no salen de la planta | Si la PC se malogra, no hay servidor (por eso los respaldos diarios) |

**Qué hay que hacer:**
- Una PC que quede prendida en horario de acopio (puede ser modesta).
- **IP fija** para esa PC en el router de la planta (reserva DHCP), por ejemplo `192.168.1.10`.
- Que **Apache y MySQL arranquen solos** al prender la PC (servicios de XAMPP),
  en vez de `php artisan serve`.
- **HTTPS local** con un certificado propio instalado en los celulares
  (o, como mínimo, un Wi-Fi de la planta con clave solo para el personal).
- Copiar los respaldos diarios a una USB o a la nube una vez por semana.

## Opción B — Servidor en internet

El servidor vive en un hosting con dominio (ej. `api.ecolacteos-huata.pe`) y
HTTPS. Los acopiadores **envían desde donde tengan datos móviles**.

| A favor | En contra |
|---|---|
| Datos casi en tiempo real (cuando hay cobertura) | Costo mensual (un VPS pequeño, aprox. S/ 20–60 al mes) |
| El admin ve todo desde cualquier lugar | Depende de internet en la planta para las pantallas de planta/calidad |
| HTTPS gratis y automático (Let's Encrypt) | Hay que mantener el servidor actualizado |
| El proveedor del hosting se encarga del hardware | Los datos quedan fuera de la planta |

**Qué hay que hacer:**
- Contratar un VPS (Linux) y un dominio.
- Instalar PHP 8.2, MySQL/MariaDB y Nginx; subir `ecolacteos-api` desde GitHub.
- Certificado HTTPS con Let's Encrypt.
- En el `.env` del servidor: `APP_ENV=production`, `APP_DEBUG=false`, usuario de
  base de datos propio y claves nuevas.
- Respaldos diarios de la base (el hosting suele ofrecerlos).

---

## Recomendación

- **Si en las comunidades casi no hay señal**: la opción **A** alcanza, porque
  igual los datos llegarían recién en la planta.
- **Si hay señal en buena parte de la ruta** y el admin quiere ver el acopio
  mientras ocurre: la opción **B**.

En cualquiera de las dos, **antes de arrancar** hay que:
- borrar los datos de prueba (historial, proveedores de ejemplo, usuarios `4000000x`);
- crear los usuarios reales con claves propias;
- en la app, poner la dirección definitiva del servidor.
