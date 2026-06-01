<?php
declare(strict_types=1);

namespace DRESearch\Form;

use Laminas\Form\Element\Csrf;
use Laminas\Form\Form;

/**
 * CSRF-only form guarding the "Reindex now" POST on the Maintenance page.
 */
class MaintenanceForm extends Form
{
    public function init(): void
    {
        $this->add([
            'name'    => 'dre_search_csrf',
            'type'    => Csrf::class,
            'options' => ['csrf_options' => ['timeout' => 3600]],
        ]);
    }
}
