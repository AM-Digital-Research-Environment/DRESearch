<?php
declare(strict_types=1);

namespace DRESearch\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

/**
 * Module configuration form: the Typesense connection. Rendered inside Omeka's
 * own module-config <form> (which supplies the CSRF token), so this adds only
 * the fields. Leaving host or API key blank disables search — the module then
 * no-ops gracefully.
 */
class ConfigForm extends Form
{
    public function init(): void
    {
        $this->add([
            'name'    => 'dre_search_typesense_host',
            'type'    => Element\Text::class,
            'options' => [
                'label' => 'Typesense host', // @translate
                'info'  => 'Hostname reachable from PHP (e.g. "typesense" inside Docker). Leave blank to disable search.', // @translate
            ],
            'attributes' => ['placeholder' => 'typesense'],
        ]);

        $this->add([
            'name'       => 'dre_search_typesense_port',
            'type'       => Element\Text::class,
            'options'    => ['label' => 'Typesense port'], // @translate
            'attributes' => ['placeholder' => '8108'],
        ]);

        $this->add([
            'name'    => 'dre_search_typesense_protocol',
            'type'    => Element\Select::class,
            'options' => [
                'label'         => 'Protocol', // @translate
                'value_options' => ['http' => 'http', 'https' => 'https'],
            ],
        ]);

        $this->add([
            'name'    => 'dre_search_typesense_api_key',
            'type'    => Element\Text::class,
            'options' => [
                'label' => 'Typesense API key', // @translate
                'info'  => 'Used server-side only — never sent to the browser. Needs collection create/import rights for reindexing.', // @translate
            ],
        ]);

        // Everything optional — a blank host simply disables search. Collection
        // aliases are per-profile config (dre_search.profiles), not set here.
        $inputFilter = $this->getInputFilter();
        foreach ([
            'dre_search_typesense_host',
            'dre_search_typesense_port',
            'dre_search_typesense_protocol',
            'dre_search_typesense_api_key',
        ] as $name) {
            $inputFilter->add(['name' => $name, 'required' => false]);
        }
    }
}
