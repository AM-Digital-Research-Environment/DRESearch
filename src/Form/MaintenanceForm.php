<?php
declare(strict_types=1);

namespace DRESearch\Form;

use Laminas\Form\Form;

/**
 * CSRF-only form guarding the "Reindex now" POST on the Maintenance page.
 *
 * We deliberately add no CSRF element of our own. Omeka's
 * {@see \Omeka\Form\Initializer\Csrf} runs on every form built through the
 * FormElementManager and injects a 'csrf' element automatically. Adding a
 * second, custom CSRF element here caused the reindex POST to always fail
 * validation: the injected 'csrf' element was never rendered by the view (the
 * template only emitted our custom field), so it arrived empty and
 * isValid() reported "Value is required". The view now renders
 * $form->get('csrf').
 */
class MaintenanceForm extends Form
{
}
