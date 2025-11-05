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

        try {
            // 1. Obtener datos del producto remoto
            $remoteProduct = $this->apiService->getProduct($remoteProductId);
            if (!$remoteProduct) {
                throw new \Exception("No se pudo obtener el producto $remoteProductId");
            }

            // 2. Verificar si el producto ya existe (por referencia)
            $localProductId = $this->findProductByReference($remoteProduct['reference']);
            
            if ($localProductId) {
                // Actualizar producto existente
                $product = new \Product($localProductId);
            } else {
                // Crear nuevo producto
                $product = new \Product();
            }

            // 3. Asignar datos básicos
            $this->setBasicData($product, $remoteProduct);

            // 4. Guardar producto
            if (!$product->save()) {
                throw new \Exception("Error al guardar el producto");
            }

            // 5. Importar características (features)
            $this->importFeatures($product, $remoteProductId);

            // 6. Importar combinaciones (atributos)
            $this->importCombinations($product, $remoteProductId);

            // 7. Importar imágenes
            $this->importImages($product, $remoteProductId);

            // 8. Actualizar stock
            \StockAvailable::setQuantity($product->id, 0, $remoteProduct['quantity'] ?? 0);

            return [
                'success' => true,
                'product_id' => $product->id,
                'message' => 'Producto importado correctamente'
            ];

        } catch (\Exception $e) {
            $this->errors[] = $e->getMessage();
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $this->errors
            ];
        }
    }

    /**
     * Buscar producto por referencia
     */
    private function findProductByReference($reference)
    {
        if (empty($reference)) {
            return false;
        }

        $sql = 'SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE reference = "' . pSQL($reference) . '"';
        return (int)\Db::getInstance()->getValue($sql);
    }

    /**
     * Asignar datos básicos al producto
     */
    private function setBasicData($product, $remoteProduct)
    {
        // Datos básicos
        $product->reference = $remoteProduct['reference'] ?? '';
        $product->ean13 = $remoteProduct['ean13'] ?? '';
        $product->upc = $remoteProduct['upc'] ?? '';
        $product->price = (float)$remoteProduct['price'];
        $product->wholesale_price = (float)($remoteProduct['wholesale_price'] ?? 0);
        $product->active = (int)$remoteProduct['active'];
        $product->id_category_default = $this->findOrCreateCategory($remoteProduct['id_category_default']);
        $product->id_tax_rules_group = (int)$remoteProduct['id_tax_rules_group'];
        
        // Textos multiidioma
        $languages = \Language::getLanguages(false);
        foreach ($languages as $lang) {
            $langId = $lang['id_lang'];
            
            // Nombre
            if (isset($remoteProduct['name'][$langId])) {
                $product->name[$langId] = $remoteProduct['name'][$langId];
            } elseif (isset($remoteProduct['name'][1])) {
                $product->name[$langId] = $remoteProduct['name'][1];
            }
            
            // Descripción
            if (isset($remoteProduct['description'][$langId])) {
                $product->description[$langId] = $remoteProduct['description'][$langId];
            } elseif (isset($remoteProduct['description'][1])) {
                $product->description[$langId] = $remoteProduct['description'][1];
            }
            
            // Descripción corta
            if (isset($remoteProduct['description_short'][$langId])) {
                $product->description_short[$langId] = $remoteProduct['description_short'][$langId];
            } elseif (isset($remoteProduct['description_short'][1])) {
                $product->description_short[$langId] = $remoteProduct['description_short'][1];
            }
            
            // Link rewrite
            if (isset($remoteProduct['link_rewrite'][$langId])) {
                $product->link_rewrite[$langId] = $remoteProduct['link_rewrite'][$langId];
            } elseif (isset($remoteProduct['link_rewrite'][1])) {
                $product->link_rewrite[$langId] = $remoteProduct['link_rewrite'][1];
            }
        }
    }

    /**
     * Encontrar o crear categoría
     */
    private function findOrCreateCategory($remoteCategoryId)
    {
        // Por ahora devuelve la categoría por defecto (Home)
        // TODO: Implementar mapeo de categorías
        return 2; // Categoría Home
    }

    /**
     * Importar características del producto
     */
    private function importFeatures($product, $remoteProductId)
    {
        try {
            $features = $this->apiService->getProductFeatures($remoteProductId);
            
            // Eliminar características existentes
            \Db::getInstance()->delete('feature_product', 'id_product = ' . (int)$product->id);
            
            foreach ($features as $feature) {
                $idFeature = $this->findOrCreateFeature($feature['id'], $feature['name'] ?? '');
                $idFeatureValue = $this->findOrCreateFeatureValue(
                    $idFeature,
                    $feature['id_feature_value'],
                    $feature['value'] ?? ''
                );
                
                if ($idFeature && $idFeatureValue) {
                    $product->addFeaturesToDB($idFeature, $idFeatureValue);
                }
            }
        } catch (\Exception $e) {
            $this->errors[] = "Error al importar características: " . $e->getMessage();
        }
    }

    /**
     * Encontrar o crear característica
     */
    private function findOrCreateFeature($remoteId, $name)
    {
        // Buscar por nombre
        $sql = 'SELECT id_feature FROM ' . _DB_PREFIX_ . 'feature_lang WHERE name = "' . pSQL($name) . '" LIMIT 1';
        $id = \Db::getInstance()->getValue($sql);
        
        if ($id) {
            return (int)$id;
        }
        
        // Crear nueva característica
        // TODO: Implementar creación de características
        return false;
    }

    /**
     * Encontrar o crear valor de característica
     */
    private function findOrCreateFeatureValue($featureId, $remoteValueId, $value)
    {
        // TODO: Implementar búsqueda y creación de valores de características
        return false;
    }

    /**
     * Importar combinaciones (atributos)
     */
    private function importCombinations($product, $remoteProductId)
    {
        try {
            $combinations = $this->apiService->getProductCombinations($remoteProductId);
            
            foreach ($combinations as $combination) {
                // TODO: Implementar importación de combinaciones
                // Esto es complejo y requiere manejar atributos y valores de atributos
            }
        } catch (\Exception $e) {
            $this->errors[] = "Error al importar combinaciones: " . $e->getMessage();
        }
    }

    /**
     * Importar imágenes del producto
     */
    private function importImages($product, $remoteProductId)
    {
        try {
            $images = $this->apiService->getProductImages($remoteProductId);
            
            foreach ($images as $imageInfo) {
                $imageId = $imageInfo['id'] ?? null;
                if (!$imageId) {
                    continue;
                }
                
                // Descargar imagen
                $imageData = $this->apiService->downloadImage($remoteProductId, $imageId);
                if (!$imageData) {
                    continue;
                }
                
                // Guardar imagen temporalmente
                $tmpFile = tempnam(sys_get_temp_dir(), 'ps_img_');
                file_put_contents($tmpFile, $imageData);
                
                // Crear objeto Image
                $image = new \Image();
                $image->id_product = $product->id;
                $image->position = \Image::getHighestPosition($product->id) + 1;
                $image->cover = (count($images) == 1 || (isset($imageInfo['cover']) && $imageInfo['cover']));
                
                if ($image->add()) {
                    // Copiar imagen a la ubicación correcta
                    $image->uploadImage($image->id, $tmpFile);
                }
                
                // Eliminar archivo temporal
                @unlink($tmpFile);
            }
        } catch (\Exception $e) {
            $this->errors[] = "Error al importar imágenes: " . $e->getMessage();
        }
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

