<?php

namespace Drupal\cohesion\Controller;

use Drupal\cohesion\CohesionJsonResponse;
use Drupal\cohesion_elements\Entity\CohesionLayout;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Json;
use Drupal\content_moderation\ModerationInformation;
use Drupal\content_moderation\StateTransitionValidationInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\RevisionableEntityBundleInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\jsonapi\Exception\UnprocessableHttpEntityException;
use Drupal\system\SystemManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class CohesionController.
 *
 * Controller routines for Site Studio admin index page.
 *
 * @package Drupal\cohesion\Controller
 */
class CohesionController extends ControllerBase {

  /**
   * @var \Drupal\system\SystemManager
   */
  protected $systemManager;

  /**
   * @var mixed
   */
  protected $file_name;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

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
   * CohesionController constructor.
   *
   * @param \Drupal\system\SystemManager $systemManager
   *
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The current user account.
   * @param \Drupal\content_moderation\ModerationInformation $moderation_information
   *   The moderation information service.
   *
   * @param \Drupal\content_moderation\StateTransitionValidationInterface $validator
   *   Moderation state transition validation service.
   */
  public function __construct(SystemManager $systemManager, TimeInterface $time, AccountInterface $user, ModerationInformation $moderation_information, StateTransitionValidationInterface $validator) {
    $this->systemManager = $systemManager;
    $file_name = \Drupal::request()->query->get('file_name');
    $this->file_name = $file_name;
    $this->time = $time;
    $this->user = $user;
    $this->moderationInformation = $moderation_information;
    $this->validator = $validator;
  }

  /**
   * The admin landing page (admin/cohesion).
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   Controller's container.
   *
   * @return static
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('system.manager'),
      $container->get('datetime.time'),
      $container->get('current_user'),
      $container->get('content_moderation.moderation_information'),
      $container->get('content_moderation.state_transition_validation')
    );
  }

  /**
   * Constructs a page with placeholder content.
   *
   * @return array
   */
  public function index() {
    return $this->systemManager->getBlockContents();
  }

  /**
   * Get an array of the available cohesion entity types.
   *
   * @return array
   */
  public static function getCohesionEnityTypes() {
    $results = [];
    foreach (\Drupal::service('entity_type.manager')->getDefinitions() as $value) {
      /** @var EntityTypeInterface $value */
      if ($value->entityClassImplements('\Drupal\cohesion\Entity\CohesionSettingsInterface')) {
        $results[$value->get('id')] = $value->getLabel()->render();
      }
    }
    return $results;
  }

  /**
   * Log JS errors to Drupal DB logs.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *
   * @return \Drupal\cohesion\CohesionJsonResponse
   */
  public static function errorLogger(Request $request) {
    if (($error_data = Json::decode($request->getContent())) && isset($error_data['message'])) {
      \Drupal::service('settings.endpoint.utils')->logError($error_data['message']);
    }
    return new CohesionJsonResponse([]);
  }

  /**
   * Save a layout canvas from the frontend editor
   *
   * returns a list of worklow state that this entity can now transition to (if applicable)
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *
   * @return \Drupal\cohesion\CohesionJsonResponse
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   *   Thrown if the entity type doesn't exist.
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   *   Thrown if the storage handler couldn't be loaded.
   *
   */
  public function saveFrontendBuilder(Request $request) {
    $content = $request->getContent();

    $data = json_decode($content);
    if(!$data || !property_exists($data, 'canvases') || !is_object($data->canvases)) {
      return new CohesionJsonResponse([
        'data' => $this->t('Missing data canvases')
      ], 400);
    }

    // The entity that holds the layout canvas
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = NULL;
    foreach ($data->canvases as $canvas_name => $canvas_data) {
      $layout_canvas_id = str_replace('cohcanvas-', '', $canvas_name);
      /** @var CohesionLayout $layout_canvas */
      // Load the layout canvas and set the new json values to be saved
      $layout_canvas = CohesionLayout::load($layout_canvas_id);
      if(!$layout_canvas) {
        return new CohesionJsonResponse([
          'data' => $this->t('Cannot find entity Layout canvas with id: @id', [
            '@id' => $layout_canvas_id
          ])
        ], 400);
      }

      // Make sure to load the correct translation
      $current_lang = $this->languageManager()->getCurrentLanguage()->getId();
      if($layout_canvas->hasTranslation($current_lang)) {
        $layout_canvas = $layout_canvas->getTranslation($current_lang);
      }else {
        return new CohesionJsonResponse([
          'data' => $this->t('Cannot find tranlaste for langauge %lang for Layout canvas entity id: @id', [
            '@lang' => $current_lang,
            '@id' => $layout_canvas_id
          ])
        ], 400);
      }
      
      $layout_canvas->setJsonValue(json_encode($canvas_data));
      $layout_canvas->setNeedsSave(TRUE);

      // Check that the json is valid
      if($errors = $layout_canvas->jsonValuesErrors()) {
        return new CohesionJsonResponse([
          'data' => $errors['error']
        ], 400);
      }

      $parent_field_name = $layout_canvas->get('parent_field_name')->get(0)->getValue()['value'];
      // Load the entity that has already been update if it exists
      if($entity == NULL) {
        $entity = $layout_canvas->getParentEntity();
      } elseif ($entity !== $layout_canvas->getParentEntity()) {
        // Only process one entity so ignore any layout canvas that doesn't
        // belong to the first entity found.
        continue;
      }

      $entity->set($parent_field_name, ['entity' => $layout_canvas]);

      // Set revision data details for revisionable entities.
      if ($entity->getEntityType()->isRevisionable()) {
        if ($bundle_entity_type = $entity->getEntityType()
          ->getBundleEntityType()) {
          $bundle_entity = $this->entityTypeManager()
            ->getStorage($bundle_entity_type)
            ->load($entity->bundle());
          if ($bundle_entity instanceof RevisionableEntityBundleInterface) {
            $entity->setNewRevision($bundle_entity->shouldCreateNewRevision());
          }
        }
        if ($entity instanceof RevisionLogInterface && $entity->isNewRevision()) {
          $entity->setRevisionUserId($this->user->id());
          $entity->setRevisionCreationTime($this->time->getRequestTime());
        }
      }

      // Set the moderation state or published state for the entity
      if(property_exists($data, 'moderationState')){
        $published_state = $data->moderationState;
        if ($this->moderationInformation->isModeratedEntity($entity)) {
          if($this->moderationInformation->getWorkflowForEntity($entity)->getTypePlugin()->hasState($published_state)) {
            $entity->set('moderation_state', $published_state);
          }
        } else {
          if($published_state == 'published') {
            $entity->setPublished();
          }elseif($published_state == 'unpublished') {
            $entity->setUnpublished();
          }
        }
      }
    }

    if($entity) {
      $transition_labels = [];
      $default_value = NULL;
      $entity->save();
      // Get the next transition states if moderated entity
      if($this->moderationInformation->isModeratedEntity($entity)) {
        // Get the states the entity can transition into and the current state
        $default = $this->moderationInformation->getOriginalState($entity);
        $transitions = $this->validator->getValidTransitions($entity, $this->user);

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

        if($entity->isPublished()) {
          $transition_labels['published']['selected'] = TRUE;
        }else {
          $transition_labels['unpublished']['selected'] = TRUE;
        }
      }
    }else{
      return new CohesionJsonResponse([
        'data' => 'An error occurred, the entity for the layout canvas can\'t be found '
      ], 400);
    }


    return new CohesionJsonResponse([
      'data' => [
        'moderationStates' => array_values($transition_labels),
      ]
    ]);
  }

}
