<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_marketplace\Kernel;

use Drupal\advancedqueue\Entity\Queue;
use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains_marketplace\EventSubscriber\CatalogSubscriber;
use Drupal\commerce_domains_marketplace\Hook\MarketplaceHooks;
use Drupal\commerce_domains_marketplace\MarketplaceSettings;
use Drupal\commerce_domains_marketplace\MarketplaceSync;
use Drupal\commerce_domains_marketplace\SyncScheduler;
use Drupal\commerce_price\Price;
use Drupal\commerce_pricelist\Entity\PriceList;
use Drupal\commerce_pricelist\Entity\PriceListInterface;
use Drupal\commerce_pricelist\Entity\PriceListItem;
use Drupal\commerce_pricelist\Entity\PriceListItemInterface;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests marketplaces priced from a price list.
 */
#[CoversClass(MarketplaceSettings::class)]
#[CoversClass(MarketplaceHooks::class)]
#[CoversClass(CatalogSubscriber::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class PriceListSyncTest extends MarketplaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['commerce_pricelist'];

  /**
   * A disabled list with a $1,500 price for repriced.com.
   *
   * @var \Drupal\commerce_pricelist\Entity\PriceListInterface
   */
  protected PriceListInterface $list;

  /**
   * The list's item.
   *
   * @var \Drupal\commerce_pricelist\Entity\PriceListItemInterface
   */
  protected PriceListItemInterface $item;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('commerce_pricelist');
    $this->installEntitySchema('commerce_pricelist_item');
    $variations = $this->createDomain('Repriced.com', 1000)->getVariations();
    $this->setListings(['repriced.com' => ['price' => 1000]]);

    $this->list = PriceList::create([
      'type' => 'commerce_product_variation',
      'name' => 'Marketplace prices',
      'stores' => [$this->store],
      'status' => FALSE,
    ]);
    $this->list->save();
    $this->item = PriceListItem::create([
      'type' => 'commerce_product_variation',
      'price_list_id' => $this->list->id(),
      'purchasable_entity' => reset($variations)->id(),
      'quantity' => 1,
      'price' => new Price('1500', 'USD'),
    ]);
    $this->item->save();
  }

  /**
   * Tests that a provider with the list gets its prices.
   */
  public function testPlan(): void {
    $sync = $this->container->get(MarketplaceSync::class);
    $provider = $this->createProvider('test');
    $this->assertSame(1, $sync->plan($provider)->unchanged, 'Without the setting, the variation price.');

    $provider->setThirdPartySetting('commerce_domains_marketplace', 'price_list', (string) $this->list->uuid())->save();
    $this->assertSame(['repriced.com' => ['price' => 1500]], $sync->plan($provider)->update);
  }

  /**
   * Tests that changing an item queues its domain.
   */
  public function testItemChangesQueue(): void {
    $this->createProvider('auto');
    $scheduler = $this->container->get(SyncScheduler::class);
    $scheduler->destruct();
    /** @var \Drupal\advancedqueue\Plugin\AdvancedQueue\Backend\Database $backend */
    $backend = Queue::load(SyncScheduler::QUEUE)->getBackend();
    $backend->deleteQueue();

    $this->item->setPrice(new Price('1600', 'USD'))->save();
    $scheduler->destruct();
    $job = $backend->claimJob();
    $this->assertNotNull($job);
    $this->assertSame(['repriced.com'], $job->getPayload()['names']);
  }

  /**
   * Tests choosing the list on the provider form.
   */
  public function testSettingsForm(): void {
    $provider = $this->createProvider('test', TRUE, FALSE);
    $form = $this->container->get('entity.form_builder')->getForm($provider, 'edit');
    $uuid = (string) $this->list->uuid();
    $options = array_map('strval', $form['marketplace']['price_list']['#options']);
    $this->assertSame(['' => '- None: the variation price -', $uuid => 'Marketplace prices (disabled)'], $options);

    $submit = function (string $price_list): void {
      $form_object = $this->entityTypeManager->getFormObject('commerce_domains_provider', 'edit')->setEntity(Provider::load('test'));
      $form_state = (new FormState())->setValues([
        'label' => 'Test',
        'status' => 1,
        'auto_sync' => NULL,
        'delete_unlisted' => 1,
        'price_list' => $price_list,
        'configuration' => ['price_factor' => '1', 'premium_price_factor' => '1.2'],
        'op' => 'Save',
      ]);
      $this->container->get('form_builder')->submitForm($form_object, $form_state);
      $this->assertSame([], $form_state->getErrors());
    };
    $submit($uuid);
    $this->assertSame($uuid, MarketplaceSettings::priceList(Provider::load('test')));
    $submit('');
    $this->assertNull(MarketplaceSettings::priceList(Provider::load('test')));
  }

}
