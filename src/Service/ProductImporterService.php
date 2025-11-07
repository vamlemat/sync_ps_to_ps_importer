<?php

namespace SyncPsToPsImporter\Service;

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

    public function importProduct($remoteProductId)
    {
        $this->errors = [];
        $warnings = [];

        try {
            $this->errors[] = "=== Iniciando importación del producto ID: $remoteProductId ===";

            // [1] Datos remotos
            $this->errors[] = "[1/10] Obteniendo datos del producto remoto...";
            $remoteProduct = $this->apiService->getProduct($remoteProductId);
            if (!$remoteProduct) {
                throw new \Exception("No se pudo obtener el producto $remoteProductId desde la API");
            }
            $productName = is_array($remoteProduct['name'] ?? null)
                ? ($remoteProduct['name'][1] ?? reset($remoteProduct['name']))
                : ($remoteProduct['name'] ?? 'Sin nombre');
            $this->errors[] = "✓ Producto obtenido: $productName";

            // [2] ¿Existe por referencia?
            $this->errors[] = "[2/10] Verificando si el producto ya existe...";
            $reference = (string)($remoteProduct['reference'] ?? '');
            $localProductId = 0;
            $this->errors[] = "Referencia remota (raw): '" . $reference . "' (len=" . \Tools::strlen($reference) . ")";
            if ($reference !== '') {
                $ref = pSQL($reference, true);
                $sql = 'SELECT `id_product` FROM `'._DB_PREFIX_.'product` WHERE `reference`=\''.$ref.'\'';
                $this->errors[] = 'SQL DEBUG (product by reference) => '.$sql;
                $localProductId = (int)\Db::getInstance()->getValue($sql);
            }
            $product = $localProductId ? new \Product($localProductId) : new \Product();
            $this->errors[] = $localProductId
                ? "✓ Producto existente (ID: $localProductId), actualizando..."
                : "✓ Producto nuevo, creando...";

            // [3] Datos básicos
            $this->errors[] = "[3/10] Asignando datos básicos...";
            $product->reference        = $reference;
            $product->ean13            = $remoteProduct['ean13'] ?? '';
            $product->upc              = $remoteProduct['upc'] ?? '';
            $product->price            = (float)($remoteProduct['price'] ?? 0);
            $product->wholesale_price  = (float)($remoteProduct['wholesale_price'] ?? 0);
            $product->active           = (int)($remoteProduct['active'] ?? 1);
            $product->id_category_default = 2;

            // Impuestos: fija grupo local 21%
            $product->id_tax_rules_group = 1;
            $this->errors[] = "✓ Grupo de impuestos asignado (id_tax_rules_group = {$product->id_tax_rules_group})";

            // Precio por unidad (unit_price + unity) con redondeo a 6 decimales
            try {
                $unityRemote     = trim((string)($remoteProduct['unity'] ?? ''));
                $unitPriceRemote = (float)($remoteProduct['unit_price'] ?? 0);
                $packM2 = 0.0;

                // Intentar leer "Packaging" de las características (m2 por caja)
                if (isset($remoteProduct['associations']['product_features']) && is_array($remoteProduct['associations']['product_features'])) {
                    foreach ($remoteProduct['associations']['product_features'] as $frow) {
                        $fid  = (int)($frow['id'] ?? 0);
                        $fval = (int)($frow['id_feature_value'] ?? 0);
                        if (!$fid || !$fval) { continue; }
                        $remoteFeature = $this->apiService->getFeature($fid);
                        if ($remoteFeature) {
                            $fname = is_array($remoteFeature['name'] ?? null)
                                ? ($remoteFeature['name'][1] ?? reset($remoteFeature['name']))
                                : ($remoteFeature['name'] ?? '');
                            if (\Tools::strtolower($fname) === \Tools::strtolower('Packaging')) {
                                $remoteValue = $this->apiService->getFeatureValue($fval);
                                $valText = is_array($remoteValue['value'] ?? null)
                                    ? ($remoteValue['value'][1] ?? reset($remoteValue['value']))
                                    : ($remoteValue['value'] ?? '');
                                $valText = str_replace(',', '.', $valText);
                                $packM2  = (float)preg_replace('/[^0-9.]/', '', $valText);
                            }
                        }
                    }
                }

                $unityFinal = ($unityRemote !== '' ? $unityRemote : 'm2');
                $unitPriceFinal = 0.0;

                if ($unitPriceRemote > 0) {
                    $unitPriceFinal = (float)\Tools::ps_round($unitPriceRemote, 6);
                    $this->errors[] = "✓ Unit price remoto: {$unitPriceFinal}";
                } else {
                    if ($packM2 > 0) {
                        // precio por m2 = price / m2_por_caja
                        $calc = ((float)$product->price) / $packM2;
                        $unitPriceFinal = (float)\Tools::ps_round($calc, 6);
                        $this->errors[] = "✓ Unit price calculado: price/packM2 = {$product->price}/{$packM2} = {$unitPriceFinal}";
                    } else {
                        // fallback: igual al price (válido y simple)
                        $unitPriceFinal = (float)\Tools::ps_round((float)$product->price, 6);
                        $this->errors[] = "✓ Unit price por defecto (price): {$unitPriceFinal}";
                    }
                }

                // límites de seguridad para pasar isPrice
                if ($unitPriceFinal < 0) { $unitPriceFinal = 0.0; }
                if ($unitPriceFinal > 9999999999) { $unitPriceFinal = 0.0; }

                $product->unit_price = $unitPriceFinal; // <= 6 decimales
                $product->unity      = $unityFinal;

            } catch (\Exception $e) {
                $this->errors[] = "⚠ Unit price: ".$e->getMessage();
                // En caso de error, no bloqueamos el guardado
                $product->unit_price = 0.0;
                $product->unity = 'm2';
            }

            // Textos multiidioma
            $languages = \Language::getLanguages(false);
            foreach ($languages as $lang) {
                $id = (int)$lang['id_lang'];
                $product->name[$id] = $productName;
                $product->description[$id] = is_array($remoteProduct['description'] ?? null)
                    ? ($remoteProduct['description'][1] ?? reset($remoteProduct['description']))
                    : ($remoteProduct['description'] ?? '');
                $product->description_short[$id] = is_array($remoteProduct['description_short'] ?? null)
                    ? ($remoteProduct['description_short'][1] ?? reset($remoteProduct['description_short']))
                    : ($remoteProduct['description_short'] ?? '');
                $product->link_rewrite[$id] = is_array($remoteProduct['link_rewrite'] ?? null)
                    ? ($remoteProduct['link_rewrite'][1] ?? reset($remoteProduct['link_rewrite']))
                    : ($remoteProduct['link_rewrite'] ?? \Tools::str2url($productName));
                $product->meta_title[$id] = is_array($remoteProduct['meta_title'] ?? null)
                    ? ($remoteProduct['meta_title'][1] ?? reset($remoteProduct['meta_title']))
                    : ($remoteProduct['meta_title'] ?? $productName);
            }
            $this->errors[] = "✓ Datos básicos asignados";

            // [4] Guardar
            $this->errors[] = "[4/10] Guardando producto...";
            if (!$product->save()) {
                throw new \Exception("Error al guardar el producto en la base de datos");
            }
            $this->errors[] = "✓ Producto guardado (ID: {$product->id})";

            // [5] Categorías
            $this->errors[] = "[5/10] Creando/asignando categorías...";
            try {
                $categories = [2];
                if (isset($remoteProduct['associations']['categories']) && is_array($remoteProduct['associations']['categories'])) {
                    $this->errors[] = "  Producto tiene ".count($remoteProduct['associations']['categories'])." categorías remotas";
                    foreach ($remoteProduct['associations']['categories'] as $cat) {
                        $remoteCategoryId = (int)($cat['id'] ?? 0);
                        if ($remoteCategoryId > 2) {
                            $localCategoryId = $this->createCategoryWithHierarchy($remoteCategoryId);
                            if ($localCategoryId && !in_array($localCategoryId, $categories, true)) {
                                $categories[] = $localCategoryId;
                            }
                        }
                    }
                }
                if (!empty($product->id_category_default) && !in_array($product->id_category_default, $categories, true)) {
                    $categories[] = (int)$product->id_category_default;
                }
                $categories = array_unique($categories);
                $product->updateCategories($categories);
                $this->errors[] = "  ✓ Categorías asignadas: ".count($categories)." (IDs: ".implode(', ', $categories).")";
            } catch (\Exception $e) {
                $warnings[] = "Categorías: ".$e->getMessage();
                $this->errors[] = "  ERROR en categorías: ".$e->getMessage();
            }

            // [6] Fabricante
            $this->errors[] = "[6/10] Asignando fabricante...";
            try {
                $manufacturerName = $remoteProduct['manufacturer_name'] ?? null;
                if ($manufacturerName) {
                    $m = pSQL($manufacturerName, true);
                    $sql = 'SELECT `id_manufacturer` FROM `'._DB_PREFIX_.'manufacturer` WHERE `name`=\''.$m.'\'';
                    $this->errors[] = 'SQL DEBUG (manufacturer by name) => '.$sql;
                    $manufacturerId = (int)\Db::getInstance()->getValue($sql);
                    if (!$manufacturerId) {
                        $man = new \Manufacturer();
                        $man->name = $manufacturerName;
                        $man->active = 1;
                        if ($man->add()) {
                            $manufacturerId = (int)$man->id;
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
                $warnings[] = "Fabricante: ".$e->getMessage();
            }

            // [7] Stock
            $this->errors[] = "[7/10] Actualizando stock...";
            try {
                $quantity = (int)($remoteProduct['quantity'] ?? 0);
                if (isset($remoteProduct['stock_availables'][0]['quantity'])) {
                    $q2 = (int)$remoteProduct['stock_availables'][0]['quantity'];
                    if ($q2 !== 0) {
                        $this->errors[] = "✓ Stock remoto (stock_availables): $q2";
                        $quantity = $q2;
                    } else {
                        $this->errors[] = "✓ Stock remoto (quantity): $quantity";
                    }
                } else {
                    $this->errors[] = "✓ Stock remoto (quantity): $quantity";
                }
                \StockAvailable::setQuantity($product->id, 0, $quantity);
                $this->errors[] = "✓ Stock aplicado: $quantity unidades";
            } catch (\Exception $e) {
                $warnings[] = "Stock: ".$e->getMessage();
            }

            // [8] Imágenes (cover único + miniaturas + associateTo)
            $this->errors[] = "[8/10] Importando imágenes...";
            try {
                $this->apiService->setDebug(true);
                $imageCount = $this->importImagesSimple($product, $remoteProduct);
                $this->apiService->setDebug(false);
                $this->errors[] = "✓ Imágenes: $imageCount importadas";
            } catch (\Exception $e) {
                $this->apiService->setDebug(false);
                $warnings[] = "Imágenes: ".$e->getMessage();
                $this->errors[] = "ERROR en imágenes: ".$e->getMessage();
            }

            // [9] Características
            $this->errors[] = "[9/10] Importando características...";
            try {
                $featureCount = $this->importFeaturesOptimized($product, $remoteProduct);
                $this->errors[] = "  ✓ Características: $featureCount importadas/asignadas";
            } catch (\Exception $e) {
                $warnings[] = "Características: ".$e->getMessage();
                $this->errors[] = "  ERROR en características: ".$e->getMessage();
            }

            // [10] Combinaciones (variantes/atributos)
            $this->errors[] = "[10/10] Importando combinaciones...";
            try {
                $combinationCount = $this->importCombinations($product, $remoteProduct);
                $this->errors[] = "  ✓ Combinaciones: $combinationCount importadas/asignadas";
            } catch (\Exception $e) {
                $warnings[] = "Combinaciones: ".$e->getMessage();
                $this->errors[] = "  ERROR en combinaciones: ".$e->getMessage();
            }

            $this->errors[] = "=== ✅ Importación completada exitosamente ===";
            $message = "Producto '{$productName}' importado (ID: {$product->id})";
            if (!empty($warnings)) { $message .= " [".count($warnings)." advertencias]"; }

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
            $logFile = _PS_MODULE_DIR_.'sync_ps_to_ps_importer/logs/import_log_'.date('Y-m-d').'.txt';
            $logDir  = dirname($logFile);
            if (!file_exists($logDir)) { @mkdir($logDir, 0777, true); }
            $logContent  = "\n=== ".date('H:i:s')." - Producto $remoteProductId ===\n";
            $logContent .= implode("\n", $this->errors)."\n";
            @file_put_contents($logFile, $logContent, FILE_APPEND);
        }
    }

    private function importImagesSimple($product, $remoteProduct)
    {
        $imported = 0;

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
        $this->errors[] = "  Encontradas ".count($imageIds)." imágenes: ".implode(', ', $imageIds);

        $hasCover = false;
        $coverInfo = \Image::getCover((int)$product->id);
        if (is_array($coverInfo) && !empty($coverInfo['id_image'])) { $hasCover = true; }

        foreach ($imageIds as $imageId) {
            try {
                $remoteProductId = (int)($remoteProduct['id'] ?? 0);
                $this->errors[] = "  Intentando descargar imagen $imageId del producto remoto $remoteProductId...";
                $imageData = $this->apiService->downloadImage($remoteProductId, $imageId);
                $size = $imageData ? strlen($imageData) : 0;
                $this->errors[] = "  Respuesta recibida: $size bytes";
                if (!$imageData || $size < 100) {
                    $this->errors[] = "  ✗ Imagen $imageId: vacía o corrupta ($size bytes)";
                    $this->errors[] = "  URL: ".$this->apiService->getApiUrl()."/api/images/products/$remoteProductId/$imageId";
                    continue;
                }

                $image = new \Image();
                $image->id_product = (int)$product->id;
                $image->cover      = $hasCover ? 0 : 1;
                $image->position   = (int)\Image::getHighestPosition($product->id) + 1;

                if (!$image->add()) {
                    $this->errors[] = "  Imagen $imageId: error al crear registro";
                    continue;
                }
                if (!$hasCover && (int)$image->cover === 1) { $hasCover = true; }

                $path = $image->getPathForCreation();
                if (!file_exists(dirname($path))) { @mkdir(dirname($path), 0777, true); }

                if (!file_put_contents($path.'.jpg', $imageData)) {
                    $image->delete();
                    $this->errors[] = "  Imagen $imageId: error al guardar archivo físico";
                    continue;
                }

                try {
                    $types = \ImageType::getImagesTypes('products');
                    foreach ($types as $t) {
                        \ImageManager::resize(
                            $path.'.jpg',
                            $path.'-'.stripslashes($t['name']).'.jpg',
                            (int)$t['width'],
                            (int)$t['height']
                        );
                    }
                } catch (\Exception $e) {
                    $this->errors[] = "  Advertencia miniaturas: ".$e->getMessage();
                }

                if (method_exists($image, 'associateTo')) {
                    $image->associateTo([(int)\Context::getContext()->shop->id]);
                }

                $imported++;
                $this->errors[] = "  ✓ Imagen $imageId importada (id local {$image->id})";
            } catch (\Exception $e) {
                $this->errors[] = "  Imagen $imageId error: ".$e->getMessage();
            }
        }

        if (!$hasCover) {
            $ids = \Image::getImages((int)\Context::getContext()->language->id, (int)$product->id);
            if (!empty($ids)) {
                $first = new \Image((int)$ids[0]['id_image']);
                $first->cover = 1;
                $first->update();
                $this->errors[] = "  ✓ Se ha fijado cover en la primera imagen (id {$first->id})";
            }
        }

        return $imported;
    }

    private function importFeaturesOptimized($product, $remoteProduct)
    {
        $imported = 0;
        if (!isset($remoteProduct['associations']['product_features']) || !is_array($remoteProduct['associations']['product_features'])) {
            $this->errors[] = "  No hay características en el producto remoto";
            return 0;
        }

        $features = $remoteProduct['associations']['product_features'];
        $this->errors[] = "  Encontradas ".count($features)." características remotas";

        \Db::getInstance()->delete('feature_product', '`id_product`='.(int)$product->id);

        static $featureCache = [];
        static $featureValueCache = [];

        foreach ($features as $row) {
            try {
                $remoteFeatureId = (int)($row['id'] ?? 0);
                $remoteValueId   = (int)($row['id_feature_value'] ?? 0);
                if (!$remoteFeatureId || !$remoteValueId) { continue; }

                $remoteFeature = $featureCache[$remoteFeatureId] ?? $this->apiService->getFeature($remoteFeatureId);
                if (!$remoteFeature) { $this->errors[] = "  ⚠ No se pudo obtener característica $remoteFeatureId"; continue; }
                $featureCache[$remoteFeatureId] = $remoteFeature;

                $featureName = is_array($remoteFeature['name'] ?? null)
                    ? ($remoteFeature['name'][1] ?? reset($remoteFeature['name']))
                    : ($remoteFeature['name'] ?? '');

                $localFeatureId = $this->findOrCreateFeatureByName($featureName);
                if (!$localFeatureId) { $this->errors[] = "  ✗ No se pudo crear/encontrar característica '$featureName'"; continue; }

                $remoteValue = $featureValueCache[$remoteValueId] ?? $this->apiService->getFeatureValue($remoteValueId);
                if (!$remoteValue) { $this->errors[] = "  ⚠ No se pudo obtener valor $remoteValueId"; continue; }
                $featureValueCache[$remoteValueId] = $remoteValue;

                $valueName = is_array($remoteValue['value'] ?? null)
                    ? ($remoteValue['value'][1] ?? reset($remoteValue['value']))
                    : ($remoteValue['value'] ?? '');

                $localValueId = $this->findOrCreateFeatureValueByName($localFeatureId, $valueName);
                if (!$localValueId) { $this->errors[] = "  ✗ No se pudo crear/encontrar valor '$valueName'"; continue; }

                \Db::getInstance()->insert('feature_product', [
                    'id_feature'       => (int)$localFeatureId,
                    'id_product'       => (int)$product->id,
                    'id_feature_value' => (int)$localValueId,
                ], false, true, \Db::INSERT_IGNORE);

                $imported++;
                $this->errors[] = "  ✓ '$featureName' = '$valueName' (Feature: $localFeatureId, Value: $localValueId)";
            } catch (\Exception $e) {
                $this->errors[] = "  Error en característica: ".$e->getMessage();
            }
        }

        $this->errors[] = "  Total: $imported características asignadas";
        return $imported;
    }

    private function findOrCreateFeatureByName($featureName)
    {
        $featureName = trim((string)$featureName);
        if ($featureName === '') { return 0; }
        $name  = pSQL($featureName, true);
        $idLang = (int)\Configuration::get('PS_LANG_DEFAULT', null, null, null, 1);

        $sql = 'SELECT `fl`.`id_feature` FROM `'._DB_PREFIX_.'feature_lang` fl
                WHERE fl.`id_lang`='.$idLang.' AND fl.`name`=\''.$name.'\'';
        $featureId = (int)\Db::getInstance()->getValue($sql);
        if ($featureId) { return $featureId; }

        $feature = new \Feature();
        foreach (\Language::getLanguages(false) as $lang) {
            $feature->name[(int)$lang['id_lang']] = $featureName;
        }
        if ($feature->add()) {
            $this->errors[] = "    + Característica CREADA: '$featureName' (ID: {$feature->id})";
            return (int)$feature->id;
        }
        return 0;
    }

    private function findOrCreateFeatureValueByName($featureId, $valueName)
    {
        $featureId = (int)$featureId;
        $valueName = trim((string)$valueName);
        if ($featureId <= 0 || $valueName === '') { return 0; }

        $val   = pSQL($valueName, true);
        $idLang = (int)\Configuration::get('PS_LANG_DEFAULT', null, null, null, 1);

        $sql = 'SELECT fvl.`id_feature_value`
                FROM `'._DB_PREFIX_.'feature_value` fv
                INNER JOIN `'._DB_PREFIX_.'feature_value_lang` fvl
                  ON (fvl.`id_feature_value`=fv.`id_feature_value`)
                WHERE fv.`id_feature`='.$featureId.' AND fvl.`id_lang`='.$idLang.' AND fvl.`value`=\''.$val.'\'';
        $valueId = (int)\Db::getInstance()->getValue($sql);
        if ($valueId) { return $valueId; }

        $featureValue = new \FeatureValue();
        $featureValue->id_feature = $featureId;
        $featureValue->custom = 0;
        foreach (\Language::getLanguages(false) as $lang) {
            $featureValue->value[(int)$lang['id_lang']] = $valueName;
        }
        if ($featureValue->add()) {
            $this->errors[] = "    + Valor CREADO: '$valueName' (ID: {$featureValue->id})";
            return (int)$featureValue->id;
        }
        return 0;
    }

    private function createCategoryWithHierarchy($remoteCategoryId)
    {
        static $categoryCache = [];
        if (isset($categoryCache[$remoteCategoryId])) {
            return $categoryCache[$remoteCategoryId];
        }
        if (empty($remoteCategoryId) || $remoteCategoryId <= 2) { return 2; }

        try {
            $remoteCategory = $this->apiService->getCategory($remoteCategoryId);
            if (!$remoteCategory) {
                $this->errors[] = "    Categoría remota $remoteCategoryId no encontrada";
                return 2;
            }
            
            // Extraer nombre (multiidioma)
            $categoryName = is_array($remoteCategory['name'] ?? null)
                ? ($remoteCategory['name'][1] ?? reset($remoteCategory['name']))
                : ($remoteCategory['name'] ?? ('Categoría '.$remoteCategoryId));

            $name  = pSQL($categoryName, true);
            $idLang = (int)\Configuration::get('PS_LANG_DEFAULT', null, null, null, 1);

            $sql = 'SELECT `id_category` FROM `'._DB_PREFIX_.'category_lang`
                    WHERE `id_lang`='.$idLang.' AND `name`=\''.$name.'\'';
            $localCategoryId = (int)\Db::getInstance()->getValue($sql);
            if ($localCategoryId) {
                $this->errors[] = "    Cat '$categoryName' ya existe (ID: $localCategoryId)";
                $categoryCache[$remoteCategoryId] = $localCategoryId;
                return $localCategoryId;
            }

            $remoteParentId = (int)($remoteCategory['id_parent'] ?? 2);
            $localParentId  = 2;
            if ($remoteParentId > 2) {
                $this->errors[] = "    Creando padre (ID remoto: $remoteParentId) primero...";
                $localParentId = $this->createCategoryWithHierarchy($remoteParentId);
            }

            $category = new \Category();
            $category->id_parent = $localParentId;
            $category->active = (int)($remoteCategory['active'] ?? 1);
            $category->is_root_category = false;
            
            // Datos multiidioma
            foreach (\Language::getLanguages(false) as $lang) {
                $id = (int)$lang['id_lang'];
                
                // Nombre
                $category->name[$id] = is_array($remoteCategory['name'] ?? null)
                    ? ($remoteCategory['name'][$id] ?? $categoryName)
                    : $categoryName;
                
                // Descripción (NUEVO)
                $category->description[$id] = is_array($remoteCategory['description'] ?? null)
                    ? ($remoteCategory['description'][$id] ?? '')
                    : ($remoteCategory['description'] ?? '');
                
                // Link rewrite
                $remoteLinkRewrite = is_array($remoteCategory['link_rewrite'] ?? null)
                    ? ($remoteCategory['link_rewrite'][$id] ?? null)
                    : ($remoteCategory['link_rewrite'] ?? null);
                $category->link_rewrite[$id] = $remoteLinkRewrite ?: \Tools::str2url($category->name[$id]);
                
                // Meta título (NUEVO - SEO)
                $category->meta_title[$id] = is_array($remoteCategory['meta_title'] ?? null)
                    ? ($remoteCategory['meta_title'][$id] ?? '')
                    : ($remoteCategory['meta_title'] ?? '');
                
                // Meta descripción (NUEVO - SEO)
                $category->meta_description[$id] = is_array($remoteCategory['meta_description'] ?? null)
                    ? ($remoteCategory['meta_description'][$id] ?? '')
                    : ($remoteCategory['meta_description'] ?? '');
                
                // Meta keywords (NUEVO - SEO)
                $category->meta_keywords[$id] = is_array($remoteCategory['meta_keywords'] ?? null)
                    ? ($remoteCategory['meta_keywords'][$id] ?? '')
                    : ($remoteCategory['meta_keywords'] ?? '');
            }

            if ($category->add()) {
                $this->errors[] = "    ✓ CREADA: '$categoryName' (ID local: {$category->id}, Padre: $localParentId)";
                
                // Importar imagen de la categoría (NUEVO)
                if (!empty($remoteCategory['id'])) {
                    $this->importCategoryImage($category->id, (int)$remoteCategory['id']);
                }
                
                $categoryCache[$remoteCategoryId] = (int)$category->id;
                return (int)$category->id;
            } else {
                $this->errors[] = "    ✗ Error al crear '$categoryName'";
                return 2;
            }
        } catch (\Exception $e) {
            $this->errors[] = "    Error en categoría $remoteCategoryId: ".$e->getMessage();
            return 2;
        }
    }
    
    /**
     * Importar imagen de categoría
     */
    private function importCategoryImage($localCategoryId, $remoteCategoryId)
    {
        try {
            // Intentar descargar la imagen de la categoría
            $imageUrl = $this->apiService->getApiUrl() . "/api/images/categories/{$remoteCategoryId}";
            $imageData = $this->apiService->downloadCategoryImage($remoteCategoryId);
            
            if (!$imageData || strlen($imageData) < 100) {
                // No hay imagen o está corrupta
                return false;
            }
            
            // Ruta donde guardar la imagen
            $categoryPath = _PS_CAT_IMG_DIR_ . $localCategoryId . '.jpg';
            
            // Guardar imagen
            if (!file_put_contents($categoryPath, $imageData)) {
                $this->errors[] = "      ⚠ No se pudo guardar imagen de categoría";
                return false;
            }
            
            // Generar miniaturas
            try {
                $imagesTypes = \ImageType::getImagesTypes('categories');
                foreach ($imagesTypes as $imageType) {
                    $thumbPath = _PS_CAT_IMG_DIR_ . $localCategoryId . '-' . stripslashes($imageType['name']) . '.jpg';
                    \ImageManager::resize(
                        $categoryPath,
                        $thumbPath,
                        (int)$imageType['width'],
                        (int)$imageType['height']
                    );
                }
                $this->errors[] = "      ✓ Imagen de categoría importada";
                return true;
            } catch (\Exception $e) {
                $this->errors[] = "      ⚠ Error en miniaturas de categoría: ".$e->getMessage();
                return false;
            }
        } catch (\Exception $e) {
            // Error silencioso, no queremos bloquear la creación de la categoría
            return false;
        }
    }

    public function importMultipleProducts($productIds)
    {
        $results = [];
        if (!is_array($productIds)) { $productIds = [$productIds]; }
        foreach ($productIds as $id) {
            $id = (int)$id;
            if ($id <= 0) {
                $results[$id] = ['success' => false, 'message' => 'ID de producto inválido'];
                continue;
            }
            if (function_exists('set_time_limit')) { @set_time_limit(60); }
            $results[$id] = $this->importProduct($id);
        }
        return $results;
    }

    /**
     * Importar combinaciones (variantes/atributos) de un producto
     */
    private function importCombinations($product, $remoteProduct)
    {
        $imported = 0;
        
        // Verificar si el producto remoto tiene combinaciones
        if (!isset($remoteProduct['associations']['combinations']) || 
            !is_array($remoteProduct['associations']['combinations']) ||
            empty($remoteProduct['associations']['combinations'])) {
            $this->errors[] = "  No hay combinaciones en el producto remoto";
            return 0;
        }
        
        $remoteCombinations = $remoteProduct['associations']['combinations'];
        $this->errors[] = "  Encontradas ".count($remoteCombinations)." combinaciones remotas";
        
        // Eliminar combinaciones locales existentes
        $this->errors[] = "  Eliminando combinaciones locales existentes...";
        $existingCombinations = $product->getAttributeCombinations((int)\Context::getContext()->language->id);
        if (!empty($existingCombinations)) {
            foreach ($existingCombinations as $existingComb) {
                $combination = new \Combination((int)$existingComb['id_product_attribute']);
                if (\Validate::isLoadedObject($combination)) {
                    $combination->delete();
                }
            }
            $this->errors[] = "  ✓ Eliminadas ".count($existingCombinations)." combinaciones existentes";
        }
        
        // Cachés para evitar consultas repetidas
        static $attributeCache = [];
        static $attributeValueCache = [];
        
        // Importar cada combinación
        foreach ($remoteCombinations as $combRow) {
            try {
                $remoteCombId = (int)($combRow['id'] ?? 0);
                if (!$remoteCombId) { continue; }
                
                $this->errors[] = "  → Procesando combinación remota ID: $remoteCombId";
                
                // Obtener datos completos de la combinación remota
                $remoteCombination = $this->apiService->getCombination($remoteCombId);
                if (!$remoteCombination) {
                    $this->errors[] = "    ✗ No se pudo obtener combinación $remoteCombId";
                    continue;
                }
                
                // Obtener atributos de la combinación
                $productOptionValues = [];
                if (isset($remoteCombination['associations']['product_option_values']) && 
                    is_array($remoteCombination['associations']['product_option_values'])) {
                    $productOptionValues = $remoteCombination['associations']['product_option_values'];
                }
                
                if (empty($productOptionValues)) {
                    $this->errors[] = "    ⚠ Combinación sin atributos, omitiendo";
                    continue;
                }
                
                // Crear/obtener atributos locales
                $localAttributeIds = [];
                foreach ($productOptionValues as $optValueRow) {
                    $remoteValueId = (int)($optValueRow['id'] ?? 0);
                    if (!$remoteValueId) { continue; }
                    
                    // Obtener información del valor de atributo remoto
                    $remoteOptionValue = $attributeValueCache[$remoteValueId] 
                        ?? $this->apiService->getProductOptionValue($remoteValueId);
                    if (!$remoteOptionValue) { continue; }
                    $attributeValueCache[$remoteValueId] = $remoteOptionValue;
                    
                    $remoteOptionId = (int)($remoteOptionValue['id_attribute_group'] ?? 0);
                    if (!$remoteOptionId) { continue; }
                    
                    // Obtener información del atributo remoto
                    $remoteOption = $attributeCache[$remoteOptionId]
                        ?? $this->apiService->getProductOption($remoteOptionId);
                    if (!$remoteOption) { continue; }
                    $attributeCache[$remoteOptionId] = $remoteOption;
                    
                    $attributeName = $remoteOption['public_name'] ?? $remoteOption['name'] ?? '';
                    $valueName = $remoteOptionValue['name'] ?? '';
                    
                    if ($attributeName === '' || $valueName === '') { continue; }
                    
                    // Crear/encontrar atributo local
                    $localAttributeGroupId = $this->findOrCreateAttributeGroup($attributeName);
                    if (!$localAttributeGroupId) {
                        $this->errors[] = "    ✗ No se pudo crear grupo de atributos '$attributeName'";
                        continue;
                    }
                    
                    // Crear/encontrar valor de atributo local
                    $localAttributeId = $this->findOrCreateAttribute($localAttributeGroupId, $valueName);
                    if (!$localAttributeId) {
                        $this->errors[] = "    ✗ No se pudo crear atributo '$valueName'";
                        continue;
                    }
                    
                    $localAttributeIds[] = $localAttributeId;
                    $this->errors[] = "    ✓ Atributo: $attributeName = $valueName (Local ID: $localAttributeId)";
                }
                
                if (empty($localAttributeIds)) {
                    $this->errors[] = "    ⚠ No se crearon atributos locales para esta combinación";
                    continue;
                }
                
                // Crear la combinación local
                $combination = new \Combination();
                $combination->id_product = (int)$product->id;
                $combination->reference = (string)($remoteCombination['reference'] ?? '');
                $combination->ean13 = (string)($remoteCombination['ean13'] ?? '');
                $combination->upc = (string)($remoteCombination['upc'] ?? '');
                $combination->price = (float)($remoteCombination['price'] ?? 0);
                $combination->unit_price_impact = (float)($remoteCombination['unit_price_impact'] ?? 0);
                $combination->wholesale_price = (float)($remoteCombination['wholesale_price'] ?? 0);
                $combination->weight = (float)($remoteCombination['weight'] ?? 0);
                $combination->minimal_quantity = (int)($remoteCombination['minimal_quantity'] ?? 1);
                $combination->default_on = (int)($remoteCombination['default_on'] ?? 0);
                
                if (!$combination->add()) {
                    $this->errors[] = "    ✗ Error al crear combinación local";
                    continue;
                }
                
                $this->errors[] = "    ✓ Combinación creada (ID local: {$combination->id})";
                
                // Asociar atributos a la combinación
                $combination->setAttributes($localAttributeIds);
                
                // Asignar stock a la combinación
                $quantity = (int)($remoteCombination['quantity'] ?? 0);
                \StockAvailable::setQuantity(
                    (int)$product->id,
                    (int)$combination->id,
                    $quantity
                );
                $this->errors[] = "    ✓ Stock asignado: $quantity unidades";
                
                // Asignar imágenes de la combinación si existen
                if (isset($remoteCombination['associations']['images']) && 
                    is_array($remoteCombination['associations']['images'])) {
                    $imageIds = [];
                    foreach ($remoteCombination['associations']['images'] as $imgRow) {
                        $imageIds[] = (int)($imgRow['id'] ?? 0);
                    }
                    $imageIds = array_filter($imageIds);
                    
                    if (!empty($imageIds)) {
                        // Mapear IDs de imagen remotos a locales (esto requiere lógica adicional)
                        // Por ahora lo dejamos como TODO
                        $this->errors[] = "    ℹ Combinación tiene ".count($imageIds)." imágenes (no implementado aún)";
                    }
                }
                
                $imported++;
                
            } catch (\Exception $e) {
                $this->errors[] = "  ✗ Error en combinación: ".$e->getMessage();
            }
        }
        
        $this->errors[] = "  Total: $imported combinaciones importadas";
        return $imported;
    }

    /**
     * Encontrar o crear grupo de atributos (AttributeGroup)
     * Ejemplo: "Talla", "Color"
     */
    private function findOrCreateAttributeGroup($groupName)
    {
        $groupName = trim((string)$groupName);
        if ($groupName === '') { return 0; }
        
        $name = pSQL($groupName, true);
        $idLang = (int)\Configuration::get('PS_LANG_DEFAULT', null, null, null, 1);
        
        $sql = 'SELECT ag.`id_attribute_group` FROM `'._DB_PREFIX_.'attribute_group` ag
                INNER JOIN `'._DB_PREFIX_.'attribute_group_lang` agl 
                  ON (agl.`id_attribute_group`=ag.`id_attribute_group`)
                WHERE agl.`id_lang`='.$idLang.' AND agl.`name`=\''.$name.'\'';
        $groupId = (int)\Db::getInstance()->getValue($sql);
        
        if ($groupId) { return $groupId; }
        
        // Crear nuevo grupo de atributos
        $attributeGroup = new \AttributeGroup();
        $attributeGroup->group_type = 'select'; // o 'radio', 'color'
        foreach (\Language::getLanguages(false) as $lang) {
            $attributeGroup->name[(int)$lang['id_lang']] = $groupName;
            $attributeGroup->public_name[(int)$lang['id_lang']] = $groupName;
        }
        
        if ($attributeGroup->add()) {
            $this->errors[] = "      + Grupo de atributos CREADO: '$groupName' (ID: {$attributeGroup->id})";
            return (int)$attributeGroup->id;
        }
        
        return 0;
    }

    /**
     * Encontrar o crear valor de atributo (Attribute)
     * Ejemplo: "S", "M", "L", "Rojo", "Azul"
     */
    private function findOrCreateAttribute($attributeGroupId, $attributeName)
    {
        $attributeGroupId = (int)$attributeGroupId;
        $attributeName = trim((string)$attributeName);
        if ($attributeGroupId <= 0 || $attributeName === '') { return 0; }
        
        $name = pSQL($attributeName, true);
        $idLang = (int)\Configuration::get('PS_LANG_DEFAULT', null, null, null, 1);
        
        $sql = 'SELECT a.`id_attribute` FROM `'._DB_PREFIX_.'attribute` a
                INNER JOIN `'._DB_PREFIX_.'attribute_lang` al 
                  ON (al.`id_attribute`=a.`id_attribute`)
                WHERE a.`id_attribute_group`='.$attributeGroupId.' 
                  AND al.`id_lang`='.$idLang.' 
                  AND al.`name`=\''.$name.'\'';
        $attributeId = (int)\Db::getInstance()->getValue($sql);
        
        if ($attributeId) { return $attributeId; }
        
        // Crear nuevo valor de atributo
        $attribute = new \Attribute();
        $attribute->id_attribute_group = $attributeGroupId;
        foreach (\Language::getLanguages(false) as $lang) {
            $attribute->name[(int)$lang['id_lang']] = $attributeName;
        }
        
        if ($attribute->add()) {
            $this->errors[] = "      + Valor de atributo CREADO: '$attributeName' (ID: {$attribute->id})";
            return (int)$attribute->id;
        }
        
        return 0;
    }

    private function getTaxRulesGroupIdByName($groupName)
    {
        $db = \Db::getInstance();
        $groupName = pSQL($groupName, true);
        $sql = 'SELECT `id_tax_rules_group` FROM `'._DB_PREFIX_.'tax_rules_group` WHERE `name`=\''.$groupName.'\'';
        return (int)$db->getValue($sql);
    }

    public function getErrors()
    {
        return $this->errors;
    }
}
