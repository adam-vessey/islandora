<?php

namespace Drupal\islandora;

use Drupal\context\ContextManager;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\ConditionInterface;
use Drupal\Core\Entity\Query\QueryException;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\flysystem\FlysystemFactory;
use Drupal\islandora\ContextProvider\FileContextProvider;
use Drupal\islandora\ContextProvider\MediaContextProvider;
use Drupal\islandora\ContextProvider\NodeContextProvider;
use Drupal\islandora\ContextProvider\TermContextProvider;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Utility functions for figuring out when to fire derivative reactions.
 */
class IslandoraUtils implements IslandoraUtilsInterface {

  /**
   * Constructor.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected ContextManager $contextManager,
    protected FlysystemFactory $flysystemFactory,
    protected LanguageManagerInterface $languageManager,
  ) {
  }

  /**
   * {@inheritDoc}
   */
  public function getParentNode(MediaInterface $media) : ?NodeInterface {
    if (!$media->hasField(self::MEDIA_OF_FIELD)) {
      return NULL;
    }
    $field = $media->get(self::MEDIA_OF_FIELD);
    if ($field->isEmpty()) {
      return NULL;
    }
    return $field->first()
      ->get('entity')
      ->getTarget()
      ?->getValue();
  }

  /**
   * {@inheritDoc}
   */
  public function getMedia(NodeInterface $node) : array {
    if (!$this->entityTypeManager->getStorage('field_storage_config')
      ->load('media.' . self::MEDIA_OF_FIELD)) {
      return [];
    }
    $mids = $this->entityTypeManager->getStorage('media')->getQuery()
      ->accessCheck(TRUE)
      ->condition(self::MEDIA_OF_FIELD, $node->id())
      ->execute();
    if (empty($mids)) {
      return [];
    }
    return $this->entityTypeManager->getStorage('media')->loadMultiple($mids);
  }

  /**
   * {@inheritDoc}
   */
  public function getMediaWithTerm(NodeInterface $node, TermInterface $term) : ?MediaInterface {
    $mids = $this->getMediaReferencingNodeAndTerm($node, $term);
    if (empty($mids)) {
      return NULL;
    }
    return $this->entityTypeManager->getStorage('media')->load(reset($mids));
  }

  /**
   * {@inheritDoc}
   */
  public function getReferencingMedia(int $fid) : array {
    // Get media fields that reference files.
    $fields = $this->getReferencingFields('media', 'file');

    // Process field names, stripping off 'media.' and appending 'target_id'.
    $conditions = array_map(
      function ($field) {
        return ltrim($field, 'media.') . '.target_id';
      },
      $fields
    );

    // Query for media that reference this file.
    $query = $this->entityTypeManager->getStorage('media')->getQuery();
    $query->accessCheck(TRUE);
    $group = $query->orConditionGroup();
    foreach ($conditions as $condition) {
      $group->condition($condition, $fid);
    }
    $query->condition($group);

    return $this->entityTypeManager->getStorage('media')
      ->loadMultiple($query->execute());
  }

  /**
   * {@inheritDoc}
   */
  public function getTermForUri(string $uri) : ?TermInterface {
    // Get authority link fields to search.
    $field_map = $this->entityFieldManager->getFieldMap();
    $fields = [];
    foreach ($field_map['taxonomy_term'] as $field_name => $field_data) {
      if ($field_data['type'] == 'authority_link') {
        $fields[] = $field_name;
      }
    }
    // Add field_external_uri.
    $fields[] = self::EXTERNAL_URI_FIELD;

    $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery();

    $orGroup = $query->orConditionGroup();
    foreach ($fields as $field) {
      $orGroup->condition("$field.uri", $uri);
    }

    $results = $query
      ->accessCheck(TRUE)
      ->condition($orGroup)
      ->execute();

    if (empty($results)) {
      return NULL;
    }

    return $this->entityTypeManager->getStorage('taxonomy_term')
      ->load(reset($results));
  }

  /**
   * {@inheritDoc}
   */
  public function getUriForTerm(TermInterface $term) : ?string {
    $fields = $this->getUriFieldNamesForTerms();
    foreach ($fields as $field_name) {
      if ($term && $term->hasField($field_name)) {
        $field = $term->get($field_name);
        if (!$field->isEmpty()) {
          $link = $field->first()->getValue();
          return $link['uri'];
        }
      }
    }
    return NULL;
  }

  /**
   * {@inheritDoc}
   */
  public function getUriFieldNamesForTerms() : array {
    // Get authority link fields to search.
    $field_map = $this->entityFieldManager->getFieldMap();
    $fields = [];
    foreach ($field_map['taxonomy_term'] as $field_name => $field_data) {
      $data_types = ['authority_link', 'field_external_authority_link'];
      if (in_array($field_data['type'], $data_types)) {
        $fields[] = $field_name;
      }
    }
    // Add field_external_uri.
    $fields[] = self::EXTERNAL_URI_FIELD;
    return $fields;
  }

