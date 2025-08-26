# ALTEK Integration for WooCommerce

**Autor:** Ing. Carlos Garzón  
**Versión:** 1.0.0  
**Licencia:** GPLv2 o posterior  

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

## ✍️ Autor

Desarrollado por **Carlos Garzón**  
Software Engineer Fullstack Web Developer.
---

