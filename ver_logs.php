<?php
/**
 * Visor de logs de importación
 * IMPORTANTE: Elimina este archivo en producción
 */

// No requiere autenticación para debugging
// IMPORTANTE: Elimina este archivo después de usarlo

$logFile = __DIR__ . '/logs/import_log_' . date('Y-m-d') . '.txt';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Logs de Importación</title>
    <style>
        body { font-family: monospace; margin: 20px; background: #1e1e1e; color: #d4d4d4; }
        h1 { color: #4ec9b0; }
        pre { background: #252526; padding: 15px; border-radius: 5px; overflow-x: auto; white-space: pre-wrap; }
        .success { color: #4ec9b0; }
        .error { color: #f48771; }
        .warning { color: #dcdcaa; }
        .refresh { background: #0e639c; color: white; padding: 10px 20px; border: none; border-radius: 3px; cursor: pointer; }
        .refresh:hover { background: #1177bb; }
    </style>
</head>
<body>
    <h1>📋 Logs de Importación - <?php echo date('Y-m-d'); ?></h1>
    
    <button class="refresh" onclick="location.reload()">🔄 Recargar logs</button>
    
    <p><strong>Archivo:</strong> <?php echo $logFile; ?></p>
    
    <?php if (file_exists($logFile)): ?>
        <p><strong>Tamaño:</strong> <?php echo round(filesize($logFile) / 1024, 2); ?> KB</p>
        <p><strong>Última modificación:</strong> <?php echo date('Y-m-d H:i:s', filemtime($logFile)); ?></p>
        
        <h2>Contenido:</h2>
        <pre><?php
        $content = file_get_contents($logFile);
        
        // Colorear output
        $content = str_replace('✓', '<span class="success">✓</span>', $content);
        $content = str_replace('✗', '<span class="error">✗</span>', $content);
        $content = str_replace('ERROR', '<span class="error">ERROR</span>', $content);
        $content = str_replace('Advertencia', '<span class="warning">Advertencia</span>', $content);
        
        echo $content;
        ?></pre>
        
        <button class="refresh" onclick="if(confirm('¿Eliminar archivo de logs?')) { window.location.href='?delete=1'; }">
            🗑️ Eliminar logs
        </button>
        
        <?php
        if (isset($_GET['delete'])) {
            @unlink($logFile);
            echo '<script>alert("Logs eliminados"); location.href="' . $_SERVER['PHP_SELF'] . '";</script>';
        }
        ?>
        
    <?php else: ?>
        <p style="color: #dcdcaa;">⚠️ No hay logs para hoy. Intenta importar un producto primero.</p>
    <?php endif; ?>
    
    <hr>
    <p style="color: #858585; font-size: 12px;">
        ⚠️ IMPORTANTE: Este archivo muestra información sensible. Elimínalo después de usarlo.<br>
        Archivo: <?php echo __FILE__; ?>
    </p>
</body>
</html>

