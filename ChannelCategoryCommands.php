<?php

namespace Drupal\custom_entity\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Update Channel/Category commands.
 */
class ChannelCategoryCommands extends DrushCommands {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Constructs a ChannelCategoryCommands object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The Entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger service.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, LoggerChannelFactoryInterface $logger_factory) {
    $this->entityTypeManager = $entityTypeManager;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // @phpstan-ignore-next-line
    return new static(
      $container->get('entity_type.manager'),
      $container->get('logger.factory'),
    );
  }

  /**
   * Update Channel/Category data from third party application.
   *
   * @param array $options
   *   Command line options.
   *
   * @command sync:channel_category
   * @aliases sync:cc
   * @option batch_size The number of entities to process in each batch. Defaults to 25.
   * @usage drush sync:channel_category --batch_size=2
   */
  public function syncChannelCategory(array $options = ['batch_size' => 25]) {

    $storage = $this->entityTypeManager->getStorage('custom_entity');
    $entity_exist = (bool) $storage->getQuery()->condition('status', 1)->accessCheck(FALSE)->range(0, 1)->execute();
    $base_url = getenv('THIRD_PARTY_BASE_URL') ?? '';
    if (!$entity_exist) {
      $this->loggerFactory->get('custom_entity_drush')->info('No published entity exists.');
      $this->output()->writeln('No published entity exists.');
      return;
    }
    elseif (empty($base_url)) {
      $this->loggerFactory->get('custom_entity_drush')->info('Third Party base url is missing.');
      $this->output()->writeln('Third Party base url is missing.');
      return;
    }

    $init_message = 'Update Channel/Category started.';
    $stop_message = 'Update Channel/Category Completed.';
    $total = $storage->getQuery()->condition('status', 1)->accessCheck(FALSE)->count()->execute();
    $batch = [
      'title' => 'Synchronizing channel and category values...',
      'operations' => [
        [
          [self::class, 'processBatch'],
          [$options['batch_size'], $base_url, $total],
        ],
      ],
      'finished' => [self::class, 'batchFinished'],
      'init_message' => $init_message,
      'stop_message' => $stop_message,
    ];
    $this->loggerFactory->get('custom_entity_drush')->info($init_message);
    $this->output()->writeln($init_message);
    batch_set($batch);
    drush_backend_batch_process();
    $this->loggerFactory->get('custom_entity_drush')->info($stop_message);
    $this->output()->writeln($stop_message);

  }

  /**
   * Method to processBatch.
   *
   * @param int $batch_size
   *   The batch size.
   * @param string $base_url
   *   The base url of api.
   * @param int $max
   *   The number of published entities.
   * @param array $context
   *   Stores all the information until operation ended.
   */
  public static function processBatch($batch_size, $base_url, $max, &$context) {
    $storage = \Drupal::entityTypeManager()->getStorage('custom_entity');
    $logger = \Drupal::logger('custom_entity');
    if (empty($context['sandbox'])) {
      $context['sandbox'] = [
        'progress' => 0,
        'batch_processed' => 0,
        'iterations' => abs(ceil($max / $batch_size)),
        'max' => $max,
      ];
    }

    // Fetch the next batch of entity IDs to process.
    $entity_ids = $storage->getQuery()
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->range($context['sandbox']['batch_processed'] * $batch_size, $batch_size)
      ->accessCheck(FALSE)
      ->execute();

    foreach ($entity_ids as $entity_id) {
      try {
        $entity = $storage->load($entity_id);
        if (!$entity) {
          $logger->info('Unable to load the entity id: @entity_id.',
            ['@entity_id' => $entity_id]
          );
          continue;
        }

        $value = $entity->channel->value ?? $entity->category->value;
        $matches = self::parseValue($value);
        if (!$matches) {
          $logger->error('Failed to parse UUID from value: @value for the entity id: @entity_id',
            [
              '@value' => $value,
              '@entity_id' => $entity_id,
            ]
          );
          $context['sandbox']['progress']++;
          $context['results']['unpublished'][] = $entity_id;
          continue;
        }

       // $field_name = $entity->channel->value ? 'channels' : 'categories';
        $field = $entity->channel->value ? 'channel' : 'category';
        $response = self::fetchApiResponse($base_url, $matches[2], $field);
        self::processApiResponse($entity, $field, $matches, $response, $context);
      }
      catch (\Exception $e) {
        $logger->error('Error processing entity ID @id: @message', [
          '@id' => $entity_id,
          '@message' => $e->getMessage(),
        ]);
      }

      $context['sandbox']['progress']++;
    }
    $context['sandbox']['batch_processed']++;
    $context['message'] = 'Processed ' . $context['sandbox']['progress'] . ' of ' . $context['sandbox']['max'];
    $context['finished'] = $context['sandbox']['batch_processed'] / $context['sandbox']['iterations'];
  }

  /**
   * Method to parseValue id and name.
   *
   * @param string $value
   *   The topic or entity value.
   *
   * @return array
   *   array of id and name.
   */
  private static function parseValue($value) {
    if (preg_match('/^(.*?)\s*\(id:\s*([0-9a-fA-F\-]+)\)$/', $value, $matches)) {
      return $matches;
    }
    return NULL;
  }

  /**
   * Method to fetch the Api Response.
   *
   * @param string $base_url
   *   The base url of api.
   * @param string $uuid
   *   The uuid value.
   * @param string $field
   *   The field name to add in url.
   *
   * @return array
   *   The response array.
   */
  private static function fetchApiResponse($base_url, $uuid, $field) {
    if (!$uuid) {
      throw new \InvalidArgumentException('Invalid UUID provided.');
    }

    $url = $base_url . '/api/' . $field . '/' . $uuid;
    return \Drupal::service('custom.service')->handlePlatformApiRequest($url);
  }

  /**
   * Method to process the Api Response.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to process with the API response.
   * @param string $field
   *   The field name to update.
   * @param array $matches
   *   The array with name and id.
   * @param array $response
   *   The API response array.
   * @param array $context
   *   Stores all the information until operation ended.
   */
  private static function processApiResponse($entity, $field, $matches, $response, &$context) {
    if (!$response || !isset($response['name'], $response['id'])) {
      $context['results']['unpublished'][] = $entity->id();
    }
    elseif ($matches[1] !== $response['name']) {
      $entity->$field->value = sprintf('%s (id: %s)', $response['name'], $response['id']);
      $entity->save();
      $context['results']['updated'][] = $entity->id();
    }
  }

  /**
   * Callback method to batchFinished.
   *
   * @param bool $success
   *   TRUE if all batch API tasks were completed successfully.
   * @param array $results
   *   An array of results from batch operation.
   */
  public static function batchFinished($success, $results) {
    $logger = \Drupal::logger('custom_entity');

    if ($success) {
      $logger->info('Channel/Category updates batch completed.');

      if (!empty($results['unpublished'])) {
        $entities = \Drupal::entityTypeManager()->getStorage('custom_entity')->loadMultiple($results['unpublished']);
        foreach ($entities as $entity) {
          if ($entity) {
            $entity->set('status', FALSE);
            $entity->save();
          }
        }
        $logger->info('Unpublished entities: @ids', ['@ids' => implode(', ', $results['unpublished'])]);
      }

      if (!empty($results['updated'])) {
        $logger->info('Updated entity ids: @ids', ['@ids' => implode(', ', $results['updated'])]);
      }
    }
    else {
      $logger->error('An error occurred during the batch processing.');
    }
  }

}
