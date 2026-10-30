# Sistema de ventas para tiendas de calzado · BMO Studios

Catálogo web con pedidos por WhatsApp, productos por talla y color, inventario,
ventas y facturas, mayorista, pedidos al taller, finanzas y respaldos.

## Instalar en una tienda nueva

1. Copia esta carpeta al servidor (en XAMPP: `C:\xampp\htdocs\<carpeta>`).
   Si la carpeta no se llama `bmo-ventas`, cambia `APP_BASE_URL` en `config/app.php`.
2. Abre `http://<servidor>/<carpeta>/` en el navegador. Te lleva al instalador:
   pon los datos de MySQL, el nombre del negocio y el usuario dueño.
   El instalador crea la base de datos, las tablas y `config/local.php`.
3. Entra al panel y completa **Configuración**: logo, WhatsApp, redes, textos,
   moneda, impuesto y la **clave de licencia** que entrega BMO Studios.
4. En **Usuarios** crea las cuentas de vendedores y bodega.

## Actualizar a una versión nueva

Copia los archivos nuevos encima (sin borrar `config/local.php`, `uploads/` ni
`backups/`). Si la versión trae cambios en la base de datos, en **Configuración**
aparece el botón **ACTUALIZAR BASE DE DATOS**.

## Respaldos

Se crea uno automático al día al abrir el panel, en la carpeta `backups/`
(bloqueada para el navegador). El dueño los descarga en **Respaldos**.

## Roles

- **Dueño:** todo.
- **Vendedor:** ventas, productos, inventario y pedidos al taller. No ve costos ni finanzas.
- **Bodega:** productos, categorías, inventario y pedidos al taller.

## Licencia y módulos

Sin licencia válida el sistema funciona con el paquete Básico y muestra un aviso.
La licencia activa los módulos Mayorista, Pedidos al taller y Finanzas.
