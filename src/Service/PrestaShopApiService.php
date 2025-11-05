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
        $data = $this->makeRequest("products/$id", ['display' => 'full']);
        return $data['product'] ?? null;
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
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->apiKey . ':');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
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
}

