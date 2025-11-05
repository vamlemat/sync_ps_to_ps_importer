<?php
/**
 * Módulo: Sincronizador PS a PS
 * Descripción: Sincroniza productos entre dos tiendas PrestaShop
 * Autor: Atech
 * Versión: 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Autoloader para las clases del módulo - Registrar ANTES de cualquier cosa
spl_autoload_register(function ($class) {
    // Solo cargar clases de nuestro namespace
    if (strpos($class, 'SyncPsToPsImporter\\') !== 0) {
        return;
    }
    
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen('SyncPsToPsImporter\\'))) . '.php';
    
    if (file_exists($file)) {
        require_once $file;
    }
}, true, true); // Prepend = true para que se ejecute primero

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
        $this->bootstrap = true;
        
        parent::__construct();
        
        $this->displayName = $this->l('Sincronizador PS a PS');
        $this->description = $this->l('Sincroniza productos entre dos tiendas PrestaShop mediante API/Webservice');
        $this->confirmUninstall = $this->l('¿Está seguro de que desea desinstalar este módulo?');
    }

    /**
     * Instalación del módulo
     */
    public function install()
    {
        return parent::install() 
            && $this->installTab();
    }

    /**
     * Desinstalación del módulo
     */
    public function uninstall()
    {
        return $this->uninstallTab() 
            && parent::uninstall();
    }

    /**
     * Crear el tab en el menú de administración
     */
    private function installTab()
    {
        // Primero verificar si ya existe
        $id_tab = (int)Tab::getIdFromClassName('AdminSyncPsToPsImporter');
        if ($id_tab) {
            return true;
        }
        
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminSyncPsToPsImporter';
        $tab->route_name = 'admin_sync_ps_to_ps_importer_panel';
        $tab->name = [];
        
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'Sincronizador PS';
        }
        
        // Ubicar en el menú Catálogo
        $tab->id_parent = (int)Tab::getIdFromClassName('AdminCatalog');
        $tab->module = $this->name;
        $tab->icon = 'sync';
        
        $result = $tab->add();
        
        // Forzar limpieza de caché de tabs
        if ($result) {
            Tools::clearCache();
        }
        
        return $result;
    }

    /**
     * Eliminar el tab del menú
     */
    private function uninstallTab()
    {
        $id_tab = (int)Tab::getIdFromClassName('AdminSyncPsToPsImporter');
        
        if (!$id_tab) {
            return true;
        }

        $tab = new Tab($id_tab);
        $result = $tab->delete();
        
        if ($result) {
            Tools::clearCache();
        }
        
        return $result;
    }

    /**
     * Página de configuración del módulo
     */
    public function getContent()
    {
        $output = '';
        
        if (Tools::isSubmit('submit' . $this->name)) {
            $apiUrl = Tools::getValue('SYNC_PS_REMOTE_URL');
            $apiKey = Tools::getValue('SYNC_PS_API_KEY');
            
            // Validar URL
            if (!empty($apiUrl) && !filter_var($apiUrl, FILTER_VALIDATE_URL)) {
                $output .= $this->displayError($this->l('La URL no es válida'));
            } else {
                // Guardar configuración
                Configuration::updateValue('SYNC_PS_REMOTE_URL', rtrim($apiUrl, '/'));
                Configuration::updateValue('SYNC_PS_API_KEY', $apiKey);
                Configuration::updateValue('SYNC_PS_CUSTOM_IP', Tools::getValue('SYNC_PS_CUSTOM_IP'));
                
                $output .= $this->displayConfirmation($this->l('Configuración guardada correctamente'));
                
                // Intentar probar la conexión
                if (!empty($apiUrl) && !empty($apiKey)) {
                    require_once __DIR__ . '/src/Service/PrestaShopApiService.php';
                    try {
                        $apiService = new \SyncPsToPsImporter\Service\PrestaShopApiService($apiUrl, $apiKey);
                        
                        // Configurar IP personalizada si está establecida
                        $customIp = Tools::getValue('SYNC_PS_CUSTOM_IP');
                        if (!empty($customIp)) {
                            $apiService->setCustomIp($customIp);
                        }
                        
                        $testResult = $apiService->testConnection();
                        
                        if ($testResult['success']) {
                            $output .= $this->displayConfirmation($this->l('✓ Conexión exitosa con la tienda remota'));
                        } else {
                            $output .= $this->displayWarning($this->l('⚠ Configuración guardada pero no se pudo conectar: ') . $testResult['message']);
                        }
                    } catch (\Exception $e) {
                        $output .= $this->displayWarning($this->l('⚠ Configuración guardada pero error de conexión: ') . $e->getMessage());
                    }
                }
            }
        }
        
        return $output . $this->renderForm();
    }

    /**
     * Formulario de configuración
     */
    protected function renderForm()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submit' . $this->name;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$this->getConfigForm()]);
    }

    /**
     * Estructura del formulario de configuración
     */
    protected function getConfigForm()
    {
        return [
            'form' => [
                'legend' => [
                    'title' => $this->l('Configuración de conexión con tienda origen'),
                    'icon' => 'icon-cogs',
                ],
                'description' => $this->l('Configura la conexión con la tienda PrestaShop de la cual quieres importar productos.'),
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('URL de la tienda origen'),
                        'name' => 'SYNC_PS_REMOTE_URL',
                        'desc' => $this->l('URL completa de la tienda origen (ejemplo: https://mitienda.com). Sin barra al final.'),
                        'required' => true,
                        'placeholder' => 'https://tienda-origen.com',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('API Key del Webservice'),
                        'name' => 'SYNC_PS_API_KEY',
                        'desc' => $this->l('Clave de API del webservice de PrestaShop. Puedes crearla en Configuración Avanzada → Webservice de la tienda origen.'),
                        'required' => true,
                        'placeholder' => 'ABCDEFGH1234567890IJKLMNOPQRSTUV',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('IP Personalizada (Opcional)'),
                        'name' => 'SYNC_PS_CUSTOM_IP',
                        'desc' => $this->l('Solo necesario si el dominio es interno/privado y el servidor no puede resolverlo. Introduce la IP del servidor origen (ejemplo: 192.168.1.100)'),
                        'required' => false,
                        'placeholder' => '192.168.1.100 o 123.456.789.123',
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Guardar y probar conexión'),
                ],
            ],
        ];
    }

    /**
     * Valores de configuración
     */
    protected function getConfigFormValues()
    {
        return [
            'SYNC_PS_REMOTE_URL' => Configuration::get('SYNC_PS_REMOTE_URL', ''),
            'SYNC_PS_API_KEY' => Configuration::get('SYNC_PS_API_KEY', ''),
            'SYNC_PS_CUSTOM_IP' => Configuration::get('SYNC_PS_CUSTOM_IP', ''),
        ];
    }
}
