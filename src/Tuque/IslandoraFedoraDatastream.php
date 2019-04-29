<?php

namespace Drupal\islandora\Tuque;

require_once __DIR__ . '/Base.php';

use FedoraDatastream;
use AbstractFedoraObject;
use FedoraRepository;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;

/**
 * Class IslandoraFedoraDatastream.
 *
 * @package Drupal\islandora\Tuque
 */
class IslandoraFedoraDatastream extends FedoraDatastream implements RefinableCacheableDependencyInterface {
  use RefinableCacheableDependencyTrait;

  /**
   * {@inheritdoc}
   */
  public function __construct($id, AbstractFedoraObject $object, FedoraRepository $repository) {
    parent::__construct($id, $object, $repository);

    $this->addCacheableDependency($object);
    $this->addCacheTags([
      $this->drupalCacheTag(),
    ]);
  }

  /**
   * Get the cache tag for the given datastream object.
   *
   * @return string
   *   The cache tag for this object.
   */
  public function drupalCacheTag() {
    return static::getDrupalCacheDatastreamTag(
      $this->parent->drupalCacheTag(),
      $this->id
    );
  }

  /**
   * Helper; generate a datastream tag, given an object and DSID.
   *
   * @param IslandoraFedoraObject $object
   *   The object containing the datastream.
   * @param string $id
   *   The datastream ID for which to generate a cache tag.
   *
   * @return string
   *   The cache tag for the given PID.
   */
  public static function getDrupalCacheDatastreamTag(IslandoraFedoraObject $object, $id) {
    return implode(':', [
      $object->drupalCacheTag(),
      $id,
    ]);
  }

  protected $fedoraRelsIntClass = IslandoraFedoraRelsInt::class;
  protected $fedoraDatastreamVersionClass = IslandoraFedoraDatastreamVersion::class;

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
   * Inherits.
   *
   * Calls parent and invokes modified and purged hooks.
   *
   * @see FedoraDatastream::modifyDatastream()
   */
  protected function modifyDatastream(array $args) {
    try {
      parent::modifyDatastream($args);
      islandora_invoke_datastream_hooks(ISLANDORA_DATASTREAM_MODIFIED_HOOK, $this->parent->models, $this->id, $this->parent, $this, $args);
      if ($this->state == 'D') {
        islandora_invoke_datastream_hooks(ISLANDORA_DATASTREAM_PURGED_HOOK, $this->parent->models, $this->id, $this->parent, $this->id);
      }
    }
    catch (Exception $e) {
      \Drupal::logger('islandora')->error('Failed to modify datastream @dsid from @pid</br>code: @code<br/>message: @msg', [
        '@pid' => $this->parent->id,
        '@dsid' => $this->id,
        '@code' => $e->getCode(),
        '@msg' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

}
