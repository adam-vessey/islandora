<?php

namespace Drupal\islandora\Tuque;

require_once __DIR__ . '/Base.php';

use FedoraObject;
use FedoraRepository;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;

/**
 * Class IslandoraFedoraObject.
 *
 * @package Drupal\islandora\Tuque
 */
class IslandoraFedoraObject extends FedoraObject implements RefinableCacheableDependencyInterface {
  use RefinableCacheableDependencyTrait;

  protected $newFedoraDatastreamClass = IslandoraNewFedoraDatastream::class;
  protected $fedoraDatastreamClass = IslandoraFedoraDatastream::class;
  protected $fedoraRelsExtClass = IslandoraFedoraRelsExt::class;

  /**
   * {@inheritdoc}
   */
  public function __construct($id, FedoraRepository $repository) {
    parent::__construct($id, $repository);

    $this
      ->addCacheableDependency($repository)
      ->addCacheTags(array_map('static::getDrupalCacheObjectTag', $this->models))
      ->addCacheTags([
        $this->drupalCacheTag(),
      ]);
  }

  /**
   * Get the cache tag for the given object.
   *
   * @return string
   *   The cache tag for this object.
   */
  public function drupalCacheTag() {
    return static::getDrupalCacheObjectTag($this->id);
  }

  /**
   * Helper; generate an object tag, given a PID.
   *
   * @param string $id
   *   The PID for which to generate a cache tag.
   *
   * @return string
   *   The cache tag for the given PID.
   */
  public static function getDrupalCacheObjectTag($id) {
    return "islandora_object:{$id}";
  }

  /**
   * Magical magic, to allow recursive modifications.
   *
   * So... Magic functions in PHP are not re-entrant... Meaning that if you
   * have something which tries to call __set on an object anywhere later in
   * the callstack after it has already been called, it will not call the
   * magic method again; instead, it will set the property on the object
   * proper. Here, we detect the property being set on the object proper, and
   * restore the magic functionality as long as it keeps getting set...
   *
   * Not necessary to try to account for this in Tuque proper, as Tuque itself
   * does not have a mechanism to trigger modifications resulting from other
   * modifications.
   *
   * @param string $name
   *   The name of the property being set.
   * @param mixed $value
   *   The value to which the property should be set.
   */
  public function __set($name, $value) {
    parent::__set($name, $value);

    // Recursion only matters for magic properties... "Plain" properties cannot
    // call other code in order to start recursing, and in fact we would get
    // stuck looping with a "plain" property.
    if ($this->propertyIsMagical($name)) {
      // XXX: Due to the structure of the code, we cannot use property_exists()
      // (because many of the properties are declared in the class, and the
      // magic triggers due them being NULLed), nor can we use isset() (because
      // it is implemented as another magic function...).
      $vars = get_object_vars($this);
      while (isset($vars[$name])) {
        $new_value = $this->$name;
        unset($this->$name);
        parent::__set($name, $new_value);
        $vars = get_object_vars($this);
      }
    }
  }

  /**
   * Ingest the given datastream.
   *
   * @see FedoraObject::ingestDatastream()
   */
  public function ingestDatastream(&$datastream) {
    module_load_include('inc', 'islandora', 'includes/tuque_wrapper');
    $object = $datastream->parent;
    $context = [
      'action' => 'ingest',
      'block' => FALSE,
    ];
    islandora_alter_datastream($object, $datastream, $context);
    try {
      if ($context['block']) {
        throw new Exception('Ingest Datastream was blocked.');
      }
      $ret = parent::ingestDatastream($datastream);
      islandora_invoke_datastream_hooks(ISLANDORA_DATASTREAM_INGESTED_HOOK, $object->models, $datastream->id, $object, $datastream);
      return $ret;
    }
    catch (Exception $e) {
      \Drupal::logger('islandora')->error('Failed to ingest datastream @datastream on object: @pid</br>code: @code<br/>message: @msg', [
        '@pid' => $object->id,
        '@dsid' => $datastream->id,
        '@code' => $e->getCode(),
        '@msg' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Inherits.
   *
   * Calls parent and invokes object modified and deleted(/purged) hooks.
   *
   * @see FedoraObject::modifyObject()
   */
  protected function modifyObject($params) {
    try {
      parent::modifyObject($params);
      islandora_invoke_object_hooks(ISLANDORA_OBJECT_MODIFIED_HOOK, $this->models, $this);
      if ($this->state == 'D') {
        islandora_invoke_object_hooks(ISLANDORA_OBJECT_PURGED_HOOK, $this->models, $this->id);
      }
    }
    catch (Exception $e) {
      \Drupal::logger('islandora')->error('Failed to modify object: @pid</br>code: @code<br/>message: @msg', [
        '@pid' => $this->id,
        '@code' => $e->getCode(),
        '@msg' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Purge a datastream.
   *
   * Invokes datastream altered/purged hooks and calls the API-M method.
   *
   * @see FedoraObject::purgeObject()
   */
  public function purgeDatastream($id) {
    module_load_include('inc', 'islandora', 'includes/tuque_wrapper');
    $this->populateDatastreams();

    if (!array_key_exists($id, $this->datastreams)) {
      return FALSE;
    }
    $context = [
      'action' => 'purge',
      'purge' => TRUE,
      'delete' => FALSE,
      'block' => FALSE,
    ];
    try {
      islandora_alter_datastream($this, $this[$id], $context);
      $action = $context['block'] ? 'block' : FALSE;
      $action = (!$action && $context['delete']) ? 'delete' : $action;
      $action = !$action ? 'purge' : $action;
      switch ($action) {
        case 'block':
          throw new Exception('Purge Datastream was blocked.');

        case 'delete':
          $this[$id]->state = 'D';
          return [];

        default:
          $to_return = parent::purgeDatastream($id);
          islandora_invoke_datastream_hooks(ISLANDORA_DATASTREAM_PURGED_HOOK, $this->models, $id, $this, $id);
          return $to_return;
      }
    }
    catch (Exception $e) {
      \Drupal::logger('islandora')->error('Failed to purge datastream @dsid from @pid</br>code: @code<br/>message: @msg', [
        '@pid' => $this->id,
        '@dsid' => $id,
        '@code' => $e->getCode(),
        '@msg' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

}
