<?php
// Ruta: modules/sync_ps_to_ps_importer/src/Controller/AdminImporterController.php
namespace SyncPsToPsImporter\Controller;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\Response;

class AdminImporterController extends FrameworkBundleAdminController
{
    /**
     * Esta es la funci贸n que se ejecuta cuando entras al panel
     */
    public function indexAction(): Response
    {
        // Esto le dice a PrestaShop que dibuje el archivo HTML (la plantilla)
        return $this->render(
            '@Modules/sync_ps_to_ps_importer/views/templates/admin/panel.html.twig'
        );
    }
}
