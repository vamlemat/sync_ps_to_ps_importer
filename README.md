# 🔄 Sincronizador PS a PS

**Módulo profesional para PrestaShop 8.x** que sincroniza productos completos entre dos tiendas PrestaShop mediante API/Webservice.

[![Version](https://img.shields.io/badge/version-1.1.0-blue.svg)](https://github.com/vamlemat/sync_ps_to_ps_importer)
[![PrestaShop](https://img.shields.io/badge/PrestaShop-8.0%2B-red.svg)](https://www.prestashop.com)
[![PHP](https://img.shields.io/badge/PHP-7.2%2B-purple.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-AFL--3.0-green.svg)](LICENSE)

---

## ✨ Características Principales

### 🎯 Importación Completa de Productos

- ✅ **Datos básicos**: Nombre, descripción, precio, referencia, EAN13, UPC
- ✅ **Categorías**: Importa jerarquía completa automáticamente
- ✅ **Imágenes**: Todas las imágenes con miniaturas automáticas
- ✅ **Fabricantes**: Crea fabricantes si no existen
- ✅ **Stock**: Sincroniza cantidades disponibles
- ✅ **Características (Features)**: Crea y asigna automáticamente
- ✅ **Combinaciones/Variantes**: Importa todas las variantes con stock y precios específicos
- ✅ **Precios**: Precio base, precio mayorista, precio por unidad
- ✅ **Impuestos**: Asigna grupos de impuestos

### 🚀 Funcionalidades Avanzadas

- 📄 **Paginación inteligente**: Navega entre miles de productos
- 🔍 **Filtros**: Búsqueda por nombre y categoría
- 📊 **Selector de límite**: 10, 20, 50 o 100 productos por página
- 🔄 **Actualización inteligente**: Detecta productos existentes por referencia
- 📝 **Logs detallados**: Registro completo de cada importación
- ⚡ **Performance optimizada**: Caché y consultas eficientes

---

## 📋 Requisitos

- **PrestaShop**: 8.0.0 o superior
- **PHP**: 7.2 o superior
- **Extensiones PHP**: cURL, SimpleXML, JSON
- **Webservice**: Acceso habilitado en tienda origen

---

## 🚀 Instalación

### Método 1: Clonar desde GitHub

```bash
cd /ruta/a/prestashop/modules/
git clone https://github.com/vamlemat/sync_ps_to_ps_importer.git
```

Luego en el Back Office:
1. Ve a **Módulos → Module Manager**
2. Busca "Sincronizador PS a PS"
3. Haz clic en **Instalar**

### Método 2: Subir por FTP

1. Descarga el módulo desde GitHub (Download ZIP)
2. Descomprime y sube la carpeta `sync_ps_to_ps_importer` a `/modules/`
3. En el Back Office: **Módulos → Module Manager**
4. Busca "Sincronizador PS a PS" e **Instalar**

### Método 3: Subir ZIP desde PrestaShop

1. Descarga el ZIP desde GitHub
2. Ve a **Módulos → Module Manager**
3. Haz clic en "Subir un módulo"
4. Selecciona el ZIP y súbelo

---

## ⚙️ Configuración

### 1. Habilitar Webservice en la Tienda Origen

En tu **tienda PrestaShop de origen** (donde están los productos):

1. Ve a **Configuración Avanzada → Webservice**
2. **Activa el webservice**
3. Haz clic en **"Añadir nueva clave"**
4. Configura los permisos (marca **GET/Ver** en):
   - ✅ products
   - ✅ categories
   - ✅ images
   - ✅ combinations
   - ✅ product_features
   - ✅ product_feature_values
   - ✅ product_options
   - ✅ product_option_values
   - ✅ manufacturers
   - ✅ stock_availables
5. Guarda y **copia la API Key generada**

### 2. Configurar el Módulo en la Tienda Destino

En tu **tienda PrestaShop de destino** (donde importarás):

1. Ve a **Módulos → Module Manager**
2. Busca "Sincronizador PS a PS" → **Configurar**
3. Ingresa:
   - **URL de la tienda origen**: `https://tu-tienda-origen.com` (sin barra final)
   - **API Key**: La clave que copiaste anteriormente
   - **IP Personalizada** (opcional): Solo si el dominio no resuelve DNS
4. Haz clic en **"Guardar y probar conexión"**
5. Deberías ver: **✓ Conexión exitosa**

---

## 📖 Uso

### Importar Productos

1. Ve a **Catálogo → Sincronizador PS**
2. Verás el listado de productos de la tienda origen
3. **Marca los productos** que quieres importar (checkbox)
4. Haz clic en **"Importar seleccionados"**
5. Espera a que termine el proceso
6. ¡Productos importados! Verifica en **Catálogo → Productos**

### Filtrar Productos

- **Por categoría**: Usa el selector "Categoría" y haz clic en "Buscar"
- **Por nombre**: Escribe en el campo "Buscar" y presiona "Buscar"
- **Limpiar filtros**: Botón "Limpiar filtros" cuando hay filtros activos

### Navegar entre Páginas

- Usa los **números de página** (1, 2, 3...)
- Botones **"Anterior"** y **"Siguiente"**
- Selector **"Productos por página"**: 10, 20, 50 o 100

---

## 🎯 ¿Qué se Importa Exactamente?

### Producto Completo

```
📦 Producto
├── 📝 Datos básicos
│   ├── Nombre (multiidioma)
│   ├── Descripción corta y larga
│   ├── Referencia (SKU)
│   ├── EAN13, UPC
│   ├── Precio base
│   ├── Precio mayorista
│   ├── Precio por unidad
│   ├── Unidad (m², kg, etc.)
│   └── Estado (activo/inactivo)
│
├── 📁 Categorías
│   ├── Categoría principal
│   ├── Categorías secundarias
│   └── Jerarquía completa (crea padres automáticamente)
│
├── 🖼️ Imágenes
│   ├── Todas las imágenes del producto
│   ├── Miniaturas automáticas
│   └── Imagen de portada (cover)
│
├── 🏭 Fabricante
│   └── Crea fabricante si no existe
│
├── 📦 Stock
│   ├── Cantidad general
│   └── Cantidad por combinación
│
├── ⚙️ Características (Features)
│   ├── Color: Rojo
│   ├── Material: Algodón
│   ├── Packaging: 2.5 m²
│   └── Crea características y valores automáticamente
│
└── 🎨 Combinaciones/Variantes
    ├── Talla S + Color Rojo
    │   ├── Stock: 10 unidades
    │   ├── Precio: +0€
    │   ├── Referencia: CAM-S-ROJO
    │   └── EAN13, UPC específicos
    ├── Talla M + Color Azul
    │   ├── Stock: 8 unidades
    │   ├── Precio: +2€
    │   └── Peso adicional
    └── Crea atributos automáticamente si no existen
```

---

## 🔧 Solución de Problemas

### Error: "No se pudo conectar"

**Causas comunes:**
- URL incorrecta (verifica https/http y sin espacios)
- API Key incorrecta
- Webservice no habilitado en origen
- Firewall bloqueando

**Solución:**
1. Verifica la URL en un navegador
2. Verifica que el webservice esté habilitado
3. Genera una nueva API Key
4. Si el dominio es interno, usa "IP Personalizada"

### Error: "Token no válido"

**Solución:** Ya está solucionado en v1.1.0. Si persiste:
1. Limpia la caché: `rm -rf var/cache/*`
2. Recarga el navegador con Ctrl+Shift+R

### Las imágenes no se importan

**Causas:**
- Permisos de escritura en `/img/p/`
- Imágenes corruptas en origen

**Solución:**
1. Verifica permisos: `chmod 755 img/p/`
2. Revisa los logs en `logs/import_log_YYYY-MM-DD.txt`

### Productos duplicados

**Nota:** El módulo detecta productos existentes por **referencia**. Si importas un producto con la misma referencia, lo **actualizará** en lugar de duplicarlo.

---

## 📊 Performance

### Tiempos de Importación (aproximados)

- **Producto simple** (sin imágenes, sin combinaciones): ~2 segundos
- **Producto con 3 imágenes**: ~5-8 segundos
- **Producto con 6 combinaciones**: ~10-15 segundos
- **Producto completo** (imágenes + combinaciones): ~15-20 segundos

### Recomendaciones

- Importa en **lotes de 10-20 productos** para evitar timeouts
- Usa el selector de **20 o 50 productos por página**
- Para importaciones masivas, aumenta `max_execution_time` en PHP

---

## 📁 Estructura del Módulo

```
sync_ps_to_ps_importer/
├── autoload.php                            # Autoloader PSR-4
├── sync_ps_to_ps_importer.php             # Clase principal del módulo
├── composer.json                           # Configuración Composer
├── README.md                               # Este archivo
├── CHANGELOG.md                            # Historial de cambios
├── config/
│   ├── routes.yml                          # Rutas Symfony
│   └── index.php                           # Seguridad
├── src/
│   ├── Controller/
│   │   ├── AdminImporterController.php    # Controlador del panel
│   │   └── index.php
│   ├── Service/
│   │   ├── PrestaShopApiService.php       # Cliente API Webservice
│   │   ├── ProductImporterService.php     # Lógica de importación
│   │   └── index.php
│   ├── Support/
│   │   └── DbEntities.php                 # Entidades de BD
│   └── index.php
├── views/
│   └── templates/
│       └── admin/
│           └── panel.html.twig            # Interfaz del panel
├── logs/
│   ├── import_log_YYYY-MM-DD.txt         # Logs de importación
│   └── index.php
└── vendor/                                 # Dependencias Composer
```

---

## 🔐 Seguridad

- ✅ Tokens CSRF en todos los formularios y enlaces
- ✅ Validación de datos con `pSQL()` y `Validate`
- ✅ Autenticación de admin requerida
- ✅ Permisos de webservice configurables
- ✅ Logs sin información sensible

---

## 🤝 Contribuir

¿Encontraste un bug? ¿Tienes una idea? ¡Contribuye!

1. Fork el repositorio
2. Crea tu rama: `git checkout -b feature/mi-mejora`
3. Commit tus cambios: `git commit -m 'Añadir mi mejora'`
4. Push a la rama: `git push origin feature/mi-mejora`
5. Abre un Pull Request

---

## 📝 Licencia

Este módulo está licenciado bajo [AFL-3.0](LICENSE) (Academic Free License 3.0).

---

## 👨‍💻 Autor

**Atech**

---

## 📞 Soporte

- 📧 Email: [Soporte](mailto:soporte@ejemplo.com)
- 🐛 Issues: [GitHub Issues](https://github.com/vamlemat/sync_ps_to_ps_importer/issues)
- 📚 Documentación: [Ver README.md](README.md)
- 📋 Changelog: [Ver CHANGELOG.md](CHANGELOG.md)

---

## 🙏 Agradecimientos

Gracias a la comunidad de PrestaShop y a todos los que contribuyen a este proyecto.

---

**⭐ Si te gusta este módulo, dale una estrella en GitHub!**

---

## 📈 Roadmap

### v1.2.0 (Próxima versión)
- [ ] Visualizador de logs en interfaz
- [ ] Importación masiva por categoría completa
- [ ] Exportar productos de local a remoto
- [ ] Programación de sincronizaciones automáticas (CRON)

### v1.3.0 (Futuro)
- [ ] Sincronización bidireccional
- [ ] Mapeo personalizado de categorías
- [ ] Sincronización de precios específicos y descuentos
- [ ] Importación de productos pack
- [ ] Importación de proveedores

---

© 2025 Atech. Todos los derechos reservados.
