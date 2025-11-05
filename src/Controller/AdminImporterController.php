<?php

namespace SyncPsToPsImporter\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use SyncPsToPsImporter\Service\PrestaShopApiService;
use SyncPsToPsImporter\Service\ProductImporterService;

class AdminImporterController extends AbstractController
{
    /**
     * Panel principal - Listado de productos remotos
     */
    public function indexAction(Request $request)
    {
        // Obtener configuración
        $apiUrl = \Configuration::get('SYNC_PS_REMOTE_URL');
        $apiKey = \Configuration::get('SYNC_PS_API_KEY');
        $customIp = \Configuration::get('SYNC_PS_CUSTOM_IP');
        
        // Variables para la vista
        $products = [];
        $categories = [];
        $error = null;
        $connectionStatus = null;

        // Verificar si hay configuración
        if (empty($apiUrl) || empty($apiKey)) {
            $error = 'Por favor, configura la URL y API Key en la configuración del módulo.';
        } else {
            try {
                $apiService = new PrestaShopApiService($apiUrl, $apiKey);
                
                // Configurar IP personalizada si existe
                if (!empty($customIp)) {
                    $apiService->setCustomIp($customIp);
                }
                
                // Probar conexión
                $testResult = $apiService->testConnection();
                $connectionStatus = $testResult;
                
                if ($testResult['success']) {
                    // Obtener filtros de la petición
                    $limit = (int)$request->query->get('limit', 20);
                    $offset = (int)$request->query->get('offset', 0);
                    $categoryFilter = $request->query->get('category', '');
                    $searchFilter = $request->query->get('search', '');
                    
                    $filters = [];
                    if ($categoryFilter) {
                        $filters['id_category'] = $categoryFilter;
                    }
                    if ($searchFilter) {
                        $filters['search'] = $searchFilter;
                    }
                    
                    // Obtener productos
                    $products = $apiService->getProducts($limit, $offset, $filters);
                    
                    // Obtener categorías para el filtro
                    $categories = $apiService->getCategories(50);
                }
            } catch (\Exception $e) {
                $error = 'Error de conexión: ' . $e->getMessage();
            }
        }

        return $this->render('@Modules/sync_ps_to_ps_importer/views/templates/admin/panel.html.twig', [
            'layoutTitle' => 'Sincronizador PS a PS',
            'requireBulkActions' => false,
            'showContentHeader' => true,
            'enableSidebar' => true,
            'help_link' => false,
            'products' => $products,
            'categories' => $categories,
            'error' => $error,
            'connectionStatus' => $connectionStatus,
            'apiUrl' => $apiUrl,
            'hasConfig' => !empty($apiUrl) && !empty($apiKey),
        ]);
    }

    /**
     * Importar producto(s) seleccionado(s) - AJAX
     */
    public function importAction(Request $request)
    {
        // Asegurar que siempre devolvemos JSON
        try {
            if (!$request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => 'Petición inválida - no es AJAX']);
            }

            $apiUrl = \Configuration::get('SYNC_PS_REMOTE_URL');
            $apiKey = \Configuration::get('SYNC_PS_API_KEY');
            $customIp = \Configuration::get('SYNC_PS_CUSTOM_IP');

            if (empty($apiUrl) || empty($apiKey)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'No hay configuración de API'
                ]);
            }

            // Leer el JSON del body
            $content = $request->getContent();
            $data = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Error al decodificar JSON: ' . json_last_error_msg()
                ]);
            }
            
            $productIds = $data['product_ids'] ?? [];
            
            if (empty($productIds)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'No se seleccionaron productos'
                ]);
            }

            $apiService = new PrestaShopApiService($apiUrl, $apiKey);
            
            // Configurar IP personalizada si existe
            if (!empty($customIp)) {
                $apiService->setCustomIp($customIp);
            }
            
            $importerService = new ProductImporterService($apiService);

            // Importar productos
            $results = $importerService->importMultipleProducts($productIds);
            
            // Contar éxitos y errores
            $success = 0;
            $errors = 0;
            $messages = [];
            $logs = [];
            
            foreach ($results as $productId => $result) {
                if ($result['success']) {
                    $success++;
                    if (isset($result['warnings']) && !empty($result['warnings'])) {
                        $messages[] = "Producto $productId importado con advertencias: " . implode(', ', $result['warnings']);
                    }
                } else {
                    $errors++;
                    // Mensaje principal del error
                    $errorMsg = "Producto $productId: " . $result['message'];
                    
                    // Añadir información adicional si está disponible
                    if (isset($result['file']) && isset($result['line'])) {
                        $errorMsg .= " (Error en {$result['file']} línea {$result['line']})";
                    }
                    
                    $messages[] = $errorMsg;
                    
                    // Guardar logs detallados para debugging
                    if (isset($result['errors']) && !empty($result['errors'])) {
                        $logs["Producto_$productId"] = $result['errors'];
                    }
                }
            }

            return new JsonResponse([
                'success' => $errors === 0,
                'message' => "$success productos importados correctamente" . ($errors > 0 ? ", $errors con errores" : ""),
                'details' => [
                    'success' => $success,
                    'errors' => $errors,
                    'messages' => $messages,
                    'logs' => $logs
                ]
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Test detallado de producto - AJAX
     */
    public function testProductAction(Request $request)
    {
        if (!$request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => false, 'message' => 'Petición inválida']);
        }

        $productId = $request->query->get('product_id');
        
        if (!$productId) {
            return new JsonResponse(['success' => false, 'message' => 'No se proporcionó product_id']);
        }

        $apiUrl = \Configuration::get('SYNC_PS_REMOTE_URL');
        $apiKey = \Configuration::get('SYNC_PS_API_KEY');
        $customIp = \Configuration::get('SYNC_PS_CUSTOM_IP');

        if (empty($apiUrl) || empty($apiKey)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'No hay configuración de API'
            ]);
        }

        try {
            $apiService = new PrestaShopApiService($apiUrl, $apiKey);
            
            // Configurar IP personalizada si existe
            if (!empty($customIp)) {
                $apiService->setCustomIp($customIp);
            }
            
            // Activar debug
            $apiService->setDebug(true);
            
            // Intentar obtener el producto
            $product = $apiService->getProduct($productId);
            
            return new JsonResponse([
                'success' => true,
                'message' => 'Producto obtenido correctamente',
                'product' => [
                    'id' => $product['id'] ?? 'N/A',
                    'name' => $product['name'] ?? 'N/A',
                    'reference' => $product['reference'] ?? 'N/A',
                    'price' => $product['price'] ?? 'N/A',
                    'keys' => array_keys($product)
                ]
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Probar conexión con API - AJAX
     */
    public function testConnectionAction(Request $request)
    {
        if (!$request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => false, 'message' => 'Petición inválida']);
        }

        $apiUrl = \Configuration::get('SYNC_PS_REMOTE_URL');
        $apiKey = \Configuration::get('SYNC_PS_API_KEY');
        $customIp = \Configuration::get('SYNC_PS_CUSTOM_IP');

        if (empty($apiUrl) || empty($apiKey)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Configura primero la URL y API Key'
            ]);
        }

        try {
            $apiService = new PrestaShopApiService($apiUrl, $apiKey);
            
            // Configurar IP personalizada si existe
            if (!empty($customIp)) {
                $apiService->setCustomIp($customIp);
            }
            
            $result = $apiService->testConnection();
            return new JsonResponse($result);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}
