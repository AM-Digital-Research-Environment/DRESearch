<?php
declare(strict_types=1);

namespace DRESearch\Service;

use DRESearch\Search\TypesenseClientProvider;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Omeka\Settings\Settings;
use Psr\Container\ContainerInterface;

/**
 * Resolves the Typesense connection from three layers, most specific first:
 *   1. Omeka settings  (admin → Modules → DRE Search → Configure)
 *   2. Environment vars (TYPESENSE_HOST / TYPESENSE_API_KEY / …) — handy in
 *      Docker, where the same key is already in the compose .env
 *   3. module.config.php defaults (host "typesense", port 8108, …)
 */
final class TypesenseClientProviderFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): TypesenseClientProvider
    {
        /** @var Settings $settings */
        $settings = $container->get('Omeka\Settings');
        $defaults = $container->get('Config')['dre_search']['typesense'] ?? [];

        $host = self::resolve($settings, 'dre_search_typesense_host', 'TYPESENSE_HOST', (string) ($defaults['host'] ?? ''));
        $port = (int) self::resolve($settings, 'dre_search_typesense_port', 'TYPESENSE_PORT', (string) ($defaults['port'] ?? 8108));
        $protocol = self::resolve($settings, 'dre_search_typesense_protocol', 'TYPESENSE_PROTOCOL', (string) ($defaults['protocol'] ?? 'http'));
        $apiKey = self::resolve($settings, 'dre_search_typesense_api_key', 'TYPESENSE_API_KEY', '');
        $collection = self::resolve($settings, 'dre_search_collection', 'TYPESENSE_COLLECTION', (string) ($defaults['collection'] ?? 'dre_research_current'));

        return new TypesenseClientProvider($host, $port, $protocol, $apiKey, $collection);
    }

    /** Non-empty setting wins, then a non-empty env var, then the default. */
    private static function resolve(Settings $settings, string $settingKey, string $envKey, string $default): string
    {
        $value = (string) $settings->get($settingKey, '');
        if ($value !== '') {
            return $value;
        }
        $env = getenv($envKey);
        if ($env !== false && $env !== '') {
            return (string) $env;
        }
        return $default;
    }
}
