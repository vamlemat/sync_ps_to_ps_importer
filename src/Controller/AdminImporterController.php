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
    public function indexAction(Request $request)
    {
        $apiUrl = \Configuration::get('SYNC_PS_REMOTE_URL');
        $apiKey = \Configuration::get('SYNC_PS_API_KEY');
        $customIp = \Configuration::get('SYNC_PS_CUSTOM_IP');

        $products = [];
        $categories = [];
        $error = null;
        $connectionStatus = null;

        if (empty($apiUrl) || empty($apiKey)) {
            $error = 'Por favor, configura la URL y API Key en la configuración del módulo.';
        } else {
            try {
                $apiService = new PrestaShopApiService($apiUrl, $apiKey);
                if (!empty($customIp)) {
                    $apiService->setCustomIp($customIp);
                }
                $testResult = $apiService->testConnection();
                $connectionStatus = $testResult;

                if ($testResult['success']) {
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

                    // Obtener productos y total para paginación
                    $products = $apiService->getProducts($limit, $offset, $filters);
                    $categories = $apiService->getCategories(50);
                    $totalProducts = $apiService->getTotalProducts($filters);
                    
                    // Calcular información de paginación
                    $currentPage = floor($offset / $limit) + 1;
                    $totalPages = $totalProducts > 0 ? ceil($totalProducts / $limit) : 1;
                    
                    $pagination = [
                        'current_page' => $currentPage,
                        'total_pages' => $totalPages,
                        'total_items' => $totalProducts,
                        'limit' => $limit,
                        'offset' => $offset,
                        'showing_from' => $totalProducts > 0 ? $offset + 1 : 0,
                        'showing_to' => min($offset + $limit, $totalProducts),
                        'has_previous' => $offset > 0,
                        'has_next' => ($offset + $limit) < $totalProducts,
                        'previous_offset' => max(0, $offset - $limit),
                        'next_offset' => $offset + $limit,
                    ];
                }
            } catch (\Throwable $e) {
                $error = 'Error de conexión: ' . $e->getMessage();
            }
        }

        // Obtener token de seguridad
        $token = \Tools::getAdminTokenLite('AdminSyncPsToPsImporter');
        
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
            'pagination' => $pagination ?? null,
            'currentCategory' => $categoryFilter ?? '',
            'currentSearch' => $searchFilter ?? '',
            'adminToken' => $token,
        ]);
    }

    public function importAction(Request $request)
    {
        try {
            if (!$request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => 'Petición inválida - no es AJAX']);
            }

            $apiUrl = \Configuration::get('SYNC_PS_REMOTE_URL');
            $apiKey = \Configuration::get('SYNC_PS_API_KEY');
            $customIp = \Configuration::get('SYNC_PS_CUSTOM_IP');

            if (empty($apiUrl) || empty($apiKey)) {
                return new JsonResponse(['success' => false,'message' => 'No hay configuración de API']);
            }

            $content = $request->getContent();
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse(['success' => false,'message' => 'Error al decodificar JSON: ' . json_last_error_msg()]);
            }

            $productIds = $data['product_ids'] ?? [];
            if (empty($productIds)) {
                return new JsonResponse(['success' => false,'message' => 'No se seleccionaron productos']);
            }

            $apiService = new PrestaShopApiService($apiUrl, $apiKey);
            if (!empty($customIp)) {
                $apiService->setCustomIp($customIp);
            }

            $importerService = new ProductImporterService($apiService);
            $results = $importerService->importMultipleProducts($productIds);

            $success = 0; $errors = 0; $messages = []; $logs = [];
            foreach ($results as $productId => $result) {
                if ($result['success']) {
                    $success++;
                    if (!empty($result['warnings'])) {
                        $messages[] = "Producto $productId importado con advertencias: " . implode(', ', $result['warnings']);
                    }
                } else {
                    $errors++;
                    $errorMsg = "Producto $productId: " . ($result['message'] ?? 'Error desconocido');
                    if (isset($result['file'], $result['line'])) {
                        $errorMsg .= " (Error en {$result['file']} línea {$result['line']})";
                    }
                    $messages[] = $errorMsg;
                    if (!empty($result['errors'])) {
                        $logs["Producto_$productId"] = $result['errors'];
                    }
                }
            }

            return new JsonResponse([
                'success' => $errors === 0,
                'message' => "$success productos importados correctamente" . ($errors > 0 ? ", $errors con errores" : ""),
                'details' => ['success' => $success, 'errors' => $errors, 'messages' => $messages, 'logs' => $logs]
            ]);
        } catch (\Throwable $e) {
            // ¡Clave! Nunca devolvemos HTML; aunque sea fatal/TypeError respondemos JSON.
            return new JsonResponse([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

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
            return new JsonResponse(['success' => false, 'message' => 'No hay configuración de API']);
        }

        try {
            $apiService = new PrestaShopApiService($apiUrl, $apiKey);
            if (!empty($customIp)) {
                $apiService->setCustomIp($customIp);
            }
            $apiService->setDebug(true);

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
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    public function testConnectionAction(Request $request)
    {
        if (!$request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => false, 'message' => 'Petición inválida']);
        }

        $apiUrl = \Configuration::get('SYNC_PS_REMOTE_URL');
        $apiKey = \Configuration::get('SYNC_PS_API_KEY');
        $customIp = \Configuration::get('SYNC_PS_CUSTOM_IP');

        if (empty($apiUrl) || empty($apiKey)) {
            return new JsonResponse(['success' => false, 'message' => 'Configura primero la URL y API Key']);
        }

        try {
            $apiService = new PrestaShopApiService($apiUrl, $apiKey);
            if (!empty($customIp)) {
                $apiService->setCustomIp($customIp);
            }
            $result = $apiService->testConnection();
            return new JsonResponse($result);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function logsAction(Request $request)
    {
        // Limpiar logs antiguos automáticamente
        $this->cleanOldLogs();

        $logsDir = _PS_MODULE_DIR_ . 'sync_ps_to_ps_importer/logs/';
        $logs = [];
        $selectedLog = $request->query->get('file', '');

        // Obtener lista de archivos de log
        if (is_dir($logsDir)) {
            $files = scandir($logsDir);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..' && $file !== 'index.php' && strpos($file, '.txt') !== false) {
                    $filePath = $logsDir . $file;
                    $logs[] = [
                        'name' => $file,
                        'size' => filesize($filePath),
                        'date' => date('Y-m-d H:i:s', filemtime($filePath)),
                        'timestamp' => filemtime($filePath)
                    ];
                }
            }
        }

        // Ordenar por fecha (más reciente primero)
        usort($logs, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });

        // Contenido del log seleccionado
        $logContent = '';
        $logFile = '';
        if ($selectedLog && preg_match('/^[a-zA-Z0-9_\-\.]+\.txt$/', $selectedLog)) {
            $logFile = $selectedLog;
            $logPath = $logsDir . $selectedLog;
            if (file_exists($logPath)) {
                $logContent = file_get_contents($logPath);
            } else {
                $logContent = 'Archivo no encontrado.';
            }
        } elseif (!empty($logs)) {
            // Si no hay seleccionado, mostrar el más reciente
            $logFile = $logs[0]['name'];
            $logPath = $logsDir . $logFile;
            $logContent = file_get_contents($logPath);
        }

        // Obtener token de seguridad
        $token = \Tools::getAdminTokenLite('AdminSyncPsToPsImporter');

        return $this->render('@Modules/sync_ps_to_ps_importer/views/templates/admin/logs.html.twig', [
            'layoutTitle' => 'Logs de Importación',
            'requireBulkActions' => false,
            'showContentHeader' => true,
            'enableSidebar' => true,
            'help_link' => false,
            'logs' => $logs,
            'selectedLog' => $logFile,
            'logContent' => $logContent,
            'adminToken' => $token,
        ]);
    }

    public function clearLogsAction(Request $request)
    {
        if (!$request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => false, 'message' => 'Petición inválida']);
        }

        try {
            $logsDir = _PS_MODULE_DIR_ . 'sync_ps_to_ps_importer/logs/';
            $deleted = 0;

            if (is_dir($logsDir)) {
                $files = scandir($logsDir);
                foreach ($files as $file) {
                    if ($file !== '.' && $file !== '..' && $file !== 'index.php' && strpos($file, '.txt') !== false) {
                        $filePath = $logsDir . $file;
                        if (unlink($filePath)) {
                            $deleted++;
                        }
                    }
                }
            }

            return new JsonResponse([
                'success' => true,
                'message' => "$deleted archivo(s) de log eliminado(s) correctamente"
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error al eliminar logs: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Limpia logs antiguos (más de 1 día)
     */
    private function cleanOldLogs()
    {
        try {
            $logsDir = _PS_MODULE_DIR_ . 'sync_ps_to_ps_importer/logs/';
            $maxAge = 86400; // 24 horas en segundos
            $now = time();
            $deleted = 0;

            if (is_dir($logsDir)) {
                $files = scandir($logsDir);
                foreach ($files as $file) {
                    if ($file !== '.' && $file !== '..' && $file !== 'index.php' && strpos($file, '.txt') !== false) {
                        $filePath = $logsDir . $file;
                        $fileAge = $now - filemtime($filePath);
                        
                        // Si el archivo tiene más de 1 día, eliminarlo
                        if ($fileAge > $maxAge) {
                            if (@unlink($filePath)) {
                                $deleted++;
                            }
                        }
                    }
                }
            }

            return $deleted;
        } catch (\Throwable $e) {
            // Error silencioso, no queremos interrumpir la navegación
            return 0;
        }
    }
}
