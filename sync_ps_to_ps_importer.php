<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Module\WidgetInterface;

class Sync_Ps_To_Ps_Importer extends Module
{
    public function __construct()
    {
        $this->name = 'sync_ps_to_ps_importer';
        $this->tab = 'migration_tools';
        $this->version = '1.0.0';
        $this->author = 'Atech';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '8.0.0',
            'max' => _PS_VERSION_,
        ];
        
        parent::__construct();
        
        $this->displayName = $this->l('Sincronizador PS a PS');
        $this->description = $this->l('Módulo para importar productos bajo demanda desde otra tienda PrestaShop.');
    }

    /**
     * Esta función se ejecuta al INSTALAR el módulo
     */
    public function install(): bool
    {
        if (parent::install() === false) {
            return false;
        }
        
        return $this->installTab();
    }

    /**
     * Esta función se ejecuta al DESINSTALAR el módulo
     */
    public function uninstall(): bool
    {
        if (!$this->uninstallTab()) {
            return false;
        }

        return parent::uninstall();
    }

    /**
     * Función para AÑADIR el enlace al menú (Tab)
     */
    public function installTab(): bool
    {
        $tab = new Tab();
        $tab->active = 1;
        
        // El nombre que se verá en el menú
        $tab->name = [];
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'Sincronizador PS a PS';
        }
        
        // El padre - "Catálogo"
        $tab->id_parent = (int)Tab::getIdFromClassName('AdminCatalog');
        $tab->module = $this->name;
        
        // Nombre de clase único (requerido por PrestaShop)
        $tab->class_name = 'AdminSyncPsToPsImporter';
        
        // Ruta de Symfony
        $tab->route_name = 'admin_sync_ps_to_ps_importer_panel'; 
        
        return $tab->add();
    }

    /**
     * Función para BORRAR el enlace al menú (Tab)
     */
    public function uninstallTab(): bool
    {
        // Buscamos el tab por class_name
        $id_tab = (int)Tab::getIdFromClassName('AdminSyncPsToPsImporter');
        
        if (!$id_tab) {
            return true;
        }

        $tab = new Tab($id_tab);
        
        return $tab->delete();
    }
}
