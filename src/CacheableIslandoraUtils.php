<?php

namespace Drupal\islandora;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
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
  ) {}

  /**
   * Return results for the given function call, caching along the way.
   *
   * @param string $func
   *   The function/method to call.
   * @param array $args
   *   The array of arguments to pass to the function/method call.
   *
   * @return mixed
   *   The results of the call.
   */
  protected function cachedCall(string $func, array $args) : mixed {
    /** @var string $cache_id */
    /** @var \Drupal\Core\Cache\CacheableMetadata $cache_meta */
    [$cache_id, $cache_meta] = $this->mapCacheId($func, $args);

    if ($data = $this->cache->get($cache_id)) {
      return $data->data;
    }

    $result = $this->wrapped->$func(...$args);
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
  protected function mapCacheId(string $func, array $parts) : array {
    $cache_meta = new CacheableMetadata();
    $cache_meta->addCacheContexts(['user']);

    $prepped = [];
    foreach ($parts as $part) {
      if ($part instanceof CacheableDependencyInterface) {
        $cache_meta->addCacheableDependency($part);
      }
      if ($part instanceof EntityInterface) {
        $prepped[] = $part->id();
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
    return $this->cachedCall(__FUNCTION__, func_get_args());
  }

  /**
   * {@inheritDoc}
   */
  public function getMedia(NodeInterface $node) : array {
    return $this->cachedCall(__FUNCTION__, func_get_args());
  }

  /**
   * {@inheritDoc}
   */
  public function getMediaWithTerm(NodeInterface $node, TermInterface $term) : ?MediaInterface {
    return $this->cachedCall(__FUNCTION__, func_get_args());
  }

  /**
   * {@inheritDoc}
   */
  public function getReferencingMedia(int $fid) : array {
    return $this->cachedCall(__FUNCTION__, func_get_args());
  }

  /**
   * {@inheritDoc}
   */
  public function getTermForUri(string $uri) : ?TermInterface {
    return $this->cachedCall(__FUNCTION__, func_get_args());
  }

  /**
   * {@inheritDoc}
   */
  public function getUriForTerm(TermInterface $term) : ?string {
    return $this->cachedCall(__FUNCTION__, func_get_args());
  }

  /**
   * {@inheritDoc}
   */
  public function getUriFieldNamesForTerms() : array {
    return $this->cachedCall(__FUNCTION__, func_get_args());
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
    return $this->cachedCall(__FUNCTION__, func_get_args());
  }

  /**
   * {@inheritDoc}
   */
  public function getReferencingFields(string $entity_type, string $target_type) : array {
    return $this->cachedCall(__FUNCTION__, func_get_args());
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
    return $this->cachedCall(__FUNCTION__, func_get_args());
  }

  /**
   * {@inheritDoc}
   */
  public function canCreateIslandoraEntity(string $entity_type, string $bundle_type) : bool {
    return $this->cachedCall(__FUNCTION__, func_get_args());
  }

  /**
   * {@inheritDoc}
   */
  public function findAncestors(ContentEntityInterface $entity, array $fields = [self::MEMBER_OF_FIELD], bool|int $max_height = FALSE) : array {
    return $this->cachedCall(__FUNCTION__, func_get_args());
  }

}
