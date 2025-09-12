# ALTEK Integration for WooCommerce

**Autor:** Ing. Carlos Garzón  
**Versión:** 5.0.0
**Licencia:** GPLv2

---

## 📌 Descripción

Este plugin extiende la funcionalidad de WooCommerce permitiendo **enviar pedidos directamente al sistema ALTEK** desde la lista de pedidos en el administrador de WordPress.  

Entre sus características principales:

- Agrega un **botón por pedido** en la lista de pedidos para enviarlo a ALTEK.  
- Permite **acciones masivas**: seleccionar varios pedidos y enviarlos al mismo tiempo.  
- Incluye una **página de configuración** para definir endpoint, credenciales y parámetros de conexión.  
- Opción para **excluir productos** específicos (por SKU o ID) que no deben transmitirse.  
- Registro de logs detallados en WooCommerce → Estado → Registros.  
- Añade **notas automáticas en el pedido** con el resultado del envío (éxito, error, productos omitidos).  

---

## ⚙️ Instalación

1. Descargar o clonar el repositorio en tu equipo.  
2. Copiar la carpeta del plugin al directorio: wp-content/plugins
3. Dentro de esta carpeta deben existir al menos dos archivos:
- `altek-integration.php` (código principal del plugin).  
- `assets-wc-altek.js` (lógica JS para manejar los clics en el botón de envío).  

4. Activar el plugin desde el panel de administración de WordPress en la sección **Plugins**.  

---

## 🚀 Configuración

Una vez activo, dirígete a:  
**WooCommerce → Integración ALTEK**

Allí encontrarás los siguientes campos configurables:

- **Endpoint ALTEK**  
URL de la API de ALTEK que recibirá los pedidos.  
Ejemplo: `https://endpoint.com/api/orders`

- **API Key**  
Token o clave de acceso. El plugin la enviará por defecto en la cabecera `Authorization: Bearer`.

- **Timeout (segundos)**  
Tiempo máximo de espera para la conexión con ALTEK. Por defecto: `20`.

- **Debug (logs detallados)**  
Al marcar esta opción, todos los envíos quedarán registrados en los logs de WooCommerce para depuración.

- **Excluir productos (SKU o ID)**  
Lista de productos que no deben transmitirse a ALTEK.  
Se pueden ingresar **SKU(s)** y/o **ID(s)** separados por comas o saltos de línea.  
Ejemplo:
SKU-TEST-001, 9876
SKU-ABC-999
1234


---

## 📋 Uso

- En el listado de pedidos de WooCommerce, aparecerá un botón adicional “**Enviar a ALTEK**” en la columna de acciones.  
- Al hacer clic, el plugin enviará el pedido completo (excepto productos excluidos) al endpoint configurado.  
- También podrás seleccionar múltiples pedidos y usar la acción masiva **Enviar a ALTEK**.  
- Cada pedido mostrará una nota interna con el resultado del envío:  
- `ALTEK: Enviado correctamente`  
- `ALTEK: Error al enviar - {detalle}`  
- `ALTEK: Se omitieron X producto(s)`  
- `ALTEK: No se envió. Todos los productos del pedido están excluidos.`  

---

## 🛡️ Consideraciones de seguridad

- No dejar credenciales ni endpoints “quemados” en el código; siempre usar la página de ajustes.  
- El envío se realiza **server-to-server** desde WordPress, sin exponer credenciales al cliente.  
- Solo los usuarios con capacidad `manage_woocommerce` pueden ejecutar los envíos.

---

## 🔔 Sistema de alertas

El plugin comunica el resultado de cada envío mediante diferentes canales para que el administrador pueda diagnosticar y actuar rápidamente:

- **Ventanas emergentes (SweetAlert2):** al enviar un pedido desde la lista, se muestra un `popup` indicando el resultado. Los íconos cambian según el mensaje: éxito por defecto, `info` si el pedido ya existía y `warning` si hubo elementos omitidos; en caso de error se usa `error`.
- **Notas del pedido:** todas las respuestas importantes se registran como nota interna, incluyendo productos excluidos, errores de base de datos o cotizaciones generadas.
- **Acciones masivas:** al ejecutar la acción **Enviar a ALTEK** sobre varios pedidos, el sistema envía cada pedido y añade parámetros `altek_sent` y `altek_fail` a la URL para resumir la cantidad de envíos exitosos o fallidos.
- **Logs de WooCommerce:** si la opción *Debug* está activa, se escriben mensajes de seguimiento en el registro `altek-integration`.
- **Respuestas AJAX para desarrolladores:** las rutas `altek_send_order` y `altek_send_orders_bulk` devuelven mensajes estructurados (JSON) que pueden ser consumidos por scripts externos.

