<?php

/**
 * @file
 * This file contains a class to include the Tuque php library.
 */

$islandora_module_path = drupal_get_path('module', 'fedora_repository');

// Do this until we expose these in a module or library.
@include_once 'sites/all/libraries/tuque/Datastream.php';
@include_once 'sites/all/libraries/tuque/FedoraApi.php';
@include_once 'sites/all/libraries/tuque/FedoraApiSerializer.php';
@include_once 'sites/all/libraries/tuque/Object.php';
@include_once 'sites/all/libraries/tuque/RepositoryConnection.php';
@include_once 'sites/all/libraries/tuque/Cache.php';
@include_once 'sites/all/libraries/tuque/RepositoryException.php';
@include_once 'sites/all/libraries/tuque/Repository.php';
@include_once 'sites/all/libraries/tuque/FedoraRelationships.php';

@include_once "$islandora_module_path/libraries/tuque/Datastream.php";
@include_once "$islandora_module_path/libraries/tuque/FedoraApi.php";
@include_once "$islandora_module_path/libraries/tuque/FedoraApiSerializer.php";
@include_once "$islandora_module_path/libraries/tuque/Object.php";
@include_once "$islandora_module_path/libraries/tuque/RepositoryConnection.php";
@include_once "$islandora_module_path/libraries/tuque/Cache.php";
@include_once "$islandora_module_path/libraries/tuque/RepositoryException.php";
@include_once "$islandora_module_path/libraries/tuque/Repository.php";
@include_once "$islandora_module_path/libraries/tuque/FedoraRelationships.php";

class IslandoraTuque {

  /**
   * Connection to the repository
   *
   * @var RepositoryConnection
   */
  public $connection = NULL;

  /**
   * The Fedora API we are using
   *
   * @var FedoraAPI
   */
  public $api = NULL;

  /**
   * The cache we use to connect.
   *
   * @var SimpleCache
   */
  public $cache = NULL;

  /**
   * The repository object.
   *
   * @var FedoraRepository
   */
  public $repository = NULL;

  /**
   * Constructor.
   *
   * @param array $user
   *   A Drupal user.
   * @param string $url
   *   The url to the fedora instance.
   */
  public function __construct($user = NULL, $url = NULL) {
    if (!isset($user)) {
      global $user;
    }

    if (!isset($user) || $user->uid == 0) {
      $user_string = 'anonymous';
      $pass_string = 'anonymous';
    }
    else {
      $user_string = $user->name;
      $pass_string = $user->pass;
    }

    if (!isset($url)) {
      $url = variable_get('fedora_base_url', 'http://localhost:8080/fedora');
    }

    if (self::exists()) {
      module_load_include('inc', 'fedora_repository', 'api/tuque_wrapper');
      $this->connection = new IslandoraRepositoryConnection($url, $user_string, $pass_string);
      $this->connection->reuseConnection = TRUE;
      $this->api = new IslandoraFedoraApi($this->connection);
      $this->cache = new IslandoraSimpleCache();
      $this->repository = new IslandoraFedoraRepository($this->api, $this->cache);
    }
  }

  /**
   * Checks if the Connection class exists.
   *
   * @returns bool
   *   TRUE if the Connection class exists FALSE otherwise.
   */
  public static function exists() {
    return class_exists('RepositoryConnection');
  }

  /**
   * Displays and error for use when Tuque is not installed.
   */
  public static function getError() {
    $islandora_doc_link = l(t('Islandora documentation'), 'https://wiki.duraspace.org/display/ISLANDORA/Islandora');
    $tuque_link = l(t('Tuque Fedora API'), 'http://github.com/islandora/tuque');
    $message = t('Islandora requires the !tuque_url. Please install in /sites/all/libraries/tuque before continuing. See the !islandora_url.', array('!tuque_url' => $tuque_link, '!islandora_url' => $islandora_doc_link));
    drupal_set_message(filter_xss($message), 'error', FALSE);
  }
}

/**
* Loads the object from the given ID if possible.
*
* Often used to get a connection and return an object for the one specified in
* the menu path as '%islandora_object'.
*
* @param string $object_id
* The pid of an object in the menu path identified by '%islandora_object'.
*
* @return FedoraObject
* If the given object id exists in the repository then this returns a
* FedoraObject.
* If no object was found it returns FALSE which triggers
* drupal_page_not_found().
* If the object was inaccessible then NULL is returned, and the
* access callback is expected to catch that case, triggering
* drupal_access_denied().
*/
function islandora_object_load($object_id) {
  $tuque = islandora_get_tuque_connection();
  if ($tuque) {
    try {
      return $tuque->repository->getObject(urldecode($object_id));
    }
    catch (Exception $e) {
      if ($e->getCode() == '404') {
        return FALSE;
      }
      else {
        return NULL;
      }
    }
  }
  else {
    IslandoraTuque::getError();
  }
  // Assuming access denied in all other cases for now.
  return NULL;
}

/**
* Just a wrapper around fetchings the IslandoraTuque object.
*
* Includes some very basic error logging.
*
* @param object $user
* The user to connect as.
* @param string $url
* The URL to connect to.
*
* @return IslandoraTuque
* A IslandoraTuque instance
*/
function islandora_get_tuque_connection($user = NULL, $url = NULL) {
  static $tuque = NULL;
  if (!$tuque) {
    if (IslandoraTuque::exists()) {
      try {
        $tuque = new IslandoraTuque($user, $url);
      }
      catch (Exception $e) {
        drupal_set_message(t('Unable to connect to the repository %e', array('%e' => $e)), 'error');
      }
    }
    else {
      return NULL;
    }
  }
  return $tuque;
}