  /**
   * {@inheritDoc}
   */
  public function executeNodeReactions($reaction_type, NodeInterface $node) : void {
    $provider = new NodeContextProvider($node);
    $provided = $provider->getRuntimeContexts([]);
    $this->contextManager->evaluateContexts($provided);

    // Fire off index reactions.
    foreach ($this->contextManager->getActiveReactions($reaction_type) as $reaction) {
      $reaction->execute($node);
    }
  }

  /**
   * {@inheritDoc}
   */
  public function executeMediaReactions($reaction_type, MediaInterface $media) : void {
    $provider = new MediaContextProvider($media);
    $provided = $provider->getRuntimeContexts([]);
    $this->contextManager->evaluateContexts($provided);

    // Fire off index reactions.
    foreach ($this->contextManager->getActiveReactions($reaction_type) as $reaction) {
      $reaction->execute($media);
    }
  }

  /**
   * {@inheritDoc}
   */
  public function executeFileReactions($reaction_type, FileInterface $file) : void {
    $provider = new FileContextProvider($file);
    $provided = $provider->getRuntimeContexts([]);
    $this->contextManager->evaluateContexts($provided);

    // Fire off index reactions.
    foreach ($this->contextManager->getActiveReactions($reaction_type) as $reaction) {
      $reaction->execute($file);
    }
  }

  /**
   * {@inheritDoc}
   */
  public function executeTermReactions($reaction_type, TermInterface $term) : void {
    $provider = new TermContextProvider($term);
    $provided = $provider->getRuntimeContexts([]);
    $this->contextManager->evaluateContexts($provided);

    // Fire off index reactions.
    foreach ($this->contextManager->getActiveReactions($reaction_type) as $reaction) {
      $reaction->execute($term);
    }
  }

  /**
   * {@inheritDoc}
   */
  public function executeDerivativeReactions($reaction_type, NodeInterface $node, MediaInterface $media) : void {
    $provider = new MediaContextProvider($media);
    $provided = $provider->getRuntimeContexts([]);
    $this->contextManager->evaluateContexts($provided);

    // Fire off index reactions.
    foreach ($this->contextManager->getActiveReactions($reaction_type) as $reaction) {
      $reaction->execute($node);
    }
  }

  /**
   * {@inheritDoc}
   */
  public function haveFieldsChanged(ContentEntityInterface $entity, ContentEntityInterface $original) : bool {

    $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity->getEntityTypeId(), $entity->bundle());

    $ignore_list = ['vid' => 1, 'changed' => 1, 'path' => 1];
    $field_definitions = array_diff_key($field_definitions, $ignore_list);

