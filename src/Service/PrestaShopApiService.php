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

        $parsed = parse_url($this->apiUrl);
        $this->domain = $parsed['host'] ?? null;
    }

    public function setCustomIp($ip)
    {
        $this->customIp = trim((string)$ip);
    }

    public function setDebug($debug = true)
    {
        $this->debug = (bool)$debug;
    }

    public function getApiUrl()
    {
        return $this->apiUrl;
    }

    private function log($msg)
    {
        if ($this->debug) {
            error_log($msg);
        }
    }

    /**
     * Petición GET genérica al Webservice (maneja JSON/XML y detecta HTML)
     */
    private function makeRequest($resource, $params = [])
    {
        $url = $this->apiUrl . '/api/' . ltrim($resource, '/');

        // Pedimos JSON, pero haremos fallback si el WS devuelve XML
        $queryParams = array_merge(['output_format' => 'JSON'], $params);
        $url .= '?' . http_build_query($queryParams);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);

        // Auth básica con ws_key
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->apiKey . ':');

        // Retorno y compresión
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_ENCODING, ''); // gzip/deflate

        // Timeouts
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        // Redirecciones
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);

        // IPv4
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

        // SSL permisivo (entornos privados)
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        // Cabeceras (forzar Host cuando hay RESOLVE)
        $headers = ['Accept: application/json', 'Accept-Encoding: gzip'];
        if (!empty($this->domain)) {
            $headers[] = 'Host: ' . $this->domain;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Pin DNS por IP personalizada
        if ($this->customIp && $this->domain) {
            $parsed = parse_url($url);
            $scheme = $parsed['scheme'] ?? 'https';
            $port = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);
            curl_setopt($ch, CURLOPT_RESOLVE, [ "{$this->domain}:{$port}:{$this->customIp}" ]);
            $this->log("Using custom IP resolution: {$this->domain}:{$port} -> {$this->customIp}");
        }

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        $errorNo  = curl_errno($ch);

        $this->log("API Request: $url");
        $this->log("HTTP Code: $httpCode");
        if ($error) {
            $this->log("cURL Error No: $errorNo");
            $this->log("cURL Error: $error");
        }
        if ($response !== false) {
            $this->log("Response (first 400): " . substr((string)$response, 0, 400));
        }

        curl_close($ch);

        if ($error) {
            $msg = "Error de conexión: $error (Código: $errorNo)";
            if ($errorNo == 6)  { $msg .= "\n- DNS/Host. Usa IP personalizada o revisa DNS."; }
            if ($errorNo == 60) { $msg .= "\n- Certificado SSL del remoto no válido."; }
            throw new \Exception($msg);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $snippet = substr((string)$response, 0, 200);
            throw new \Exception("HTTP $httpCode desde $resource. Cuerpo: " . $snippet);
        }

        // --- Parse inteligente ---
        $body = (string)$response;
        $trim = ltrim($body);

        // 1) JSON
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            $data = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
            // si no es JSON válido, seguimos a XML/HTML
        }

        // 2) XML (no HTML)
        $isXml  = (stripos($trim, '<?xml') === 0) ||
                  (stripos($trim, '<prestashop') !== false) ||
                  ($trim !== '' && $trim[0] === '<' && stripos($trim, '</') !== false);
        $isHtml = (stripos($trim, '<html') !== false) || (stripos($trim, '<!DOCTYPE html') !== false);

        if ($isXml && !$isHtml) {
            if (!function_exists('simplexml_load_string')) {
                throw new \Exception(
                    'El servidor no tiene habilitada la extensión PHP SimpleXML/DOM/XML. ' .
                    'Actívalas para poder parsear respuestas XML del Webservice.'
                );
            }
            $xml = @simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
            if ($xml !== false) {
                $json = json_encode($xml);
                $arr  = json_decode($json, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($arr)) {
                    return $arr;
                }
            }
        }

        // 3) HTML u otro contenido
        $snippet = substr($trim, 0, 200);
        throw new \Exception("Respuesta no JSON del Webservice. Probable HTML (401/403/500, WAF o login). Fragmento: ".$snippet);
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
            'limit'   => "$offset,$limit",
        ];

        if (!empty($filters['id_category'])) {
            $params['filter[id_category_default]'] = (int)$filters['id_category'];
        }

        if (!empty($filters['search'])) {
            $params['filter[name]'] = '%' . $filters['search'] . '%';
        }

        $data = $this->makeRequest('products', $params);
        return $data['products'] ?? [];
    }

    /**
     * Obtener el total de productos (con filtros opcionales)
     * Útil para calcular la paginación
     */
    public function getTotalProducts($filters = [])
    {
        try {
            $params = [
                'display' => '[id]', // Solo necesitamos los IDs para contar
            ];

            if (!empty($filters['id_category'])) {
                $params['filter[id_category_default]'] = (int)$filters['id_category'];
            }

            if (!empty($filters['search'])) {
                $params['filter[name]'] = '%' . $filters['search'] . '%';
            }

            $data = $this->makeRequest('products', $params);
            
            // Contar productos en la respuesta
            if (isset($data['products']) && is_array($data['products'])) {
                return count($data['products']);
            }
            
            return 0;
        } catch (\Exception $e) {
            $this->log("Error obteniendo total de productos: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Obtener un producto completo por ID
     */
    public function getProduct($id)
    {
        $data = $this->makeRequest("products/" . (int)$id, ['display' => 'full']);

        if (isset($data['product'])) {
            return $data['product'];
        } elseif (isset($data['products'][0])) {
            return $data['products'][0];
        }
        throw new \Exception("La respuesta de la API no contiene 'product' ni 'products'.");
    }

    /**
     * Obtener categorías
     */
    public function getCategories($limit = 100)
    {
        $params = [
            'display' => 'full',
            'limit'   => (int)$limit
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
            $data = $this->makeRequest("categories/" . (int)$id, ['display' => 'full']);

            if (isset($data['category'])) {
                return $data['category'];
            } elseif (isset($data['categories'][0])) {
                return $data['categories'][0];
            }
            return null;
        } catch (\Exception $e) {
            $this->log("getCategory($id) error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtener productos de una categoría
     */
    public function getProductsByCategory($categoryId, $limit = 100)
    {
        return $this->getProducts((int)$limit, 0, ['id_category' => (int)$categoryId]);
    }

    /**
     * Obtener imágenes de un producto
     */
    public function getProductImages($productId)
    {
        try {
            $data = $this->makeRequest("images/products/" . (int)$productId, ['display' => 'full']);
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
        $url = $this->apiUrl . "/api/images/products/" . (int)$productId . "/" . (int)$imageId;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);

        // Auth básica
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->apiKey . ':');

        // Retorno + compresión
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_ENCODING, '');

        // Timeouts
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        // Redirecciones
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);

        // IPv4
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

        // SSL permisivo
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        // Cabeceras
        $headers = ['Accept: image/jpeg', 'Accept-Encoding: gzip'];
        if (!empty($this->domain)) {
            $headers[] = 'Host: ' . $this->domain;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Pin DNS
        if ($this->customIp && $this->domain) {
            $parsed = parse_url($url);
            $scheme = $parsed['scheme'] ?? 'https';
            $port = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);
            curl_setopt($ch, CURLOPT_RESOLVE, [ "{$this->domain}:{$port}:{$this->customIp}" ]);
            $this->log("Download image using custom IP: {$this->domain}:{$port} -> {$this->customIp}");
        }

        $imageData = curl_exec($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err       = curl_error($ch);

        $this->log("Download image - URL: $url");
        $this->log("Download image - HTTP Code: $httpCode");
        if ($imageData !== false) {
            $this->log("Download image - Size: " . strlen((string)$imageData) . " bytes");
        }
        if ($err) {
            $this->log("Download image - Error: $err");
        }

        curl_close($ch);

        if ($httpCode !== 200 || $imageData === false) {
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
                'filter[id_product]' => (int)$productId
            ]);
            return $data['combinations'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Obtener una combinación específica por ID
     */
    public function getCombination($combinationId)
    {
        try {
            $data = $this->makeRequest("combinations/{$combinationId}", ['display' => 'full']);
            
            if (isset($data['combination'])) {
                return $data['combination'];
            } elseif (isset($data['combinations'][0])) {
                return $data['combinations'][0];
            }
            return null;
        } catch (\Exception $e) {
            $this->log("Error en getCombination($combinationId): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtener atributo (product_option) por ID
     * Ejemplo: "Talla", "Color"
     */
    public function getProductOption($optionId)
    {
        try {
            $data = $this->makeRequest("product_options/{$optionId}", ['display' => 'full']);
            
            $row = null;
            if (isset($data['product_option'])) {
                $row = $data['product_option'];
            } elseif (!empty($data['product_options'][0])) {
                $row = $data['product_options'][0];
            }
            
            if (!$row) {
                return null;
            }
            
            // Normalizar nombre
            if (isset($row['name'])) {
                $row['name'] = $this->normalizeLangField($row['name'], 1);
            }
            if (isset($row['public_name'])) {
                $row['public_name'] = $this->normalizeLangField($row['public_name'], 1);
            }
            
            return $row;
        } catch (\Exception $e) {
            $this->log("Error en getProductOption($optionId): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtener valor de atributo (product_option_value) por ID
     * Ejemplo: "S", "M", "L", "Rojo", "Azul"
     */
    public function getProductOptionValue($valueId)
    {
        try {
            $data = $this->makeRequest("product_option_values/{$valueId}", ['display' => 'full']);
            
            $row = null;
            if (isset($data['product_option_value'])) {
                $row = $data['product_option_value'];
            } elseif (!empty($data['product_option_values'][0])) {
                $row = $data['product_option_values'][0];
            }
            
            if (!$row) {
                return null;
            }
            
            // Normalizar nombre
            if (isset($row['name'])) {
                $row['name'] = $this->normalizeLangField($row['name'], 1);
            }
            
            return $row;
        } catch (\Exception $e) {
            $this->log("Error en getProductOptionValue($valueId): " . $e->getMessage());
            return null;
        }
    }

       /** Normaliza un campo multilenguaje a string (devuelve el de id_lang=1 si existe). */
    private function normalizeLangField($field, $preferredIdLang = 1)
    {
        if (!isset($field)) {
            return '';
        }
        // Caso: ["language" => [ ["id"=>1,"value"=>"..."], ["id"=>2,"value"=>"..."] ]]
        if (is_array($field) && isset($field['language']) && is_array($field['language'])) {
            foreach ($field['language'] as $node) {
                if (isset($node['id']) && (int)$node['id'] === (int)$preferredIdLang && isset($node['value'])) {
                    return (string)$node['value'];
                }
            }
            // si no hay preferred, toma el primero con value
            foreach ($field['language'] as $node) {
                if (isset($node['value'])) {
                    return (string)$node['value'];
                }
            }
            return '';
        }

        // Caso: array indexado por id_lang: ["1"=>"...", "2"=>"..."]
        if (is_array($field)) {
            if (isset($field[$preferredIdLang])) {
                return (string)$field[$preferredIdLang];
            }
            $first = reset($field);
            return (string)$first;
        }

        // Caso: string plano
        return (string)$field;
    }

    /** Obtener datos de una característica (feature) */
    public function getFeature($featureId)
    {
        try {
            $data = $this->makeRequest("product_features/{$featureId}", ['display' => 'full']);

            $row = null;
            if (isset($data['product_feature'])) {
                $row = $data['product_feature'];
            } elseif (!empty($data['product_features'][0])) {
                $row = $data['product_features'][0];
            }
            if (!$row) {
                return null;
            }

            // Normaliza nombre
            if (isset($row['name'])) {
                $row['name'] = $this->normalizeLangField($row['name'], 1);
            }
            return $row;
        } catch (\Exception $e) {
            error_log("Error en getFeature($featureId): " . $e->getMessage());
            return null;
        }
    }

    /** Obtener datos de un valor de característica (feature value) */
    public function getFeatureValue($featureValueId)
    {
        try {
            $data = $this->makeRequest("product_feature_values/{$featureValueId}", ['display' => 'full']);

            $row = null;
            if (isset($data['product_feature_value'])) {
                $row = $data['product_feature_value'];
            } elseif (!empty($data['product_feature_values'][0])) {
                $row = $data['product_feature_values'][0];
            }
            if (!$row) {
                return null;
            }

            // Normaliza value
            if (isset($row['value'])) {
                $row['value'] = $this->normalizeLangField($row['value'], 1);
            }
            return $row;
        } catch (\Exception $e) {
            error_log("Error en getFeatureValue($featureValueId): " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Obtener stock (stock_availables) de un producto remoto.
     * Devuelve total (suma de todas las combinaciones) y el detalle de filas.
     */
    public function getStockForProduct($productId)
    {
        try {
            $data = $this->makeRequest('stock_availables', [
                'filter[id_product]' => (int)$productId,
                'display' => '[id,quantity,id_product_attribute]'
            ]);
    
            $rows = $data['stock_availables'] ?? [];
            $total = 0;
            foreach ($rows as $row) {
                $total += (int)($row['quantity'] ?? 0);
            }
    
            return ['total' => $total, 'rows' => $rows];
        } catch (\Exception $e) {
            // Si falla el WS, devolvemos null para usar fallback
            return ['total' => null, 'rows' => []];
        }
    }

}


