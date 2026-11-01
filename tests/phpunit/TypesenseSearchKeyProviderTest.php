<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Search\TypesenseSearchKeyProvider;
use Omeka\Settings\SettingsInterface;
use PHPUnit\Framework\TestCase;

/** Which key public search runs with, without a Typesense to ask (see TypesenseIntegrationTest). */
final class TypesenseSearchKeyProviderTest extends TestCase
{
    private const ALIASES = ['dre_research_current', 'dre_people_current'];

    protected function setUp(): void
    {
        if (!interface_exists(SettingsInterface::class)) {
            self::markTestSkipped('Requires Omeka core.');
        }
    }

    public function testAKeyIsMintedOnceForTheAliasesAndReusedFromItsSlot(): void
    {
        $keys = $this->keys();
        $settings = $this->settings();
        $provider = $this->provider($keys, $settings);

        self::assertSame('minted-1', $provider->key());
        self::assertSame('minted-1', $provider->key(), 'Resolved once per request.');
        self::assertCount(1, $keys->created);
        self::assertSame([
            'description' => TypesenseSearchKeyProvider::DESCRIPTION,
            'actions' => ['documents:search'],
            'collections' => ['^dre_people_current$', '^dre_research_current$'],
        ], $keys->created[0]);
        self::assertSame('minted-1', $settings->get($provider->slot()));

        // The next request finds it in its slot.
        self::assertSame('minted-1', $this->provider($keys, $settings)->key());
        self::assertCount(1, $keys->created);
    }

    public function testTheSlotIsNamedAfterTheScope(): void
    {
        $settings = $this->settings();
        $a = $this->provider($this->keys(), $settings);
        $b = $this->provider($this->keys(), $settings, ['dre_research_current']);
        self::assertStringStartsWith(TypesenseSearchKeyProvider::SLOT_PREFIX, $a->slot());
        self::assertNotSame($a->slot(), $b->slot(), 'A changed alias list mints its own key.');
        self::assertSame($a->slot(), $this->provider($this->keys(), $settings, array_reverse(self::ALIASES))->slot(), 'Order is not scope.');
    }

    public function testAnOperatorKeyWinsAndNothingIsMinted(): void
    {
        $keys = $this->keys();
        $provider = $this->provider($keys, $this->settings(), self::ALIASES, 'operator-key');
        self::assertSame('operator-key', $provider->key());
        self::assertSame([], $keys->created);
    }

    public function testAFailedMintIsLoggedFallsBackAndWaitsBeforeTheNextAttempt(): void
    {
        $keys = $this->keys(new \RuntimeException('Forbidden - a valid `x-typesense-api-key` header must be sent.'));
        $settings = $this->settings();
        $writer = new \Laminas\Log\Writer\Mock();

        self::assertNull($this->provider($keys, $settings, self::ALIASES, '', $writer)->key(), 'The admin key serves.');
        self::assertCount(1, $keys->attempts);
        self::assertCount(1, $writer->events);
        $failure = $settings->get(TypesenseSearchKeyProvider::FAILURE);
        self::assertIsArray($failure);
        self::assertStringContainsString('Forbidden', $failure['message']);

        // Within the retry window, the next request does not ask again.
        self::assertNull($this->provider($keys, $settings)->key());
        self::assertCount(1, $keys->attempts);

        // After it, it does; a success clears the failure note.
        $settings->set(TypesenseSearchKeyProvider::FAILURE, ['at' => gmdate('Y-m-d H:i:s', time() - TypesenseSearchKeyProvider::RETRY_SECONDS - 1)] + $failure);
        $keys->failure = null;
        self::assertSame('minted-1', $this->provider($keys, $settings)->key());
        self::assertNull($settings->get(TypesenseSearchKeyProvider::FAILURE));
    }

    public function testARejectedMintedKeyIsForgottenAndMintedAgain(): void
    {
        $keys = $this->keys();
        $settings = $this->settings();
        $provider = $this->provider($keys, $settings);
        self::assertSame('minted-1', $provider->key());
        $provider->rejected();
        self::assertNull($settings->get($provider->slot()));
        self::assertSame('minted-2', $this->provider($keys, $settings)->key());

        // An operator's key is the operator's to fix.
        $operator = $this->provider($keys, $settings, self::ALIASES, 'operator-key');
        $operator->key();
        $operator->rejected();
        self::assertSame('operator-key', $operator->key());
    }

    public function testTheStatusSaysWhatTheKeyInUseMayDo(): void
    {
        $keys = $this->keys();
        $settings = $this->settings();
        $provider = $this->provider($keys, $settings);
        $keys->listed = [['id' => 7, 'value_prefix' => 'mint', 'description' => TypesenseSearchKeyProvider::DESCRIPTION,
            'actions' => ['documents:search'], 'collections' => ['^dre_people_current$', '^dre_research_current$']]];
        $status = $provider->status();
        self::assertSame('minted', $status['source']);
        self::assertSame(['documents:search'], $status['actions']);
        self::assertNull($status['problem']);

        $keys->listed = [['id' => 8, 'value_prefix' => 'oper', 'actions' => ['*'], 'collections' => ['*']]];
        self::assertSame('too_broad', $this->provider($keys, $settings, self::ALIASES, 'operator-key')->status()['problem']);

        // Typesense lost the minted key (a volume reset): forgotten, minted again next time.
        $keys->listed = [];
        self::assertSame('gone', $this->provider($keys, $settings)->status()['problem']);
        self::assertNull($settings->get($provider->slot()));

        $keys->failure = new \RuntimeException('down');
        $fallback = $this->provider($keys, $this->settings())->status();
        self::assertSame('admin', $fallback['source']);
        self::assertSame('down', $fallback['failure']['message'] ?? null);
    }

    public function testWithoutAnAliasThereIsNothingToScopeAKeyTo(): void
    {
        $keys = $this->keys();
        self::assertNull($this->provider($keys, $this->settings(), [])->key());
        self::assertSame([], $keys->attempts);
    }

    /**
     * @param list<string> $aliases
     */
    private function provider(
        FakeTypesenseKeys $keys,
        SettingsInterface $settings,
        array $aliases = self::ALIASES,
        string $configured = '',
        ?\Laminas\Log\Writer\WriterInterface $writer = null,
    ): TypesenseSearchKeyProvider {
        $logger = new \Laminas\Log\Logger();
        $logger->addWriter($writer ?? new \Laminas\Log\Writer\Noop());
        $client = (object) ['keys' => $keys];
        return new TypesenseSearchKeyProvider(static fn(): object => $client, $settings, $logger, $aliases, $configured);
    }

    private function keys(?\Throwable $failure = null): FakeTypesenseKeys
    {
        return new FakeTypesenseKeys($failure);
    }

    private function settings(): SettingsInterface
    {
        return new ArraySettings();
    }
}
