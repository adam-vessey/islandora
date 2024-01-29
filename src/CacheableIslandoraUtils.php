<?php

namespace Drupal\islandora;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Caching proxy for IslandoraUtils service.
 *
 * XXX: Ideally, the subclassing of `IslandoraUtils` should go away.
 */
class CacheableIslandoraUtils extends IslandoraUtils implements IslandoraUtilsInterface {

  /**
   * Constructor.
   */
  public function __construct(
    protected IslandoraUtilsInterface $wrapped,
    protected CacheBackendInterface $cache,
    protected CacheContextsManager $cacheContextsManager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Return results for the given function call, caching along the way.
   *
   * @param string $func
   *   The function/method to call.
   * @param array $args
   *   The array of arguments to pass to the function/method call.
   * @param array $cache_info
   *   Info to build out additional cache context/tags to add if we are to
   *   return a falsy value, as caching these values can still be useful;
   *   however, their invalidation can be dependent on new entities.
   *   Presently, expected:
   *   - type_list: An array of strings representing type names to add in "list"
   *     info. Context will be added in to generate the IDs; however, list tags
   *     will only be added for false-y responses.
   *
   * @return mixed
   *   The results of the call.
   */
  protected function cachedCall(string $func, array $args, array $cache_info = []) : mixed {
    /** @var string $cache_id */
    /** @var \Drupal\Core\Cache\CacheableMetadata $cache_meta */
    [$cache_id, $cache_meta] = $this->mapCacheId($func, $args, $cache_info);

    if ($data = $this->cache->get($cache_id)) {
      return $data->data;
    }

    $result = $this->wrapped->$func(...$args);
    if (!$result) {
      foreach ($cache_info['type_list'] ?? [] as $type_name) {
        $type = $this->entityTypeManager->getDefinition($type_name);
        $cache_meta->addCacheTags($type->getListCacheTags());
      }
    }
    $this->cache->set($cache_id, $result, CacheBackendInterface::CACHE_PERMANENT, $cache_meta->getCacheTags());
    return $result;
  }

  /**
   * Helper; build out a cache ID.
   *
   * @param string $func
   *   The name of the function for which to build a cache ID.
   * @param array $parts
   *   Items with which to build out the cache ID.
   *
   * @return array
   *   An array containing:
   *   - the cache ID; and,
   *   - a CacheableMetadata instance.
   */
  protected function mapCacheId(string $func, array $parts, array $cache_info = []) : array {
    $cache_meta = new CacheableMetadata();
    $cache_meta->addCacheContexts(['user']);

    foreach ($cache_info['type_list'] ?? [] as $type_name) {
      $type = $this->entityTypeManager->getDefinition($type_name);
      $cache_meta->addCacheContexts($type->getListCacheContexts());
    }

    $prepped = [];

    foreach ($parts as $part) {
      if ($part instanceof CacheableDependencyInterface) {
        $cache_meta->addCacheableDependency($part);
      }
      if ($part instanceof EntityInterface) {
        $prepped[] = $part->id();
      }
      elseif (is_array($part)) {
        // Only relevant with ::findAncestors(), presently.
        $prepped = array_merge($prepped, $part);
      }
      else {
        $prepped[] = $part;
      }
    }

    return [
      implode(':', array_merge(
        [
          Html::getClass($func),
        ],
        $this->cacheContextsManager->convertTokensToKeys($cache_meta->getCacheContexts())->getKeys(),
        array_map(Html::getClass(...), $prepped),
      )),
      $cache_meta,
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function getParentNode(MediaInterface $media) : ?NodeInterface {
    return $this->cachedCall(__FUNCTION__, func_get_args(), [
      'type_list' => [
        // In some flows (migrations?), it's hypothetically possible for a media
        // to be created before the node it is supposed to reference.
        'node',
        // Fields not being configured on the given type could lead to
        // NULLs.
        'field_config',
      ],
    ]);
  }

  /**
   * {@inheritDoc}
   */
  public function getMedia(NodeInterface $node) : array {
    return $this->cachedCall(__FUNCTION__, func_get_args(), [
      'type_list' => [
        // Have to clear if/when new media are added, as they could be relevant.
        'media',
        // Fields not being configured on the given type could lead to
        // NULLs.
        'field_config',
      ],
    ]);
  }

  /**
   * {@inheritDoc}
   */
  public function getMediaWithTerm(NodeInterface $node, TermInterface $term) : ?MediaInterface {
    return $this->cachedCall(__FUNCTION__, func_get_args(), [
      'type_list' => [
        // Have to clear if/when new media are added, as they could be relevant.
        'media',
        // Fields not being configured on the given type could lead to
        // NULLs.
        'field_config',
      ],
    ]);
  }

  /**
   * {@inheritDoc}
   */
  public function getReferencingMedia(int $fid) : array {
    return $this->cachedCall(__FUNCTION__, func_get_args(), [
      'type_list' => [
        // Have to clear if/when new media are added, as they could be relevant.
        'media',
        // Fields not being configured on the given type could lead to
        // NULLs.
        'field_config',
      ],
    ]);
  }

  /**
   * {@inheritDoc}
   */
  public function getTermForUri(string $uri) : ?TermInterface {
    return $this->cachedCall(__FUNCTION__, func_get_args(), [
      'type_list' => [
        // Have to clear if/when new terms are added, as they could be relevant.
        'taxonomy_term',
        // Fields not being configured on the given type could lead to
        // NULLs.
        'field_config',
      ],
    ]);
  }

  /**
   * {@inheritDoc}
   */
  public function getUriForTerm(TermInterface $term) : ?string {
    return $this->cachedCall(__FUNCTION__, func_get_args(), [
      'type_list' => [
        // Fields not being configured on the given type could lead to
        // NULLs.
        'field_config',
      ],
    ]);
  }

  /**
   * {@inheritDoc}
   */
  public function getUriFieldNamesForTerms() : array {
    return $this->cachedCall(__FUNCTION__, func_get_args(), [
      'type_list' => [
        // Is exactly what is being interrogated.
        'field_config',
      ],
    ]);
  }

  /**
   * {@inheritDoc}
   */
  public function executeNodeReactions($reaction_type, NodeInterface $node) : void {
    call_user_func_array([$this->wrapped, __FUNCTION__], func_get_args());
  }

  /**
   * {@inheritDoc}
   */
  public function executeMediaReactions($reaction_type, MediaInterface $media) : void {
    call_user_func_array([$this->wrapped, __FUNCTION__], func_get_args());
  }

  /**
   * {@inheritDoc}
   */
  public function executeFileReactions($reaction_type, FileInterface $file) : void {
    call_user_func_array([$this->wrapped, __FUNCTION__], func_get_args());
  }

  /**
   * {@inheritDoc}
   */
  public function executeTermReactions($reaction_type, TermInterface $term) : void {
    call_user_func_array([$this->wrapped, __FUNCTION__], func_get_args());
  }

  /**
   * {@inheritDoc}
   */
  public function executeDerivativeReactions($reaction_type, NodeInterface $node, MediaInterface $media) : void {
    call_user_func_array([$this->wrapped, __FUNCTION__], func_get_args());
  }

  /**
   * {@inheritDoc}
   */
  public function haveFieldsChanged(ContentEntityInterface $entity, ContentEntityInterface $original) : bool {
    return call_user_func_array([$this->wrapped, __FUNCTION__], func_get_args());
  }

  /**
   * {@inheritDoc}
   */
  public function getFilesystemSchemes() : array {
    return $this->cachedCall(__FUNCTION__, func_get_args());
  }

  /**
   * {@inheritDoc}
   */
  public function getMediaReferencingNodeAndTerm(NodeInterface $node, TermInterface $term) : ?array {
    return $this->cachedCall(__FUNCTION__, func_get_args(), [
      'type_list' => [
        // Have to clear if/when new media are added, as they could be relevant.
        'media',
        // Fields not being configured on the given type could lead to
        // NULLs.
        'field_config',
      ],
    ]);
  }

  /**
   * {@inheritDoc}
   */
  public function getReferencingFields(string $entity_type, string $target_type) : array {
    return $this->cachedCall(__FUNCTION__, func_get_args(), [
      'type_list' => [
        // Is exactly what is being interrogated.
        'field_config',
      ],
    ]);
  }

  /**
   * {@inheritDoc}
   */
  public function getEntityUrl(EntityInterface $entity) : string {
    return $this->cachedCall(__FUNCTION__, func_get_args());
  }

  /**
   * {@inheritDoc}
   */
  public function getDownloadUrl(FileInterface $file) : string {
    return $this->cachedCall(__FUNCTION__, func_get_args());
  }

  /**
   * {@inheritDoc}
   */
  public function getRestUrl(EntityInterface $entity, $format = '') : string {
    return $this->cachedCall(__FUNCTION__, func_get_args());
  }

  /**
   * {@inheritDoc}
   */
  public function isIslandoraType(string $entity_type, string $bundle) : bool {
    return $this->cachedCall(__FUNCTION__, func_get_args(), [
      'type_list' => [
        // Is exactly what is being interrogated.
        'field_config',
      ],
    ]);
  }

  /**
   * {@inheritDoc}
   */
  public function canCreateIslandoraEntity(string $entity_type, string $bundle_type) : bool {
    return $this->cachedCall(__FUNCTION__, func_get_args(), [
      'type_list' => [
        // Is exactly what is being interrogated.
        'field_config',
      ],
    ]);
  }

  /**
   * {@inheritDoc}
   */
  public function findAncestors(ContentEntityInterface $entity, array $fields = [self::MEMBER_OF_FIELD], bool|int $max_height = FALSE) : array {
    // XXX: Could be refactored to have each _level_ do a cache lookup, to
    // potentially make use of the ancestors of the parent.
    return $this->cachedCall(__FUNCTION__, func_get_args(), [
      'type_list' => [
        // They might change.
        'node',
        // Fields being configured could influence.
        'field_config',
      ],
    ]);
  }

}
