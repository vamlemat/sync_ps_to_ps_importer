# 📖 Guía de Uso - Sincronizador PS a PS

## 🚀 Configuración Inicial

### 1️⃣ **Habilitar el Webservice en la tienda ORIGEN**

Antes de usar este módulo, debes configurar el webservice en la tienda PrestaShop de donde quieres importar productos:

#### En la tienda ORIGEN:

1. Ve a **Configuración Avanzada → Webservice**
2. **Habilita el webservice** activando la opción
3. Haz clic en **"Añadir nueva clave"**
4. Configura los permisos:
   - **Ver (GET)** en los siguientes recursos:
     - ✅ products
     - ✅ categories
     - ✅ images
     - ✅ combinations
     - ✅ product_features
     - ✅ product_feature_values
     - ✅ product_options
     - ✅ product_option_values
5. Deja **"Generar" marcado** para crear una clave automática
6. **Guarda** y copia la clave API generada

---

### 2️⃣ **Configurar el módulo en la tienda DESTINO**

En tu tienda PrestaShop donde quieres importar los productos:

1. Ve a **Módulos → Module Manager**
2. Busca **"Sincronizador PS a PS"**
3. Haz clic en **"Configurar"**
4. Ingresa:
   - **URL de la tienda origen**: `https://tu-tienda-origen.com` (sin barra al final)
   - **API Key**: La clave que copiaste en el paso anterior
5. Haz clic en **"Guardar y probar conexión"**
6. Deberías ver un mensaje de **"✓ Conexión exitosa"**

---

## 📦 Importar Productos

### Método 1: Importación Individual o Múltiple

1. Ve a **Catálogo → Sincronizador PS** en el menú
2. Verás el listado de productos de la tienda origen
3. **Marca los productos** que quieres importar (puedes seleccionar varios)
4. Haz clic en **"Importar seleccionados"**
5. Espera a que termine el proceso
6. ¡Los productos estarán importados en tu catálogo!

### Método 2: Filtrar por Categoría

1. En el panel, usa el filtro de **"Categoría"**
2. Selecciona la categoría que quieres importar
3. Haz clic en **"Buscar"**
4. Marca todos los productos con el checkbox superior
5. Haz clic en **"Importar seleccionados"**

### Método 3: Buscar por Nombre

1. En el panel, escribe el nombre del producto en el campo **"Buscar"**
2. Haz clic en **"Buscar"**
3. Selecciona y importa los productos encontrados

---

## ✨ ¿Qué se importa?

El módulo importa **TODO** el producto:

- ✅ **Datos básicos**: Nombre, descripción, precio, referencia, EAN13
- ✅ **Imágenes**: Todas las imágenes del producto
- ✅ **Stock**: Cantidad disponible
- ✅ **Características** (features): Color, Material, etc.
- ✅ **Combinaciones** (atributos): Tallas, colores combinados
- ✅ **Estado**: Activo/Inactivo
- ✅ **Categorías**: Asignación a categorías
- ✅ **Precios**: Precio base y precio mayorista

---

## ⚠️ Importante

### Productos Duplicados

- Si un producto con la **misma referencia** ya existe, se **actualizará** en lugar de crear uno nuevo
- Esto permite sincronizar productos que ya habías importado antes

### Categorías

- Por ahora, los productos se asignan a la **categoría Home (2)** por defecto
- En futuras versiones podrás mapear categorías automáticamente

### Características y Atributos

- Las características y atributos se intentan importar
- Si no existen en tu tienda, el módulo los omitirá (sin dar error)
- Puedes crear manualmente las características en tu tienda antes de importar

---

## 🔧 Solución de Problemas

### Error: "No se pudo conectar con la tienda origen"

**Posibles causas:**
- URL incorrecta (verifica que sea exacta, sin espacios ni barra al final)
- API Key incorrecta
- Webservice no habilitado en la tienda origen
- Firewall bloqueando la conexión

**Solución:**
1. Verifica la URL en un navegador
2. Verifica que el webservice esté habilitado
3. Genera una nueva API Key y prueba de nuevo

### Error: "No se encontraron productos"

**Posibles causas:**
- No hay productos activos en la tienda origen
- Los filtros aplicados son muy restrictivos

**Solución:**
1. Limpia los filtros
2. Verifica que haya productos en la tienda origen

### Error al importar imágenes

**Posible causa:**
- Permisos de escritura en la carpeta `/img/`

**Solución:**
1. Verifica permisos de la carpeta `img/p/` en tu servidor
2. Debe tener permisos 755 o 777

---

## 📊 Rendimiento

- **Productos pequeños** (sin imágenes): ~2 segundos cada uno
- **Productos con imágenes**: ~5-10 segundos cada uno
- **Productos con muchas combinaciones**: ~10-20 segundos cada uno

**Recomendación:** Importa en lotes de 10-20 productos a la vez para evitar timeouts.

---

## 🆘 Soporte

Si tienes problemas:
1. Verifica los logs de PHP en tu servidor
2. Activa el modo debug de PrestaShop
3. Revisa el log de errores en `var/logs/`

---

## 📝 Notas Técnicas

- El módulo usa la **API REST de PrestaShop**
- Las conexiones son seguras (HTTPS recomendado)
- No modifica los productos en la tienda origen (solo lectura)
- Puedes importar el mismo producto múltiples veces (se actualizará)

---

¡Disfruta sincronizando tus tiendas PrestaShop! 🎉

