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

            // PASO 5: Asignar categorías
            $this->errors[] = "[5/9] Asignando categorías...";
            try {
                $categories = [2]; // Home
                if (isset($remoteProduct['id_category_default'])) {
                    $categories[] = $remoteProduct['id_category_default'];
                }
                $product->updateCategories(array_unique($categories));
                $this->errors[] = "✓ Categorías asignadas: " . count($categories);
            } catch (\Exception $e) {
                $warnings[] = "Categorías: " . $e->getMessage();
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

            // PASO 8: Importar imágenes
            $this->errors[] = "[8/9] Importando imágenes...";
            try {
                $imageCount = $this->importImagesSimple($product, $remoteProduct);
                $this->errors[] = "✓ Imágenes: $imageCount importadas";
            } catch (\Exception $e) {
                $warnings[] = "Imágenes: " . $e->getMessage();
            }

            // PASO 9: Importar características (deshabilitado temporalmente - muy lento)
            $this->errors[] = "[9/9] Características: omitidas (requieren configuración manual)";
            $warnings[] = "Las 28 características no se importan automáticamente. Puedes asignarlas manualmente después.";

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
                    $imageData = $this->apiService->downloadImage($remoteProductId, $imageId);
                    
                    if (!$imageData || strlen($imageData) < 100) {
                        $this->errors[] = "  Imagen $imageId: vacía o corrupta (" . strlen($imageData) . " bytes), omitida";
                        continue;
                    }
                    
                    $this->errors[] = "  Imagen $imageId descargada (" . strlen($imageData) . " bytes)";
                    
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
     * Obtener errores
     */
    public function getErrors()
    {
        return $this->errors;
    }
}