### Flujo típico de un envío individual

1. El usuario hace clic en **Enviar a ALTEK** y el botón cambia a “Enviando…”.
2. Se envía una petición AJAX al servidor; el resultado determina la ventana emergente mostrada y se añade una nota al pedido.
3. Si todos los productos estaban excluidos o el pedido ya existía, el servidor lo indica claramente y se clasifica como advertencia o información.

### Ejemplos de mensajes

- `ALTEK: Cotización 123 creada.` → popup de éxito y nota en el pedido.
- `ALTEK: Cotización 123 (ya fué creada).` → popup informativo; evita duplicados.
- `ALTEK: Se omitieron 2 producto(s): SKU:TEST1…` → popup de advertencia y detalle en la nota.
- `ALTEK: Error al enviar (DB) - detalle` → popup de error; revisar logs para mayor información.

---

# 📋 Glosario de Mensajes — Plugin ALTEK Integration for WooCommerce

Este glosario cubre **errores**, **advertencias**, **alertas**, **mensajes de éxito** y **mensajes informativos** generados por el plugin que integra WooCommerce con el sistema ALTEK.

---

## 🟥 Errores

| Mensaje                                                        | Significado / Causa                                                              | Sugerencia de acción                     |
|---------------------------------------------------------------|----------------------------------------------------------------------------------|------------------------------------------|
| **ALTEK: Error al enviar (DB) - [detalle]**                   | Error en la conexión o escritura a la base de datos ALTEK.                        | Revisar detalles del error; revisar conexión, permisos, o datos. |
| **order_id missing**                                          | Falta el ID del pedido en la petición AJAX.                                       | Revisar código JS o recargar la página.  |
| **forbidden**                                                 | El usuario no tiene permisos suficientes.                                         | Usar usuario con permisos de admin.      |
| **invalid nonce**                                             | Token de seguridad de WordPress inválido o expirado.                              | Recargar la página e intentar de nuevo.  |
| **No se pudo conectar a Postgres...**                         | Error de conexión a la base ALTEK (host, usuario, contraseña, firewall, SSL, etc.)| Revisar credenciales, red y firewall.    |
| **Extensión PHP "pgsql" no está instalada...**                | Falta la extensión PHP para PostgreSQL.                                           | Habilitar la extensión pgsql en el servidor. |
| **No se pudo iniciar transacción.**                           | Fallo al iniciar transacción SQL.                                                 | Revisar permisos o integridad de la base.|
| **Fallo al consultar idempotencia**                           | No se pudo consultar si ya existe la cotización en ALTEK.                         | Revisar sintaxis SQL y permisos.         |
| **Fallo al resolver SKUs**                                    | No se pudieron buscar los SKUs en la tabla `inv_items`.                           | Revisar consulta y datos.                |
| **Los productos no tienen SKU. Defina SKU o configure exclusiones.** | El pedido tiene productos sin SKU asignado.                                       | Asignar SKU válido a todos los productos.|
| **SKUs no encontrados en [schema].inv_items: [listado]**      | Uno o más SKUs del pedido no existen en ALTEK.                                    | Registrar primero los SKU en ALTEK.      |
| **SKU sin resolver: [SKU]**                                   | Un SKU de la orden no se pudo mapear al ID de ALTEK.                              | Revisar y corregir el SKU en WooCommerce/ALTEK.|
| **Fallo insert cotización**                                   | No se pudo insertar la cabecera del pedido.                                       | Revisar estructura y datos requeridos.   |
| **Fallo insert ítem: [SKU]**                                  | No se pudo insertar el producto en la tabla de cotización.                        | Revisar integridad y datos del producto. |
| **Fallo commit/rollback**                                     | Error al confirmar o revertir una transacción SQL.                                | Revisar estabilidad de la base.          |
| **Pedido no encontrado**                                      | El pedido no existe en WooCommerce.                                               | Verificar que el pedido esté creado.     |
| **Todos los productos del pedido están excluidos.**           | Todos los productos fueron excluidos por configuración, nada para enviar.          | Revisar exclusiones en la configuración. |
| **Some orders failed**                                        | Fallaron algunos pedidos en envío masivo.                                         | Ver detalles individuales del error.     |

