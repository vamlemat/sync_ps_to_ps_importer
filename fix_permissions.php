<?php
/**
 * Script temporal para asignar permisos al tab del módulo
 * Ejecutar desde la raíz de PrestaShop: php modules/sync_ps_to_ps_importer/fix_permissions.php
 */

require_once(dirname(__FILE__) . '/../../config/config.inc.php');

echo "=== Asignando permisos al tab ===\n\n";

// Obtener el ID del tab
$tabId = Tab::getIdFromClassName('AdminSyncPsToPsImporter');

if (!$tabId) {
    die("ERROR: No se encontró el tab AdminSyncPsToPsImporter\n");
}

echo "Tab ID encontrado: " . $tabId . "\n";

// Obtener todos los perfiles (empleados)
$profiles = Profile::getProfiles(Context::getContext()->language->id);

foreach ($profiles as $profile) {
    $profileId = (int)$profile['id_profile'];
    
    // Verificar si ya existe el permiso
    $exists = Db::getInstance()->getValue('
        SELECT COUNT(*) 
        FROM ' . _DB_PREFIX_ . 'access 
        WHERE id_profile = ' . $profileId . ' 
        AND id_authorization_role = ' . $tabId
    );
    
    if ($exists) {
        echo "Perfil '" . $profile['name'] . "' (ID: $profileId) ya tiene permisos.\n";
        continue;
    }
    
    // Insertar permisos completos (view=1, add=1, edit=1, delete=1)
    $sql = 'INSERT INTO ' . _DB_PREFIX_ . 'access 
            (id_profile, id_authorization_role) 
            VALUES (' . $profileId . ', ' . $tabId . ')';
    
    if (Db::getInstance()->execute($sql)) {
        echo "✓ Permisos asignados a perfil '" . $profile['name'] . "' (ID: $profileId)\n";
    } else {
        echo "✗ Error asignando permisos a perfil '" . $profile['name'] . "'\n";
    }
}

echo "\n=== Proceso completado ===\n";
echo "Ahora limpia la caché y recarga el back-office.\n";
