# Sincronizador PS a PS

Módulo para PrestaShop 8.2.3 que permite sincronizar productos entre dos tiendas PrestaShop.

## 📋 Requisitos

- PrestaShop 8.0.0 o superior
- PHP 7.2 o superior
- Acceso al webservice de la tienda origen

## 🚀 Instalación

### Método 1: Subir por FTP

1. Copia la carpeta `sync_ps_to_ps_importer` a `/modules/` en tu servidor
2. Ve al Back Office → Módulos → Module Manager
3. Busca "Sincronizador PS a PS"
4. Haz clic en **Instalar**

### Método 2: Subir ZIP desde el Back Office

1. Comprime la carpeta `sync_ps_to_ps_importer` en un archivo ZIP
2. Ve al Back Office → Módulos → Module Manager
3. Haz clic en "Subir un módulo"
4. Selecciona el archivo ZIP y súbelo

## ⚙️ Configuración

1. Ve a **Módulos → Module Manager**
2. Busca "Sincronizador PS a PS" y haz clic en **Configurar**
3. Ingresa:
   - **URL de la tienda origen**: La URL completa de tu tienda PrestaShop origen
   - **API Key**: La clave de API del webservice de PrestaShop

### Obtener la API Key de la tienda origen:

1. En la tienda origen, ve a **Configuración Avanzada → Webservice**
2. Habilita el webservice
3. Crea una nueva clave con permisos de lectura en:
   - Products
   - Categories
   - Images
   - Combinations
   - Manufacturers

## 📖 Uso

1. Ve a **Catálogo → Sincronizador PS** en el menú
2. Verás el panel principal del módulo
3. (Las funcionalidades se irán agregando progresivamente)

## 🔧 Solución de problemas

### El módulo no aparece en el menú

1. Desinstala el módulo
2. Limpia la caché de PrestaShop (Configuración Avanzada → Rendimiento)
3. Instala el módulo nuevamente

### Error 500

1. Elimina la carpeta `var/cache/` del servidor
2. Verifica que todos los archivos se hayan subido correctamente
3. Verifica que el archivo `autoload.php` esté presente

## 📁 Estructura del módulo

```
sync_ps_to_ps_importer/
├── autoload.php                  # Autoloader de clases
├── sync_ps_to_ps_importer.php   # Archivo principal del módulo
├── composer.json                # Configuración de Composer (opcional)
├── config/
│   └── routes.yml               # Rutas de Symfony
├── src/
│   └── Controller/
│       └── AdminImporterController.php  # Controlador principal
└── views/
    └── templates/
        └── admin/
            └── panel.html.twig  # Plantilla del panel
```

## 📝 Versión

- **Versión actual**: 1.0.0
- **Compatible con**: PrestaShop 8.0.0 - 8.2.3+
- **Autor**: Atech

## 🔄 Próximas funcionalidades

- [ ] Conexión con tienda remota vía API
- [ ] Listado de productos remotos
- [ ] Importación de productos bajo demanda
- [ ] Sincronización de stock y precios
- [ ] Sincronización de imágenes
- [ ] Programación de sincronizaciones automáticas