---

## 🟧 Alertas y Advertencias

| Mensaje                                                        | Significado / Causa                                               | Sugerencia de acción             |
|---------------------------------------------------------------|-------------------------------------------------------------------|----------------------------------|
| **ALTEK: Se omitieron X producto(s):**                        | Productos excluidos del envío por configuración de exclusiones.    | Revisar exclusiones (SKU/ID).    |
| **ALTEK: No se envió. Todos los productos del pedido están excluidos por configuración.** | Ningún producto del pedido es válido para enviar.                 | Ajustar exclusiones o pedido.    |
| **ALTEK: SKU sin resolver: [SKU]**                            | El SKU no está registrado en ALTEK o no cumple el formato.        | Registrar el SKU en ALTEK y reintentar. |
| **ALTEK: Cotización [ID] (ya fué creada).**                     | El pedido ya fue transmitido previamente; no se duplica.           | Nada que hacer, el registro ya existe. |

---

## 🟩 Mensajes de Éxito

| Mensaje                                                        | Significado / Causa                                               | Notas                            |
|---------------------------------------------------------------|-------------------------------------------------------------------|----------------------------------|
| **ALTEK: Cotización [ID] creada.**                            | Cotización transmitida correctamente y registrada en ALTEK.        | El ID es el número de cotización asignado. |
| **Pedido enviado a ALTEK** (en la interfaz o JS)              | El pedido fue procesado y enviado a ALTEK sin errores.             | Confirmar en logs internos.      |
| **all sent** (en acciones masivas AJAX)                       | Todos los pedidos seleccionados fueron enviados correctamente.      |                                  |

---

## 🟦 Mensajes Informativos / Logs

| Mensaje                                                        | Significado / Causa                                               | Notas                            |
|---------------------------------------------------------------|-------------------------------------------------------------------|----------------------------------|
| **ALTEK: Cotización [ID] (idempotente).**                     | Detección de intento de re-envío; ya existe ese pedido en ALTEK.   | No se creó un duplicado.         |
| **ALTEK: Se omitieron X producto(s):**                        | Registro de exclusión por configuración (no es error).             | Solo informativo.                |
| **ALTEK: [detalle adicional en logs de WooCommerce]**          | Mensajes de depuración si el modo debug está activado.             | Consultar en WooCommerce → Estado → Registros. |

---

## 🟪 Mensajes Técnicos (Respuesta AJAX, para desarrolladores)

| Mensaje                       | Descripción                                                     |
|-------------------------------|-----------------------------------------------------------------|
| **order_id missing**          | No se envió el parámetro `order_id` en la petición AJAX.        |
| **forbidden**                 | Usuario no tiene permisos suficientes.                          |
| **invalid nonce**             | Token de seguridad inválido/expirado.                           |
| **all sent**                  | Todos los pedidos masivos fueron enviados con éxito.            |
| **Some orders failed**        | Uno o más pedidos masivos fallaron; se entregan detalles por pedido. |

---

## 🟨 Notas sobre Mensajes de Exclusión y Configuración

- **"Se omitieron X producto(s):"**  
  Se genera cuando un producto está en la lista de exclusiones (por SKU o ID) configurada en el plugin.

- **"No se envió. Todos los productos del pedido están excluidos por configuración."**  
  Ocurre cuando ninguno de los productos del pedido es elegible para envío.

---

## 🔎 ¿Dónde se ven estos mensajes?
- **Notas del pedido en WooCommerce:** Visibles en la sección de notas internas.
- **Logs de WooCommerce:** Si está activado "Debug", ver en WooCommerce → Estado → Registros → "altek-integration".
- **Respuestas AJAX:** En consola o al hacer debugging de la integración/admin.
- **Mensajes de la interfaz:** Al usar la acción en el panel de pedidos o acción masiva.

---


---
## ✍️ Autor

- Desarrollado por **Carlos Garzón**  
- Software Engineer Fullstack Web Developer.
---

