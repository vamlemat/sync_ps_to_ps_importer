<?php
/**
 * Script de prueba para verificar la conexión API a un producto específico
 * IMPORTANTE: Elimina este archivo después de usarlo
 */

// Incluir configuración de PrestaShop
// Subir 2 niveles: modules/sync_ps_to_ps_importer -> modules -> raíz
require_once __DIR__ . '/../../config/config.inc.php';
require_once __DIR__ . '/autoload.php';

use SyncPsToPsImporter\Service\PrestaShopApiService;

// Obtener configuración
$apiUrl = Configuration::get('SYNC_PS_REMOTE_URL');
$apiKey = Configuration::get('SYNC_PS_API_KEY');
$customIp = Configuration::get('SYNC_PS_CUSTOM_IP');

// ID del producto a probar
$productId = isset($_GET['id']) ? (int)$_GET['id'] : 62553;

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Test API - Producto <?php echo $productId; ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .test { background: white; padding: 15px; margin: 10px 0; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .test h3 { margin-top: 0; color: #333; }
        .success { color: #28a745; font-weight: bold; }
        .error { color: #dc3545; font-weight: bold; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 3px; overflow-x: auto; max-height: 400px; }
        .info { background: #e7f3ff; padding: 10px; border-left: 4px solid #2196F3; margin: 10px 0; }
    </style>
</head>
<body>
    <h1>🔍 Test de API - Producto <?php echo $productId; ?></h1>
    
    <div class="info">
        <strong>URL de test:</strong> <?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?><br>
        <strong>Cambiar producto:</strong> Añade <code>?id=XXXX</code> a la URL
    </div>

    <div class="test">
        <h3>Configuración</h3>
        <pre><?php
        echo "API URL: " . ($apiUrl ?: 'NO CONFIGURADO') . "\n";
        echo "API Key: " . ($apiKey ? substr($apiKey, 0, 10) . '...' : 'NO CONFIGURADO') . "\n";
        echo "IP Personalizada: " . ($customIp ?: 'NO CONFIGURADO') . "\n";
        ?></pre>
    </div>

    <?php if (empty($apiUrl) || empty($apiKey)): ?>
        <div class="test">
            <h3 class="error">❌ Error</h3>
            <p>No hay configuración de API. Por favor configura el módulo primero.</p>
        </div>
    <?php else: ?>
        
        <div class="test">
            <h3>1️⃣ Test de Conexión Básica</h3>
            <?php
            try {
                $apiService = new PrestaShopApiService($apiUrl, $apiKey);
                
                if (!empty($customIp)) {
                    $apiService->setCustomIp($customIp);
                    echo "<p>✅ IP personalizada configurada: $customIp</p>";
                }
                
                $testResult = $apiService->testConnection();
                if ($testResult['success']) {
                    echo '<p class="success">✅ Conexión exitosa</p>';
                } else {
                    echo '<p class="error">❌ Error: ' . $testResult['message'] . '</p>';
                }
            } catch (Exception $e) {
                echo '<p class="error">❌ Error: ' . $e->getMessage() . '</p>';
            }
            ?>
        </div>

        <div class="test">
            <h3>2️⃣ Test de Obtener Producto Completo</h3>
            <?php
            try {
                $apiService = new PrestaShopApiService($apiUrl, $apiKey);
                
                if (!empty($customIp)) {
                    $apiService->setCustomIp($customIp);
                }
                
                // Activar debug
                $apiService->setDebug(true);
                
                echo "<p>Intentando obtener producto ID: <strong>$productId</strong></p>";
                
                $product = $apiService->getProduct($productId);
                
                if ($product) {
                    echo '<p class="success">✅ Producto obtenido correctamente</p>';
                    echo '<h4>Datos del producto:</h4>';
                    echo '<pre>';
                    echo "ID: " . ($product['id'] ?? 'N/A') . "\n";
                    echo "Nombre: " . (is_array($product['name'] ?? null) ? print_r($product['name'], true) : ($product['name'] ?? 'N/A')) . "\n";
                    echo "Referencia: " . ($product['reference'] ?? 'N/A') . "\n";
                    echo "Precio: " . ($product['price'] ?? 'N/A') . "\n";
                    echo "EAN13: " . ($product['ean13'] ?? 'N/A') . "\n";
                    echo "Activo: " . ($product['active'] ?? 'N/A') . "\n";
                    echo "\nTotal de campos: " . count($product) . "\n";
                    echo "\nCampos disponibles:\n" . implode(', ', array_keys($product));
                    echo '</pre>';
                    
                    echo '<h4>Producto completo (JSON):</h4>';
                    echo '<pre>' . json_encode($product, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
                } else {
                    echo '<p class="error">❌ getProduct() devolvió NULL</p>';
                }
                
            } catch (Exception $e) {
                echo '<p class="error">❌ Error: ' . $e->getMessage() . '</p>';
                echo '<pre>Trace: ' . $e->getTraceAsString() . '</pre>';
            }
            ?>
        </div>

        <div class="test">
            <h3>3️⃣ Test de URL Directa</h3>
            <?php
            $testUrl = $apiUrl . "/api/products/$productId?output_format=JSON&ws_key=$apiKey&display=full";
            echo "<p>URL de test:</p>";
            echo "<pre>" . htmlspecialchars($testUrl) . "</pre>";
            echo '<p><a href="' . htmlspecialchars($testUrl) . '" target="_blank" style="color: #2196F3;">🔗 Abrir en nueva ventana</a></p>';
            ?>
        </div>

    <?php endif; ?>

    <div class="test" style="background: #fff3cd; border-left: 4px solid #ffc107;">
        <h3>⚠️ IMPORTANTE</h3>
        <p><strong>Elimina este archivo después de usarlo:</strong></p>
        <p><code><?php echo __FILE__; ?></code></p>
    </div>
</body>
</html>

