<?php
/**
 * Autoloader para el módulo sync_ps_to_ps_importer
 * PrestaShop carga este archivo automáticamente
 */

if (!function_exists('syncPsToPsAutoloader')) {
    function syncPsToPsAutoloader($class)
    {
        // Solo procesar clases de nuestro namespace
        if (strpos($class, 'SyncPsToPsImporter\\') !== 0) {
            return false;
        }
        
        // Construir la ruta del archivo
        $relative_class = substr($class, strlen('SyncPsToPsImporter\\'));
        $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative_class) . '.php';
        
        // Cargar el archivo si existe
        if (file_exists($file)) {
            require_once $file;
            return true;
        }
        
        return false;
    }
    
    // Registrar el autoloader con PREPEND=true para que se ejecute primero
    spl_autoload_register('syncPsToPsAutoloader', true, true);
}
