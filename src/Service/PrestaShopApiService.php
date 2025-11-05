<?php

namespace SyncPsToPsImporter\Service;

/**
 * Servicio para conectar con el webservice de PrestaShop remoto
 */
class PrestaShopApiService
{
    private $apiUrl;
    private $apiKey;
    private $debug = false;
    private $customIp = null;
    private $domain = null;

    public function __construct($apiUrl, $apiKey)
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = $apiKey;
        
        // Extraer el dominio de la URL
        $parsed = parse_url($this->apiUrl);
        $this->domain = $parsed['host'] ?? null;
    }
    
    /**
     * Configurar IP personalizada para resolver el dominio
     * Útil cuando el dominio es interno y no se puede modificar /etc/hosts
     */
    public function setCustomIp($ip)
    {
        $this->customIp = $ip;
    }

    /**
     * Activar modo debug
     */
    public function setDebug($debug = true)
    {
        $this->debug = $debug;
    }
    
    /**
     * Obtener URL de API
     */
    public function getApiUrl()
    {
        return $this->apiUrl;
    }

    /**
     * Hacer petición GET al webservice
     */
    private function makeRequest($resource, $params = [])
    {
        $url = $this->apiUrl . '/api/' . $resource;
        
        // Añadir parámetros
        $queryParams = array_merge(['output_format' => 'JSON'], $params);
        $url .= '?' . http_build_query($queryParams);

        // Configurar cURL con opciones mejoradas para resolver problemas de DNS y SSL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->apiKey . ':');
        
        // Configuración SSL (desactivar verificación para certificados autofirmados)
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        // Forzar uso de IPv4 para evitar problemas de DNS con IPv6
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        
        // Timeouts más generosos
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        
        // Seguir redirecciones
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        
        // User Agent para evitar bloqueos
        curl_setopt($ch, CURLOPT_USERAGENT, 'PrestaShop-Sync-Module/1.0');
        
        // Usar DNS cache
        curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 120);
        
        // Si se configuró una IP personalizada, forzar la resolución
        if ($this->customIp && $this->domain) {
            $parsed = parse_url($url);
            $port = $parsed['port'] ?? ($parsed['scheme'] === 'https' ? 443 : 80);
            $resolve = ["{$this->domain}:{$port}:{$this->customIp}"];
            curl_setopt($ch, CURLOPT_RESOLVE, $resolve);
            
            if ($this->debug) {
                error_log("Using custom IP resolution: {$this->domain}:{$port} -> {$this->customIp}");
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errorNo = curl_errno($ch);
        
        if ($this->debug) {
            error_log("API Request: $url");
            error_log("HTTP Code: $httpCode");
            error_log("cURL Error No: $errorNo");
            error_log("cURL Error: $error");
            error_log("Response: " . substr($response, 0, 500));
        }
        
        curl_close($ch);

        if ($error) {
            // Proporcionar mensaje de error más específico
            $errorMsg = "Error de conexión: $error (Código: $errorNo)";
            
            if ($errorNo == 6) { // CURLE_COULDNT_RESOLVE_HOST
                $errorMsg .= "\n\nEl servidor no puede resolver el dominio. Posibles soluciones:\n";
                $errorMsg .= "1. Verifica la configuración de DNS del servidor\n";
                $errorMsg .= "2. Contacta con tu proveedor de hosting\n";
                $errorMsg .= "3. Añade la IP del dominio al archivo /etc/hosts del servidor";
            } elseif ($errorNo == 60) { // CURLE_SSL_CACERT
                $errorMsg .= "\n\nProblema con el certificado SSL. El certificado de {$this->apiUrl} no es válido.";
            }
            
            throw new \Exception($errorMsg);
        }

        if ($httpCode !== 200) {
            throw new \Exception("Error HTTP $httpCode: " . substr($response, 0, 200));
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Error al decodificar JSON: " . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Probar conexión con la API
     */
    public function testConnection()
    {
        try {
            $this->makeRequest('products', ['limit' => 1]);
            return ['success' => true, 'message' => 'Conexión exitosa'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Obtener lista de productos
     */
    public function getProducts($limit = 50, $offset = 0, $filters = [])
    {
        $params = [
            'display' => 'full',
            'limit' => "$offset,$limit"
        ];

        // Aplicar filtros si existen
        if (!empty($filters['id_category'])) {
            $params['filter[id_category_default]'] = $filters['id_category'];
        }

        if (!empty($filters['search'])) {
            $params['filter[name]'] = '%' . $filters['search'] . '%';
        }

        $data = $this->makeRequest('products', $params);
        return $data['products'] ?? [];
    }

    /**
     * Obtener un producto completo por ID
     */
    public function getProduct($id)
    {
        try {
            $data = $this->makeRequest("products/$id", ['display' => 'full']);
            
            // La API puede devolver el producto de dos formas:
            // 1. Como objeto único: {"product": {...}}
            // 2. Como array de un elemento: {"products": [{...}]}
            
            if (isset($data['product'])) {
                // Formato 1: objeto único
                return $data['product'];
            } elseif (isset($data['products']) && is_array($data['products']) && count($data['products']) > 0) {
                // Formato 2: array con un elemento
                return $data['products'][0];
            } else {
                error_log("API Response for product $id: " . print_r($data, true));
                throw new \Exception("La respuesta de la API no contiene 'product' ni 'products'. Respuesta: " . json_encode($data));
            }
        } catch (\Exception $e) {
            error_log("Error en getProduct($id): " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Obtener categorías
     */
    public function getCategories($limit = 100)
    {
        $params = [
            'display' => 'full',
            'limit' => $limit
        ];
        
        $data = $this->makeRequest('categories', $params);
        return $data['categories'] ?? [];
    }
    
    /**
     * Obtener una categoría por ID
     */
    public function getCategory($id)
    {
        try {
            $data = $this->makeRequest("categories/$id", ['display' => 'full']);
            
            if (isset($data['category'])) {
                return $data['category'];
            } elseif (isset($data['categories']) && is_array($data['categories']) && count($data['categories']) > 0) {
                return $data['categories'][0];
            }
            
            return null;
        } catch (\Exception $e) {
            error_log("Error en getCategory($id): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtener productos de una categoría
     */
    public function getProductsByCategory($categoryId, $limit = 100)
    {
        return $this->getProducts($limit, 0, ['id_category' => $categoryId]);
    }

    /**
     * Obtener imágenes de un producto
     */
    public function getProductImages($productId)
    {
        try {
            $data = $this->makeRequest("images/products/$productId", ['display' => 'full']);
            return $data['images'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Descargar imagen
     */
    public function downloadImage($productId, $imageId)
    {
        $url = $this->apiUrl . "/api/images/products/$productId/$imageId";
        
        // Usar la MISMA configuración que makeRequest()
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->apiKey . ':');
        
        // SSL
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        // Forzar IPv4
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        
        // Timeouts
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        
        // Seguir redirecciones
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        
        // User Agent
        curl_setopt($ch, CURLOPT_USERAGENT, 'PrestaShop-Sync-Module/1.0');
        
        // DNS cache
        curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 120);
        
        // *** IMPORTANTE: Si hay IP personalizada, usarla ***
        if ($this->customIp && $this->domain) {
            $parsed = parse_url($url);
            $port = $parsed['port'] ?? ($parsed['scheme'] === 'https' ? 443 : 80);
            $resolve = ["{$this->domain}:{$port}:{$this->customIp}"];
            curl_setopt($ch, CURLOPT_RESOLVE, $resolve);
            
            if ($this->debug) {
                error_log("Download image using custom IP: {$this->domain}:{$port} -> {$this->customIp}");
            }
        }

        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errorNo = curl_errno($ch);
        
        if ($this->debug) {
            error_log("Download image - URL: $url");
            error_log("Download image - HTTP Code: $httpCode");
            error_log("Download image - Size: " . strlen($imageData) . " bytes");
            if ($error) {
                error_log("Download image - Error: $error (Code: $errorNo)");
            }
        }
        
        curl_close($ch);

        if ($httpCode !== 200 || $error) {
            error_log("Error downloading image $productId/$imageId: HTTP $httpCode, Error: $error");
            return false;
        }

        return $imageData;
    }

    /**
     * Obtener combinaciones (atributos) de un producto
     */
    public function getProductCombinations($productId)
    {
        try {
            $data = $this->makeRequest("combinations", [
                'display' => 'full',
                'filter[id_product]' => $productId
            ]);
            return $data['combinations'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Obtener características de un producto
     */
    public function getProductFeatures($productId)
    {
        try {
            $product = $this->getProduct($productId);
            return $product['associations']['product_features'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Obtener datos de una característica (feature)
     */
    public function getFeature($featureId)
    {
        try {
            $data = $this->makeRequest("product_features/$featureId", ['display' => 'full']);
            
            if (isset($data['product_feature'])) {
                return $data['product_feature'];
            } elseif (isset($data['product_features']) && is_array($data['product_features']) && count($data['product_features']) > 0) {
                return $data['product_features'][0];
            }
            
            return null;
        } catch (\Exception $e) {
            error_log("Error en getFeature($featureId): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtener datos de un valor de característica (feature value)
     */
    public function getFeatureValue($featureValueId)
    {
        try {
            $data = $this->makeRequest("product_feature_values/$featureValueId", ['display' => 'full']);
            
            if (isset($data['product_feature_value'])) {
                return $data['product_feature_value'];
            } elseif (isset($data['product_feature_values']) && is_array($data['product_feature_values']) && count($data['product_feature_values']) > 0) {
                return $data['product_feature_values'][0];
            }
            
            return null;
        } catch (\Exception $e) {
            error_log("Error en getFeatureValue($featureValueId): " . $e->getMessage());
            return null;
        }
    }
}


