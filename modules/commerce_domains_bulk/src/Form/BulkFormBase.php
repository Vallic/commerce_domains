<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_bulk\Form;

use Drupal\commerce_domains_bulk\BulkEditor;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Base for the forms of the bulk actions: options, then a batch.
 *
 * The action keeps the selection in the private temp store; the form reads
 * it, and changes the domains in batches.
 *
 * @phpstan-consistent-constructor
 */
abstract class BulkFormBase extends ConfirmFormBase {

  /**
   * The selected domains, keyed by product ID.
   *
   * @var \Drupal\commerce_product\Entity\ProductInterface[]
   */
  protected array $products = [];

  public function __construct(
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RouteProviderInterface $routeProvider,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('tempstore.private'),
      $container->get('entity_type.manager'),
      $container->get('router.route_provider'),
    );
  }

  /**
   * Returns the ID of the action whose selection the form changes.
   */
  abstract protected function actionId(): string;

  /**
   * Returns the edit to apply; one of the BulkEditor constants.
   */
  abstract protected function edit(): string;

  /**
   * Returns the edit's options from the submitted form.
   */
  abstract protected function options(FormStateInterface $form_state): array;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_domains_bulk_' . $this->edit();
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    // The Domains overview comes with Commerce Domains Marketplace and Views.
    $route = 'view.commerce_domains_overview.page_1';
    return Url::fromRoute($this->routeProvider->getRoutesByNames([$route]) ? $route : 'entity.commerce_product.collection');
  }

  /**
   * Builds the form for the selection.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   The form, or a redirect back when nothing is selected.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $ids = $this->tempStore()->get($this->actionId()) ?: [];
    /** @var \Drupal\commerce_product\Entity\ProductInterface[] $products */
    $products = $ids ? $this->entityTypeManager->getStorage('commerce_product')->loadMultiple($ids) : [];
    $this->products = $products;
    if (!$this->products) {
      return new RedirectResponse($this->getCancelUrl()->setAbsolute()->toString());
    }
    $form['domains'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Selected domains'),
      '#items' => array_map(fn($product) => $product->label(), array_values($this->products)),
      '#weight' => -10,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Changing the selected domains'))
      ->setFinishCallback([BulkEditor::class, 'batchFinished']);
    $options = $this->options($form_state);
    foreach (array_chunk(array_keys($this->products), BulkEditor::BATCH_SIZE) as $ids) {
      $batch->addOperation([BulkEditor::class, 'batchOperation'], [$this->edit(), $ids, $options]);
    }
    batch_set($batch->toArray());
    $this->tempStore()->delete($this->actionId());
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * Returns the temp store the actions keep the selection in.
   */
  protected function tempStore(): PrivateTempStore {
    return $this->tempStoreFactory->get(BulkEditor::TEMPSTORE);
  }

}
