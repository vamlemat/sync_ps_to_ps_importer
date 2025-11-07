# Changelog

Todos los cambios notables de este proyecto serán documentados en este archivo.

El formato está basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/),
y este proyecto adhiere a [Versionado Semántico](https://semver.org/lang/es/).

---

## [1.2.0] - 2025-11-07

### 🎉 Versión Mayor - Mejoras de UX y Gestión

### Añadido
- ✨ **Visualizador de logs integrado en la interfaz**
  - Visor de logs en tiempo real dentro del panel de administración
  - Lista de archivos de log con fecha y tamaño
  - Visor de contenido con resaltado de sintaxis
  - Estadísticas por log (total líneas, éxitos, errores, warnings)
  - Botones para copiar contenido al portapapeles
  - Descarga directa de archivos de log
  - Limpieza manual de todos los logs
  - Limpieza automática de logs >24 horas
  - Nueva ruta: `/admin/sync-ps-to-ps/logs`
  - Endpoint AJAX: `/admin/sync-ps-to-ps/clear-logs`
  - CSS personalizado con tema oscuro para el visor
  - JavaScript para cálculo de estadísticas en tiempo real

- 🗂️ **Importación completa de categorías con SEO**
  - Descripción de categoría (multiidioma)
  - SEO metadata completa:
    - `meta_title` - Título SEO
    - `meta_description` - Descripción SEO
    - `meta_keywords` - Palabras clave
  - `link_rewrite` - URLs amigables (slug)
  - Estado activo/inactivo de la categoría
  - Importación de imagen destacada de categoría
  - Generación automática de thumbnails de categoría
  - Todos los tipos de imagen configurados en PrestaShop
  - Nuevo método `downloadCategoryImage()` en `PrestaShopApiService`
  - Nuevo método `importCategoryImage()` en `ProductImporterService`
  - Manejo robusto de errores (no bloquea si falla imagen)

- 📊 **Indicador visual de productos importados**
  - Nueva columna "Importado" en la tabla de productos
  - Badge verde ✅ "Sí" para productos ya importados en local
  - Badge amarillo ➕ "Nuevo" para productos no importados
  - Tooltip con ID local del producto importado
  - Verificación automática por referencia en BD local
  - Añade campos `is_imported` y `local_id` a cada producto
  - **Filtros rápidos visuales**:
    - Botón "Todos" - Muestra todos los productos
    - Botón "Solo Nuevos" - Filtra solo no importados
    - Botón "Solo Importados" - Filtra solo ya importados
  - Filtrado en tiempo real con JavaScript (sin recargar)
  - Botón activo resaltado con colores distintivos
  - Estados visuales con iconos Material Icons
  - Integración perfecta con selección de productos

### Mejorado
- 🔧 **Gestión de logs**
  - Retención automática de logs a 24 horas
  - Evita acumulación de archivos de log
  - Liberación automática de espacio en disco
  - Llamada a `cleanOldLogs()` en cada vista de logs

- 🎨 **Interfaz más intuitiva**
  - Saber de un vistazo qué productos están importados
  - Evitar re-importación de productos existentes
  - Filtros instantáneos sin pérdida de selección
  - Mejor organización visual de la información

- ⚡ **Performance**
  - Consulta SQL eficiente por referencia
  - Cache de resultados de verificación
  - Filtrado del lado del cliente (JavaScript)
  - Sin impacto en tiempo de carga

### Técnico
- 📝 Logs organizados por fecha (`logs/import_log_YYYY-MM-DD.txt`)
- 🧹 Sistema de limpieza automática con `filemtime()` y `unlink()`
- 🖼️ Uso de `_PS_CAT_IMG_DIR_` para imágenes de categoría
- 🔍 Query SQL con `pSQL()` para seguridad
- 🎭 Twig template nueva: `logs.html.twig`
- 📐 Detección de badge con selectores CSS específicos
- 🎨 Clases CSS: `.badge-success`, `.badge-warning`
- 🔗 Ruptura de referencia con `unset($product)` post-loop

### Archivos Modificados
- `src/Controller/AdminImporterController.php` (+97 líneas)
  - Nuevo método `logsAction()` - Vista de logs
  - Nuevo método `clearLogsAction()` - Limpieza AJAX
  - Nuevo método `cleanOldLogs()` - Retención 24h
  - Verificación de productos importados en `indexAction()`
- `src/Service/PrestaShopApiService.php` (+71 líneas)
  - Nuevo método `downloadCategoryImage()`
- `src/Service/ProductImporterService.php` (+94 líneas)
  - Método `importCategoryImage()` - Descarga y redimensiona
  - Mejoras en `createCategoryWithHierarchy()` - SEO completo
- `views/templates/admin/panel.html.twig` (+113 líneas)
  - Nueva columna "Importado"
  - Badges con estados visuales
  - Filtros rápidos con botones
  - JavaScript para filtrado en tiempo real
  - CSS para estados activos
- `views/templates/admin/logs.html.twig` (nuevo archivo, +230 líneas)
  - Interfaz completa de visualización de logs
- `config/routes.yml` (+12 líneas)
  - Ruta `admin_sync_ps_to_ps_importer_logs`
  - Ruta `admin_sync_ps_to_ps_importer_clear_logs`

**Total de líneas añadidas: +617 líneas**

### Beneficios
- 🎯 Mejor visibilidad del estado de importación
- 🐛 Debugging más rápido con logs accesibles
- 🧹 Gestión automática de espacio en disco
- 🏎️ Importaciones más eficientes (evita duplicados)
- 📊 Información SEO completa en categorías
- 🖼️ Imágenes de categoría sincronizadas
- 🎨 UX mejorada con filtros visuales

---

## [1.1.0] - 2025-11-07

### 🎉 Versión Mayor - Funcionalidades Críticas

### Añadido
- ✨ **Sistema completo de paginación**
  - Navegación por números de página (1, 2, 3...)
  - Botones Anterior/Siguiente
  - Selector de productos por página (10, 20, 50, 100)
  - Información de paginación: "Mostrando X-Y de Z productos"
  - Paginación inteligente con máximo 5 páginas visibles
  - Saltos rápidos a primera/última página
  
- ✨ **Importación completa de combinaciones/variantes**
  - Importa todas las variantes de productos (ej: Talla S/M/L + Color Rojo/Azul)
  - Stock específico por cada combinación
  - Precios impactados por combinación (+/- precio)
  - Referencias, EAN13, UPC específicos por combinación
  - Creación automática de atributos y valores si no existen en local
  - Caché de atributos para optimizar performance
  - 3 nuevos métodos en `PrestaShopApiService`:
    - `getCombination()` - Obtiene datos completos de combinación
    - `getProductOption()` - Obtiene atributo (ej: "Talla", "Color")
    - `getProductOptionValue()` - Obtiene valor (ej: "S", "M", "Rojo")
  - 3 nuevos métodos en `ProductImporterService`:
    - `importCombinations()` - Método principal de importación
    - `findOrCreateAttributeGroup()` - Gestión de grupos de atributos
    - `findOrCreateAttribute()` - Gestión de valores de atributos

- 🔍 **Filtros persistentes**
  - Búsqueda por nombre de producto
  - Filtro por categoría
  - Filtros se mantienen al cambiar de página
  - Botón "Limpiar filtros" cuando hay filtros activos
  
- 🎨 **Mejoras en la interfaz**
  - Información "Mostrando X-Y de Z productos"
  - Selector dinámico de límite de productos
  - Estilos mejorados para paginación
  - Valores pre-seleccionados en filtros
  - Colores del tema PrestaShop integrados

### Mejorado
- 🔧 **Proceso de importación ampliado a 10 pasos** (antes 9):
  - [1/10] Obtener datos remotos
  - [2/10] Verificar producto existente
  - [3/10] Datos básicos
  - [4/10] Guardar producto
  - [5/10] Categorías
  - [6/10] Fabricante
  - [7/10] Stock
  - [8/10] Imágenes
  - [9/10] Características
  - [10/10] **Combinaciones** ⭐ NUEVO

- ⚡ **Performance optimizado**
  - Método `getTotalProducts()` para calcular paginación
  - Caché estática de atributos y valores
  - Consultas SQL optimizadas
  - Solo carga productos de la página actual

### Corregido
- 🔒 **Tokens de seguridad CSRF**
  - Añadidos tokens en todos los enlaces de paginación
  - Token en formulario de búsqueda
  - Token en botón "Limpiar filtros"
  - Token en selector de límite (JavaScript)
  - Soluciona error "Token no válido: el acceso directo..."

### Técnico
- 📝 Logs detallados de combinaciones importadas
- 🧪 Validación completa de datos antes de crear
- 🛡️ Manejo de errores por combinación (una falla no afecta otras)
- 📊 Estadísticas de importación mejoradas

### Archivos Modificados
- `src/Service/PrestaShopApiService.php` (+120 líneas)
- `src/Service/ProductImporterService.php` (+272 líneas)
- `src/Controller/AdminImporterController.php` (+27 líneas)
- `views/templates/admin/panel.html.twig` (+181 líneas)

**Total de líneas añadidas: +600 líneas**

---

## [1.0.0] - 2025-11-06

### 🎉 Versión Inicial Estable

### Añadido
- ✨ **Importación completa de productos**
  - Datos básicos (nombre, descripción, precio, referencia, EAN13, UPC)
  - Soporte multiidioma completo
  - Precio base, precio mayorista, precio por unidad
  - Cálculo inteligente de precio por unidad desde "Packaging"
  - Asignación de grupos de impuestos

- 📁 **Categorías con jerarquía**
  - Importa estructura completa de categorías
  - Crea categorías padres automáticamente
  - Mapeo de categorías remotas a locales
  - Asignación de categoría principal y secundarias

- 🖼️ **Importación de imágenes**
  - Todas las imágenes del producto
  - Generación automática de miniaturas
  - Asignación de imagen de portada (cover)
  - Asociación a tienda (multitienda ready)
  - Manejo de errores por imagen

- 🏭 **Fabricantes**
  - Creación automática si no existe
  - Mapeo por nombre
  - Asignación al producto

- 📦 **Gestión de stock**
  - Sincronización de cantidades disponibles
  - Soporte para StockAvailable
  - Actualización automática

- ⚙️ **Características (Features)**
  - Importación automática de características
  - Creación de características si no existen
  - Creación de valores de características
  - Asignación al producto
  - Caché para optimizar consultas repetidas

- 🔌 **Cliente API robusto**
  - Soporte JSON y XML
  - Autenticación básica
  - Detección inteligente de respuestas HTML (WAF, 403, 401)
  - Manejo de compresión (gzip/deflate)
  - Redirecciones automáticas
  - Timeouts configurables
  - IPv4 forzado
  - SSL permisivo para entornos privados
  - Soporte para IP personalizada (entornos internos)

- 🎯 **Interfaz de usuario**
  - Panel administrativo integrado en PrestaShop
  - Listado de productos remotos
  - Selección múltiple de productos
  - Importación por lotes
  - Indicador de conexión con tienda origen
  - Mensajes de estado y progreso

- 🔧 **Configuración del módulo**
  - URL de tienda origen
  - API Key del webservice
  - IP personalizada (opcional)
  - Prueba de conexión automática
  - Validación de configuración

- 📝 **Sistema de logs**
  - Logs detallados por día
  - Registro de cada paso de importación
  - Información de errores y advertencias
  - Ubicación: `logs/import_log_YYYY-MM-DD.txt`

### Características Técnicas
- 🏗️ Arquitectura PSR-4
- 🎨 Plantillas Twig
- 🛣️ Rutas Symfony
- 🔒 Validación y sanitización de datos
- 🗄️ Consultas SQL optimizadas
- ⚡ Caché estática de entidades
- 🛡️ Manejo robusto de errores
- 📊 Detección de productos existentes por referencia

### Compatibilidad
- PrestaShop 8.0.0+
- PHP 7.2+
- Extensiones: cURL, SimpleXML, JSON

### Archivos Principales
- `sync_ps_to_ps_importer.php` - Módulo principal
- `src/Service/PrestaShopApiService.php` - Cliente API
- `src/Service/ProductImporterService.php` - Lógica de importación
- `src/Controller/AdminImporterController.php` - Controlador admin
- `views/templates/admin/panel.html.twig` - Interfaz

---

## [0.1.0] - 2025-11-05

### Añadido
- 🎬 Versión inicial de desarrollo
- 🏗️ Estructura base del módulo
- 🔌 Conexión básica con API
- 📦 Importación básica de productos

---

## Tipos de Cambios

- `Añadido` para funcionalidades nuevas
- `Cambiado` para cambios en funcionalidades existentes
- `Obsoleto` para funcionalidades que serán eliminadas
- `Eliminado` para funcionalidades eliminadas
- `Corregido` para corrección de errores
- `Seguridad` para vulnerabilidades

---

## Formato de Versionado

Este proyecto usa [Versionado Semántico](https://semver.org/lang/es/):

- **MAJOR** (1.x.x): Cambios incompatibles en la API
- **MINOR** (x.1.x): Funcionalidades nuevas compatibles
- **PATCH** (x.x.1): Correcciones de errores compatibles

---

[1.2.0]: https://github.com/vamlemat/sync_ps_to_ps_importer/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/vamlemat/sync_ps_to_ps_importer/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/vamlemat/sync_ps_to_ps_importer/releases/tag/v1.0.0
[0.1.0]: https://github.com/vamlemat/sync_ps_to_ps_importer/releases/tag/v0.1.0
