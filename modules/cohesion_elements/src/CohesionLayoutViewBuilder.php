<?php

namespace Drupal\cohesion_elements;

use Drupal\content_moderation\ModerationInformation;
use Drupal\content_moderation\StateTransitionValidationInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Theme\Registry;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class CohesionLayoutViewBuilder.
 *
 * Render controller for cohesion_layout.
 *
 * @package Drupal\cohesion_elements
 */
class CohesionLayoutViewBuilder extends EntityViewBuilder {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * The current user account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $user;

  /**
   * The moderation information service.
   *
   * @var \Drupal\content_moderation\ModerationInformation
   */
  protected $moderationInformation;

  /**
   * Moderation state transition validation service.
   *
   * @var \Drupal\content_moderation\StateTransitionValidationInterface
   */
  protected $validator;

  /**
   * Constructs a new EntityViewBuilder.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Theme\Registry $theme_registry
   *   The theme registry.
   * @param \Drupal\Core\Entity\EntityDisplayRepositoryInterface $entity_display_repository
   *   The entity display repository.
   * * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The current user account.
   * @param \Drupal\content_moderation\ModerationInformation $moderation_information
   *   The moderation information service.
   *
   * @param \Drupal\content_moderation\StateTransitionValidationInterface $validator
   *   Moderation state transition validation service.
   */
  public function __construct(EntityTypeInterface $entity_type, EntityRepositoryInterface $entity_repository, LanguageManagerInterface $language_manager, Registry $theme_registry = NULL, EntityDisplayRepositoryInterface $entity_display_repository = NULL, EntityTypeManagerInterface $entity_type_manager, AccountInterface $user, ModerationInformation $moderation_information, StateTransitionValidationInterface $validator) {
    parent::__construct($entity_type, $entity_repository, $language_manager, $theme_registry, $entity_display_repository);
    $this->entityTypeManager = $entity_type_manager;
    $this->user = $user;
    $this->moderationInformation = $moderation_information;
    $this->validator = $validator;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity.repository'),
      $container->get('language_manager'),
      $container->get('theme.registry'),
      $container->get('entity_display.repository'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('content_moderation.moderation_information'),
      $container->get('content_moderation.state_transition_validation')
    );
  }

  /**
   * {@inheritdoc}
   *
   *  * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   *   Thrown if the entity type doesn't exist.
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   *   Thrown if the storage handler couldn't be loaded.
   */
  public function view(EntityInterface $entity, $view_mode = 'full', $langcode = NULL) {
    /** @var EntityInterface $host */
    $host = $entity->getParentEntity();
    $entities = [];
    $cache_tags = [];
    if ($host) {
      $token_type = \Drupal::service('token.entity_mapper')->getTokenTypeForEntityType($host->getEntityTypeId(), $host->getEntityTypeId());
      $entities[$token_type] = $host;

      $cache_tags[] = 'layout_formatter.' . $host->uuid();
    }

    $cacheContexts = \Drupal::service('cohesion_templates.cache_contexts');

    // Set up some variables.
    $variables = $entities;
    $variables['layout_builder_entity'] = [
      'entity' => $entity,
      'entity_type_id' => $entity->getEntityTypeId(),
      'id' => $entity->id(),
      'revision_id' => $entity->getRevisionId(),
    ];

    // Tell the field to render as a "cohesion_layout".
    $build = [
      '#type' => 'inline_template',
      '#template' => $entity->getTwig(),
      '#context' => $variables,
      '#cache' => [
        'contexts' => $cacheContexts->getFromContextName($entity->getTwigContexts()),
        'tags' => $cache_tags,
      ],
    ];

    $content = '<style>' . $entity->getStyles() . '</style>';
    $build['#attached'] = ['cohesion' => [$content]];

    foreach (\Drupal::routeMatch()->getParameters() as $param_key => $param) {


      if ($param instanceof ContentEntityInterface && $host->getEntityTypeId() == $param->getEntityTypeId() && $host->id() == $param->id() && $host->access('edit') && isset($entity->_referringItem)) {
        // Get the latest layout canvas and pass it to drupalSettings
        $latest_entity = $entity;
        if(!$entity->isLatestRevision()) {
          /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
          $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
          $latest_revision_id = $storage->getLatestRevisionId($entity->id());
          $latest_entity = $storage->loadRevision($latest_revision_id);
        }
        $build['#attached']['drupalSettings']['cohesion']['cohCanvases']['cohcanvas-' . $entity->id()] = json_decode($latest_entity->getJsonValues());

        // Get the latest host and pass the next states it can transition into to drupalSettings
        $latest_host = $host;
        if(!$host->isLatestRevision()) {
          /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
          $storage = $this->entityTypeManager->getStorage($host->getEntityTypeId());
          $latest_revision_id = $storage->getLatestRevisionId($host->id());
          $latest_host = $storage->loadRevision($latest_revision_id);
          $build['#attached']['drupalSettings']['cohesion']['isLatest'] = FALSE;
        }

        $transition_labels = [];
        if($this->moderationInformation->isModeratedEntity($latest_host)) {
          // Get the states the entity can transition into and the current state
          $default = $this->moderationInformation->getOriginalState($latest_host);
          $transitions = $this->validator->getValidTransitions($latest_host, $this->user);

          foreach ($transitions as $transition) {
            $transition_to_state = $transition->to();
            $transition_labels[$transition_to_state->id()] = [
              'state' => $transition_to_state->id(),
              'label' => $transition_to_state->label(),
            ];

            if ($default->id() === $transition_to_state->id()) {
              $transition_labels[$transition_to_state->id()]['selected'] = TRUE;
            }
          }
        }else {
          $transition_labels['published'] = [
            'state' => 'published',
            'label' => $this->t('Published'),
          ];
          $transition_labels['unpublished'] = [
            'state' => 'unpublished',
            'label' => $this->t('Unpublished'),
          ];

          if($latest_host->isPublished()) {
            $transition_labels['published']['selected'] = TRUE;
          }else {
            $transition_labels['unpublished']['selected'] = TRUE;
          }
        }

        $build['#attached']['drupalSettings']['cohesion']['moderationStates'] = array_values($transition_labels);
        $build['#attached']['library'][] = 'cohesion/cohesion-frontend-edit-scripts';
      }
    }

    return $build;
  }

}
