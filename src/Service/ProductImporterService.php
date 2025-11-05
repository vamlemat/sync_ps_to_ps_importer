<?php

namespace SyncPsToPsImporter\Service;

/**
 * Servicio para importar productos desde PrestaShop remoto al local
 */
class ProductImporterService
{
    private $apiService;
    private $context;
    private $errors = [];

    public function __construct(PrestaShopApiService $apiService)
    {
        $this->apiService = $apiService;
        $this->context = \Context::getContext();
    }

    /**
     * Importar un producto completo
     */
    public function importProduct($remoteProductId)
    {
        $this->errors = [];
        $warnings = [];

        try {
            $this->errors[] = "=== Iniciando importación del producto ID: $remoteProductId ===";
            
            // PASO 1: Obtener datos del producto remoto
            $this->errors[] = "[1/9] Obteniendo datos del producto remoto...";
            $remoteProduct = $this->apiService->getProduct($remoteProductId);
            if (!$remoteProduct) {
                throw new \Exception("No se pudo obtener el producto $remoteProductId desde la API");
            }
            
            $productName = is_array($remoteProduct['name'] ?? null) 
                ? ($remoteProduct['name'][1] ?? reset($remoteProduct['name']))
                : ($remoteProduct['name'] ?? 'Sin nombre');
            
            $this->errors[] = "✓ Producto obtenido: $productName";

            // PASO 2: Verificar si el producto ya existe
            $this->errors[] = "[2/9] Verificando si el producto ya existe...";
            $reference = $remoteProduct['reference'] ?? '';
            $localProductId = 0;
            
            if (!empty($reference)) {
                $sql = 'SELECT id_product FROM `' . _DB_PREFIX_ . 'product` WHERE `reference` = "' . pSQL($reference) . '"';
                $localProductId = (int)\Db::getInstance()->getValue($sql);
            }
            
            if ($localProductId) {
                $this->errors[] = "✓ Producto existente (ID: $localProductId), actualizando...";
                $product = new \Product($localProductId);
            } else {
                $this->errors[] = "✓ Producto nuevo, creando...";
                $product = new \Product();
            }

            // PASO 3: Asignar datos básicos
            $this->errors[] = "[3/9] Asignando datos básicos...";
            
            $product->reference = $reference;
            $product->ean13 = $remoteProduct['ean13'] ?? '';
            $product->upc = $remoteProduct['upc'] ?? '';
            $product->price = (float)($remoteProduct['price'] ?? 0);
            $product->wholesale_price = (float)($remoteProduct['wholesale_price'] ?? 0);
            $product->active = (int)($remoteProduct['active'] ?? 1);
            $product->id_tax_rules_group = (int)($remoteProduct['id_tax_rules_group'] ?? 1);
            $product->id_category_default = 2; // Por ahora Home, luego mejoraremos
            
            // Asignar textos multiidioma
            $languages = \Language::getLanguages(false);
            foreach ($languages as $lang) {
                $langId = $lang['id_lang'];
                
                $product->name[$langId] = $productName;
                $product->description[$langId] = is_array($remoteProduct['description'] ?? null)
                    ? ($remoteProduct['description'][1] ?? reset($remoteProduct['description']))
                    : ($remoteProduct['description'] ?? '');
                $product->description_short[$langId] = is_array($remoteProduct['description_short'] ?? null)
                    ? ($remoteProduct['description_short'][1] ?? reset($remoteProduct['description_short']))
                    : ($remoteProduct['description_short'] ?? '');
                $product->link_rewrite[$langId] = is_array($remoteProduct['link_rewrite'] ?? null)
                    ? ($remoteProduct['link_rewrite'][1] ?? reset($remoteProduct['link_rewrite']))
                    : ($remoteProduct['link_rewrite'] ?? \Tools::str2url($productName));
                $product->meta_title[$langId] = is_array($remoteProduct['meta_title'] ?? null)
                    ? ($remoteProduct['meta_title'][1] ?? reset($remoteProduct['meta_title']))
                    : ($remoteProduct['meta_title'] ?? $productName);
            }
            
            $this->errors[] = "✓ Datos básicos asignados";

            // PASO 4: Guardar producto
            $this->errors[] = "[4/9] Guardando producto...";
            if (!$product->save()) {
                throw new \Exception("Error al guardar el producto en la base de datos");
            }
            $this->errors[] = "✓ Producto guardado (ID: {$product->id})";

            // PASO 5: Asignar categorías CON JERARQUÍA COMPLETA
            $this->errors[] = "[5/9] Creando/asignando categorías...";
            try {
                $categories = [2]; // Siempre incluir Home
                
                // Obtener todas las categorías del producto desde associations
                if (isset($remoteProduct['associations']['categories']) && is_array($remoteProduct['associations']['categories'])) {
                    $this->errors[] = "  Producto tiene " . count($remoteProduct['associations']['categories']) . " categorías remotas";
                    
                    foreach ($remoteProduct['associations']['categories'] as $cat) {
                        $remoteCategoryId = (int)($cat['id'] ?? 0);
                        if ($remoteCategoryId > 2) { // Omitir Home y Root
                            $localCategoryId = $this->createCategoryWithHierarchy($remoteCategoryId);
                            if ($localCategoryId && !in_array($localCategoryId, $categories)) {
                                $categories[] = $localCategoryId;
                            }
                        }
                    }
                }
                
                // Asegurar que la categoría principal esté en la lista
                if (!empty($product->id_category_default) && !in_array($product->id_category_default, $categories)) {
                    $categories[] = $product->id_category_default;
                }
                
                $categories = array_unique($categories);
                $product->updateCategories($categories);
                $this->errors[] = "  ✓ Categorías asignadas: " . count($categories) . " categorías (IDs: " . implode(', ', $categories) . ")";
            } catch (\Exception $e) {
                $warnings[] = "Categorías: " . $e->getMessage();
                $this->errors[] = "  ERROR en categorías: " . $e->getMessage();
            }

            // PASO 6: Asignar fabricante
            $this->errors[] = "[6/9] Asignando fabricante...";
            try {
                $manufacturerName = $remoteProduct['manufacturer_name'] ?? null;
                if ($manufacturerName) {
                    $sql = 'SELECT id_manufacturer FROM `' . _DB_PREFIX_ . 'manufacturer` WHERE `name` = "' . pSQL($manufacturerName) . '"';
                    $manufacturerId = (int)\Db::getInstance()->getValue($sql);
                    
                    if (!$manufacturerId) {
                        $manufacturer = new \Manufacturer();
                        $manufacturer->name = $manufacturerName;
                        $manufacturer->active = 1;
                        if ($manufacturer->add()) {
                            $manufacturerId = $manufacturer->id;
                            $this->errors[] = "✓ Fabricante creado: $manufacturerName";
                        }
                    } else {
                        $this->errors[] = "✓ Fabricante existente: $manufacturerName";
                    }
                    
                    if ($manufacturerId) {
                        $product->id_manufacturer = $manufacturerId;
                        $product->save();
                    }
                }
            } catch (\Exception $e) {
                $warnings[] = "Fabricante: " . $e->getMessage();
            }

            // PASO 7: Actualizar stock
            $this->errors[] = "[7/9] Actualizando stock...";
            try {
                $quantity = (int)($remoteProduct['quantity'] ?? 0);
                \StockAvailable::setQuantity($product->id, 0, $quantity);
                $this->errors[] = "✓ Stock: $quantity unidades";
            } catch (\Exception $e) {
                $warnings[] = "Stock: " . $e->getMessage();
            }

            // 8. Importar imágenes (activar debug)
            $this->errors[] = "[8/9] Importando imágenes...";
            try {
                // Activar debug para ver qué pasa con las imágenes
                $this->apiService->setDebug(true);
                $imageCount = $this->importImagesSimple($product, $remoteProduct);
                $this->apiService->setDebug(false);
                $this->errors[] = "✓ Imágenes: $imageCount importadas";
            } catch (\Exception $e) {
                $this->apiService->setDebug(false);
                $warnings[] = "Imágenes: " . $e->getMessage();
                $this->errors[] = "ERROR en imágenes: " . $e->getMessage();
            }

            // PASO 9: Importar características
            $this->errors[] = "[9/9] Importando características...";
            try {
                $featureCount = $this->importFeaturesOptimized($product, $remoteProduct);
                $this->errors[] = "  ✓ Características: $featureCount importadas/asignadas";
            } catch (\Exception $e) {
                $warnings[] = "Características: " . $e->getMessage();
                $this->errors[] = "  ERROR en características: " . $e->getMessage();
            }

            $this->errors[] = "=== ✅ Importación completada exitosamente ===";

            $message = "Producto '{$productName}' importado (ID: {$product->id})";
            if (!empty($warnings)) {
                $message .= " [" . count($warnings) . " advertencias]";
            }

            return [
                'success' => true,
                'product_id' => $product->id,
                'message' => $message,
                'log' => $this->errors,
                'warnings' => $warnings
            ];

        } catch (\Exception $e) {
            $this->errors[] = "=== ✗ ERROR FATAL ===";
            $this->errors[] = $e->getMessage();
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $this->errors,
                'line' => $e->getLine(),
                'file' => basename($e->getFile())
            ];
        } finally {
            // Guardar logs en archivo para debugging
            $logFile = _PS_MODULE_DIR_ . 'sync_ps_to_ps_importer/logs/import_log_' . date('Y-m-d') . '.txt';
            $logDir = dirname($logFile);
            if (!file_exists($logDir)) {
                @mkdir($logDir, 0777, true);
            }
            $logContent = "\n=== " . date('H:i:s') . " - Producto $remoteProductId ===\n";
            $logContent .= implode("\n", $this->errors) . "\n";
            @file_put_contents($logFile, $logContent, FILE_APPEND);
        }
    }

    /**
     * Importar imágenes (versión simplificada)
     */
    private function importImagesSimple($product, $remoteProduct)
    {
        $imported = 0;
        
        try {
            // Obtener IDs de imágenes desde associations
            $imageIds = [];
            if (isset($remoteProduct['associations']['images']) && is_array($remoteProduct['associations']['images'])) {
                foreach ($remoteProduct['associations']['images'] as $img) {
                    $imageIds[] = $img['id'] ?? null;
                }
                $imageIds = array_filter($imageIds);
            }
            
            if (empty($imageIds)) {
                $this->errors[] = "  No se encontraron imágenes en el producto remoto";
                return 0;
            }
            
            $this->errors[] = "  Encontradas " . count($imageIds) . " imágenes: " . implode(', ', $imageIds);
            
            $isFirstImage = true;
            
            foreach ($imageIds as $imageId) {
                try {
                    // Descargar imagen desde la API (usar ID del producto REMOTO)
                    $remoteProductId = $remoteProduct['id'] ?? 0;
                    $this->errors[] = "  Intentando descargar imagen $imageId del producto remoto $remoteProductId...";
                    
                    $imageData = $this->apiService->downloadImage($remoteProductId, $imageId);
                    
                    $imageSize = $imageData ? strlen($imageData) : 0;
                    $this->errors[] = "  Respuesta recibida: $imageSize bytes";
                    
                    if (!$imageData || $imageSize < 100) {
                        $this->errors[] = "  ✗ Imagen $imageId: vacía o corrupta ($imageSize bytes)";
                        $this->errors[] = "  URL: " . $this->apiService->getApiUrl() . "/api/images/products/$remoteProductId/$imageId";
                        $this->errors[] = "  POSIBLE CAUSA: La API Key no tiene permisos para imágenes o el webservice no permite descargar imágenes.";
                        $this->errors[] = "  SOLUCIÓN: Verifica en newgoparket.com → Configuración Avanzada → Webservice → que la API Key tenga permisos GET en 'images'";
                        continue;
                    }
                    
                    $this->errors[] = "  ✓ Imagen $imageId descargada correctamente ($imageSize bytes)";
                    
                    // Crear objeto Image de PrestaShop
                    $image = new \Image();
                    $image->id_product = $product->id;
                    $image->position = \Image::getHighestPosition($product->id) + 1;
                    $image->cover = $isFirstImage;
                    
                    if (!$image->add()) {
                        $this->errors[] = "  Imagen $imageId: error al crear registro";
                        continue;
                    }
                    
                    $this->errors[] = "  Registro de imagen creado (ID local: {$image->id})";
                    
                    // Método 1: Guardar imagen directamente en el sistema de archivos
                    $path = $image->getPathForCreation();
                    
                    // Crear directorio si no existe
                    if (!file_exists(dirname($path))) {
                        @mkdir(dirname($path), 0777, true);
                    }
                    
                    // Guardar imagen en diferentes tamaños
                    $success = false;
                    if (file_put_contents($path . '.jpg', $imageData)) {
                        $this->errors[] = "  Imagen guardada en: " . $path . '.jpg';
                        
                        // Generar miniaturas
                        try {
                            $imagesTypes = \ImageType::getImagesTypes('products');
                            foreach ($imagesTypes as $imageType) {
                                \ImageManager::resize(
                                    $path . '.jpg',
                                    $path . '-' . stripslashes($imageType['name']) . '.jpg',
                                    $imageType['width'],
                                    $imageType['height']
                                );
                            }
                            $success = true;
                            $imported++;
                            $isFirstImage = false;
                            $this->errors[] = "  ✓ Imagen $imageId importada con miniaturas";
                        } catch (\Exception $e) {
                            $this->errors[] = "  Advertencia generando miniaturas: " . $e->getMessage();
                            // Aunque falle miniaturas, si se guardó la imagen principal, es un éxito
                            $imported++;
                            $isFirstImage = false;
                        }
                    } else {
                        $image->delete();
                        $this->errors[] = "  Imagen $imageId: error al guardar archivo físico";
                    }
                    
                } catch (\Exception $e) {
                    $this->errors[] = "  Imagen $imageId error: " . $e->getMessage();
                    continue;
                }
            }
            
            return $imported;
            
        } catch (\Exception $e) {
            throw new \Exception("Error general en imágenes: " . $e->getMessage());
        }
    }

    /**
     * Importar características (CREAR si no existen)
     */
    private function importFeaturesSimple($product, $remoteProduct)
    {
        $imported = 0;
        
        try {
            // Obtener características desde associations
            if (!isset($remoteProduct['associations']['product_features']) || !is_array($remoteProduct['associations']['product_features'])) {
                $this->errors[] = "  No hay características en el producto remoto";
                return 0;
            }
            
            $features = $remoteProduct['associations']['product_features'];
            $this->errors[] = "  Encontradas " . count($features) . " características remotas";
            
            // Eliminar características existentes del producto
            \Db::getInstance()->delete('feature_product', '`id_product` = ' . (int)$product->id);
            
            foreach ($features as $featureData) {
                try {
                    $remoteFeatureId = (int)($featureData['id'] ?? 0);
                    $remoteFeatureValueId = (int)($featureData['id_feature_value'] ?? 0);
                    
                    if (!$remoteFeatureId || !$remoteFeatureValueId) {
                        continue;
                    }
                    
                    // Obtener datos completos de la característica desde la API remota
                    $this->errors[] = "  Procesando característica $remoteFeatureId...";
                    
                    $remoteFeature = $this->apiService->getFeature($remoteFeatureId);
                    $remoteFeatureValue = $this->apiService->getFeatureValue($remoteFeatureValueId);
                    
                    if (!$remoteFeature || !$remoteFeatureValue) {
                        $this->errors[] = "    No se pudo obtener datos de característica/valor, omitida";
                        continue;
                    }
                    
                    $featureName = is_array($remoteFeature['name'] ?? null)
                        ? ($remoteFeature['name'][1] ?? reset($remoteFeature['name']))
                        : ($remoteFeature['name'] ?? '');
                    
                    $featureValueName = is_array($remoteFeatureValue['value'] ?? null)
                        ? ($remoteFeatureValue['value'][1] ?? reset($remoteFeatureValue['value']))
                        : ($remoteFeatureValue['value'] ?? '');
                    
                    // Buscar o crear la característica localmente
                    $localFeatureId = $this->findOrCreateFeature($featureName);
                    
                    // Buscar o crear el valor de característica localmente
                    $localFeatureValueId = $this->findOrCreateFeatureValue($localFeatureId, $featureValueName);
                    
                    if ($localFeatureId && $localFeatureValueId) {
                        // Asignar característica al producto
                        \Db::getInstance()->insert('feature_product', [
                            'id_feature' => $localFeatureId,
                            'id_product' => (int)$product->id,
                            'id_feature_value' => $localFeatureValueId
                        ]);
                        $imported++;
                        $this->errors[] = "    ✓ $featureName: $featureValueName";
                    }
                    
                } catch (\Exception $e) {
                    $this->errors[] = "    Error: " . $e->getMessage();
                    continue;
                }
            }
            
            return $imported;
            
        } catch (\Exception $e) {
            throw new \Exception("Error general en características: " . $e->getMessage());
        }
    }

    /**
     * Encontrar o crear característica por nombre
     */
    private function findOrCreateFeature($featureName)
    {
        if (empty($featureName)) {
            return 0;
        }
        
        // Buscar por nombre
        $sql = 'SELECT f.id_feature FROM `' . _DB_PREFIX_ . 'feature` f
                INNER JOIN `' . _DB_PREFIX_ . 'feature_lang` fl ON (f.id_feature = fl.id_feature)
                WHERE fl.name = "' . pSQL($featureName) . '" AND fl.id_lang = 1
                LIMIT 1';
        $featureId = (int)\Db::getInstance()->getValue($sql);
        
        if ($featureId) {
            return $featureId;
        }
        
        // Crear nueva característica
        $feature = new \Feature();
        
        $languages = \Language::getLanguages(false);
        foreach ($languages as $lang) {
            $feature->name[$lang['id_lang']] = $featureName;
        }
        
        if ($feature->add()) {
            $this->errors[] = "    ✓ Característica creada: $featureName (ID: {$feature->id})";
            return $feature->id;
        }
        
        return 0;
    }

    /**
     * Encontrar o crear valor de característica
     */
    private function findOrCreateFeatureValue($featureId, $valueName)
    {
        if (empty($featureId) || empty($valueName)) {
            return 0;
        }
        
        // Buscar por nombre y feature
        $sql = 'SELECT id_feature_value FROM `' . _DB_PREFIX_ . 'feature_value`
                WHERE id_feature = ' . (int)$featureId . '
                AND value = "' . pSQL($valueName) . '"
                LIMIT 1';
        $valueId = (int)\Db::getInstance()->getValue($sql);
        
        if ($valueId) {
            return $valueId;
        }
        
        // Crear nuevo valor
        $featureValue = new \FeatureValue();
        $featureValue->id_feature = $featureId;
        $featureValue->custom = 0;
        
        $languages = \Language::getLanguages(false);
        foreach ($languages as $lang) {
            $featureValue->value[$lang['id_lang']] = $valueName;
        }
        
        if ($featureValue->add()) {
            $this->errors[] = "    ✓ Valor creado: $valueName (ID: {$featureValue->id})";
            return $featureValue->id;
        }
        
        return 0;
    }

    /**
     * Importar múltiples productos
     */
    public function importMultipleProducts($productIds)
    {
        $results = [];
        
        foreach ($productIds as $productId) {
            $results[$productId] = $this->importProduct($productId);
        }
        
        return $results;
    }

    /**
     * Importar características CREÁNDOLAS si no existen
     */
    private function importFeaturesOptimized($product, $remoteProduct)
    {
        $imported = 0;
        $created = 0;
        
        try {
            // Obtener características desde associations
            if (!isset($remoteProduct['associations']['product_features']) || !is_array($remoteProduct['associations']['product_features'])) {
                $this->errors[] = "  No hay características en el producto remoto";
                return 0;
            }
            
            $features = $remoteProduct['associations']['product_features'];
            $this->errors[] = "  Encontradas " . count($features) . " características remotas";
            
            // Eliminar características existentes del producto
            \Db::getInstance()->delete('feature_product', '`id_product` = ' . (int)$product->id);
            
            // Cache local de características
            static $featureCache = [];
            static $featureValueCache = [];
            
            foreach ($features as $featureData) {
                try {
                    $remoteFeatureId = (int)($featureData['id'] ?? 0);
                    $remoteFeatureValueId = (int)($featureData['id_feature_value'] ?? 0);
                    
                    if (!$remoteFeatureId || !$remoteFeatureValueId) {
                        continue;
                    }
                    
                    // PASO 1: Obtener datos de la característica remota
                    $remoteFeature = isset($featureCache[$remoteFeatureId]) 
                        ? $featureCache[$remoteFeatureId]
                        : $this->apiService->getFeature($remoteFeatureId);
                    
                    if (!$remoteFeature) {
                        $this->errors[] = "  ⚠ No se pudo obtener característica $remoteFeatureId desde API";
                        continue;
                    }
                    
                    $featureCache[$remoteFeatureId] = $remoteFeature;
                    
                    $featureName = is_array($remoteFeature['name'] ?? null)
                        ? ($remoteFeature['name'][1] ?? reset($remoteFeature['name']))
                        : ($remoteFeature['name'] ?? '');
                    
                    // PASO 2: Buscar o crear la característica localmente
                    $localFeatureId = $this->findOrCreateFeatureByName($featureName);
                    
                    if (!$localFeatureId) {
                        $this->errors[] = "  ✗ No se pudo crear/encontrar característica '$featureName'";
                        continue;
                    }
                    
                    // PASO 3: Obtener datos del valor de característica remoto
                    $remoteFeatureValue = isset($featureValueCache[$remoteFeatureValueId])
                        ? $featureValueCache[$remoteFeatureValueId]
                        : $this->apiService->getFeatureValue($remoteFeatureValueId);
                    
                    if (!$remoteFeatureValue) {
                        $this->errors[] = "  ⚠ No se pudo obtener valor $remoteFeatureValueId desde API";
                        continue;
                    }
                    
                    $featureValueCache[$remoteFeatureValueId] = $remoteFeatureValue;
                    
                    $valueName = is_array($remoteFeatureValue['value'] ?? null)
                        ? ($remoteFeatureValue['value'][1] ?? reset($remoteFeatureValue['value']))
                        : ($remoteFeatureValue['value'] ?? '');
                    
                    // PASO 4: Buscar o crear el valor de característica localmente
                    $localFeatureValueId = $this->findOrCreateFeatureValueByName($localFeatureId, $valueName);
                    
                    if (!$localFeatureValueId) {
                        $this->errors[] = "  ✗ No se pudo crear/encontrar valor '$valueName'";
                        continue;
                    }
                    
                    // PASO 5: Asignar característica al producto
                    \Db::getInstance()->insert('feature_product', [
                        'id_feature' => $localFeatureId,
                        'id_product' => (int)$product->id,
                        'id_feature_value' => $localFeatureValueId
                    ], false, true, \Db::INSERT_IGNORE);
                    
                    $imported++;
                    $this->errors[] = "  ✓ '$featureName' = '$valueName' (Feature: $localFeatureId, Value: $localFeatureValueId)";
                    
                } catch (\Exception $e) {
                    $this->errors[] = "  Error en característica: " . $e->getMessage();
                    continue;
                }
            }
            
            $this->errors[] = "  Total: $imported características asignadas";
            return $imported;
            
        } catch (\Exception $e) {
            throw new \Exception("Error general en características: " . $e->getMessage());
        }
    }
    
    /**
     * Buscar o crear característica por nombre
     */
    private function findOrCreateFeatureByName($featureName)
    {
        if (empty($featureName)) {
            return 0;
        }
        
        // Cache
        static $cache = [];
        $cacheKey = md5($featureName);
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }
        
        // Buscar por nombre
        $sql = 'SELECT f.id_feature FROM `' . _DB_PREFIX_ . 'feature` f
                INNER JOIN `' . _DB_PREFIX_ . 'feature_lang` fl ON (f.id_feature = fl.id_feature)
                WHERE fl.name = "' . pSQL($featureName) . '" AND fl.id_lang = 1
                LIMIT 1';
        $featureId = (int)\Db::getInstance()->getValue($sql);
        
        if ($featureId) {
            $cache[$cacheKey] = $featureId;
            return $featureId;
        }
        
        // Crear nueva característica
        $feature = new \Feature();
        $languages = \Language::getLanguages(false);
        foreach ($languages as $lang) {
            $feature->name[$lang['id_lang']] = $featureName;
        }
        
        if ($feature->add()) {
            $this->errors[] = "    + Característica CREADA: '$featureName' (ID: {$feature->id})";
            $cache[$cacheKey] = $feature->id;
            return $feature->id;
        }
        
        return 0;
    }
    
    /**
     * Buscar o crear valor de característica por nombre
     */
    private function findOrCreateFeatureValueByName($featureId, $valueName)
    {
        if (empty($featureId) || empty($valueName)) {
            return 0;
        }
        
        // Cache
        static $cache = [];
        $cacheKey = $featureId . '_' . md5($valueName);
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }
        
        // Buscar por nombre y característica
        $sql = 'SELECT id_feature_value FROM `' . _DB_PREFIX_ . 'feature_value`
                WHERE id_feature = ' . (int)$featureId . '
                AND value = "' . pSQL($valueName) . '"
                LIMIT 1';
        $valueId = (int)\Db::getInstance()->getValue($sql);
        
        if ($valueId) {
            $cache[$cacheKey] = $valueId;
            return $valueId;
        }
        
        // Crear nuevo valor
        $featureValue = new \FeatureValue();
        $featureValue->id_feature = $featureId;
        $featureValue->custom = 0;
        
        $languages = \Language::getLanguages(false);
        foreach ($languages as $lang) {
            $featureValue->value[$lang['id_lang']] = $valueName;
        }
        
        if ($featureValue->add()) {
            $this->errors[] = "    + Valor CREADO: '$valueName' (ID: {$featureValue->id})";
            $cache[$cacheKey] = $featureValue->id;
            return $featureValue->id;
        }
        
        return 0;
    }

    /**
     * Crear categoría con toda su jerarquía (recursivo)
     */
    private function createCategoryWithHierarchy($remoteCategoryId)
    {
        // Cache de categorías
        static $categoryCache = [];
        
        if (isset($categoryCache[$remoteCategoryId])) {
            return $categoryCache[$remoteCategoryId];
        }
        
        if (empty($remoteCategoryId) || $remoteCategoryId <= 2) {
            return 2; // Home
        }
        
        try {
            // Obtener datos de la categoría remota
            $remoteCategory = $this->apiService->getCategory($remoteCategoryId);
            if (!$remoteCategory) {
                $this->errors[] = "    Categoría remota $remoteCategoryId no encontrada";
                return 2;
            }
            
            $categoryName = is_array($remoteCategory['name'] ?? null) 
                ? ($remoteCategory['name'][1] ?? reset($remoteCategory['name']))
                : ($remoteCategory['name'] ?? 'Categoría ' . $remoteCategoryId);
            
            // Buscar si ya existe localmente por nombre
            $sql = 'SELECT c.id_category FROM `' . _DB_PREFIX_ . 'category` c
                    INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON (c.id_category = cl.id_category)
                    WHERE cl.name = "' . pSQL($categoryName) . '" AND cl.id_lang = 1
                    LIMIT 1';
            $localCategoryId = (int)\Db::getInstance()->getValue($sql);
            
            if ($localCategoryId) {
                $this->errors[] = "    Cat '$categoryName' ya existe (ID: $localCategoryId)";
                $categoryCache[$remoteCategoryId] = $localCategoryId;
                return $localCategoryId;
            }
            
            // Crear el padre primero (recursivo)
            $remoteParentId = (int)($remoteCategory['id_parent'] ?? 2);
            $localParentId = 2;
            
            if ($remoteParentId > 2) {
                $this->errors[] = "    Creando padre (ID remoto: $remoteParentId) primero...";
                $localParentId = $this->createCategoryWithHierarchy($remoteParentId);
            }
            
            // Crear la categoría
            $category = new \Category();
            $category->id_parent = $localParentId;
            $category->active = 1;
            $category->is_root_category = false;
            
            $languages = \Language::getLanguages(false);
            foreach ($languages as $lang) {
                $category->name[$lang['id_lang']] = $categoryName;
                $category->link_rewrite[$lang['id_lang']] = \Tools::str2url($categoryName);
                $category->description[$lang['id_lang']] = '';
            }
            
            if ($category->add()) {
                $this->errors[] = "    ✓ CREADA: '$categoryName' (ID local: {$category->id}, Padre: $localParentId)";
                $categoryCache[$remoteCategoryId] = $category->id;
                return $category->id;
            } else {
                $this->errors[] = "    ✗ Error al crear '$categoryName'";
                return 2;
            }
            
        } catch (\Exception $e) {
            $this->errors[] = "    Error en categoría $remoteCategoryId: " . $e->getMessage();
            return 2;
        }
    }

    /**
     * Obtener errores
     */
    public function getErrors()
    {
        return $this->errors;
    }
}