    foreach ($field_definitions as $field_name => $field_definition) {
      $langcodes = array_keys($entity->getTranslationLanguages());

      if ($langcodes !== array_keys($original->getTranslationLanguages())) {
        // If the list of langcodes has changed, we need to save.
        return TRUE;
      }

      foreach ($langcodes as $langcode) {
        $items = $entity
          ->getTranslation($langcode)
          ->get($field_name)
          ->filterEmptyItems();
        $original_items = $original
          ->getTranslation($langcode)
          ->get($field_name)
          ->filterEmptyItems();

        // If the field items are not equal, we need to save.
        if (!$items->equals($original_items)) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * {@inheritDoc}
   */
  public function getFilesystemSchemes() : array {
    $schemes = ['public'];
    if (!empty(Settings::get('file_private_path'))) {
      $schemes[] = 'private';
    }
    return array_merge($schemes, $this->flysystemFactory->getSchemes());
  }

  /**
   * {@inheritDoc}
   */
  public function getMediaReferencingNodeAndTerm(NodeInterface $node, TermInterface $term) : ?array {
    $term_fields = $this->getReferencingFields('media', 'taxonomy_term');
    if (count($term_fields) <= 0) {
      \Drupal::logger("No media fields reference a taxonomy term");
      return NULL;
    }
    $node_fields = $this->getReferencingFields('media', 'node');
    if (count($node_fields) <= 0) {
      \Drupal::logger("No media fields reference a node.");
      return NULL;
    }

    $remove_entity = function (&$o) {
      $o = substr($o, strpos($o, '.') + 1);
    };
    array_walk($term_fields, $remove_entity);
    array_walk($node_fields, $remove_entity);

    $query = $this->entityTypeManager->getStorage('media')->getQuery();
    $query->accessCheck(TRUE);
    $taxon_condition = $this->getEntityQueryOrCondition($query, $term_fields, $term->id());
    $query->condition($taxon_condition);
    $node_condition = $this->getEntityQueryOrCondition($query, $node_fields, $node->id());
    $query->condition($node_condition);
    // Does the tags field exist?
    try {
      $mids = $query->execute();
    }
    catch (QueryException $e) {
      $mids = [];
    }
    return $mids;
  }

  /**
   * {@inheritDoc}
   */
  public function getReferencingFields(string $entity_type, string $target_type) : array {
    $fields = $this->entityTypeManager->getStorage('field_storage_config')->getQuery()
      ->condition('entity_type', $entity_type)
      ->condition('settings.target_type', $target_type)
      ->execute();
    if (!is_array($fields)) {
      $fields = [$fields];
    }
    return $fields;
  }

  /**
   * Make an OR condition for an array of fields and a value.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   The QueryInterface for the query.
   * @param array $fields
   *   The array of field names.
   * @param string $value
   *   The value to search the fields for.
   *
   * @return \Drupal\Core\Entity\Query\ConditionInterface
   *   The OR condition to add to your query.
   */
  private function getEntityQueryOrCondition(QueryInterface $query, array $fields, $value) : ConditionInterface {
    $condition = $query->orConditionGroup();
    foreach ($fields as $field) {
      $condition->condition($field, $value);
    }
    return $condition;
  }

  /**
   * {@inheritDoc}
   */
  public function getEntityUrl(EntityInterface $entity) : string {
    $undefined = $this->languageManager->getLanguage('und');
    return $entity->toUrl('canonical', [
      'absolute' => TRUE,
      'language' => $undefined,
    ])->toString();
  }

  /**
   * {@inheritDoc}
   */
  public function getDownloadUrl(FileInterface $file) : string {
    return $file->createFileUrl(FALSE);
  }

  /**
   * {@inheritDoc}
   */
  public function getRestUrl(EntityInterface $entity, $format = '') : string {
    $undefined = $this->languageManager->getLanguage('und');
    $entity_type = $entity->getEntityTypeId();
    $rest_url = Url::fromRoute(
      "rest.entity.$entity_type.GET",
      [$entity_type => $entity->id()],
      ['absolute' => TRUE, 'language' => $undefined]
    )->toString();
    if (!empty($format)) {
      $rest_url .= "?_format=$format";
    }
    return $rest_url;
  }

  /**
   * {@inheritDoc}
   */
  public function isIslandoraType(string $entity_type, string $bundle) : bool {
    $fields = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);
    return match ($entity_type) {
      'media' => isset($fields[self::MEDIA_OF_FIELD]) && isset($fields[self::MEDIA_USAGE_FIELD]),
      'taxonomy_term' => isset($fields[self::EXTERNAL_URI_FIELD]),
      default => isset($fields[self::MEMBER_OF_FIELD]),
    };
  }

  /**
   * {@inheritDoc}
   */
  public function canCreateIslandoraEntity(string $entity_type, string $bundle_type) : bool {
    $bundles = $this->entityTypeManager->getStorage($bundle_type)->loadMultiple();
    $access_control_handler = $this->entityTypeManager->getAccessControlHandler($entity_type);

    foreach (array_keys($bundles) as $bundle) {
      // Skip bundles that aren't 'Islandora' types.
      if (!$this->isIslandoraType($entity_type, $bundle)) {
        continue;
      }

      $access = $access_control_handler->createAccess($bundle, NULL, [], TRUE);
      if (!$access->isAllowed()) {
        continue;
      }

      return TRUE;
    }

    return FALSE;
  }

  /**
   * {@inheritDoc}
   */
  public function findAncestors(ContentEntityInterface $entity, array $fields = [self::MEMBER_OF_FIELD], int|bool $max_height = FALSE): array {
    // XXX: If a negative integer is passed assume it's false.
    if ($max_height < 0) {
      $max_height = FALSE;
    }
    $context = [
      'max_height' => $max_height,
      'ancestors' => [],
    ];
    $this->findAncestorsByEntityReference($entity, $context, $fields);
    return $context['ancestors'];
  }

  /**
   * Helper that builds up the ancestors.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being checked.
   * @param array $context
   *   An array containing:
   *     -ancestors: The ancestors that have been found.
   *     -max_height: How far up the chain to go.
   * @param array $fields
   *   An optional array where the values are the field names to be used for
   *   retrieval.
   * @param int $current_height
   *   The current height of the recursion.
   */
  protected function findAncestorsByEntityReference(ContentEntityInterface $entity, array &$context, array $fields = [self::MEMBER_OF_FIELD], int $current_height = 1) : void {
    $parents = $this->getParentsByEntityReference($entity, $fields);
    foreach ($parents as $parent) {
      if (isset($context['ancestors'][$parent->id()])) {
        continue;
      }
      $context['ancestors'][$parent->id()] = $parent->id();
      if ($context['max_height'] === FALSE || $current_height < $context['max_height']) {
        $this->findAncestorsByEntityReference($parent, $context, $fields, $current_height + 1);
      }
    }
  }

  /**
   * Helper that gets the immediate parents of a node.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being checked.
   * @param array $fields
   *   An array where the values are the field names to be used.
   *
   * @return array
   *   An array of entity objects keyed by field item deltas.
   */
  protected function getParentsByEntityReference(ContentEntityInterface $entity, array $fields) : array {
    $parents = [];
    foreach ($fields as $field) {
      if ($entity->hasField($field)) {
        $reference_field = $entity->get($field);
        if (!$reference_field->isEmpty()) {
          $parents = array_merge($parents, $reference_field->referencedEntities());
        }
      }
    }
    return $parents;
  }

}
