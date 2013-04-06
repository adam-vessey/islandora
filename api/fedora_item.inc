<?php

/**
 * @file
 * Fedora Item
 */
define('RELS_EXT_URI', 'info:fedora/fedora-system:def/relations-external#');
define("FEDORA_MODEL_URI", 'info:fedora/fedora-system:def/model#');
define("ISLANDORA_PAGE_URI", 'info:islandora/islandora-system:def/pageinfo#');
define("ISLANDORA_RELS_EXT_URI", 'http://islandora.ca/ontology/relsext#');
define("ISLANDORA_RELS_INT_URI", "http://islandora.ca/ontology/relsint#");

define("RELS_TYPE_URI", 0);
define("RELS_TYPE_PLAIN_LITERAL", 1);
define("RELS_TYPE_STRING", 2);
define("RELS_TYPE_INT", 3);
define("RELS_TYPE_DATETIME", 4);

/**
 * Fedora Item Class
 *
 * @deprecated
 *   Deprecated in favour of using Tuque directly.
 */
class Fedora_Item {
  // The $pid of the fedora object represented by an instance of this class.
  public $pid = NULL;
  public $objectProfile = NULL;
  public $ownerId = NULL;
  // A SimpleXML object to store a list of this item's datastreams.
  private $datastreams_list = NULL;
  public $datastreams = NULL;
  private static $connection_helper = NULL;
  private static $instantiated_pids = array();
  private static $SoapManagedFunctions = array(
    'ingest',
    'addDataStream',
    'addRelationship',
    'export',
    'getDatastream',
    'getDatastreamHistory',
    'getNextPID',
    'getRelationships',
    'modifyDatastreamByValue',
    'modifyDatastreamByReference',
    'purgeDatastream',
    'purgeObject',
    'modifyObject',
    'setDatastreamState',
  );

  /**
   * Create an object to represent an item in the Fedora repository.
   * Throws a SOAPException if the PID is not in the repository.
   *
   * @param string $pid
   *   The Fedora PID to create an object for.
   *
   * @return Fedora_Item
   */
  function __construct($pid) {
    drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);
    module_load_include('inc', 'fedora_repository', 'ConnectionHelper');
    module_load_include('inc', 'fedora_repository', 'api/fedora_utils');
    module_load_include('inc', 'fedora_repository', 'api/tuque');

    $this->pid = $pid;
    if (isset(Fedora_Item::$instantiated_pids[$pid])) {
      $this->objectProfile = & Fedora_Item::$instantiated_pids[$pid]->objectProfile;
      $this->datastreams = & Fedora_Item::$instantiated_pids[$pid]->datastreams;
      $this->datastreams_list = & Fedora_Item::$instantiated_pids[$pid]->datastreams_list;
      $this->ownerId = & Fedora_Item::$instantiated_pids[$pid]->ownerId;
    }
    else {
      if (empty(self::$connection_helper)) {
        self::$connection_helper = new ConnectionHelper();
      }

      $raw_objprofile = $this->soap_call('getObjectProfile', array('pid' => $this->pid, 'asOfDateTime' => ""), TRUE);

      if (!empty($raw_objprofile)) {
        $this->objectProfile = $raw_objprofile->objectProfile;
        $this->datastreams = $this->get_datastreams_list_as_array();
        $this->ownerId = Fedora_Item::getOwnerId($pid);
      }
      else {
        $this->objectProfile = '';
        $this->datastreams = array();
        $this->ownerId = NULL;
      }
      
      // Allow only up to 100 items in the cache...  Maybe should be lower?
      for ($i = count(Fedora_Item::$instantiated_pids); $i >= 100; $i--) {
        array_shift(Fedora_Item::$instantiated_pids[$pid]);
      }
      
      Fedora_Item::$instantiated_pids[$pid] = &$this;
    }
  }
  
  /**
   * Get a Tuque FedoraObject representing this Fedora_Item.
   */
  protected function get_tuque_object() {
    $object = islandora_object_load($this->pid);
    return $object;
  }

  /**
   * Forget this Object, do manually when memory constraints apply.
   *
   * Removes this object from the static list of $instantiated_pids
   */
  function forget() {
    unset(Fedora_Item::$instantiated_pids[$this->pid]);
  }

  /**
   * Exists
   *
   * @return type
   */
  function exists() {
    return (!empty($this->objectProfile));
  }

  /**
   * Add datastream from file
   *
   * @param type $datastream_file
   * @param type $datastream_id
   * @param type $datastream_label
   * @param type $datastream_mimetype
   * @param type $controlGroup
   * @param type $logMessage
   *
   * @return type
   */
  function add_datastream_from_file($datastream_file, $datastream_id, $datastream_label = NULL, $datastream_mimetype = '', $controlGroup = 'M', $logMessage = NULL) {
    if (empty($datastream_mimetype)) {
      // Get mime type from the file extension.
      $mimetype_helper = new MimeClass();
      $datastream_mimetype = $mimetype_helper->getType($datastream_file);
    }
    
    $object = $this->get_tuque_object();
    $datastream = $object->constructDatastream($datastream_id, $controlGroup);
    $datastream->mimetype = $datastream_mimetype;
    $datastream->label = $datastream_label;
    $datastream->logMessage = ($logMessage != NULL) ? $logMessage : 'Ingested object ' . $datastream_id;
    $datastream->setContentFromFile($datastream_file);
    $result = $object->ingestDatastream($datastream);
    
        // Add new DS to the DS list so the item is in sync with the repository.
    $this->datastreams[$datastream_id] = array(
      'label' => $datastream_label,
      'MIMEType' => $datastream_mimetype,
      'URL' => ($this->url() . '/' . $datastream_id . '/' .
      drupal_urlencode($datastream_label)),);
      
    return $result;
  }

  /**
   * Add datastream from url
   *
   * @param type $datastream_url
   * @param type $datastream_id
   * @param type $datastream_label
   * @param type $datastream_mimetype
   * @param type $controlGroup
   * @param type $logMessage
   *
   * @return type
   */
  function add_datastream_from_url($datastream_url, $datastream_id, $datastream_label = NULL, $datastream_mimetype = '', $controlGroup = 'M', $logMessage = NULL) {
    global $base_url;

    if (empty($datastream_label)) {
      $datastream_label = $datastream_id;
    }

    // Fedora has some problems getting files from HTTPS connections sometimes, so if we are getting a file
    // from the local drupal, we try to pass a HTTP url instead of a HTTPS one.
    if (stripos($datastream_url, 'https://') !== FALSE && stripos($datastream_url, $base_url) !== FALSE) {
      $datastream_url = str_ireplace('https://', 'http://', $datastream_url);
    }
    $datastream_label = truncate_utf8($datastream_label, 255, TRUE, TRUE);
    
    $object = $this->get_tuque_object();
    $datastream = $object->constructDatastream($datastream_id, $controlGroup);
    $datastream->label = $datastream_label;
    $datastream->mimetype = $datastream_mimetype;
    $datastream->setContentFromUrl($datastream_url);
    $datastream->logMessage = ($logMessage != NULL) ? $logMessage : 'Ingested object ' . $datastream_id;
    $result = $object->ingestDatastream($datastream);


    // Add new DS to the DS list so the item is in sync with the repository.
    $this->datastreams[$datastream_id] = array(
      'label' => $datastream_label,
      'MIMEType' => $datastream_mimetype,
      'URL' => ($this->url() . '/' . $datastream_id . '/' .
      drupal_urlencode($datastream_label)),);

    return $result;
  }

  /**
   * Add datastream from string
   *
   * @param type $str
   * @param type $datastream_id
   * @param type $datastream_label
   * @param type $datastream_mimetype
   * @param type $controlGroup
   * @param type $logMessage
   *
   * @return type
   */
  function add_datastream_from_string($str, $datastream_id, $datastream_label = NULL, $datastream_mimetype = 'text/xml', $controlGroup = 'M', $logMessage = NULL) {
    $object = $this->get_tuque_object();
    $datastream = $object->constructDatastream($datastream_id, $controlGroup);
    $datastream->mimetype = $datastream_mimetype;
    $datastream->label = $datastream_label;
    $datastream->logMessage = ($logMessage != NULL) ? $logMessage : 'Ingested object ' . $datastream_id;
    $datastream->content = $str;
    $result = $object->ingestDatastream($datastream);
    
        // Add new DS to the DS list so the item is in sync with the repository.
    $this->datastreams[$datastream_id] = array(
      'label' => $datastream_label,
      'MIMEType' => $datastream_mimetype,
      'URL' => ($this->url() . '/' . $datastream_id . '/' .
      drupal_urlencode($datastream_label)),);
      
    return $result;
  }

  /**
   * Wrapper to add new or modify existing datastream
   *
   * @global url $base_url
   *
   * @param url $external_url
   * @param string $dsid
   * @param string $label
   * @param string $mime_type
   * @param string $controlGroup
   * @param boolean $force
   * @param string $logMessage
   * @param boolean $quiet
   */
  function add_or_modify_by_reference($external_url, $dsid, $label, $mime_type, $controlGroup = 'M', $force = FALSE, $logMessage = 'Modified by Islandora API', $quiet=FALSE) {
    global $base_url;
    if (array_key_exists($dsid, $this->datastreams)) {
      $this->modify_datastream_by_reference($external_url, $dsid, $label, $mime_type, $force, $logMessage, $quiet);
    }
    else {
      $file_location = str_replace("$base_url/", '', $external_url);
      $this->add_datastream_from_file($file_location, $dsid, $label, $mime_type, $controlGroup = 'M', $logMessage = NULL);
    }
  }

  /**
   *
   * @param unknown_type $el
   * @param unknown_type $object
   * @param unknown_type $type
   */
  protected function buildRelsStatement(&$el, $object, $type) {
    if ($type > 0) {
      $el->appendChild($el->ownerDocument->createTextNode($object));
      if ($type == RELS_TYPE_STRING) {
        $el->setAttribute('rdf:datatype', 'http://www.w3.org/2001/XMLSchema#string');
      }
      elseif ($type == RELS_TYPE_INT) {
        $el->setAttribute('rdf:datatype', 'http://www.w3.org/2001/XMLSchema#int');
      }
      elseif ($type == RELS_TYPE_DATETIME) {
        $el->setAttribute('rdf:datatype', 'http://www.w3.org/2001/XMLSchema#dateTime');
      }
      else {
        // Plain literal.
      }
    }
    else {
      $el->setAttribute('rdf:resource', $object);
    }
  }

  /**
   * Add a relationship string to this object's RELS-EXT.
   *
   * does not support rels-int yet.
   *
   * @param string $relationship
   *   The predicate/relationship tag to add
   * @param string|array $object
   *   The object(s) to be related to.
   * @param string $namespaceURI
   *   The predicate namespace.
   * @param int $literal_value
   *   Used to type the value.
   *  - 0: URI
   *  - 1: plain literal
   *  - 2: string (explicitly typed)
   *  - 3: integer
   *  - 4: dateTime
   *
   * @return ???
   *   Value returned from SOAP call for modify_datastream.
   */
  function add_relationship($relationship, $object, $namespaceURI = RELS_EXT_URI, $literal_value = RELS_TYPE_URI) {
    $tuque_object = $this->get_tuque_object();;
    return $tuque_object->relationships->add($namespaceURI, $relationship, $object, $literal_value);
  }

  /**
   * Extension of add_relationship(), which acts on RELS-INT.
   *
   * @param $dsid
   *   A string containing either the base dsid (EXAMPLE)
   *   or URI to the datastream (info:fedora/pid/EXAMPLE)
   */
  function add_dsid_relationship($dsid, $relationship, $object, $namespaceURI = RELS_EXT_URI, $literal_value = RELS_TYPE_URI) {
      $tuque_object = $this->get_tuque_object();;
      return $tuque_object[$dsid]->relationships->add($namespaceURI, $relationship, $object, $literal_value);
  }

  /**
   * Purge/delete relationships string from this object's RELS-EXT.
   *
   * does not support rels-int yet.
   *
   * @param string $relationship
   *   The predicate/relationship tag to delete
   * @param string $object
   *   The object to be related to. (NULL/value for which empty()
   *   evaluates to true will remove all relations of the given type,
   *   ignoring $literal_value)
   * @param string $namespaceURI
   *   The predicate namespace.
   * @param int $literal_value
   *   Same as add_relationship.  NOTE: dateTime implemented.
   * @return boolean
   *   Whether or not this operation has produced any changes in the RELS-EXT
   */
  function purge_relationships($relationship, $object, $namespaceURI = RELS_EXT_URI, $literal_value = FALSE) {
    $tuque_object = $this->get_tuque_object();;
    return $tuque_object->relationships->remove($namespaceURI, $relationship, $object, $literal_value);
  }

  /**
   * Extension of purge_relationships, which acts on RELS-INT.
   *
   * @param string $dsid
   *   A string containing either the base dsid (EXAMPLE)
   *   or URI to the datastream (info:fedora/pid/EXAMPLE)
   */
  function purge_dsid_relationships($dsid, $relationship, $object, $namespaceURI = RELS_EXT_URI, $literal_value = FALSE) {
    $tuque_object = $this->get_tuque_object();;
    return $tuque_object[$dsid]->relationships->remove($namespaceURI, $relationship, $object, $literal_value);
  }

  /**
   * Removes the given relationship from the item's RELS-EXT and re-saves it.
   *
   * @deprecated
   *   Dropped in favour of purge_relationships, which follows the same paradigm as add_relationship.  This function tries to figure out the predicate URI based on the prefix/predicate given, which requires specific naming...
   * @param string $relationship
   * @param string $object
   */
  function purge_relationship($relationship, $object) {
    $namespaceURI = 'info:fedora/fedora-system:def/relations-external#';
    // Pre-pend a namespace prefix to recognized relationships

    switch ($relationship) {
      case 'rel:isMemberOf':
      case 'fedora:isMemberOf':
        $relationship = "isMemberOf";
        $namespaceURI = 'info:fedora/fedora-system:def/relations-external#';
        break;
      case "rel:isMemberOfCollection":
      case "fedora:isMemberOfCollection":
        $relationship = "isMemberOfCollection";
        $namespaceURI = 'info:fedora/fedora-system:def/relations-external#';
        break;
      case "fedora:isPartOf":
        $relationship = "isPartOf";
        $namespaceURI = 'info:fedora/fedora-system:def/relations-external#';
        break;
      case "rel:hasModel":
      case "hasModel":
        $relationship = "hasModel";
        $namespaceURI = FEDORA_MODEL_URI;
        break;
      case "isPageNumber":
        $relationship = "isPageNumber";
        $namespaceURI = ISLANDORA_PAGE_URI;
        break;
    }
    
    return $this->purge_relationships($namespaceURI, $relationship, $object);
  }

  /**
   * Export as foxml
   *
   * @return type
   */
  function export_as_foxml() {
    $tuque = islandora_get_tuque_connection();
    return $tuque->repository->api->m->export($this->pid, array(
      'format' => 'info:fedora/fedora-system:FOXML-1.1',
      'context' => 'migrate',
    ));
  }

  /**
   * Does a search using the "query" format followed by the Fedora REST APi.
   *
   * @param string $pattern to search for, including wildcards.
   * @param string $field The field to search on, e.g. pid, title, cDate. See http://www.fedora-commons.org/confluence/display/FCR30/REST+API#RESTAPI-findObjects for details
   * @param int $max_results not used at this time
   *
   * @return Array of pid => title pairs that match the results
   */
  static function find_objects_by_pattern($pattern = '*', $field = 'pid', $max_results = 100, $resultFields = array()) {
    module_load_include('inc', 'fedora_repository', 'api/fedora_utils');
    $fedora_url = variable_get('fedora_base_url', 'http://localhost:8080/fedora');

    $pattern = drupal_urlencode($pattern);
    $done = FALSE;
    $cursor = 0;
    $session_token = '';
    $i = 0;
    $results = array();
    while (!$done && $i < 5) {
      $i++;
      
      $parameters = array(
        'pid' => 'true',
        'title' => 'true',
        'resultFormat' => 'xml',
        'maxResults' => $max_results,
      );
      if (count($resultFields) > 0) {
        foreach ($resultFields as $field) {
          $parameters[$field] = 'true';
        }
      }
      
      if ($cursor == 0) {
        $parameters += array(
          'query' => "$field~$pattern",
        );
      }
      else {
        $parameters += array(
          'sessionToken' => $session_token,
        );
      }

      $resultxml = do_curl(url("$fedora_url/objects", array(
        'query' => $parameters,
      )));
      libxml_use_internal_errors(TRUE);
      $resultelements = simplexml_load_string($resultxml);
      if ($resultelements === FALSE) {
        libxml_clear_errors();
        break;
      }
      $cursor += count($resultelements->resultList->objectFields);
      if (count($resultelements->resultList->objectFields) < $max_results
          || count($resultelements->resultList->objectFields) == 0) {
        $done = TRUE;
      }
      foreach ($resultelements->resultList->objectFields as $obj) {

        $ret = (string) $obj->title;
        if (count($resultFields) > 0) {
          $ret = array('title' => $ret);
          foreach ($resultFields as $field) {
            $ret[$field] = (string) $obj->$field;
          }
        }
        $results[(string) $obj->pid] = $ret;
        $cursor++;
        if ($cursor >= $max_results) {
          $done = TRUE;
          break;
        }
      }
      $session_token = $resultelements->listSession->token;
      $done = !empty($session_token);
    }
    return $results;
  }

  /**
   * Get datastream dissemination
   *
   * @param string $dsid
   *   The DSID of the datastream to get the dissemination of.
   * @param string $as_of_date_time
   *   We can get old versions of versioned datastreams.
   *   Defaults to now.
   * @param type $quiet
   *   Squash errors? defaults to FALSE.
   *
   * @return mixed
   *   NULL if the DS is not present in Fedora Item's datastream list.
   *   The content of the DS (NULL if empty)
   */
  function get_datastream_dissemination($dsid, $as_of_date_time = NULL, $quiet = FALSE) {
    if (!array_key_exists($dsid, $this->datastreams)) {
      return NULL;
    }

    $tuque = islandora_get_tuque_connection();
    return trim($tuque->repository->api->a->getDatastreamDissemination($this->pid, $dsid, $as_of_date_time));
  }

  /**
   * Get datastream
   *
   * @deprecated
   *   Most properties should be available on the Tuque datastream.
   *
   * @param type $dsid
   * @param type $as_of_date_time
   *
   * @return type
   */
  function get_datastream($dsid, $as_of_date_time = '', $quiet = TRUE) {
    if (!array_key_exists($dsid, $this->datastreams)) {
      return NULL;
    }
    $params = array(
      'pid' => $this->pid,
      'dsID' => $dsid,
      'asOfDateTime' => $as_of_date_time,
    );
    $object = self::soap_call('getDatastream', $params, $quiet);

    return $object->datastream;
  }

  /**
   * Get datastream history
   *
   * @deprecated
   *   Either iterating over a datastream object or some other Tuque call is
   *   preferable.
   *
   * @param type $dsid
   *
   * @return type
   */
  function get_datastream_history($dsid) {
    $params = array(
      'pid' => $this->pid,
      'dsID' => $dsid,
    );
    $object = self::soap_call('getDatastreamHistory', $params);
    $ret = FALSE;
    if (!empty($object)) {
      $ret = $object->datastream;
    }

    return $ret;
  }

  /**
   * Get dissemination
   *
   * @param type $service_definition_pid
   * @param type $method_name
   * @param type $parameters
   * @param type $as_of_date_time
   *
   * @return string
   */
  function get_dissemination($service_definition_pid, $method_name, $parameters = array(), $as_of_date_time = NULL) {
  
    if ($as_of_date_time !== NULL) {
      watchdog('fedora_repository', 'Using SOAP API for getDissemination, due to use of "asOfDateTime".', array(), WATCHDOG_WARNING);
      $params = array(
        'pid' => $this->pid,
        'serviceDefinitionPid' => $service_definition_pid,
        'methodName' => $method_name,
        'parameters' => $parameters,
        'asOfDateTime' => $as_of_date_time,
      );
      
      $object = self::soap_call('getDissemination', $params);
      if (!empty($object)) {
        $content = $object->dissemination->stream;
        $content = trim($content);
      }
      else {
        $content = "";
      }
      return $content;
    }
    else {
      $tuque = islandora_get_tuque_connection();
      return trim($tuque->repository->api->a->getDissemination($this->pid, $service_definition_pid, $method_name));
    }
  }

  /**
   * Retrieves and returns a SimpleXML list of this item's datastreams,
   * and stores them as an instance variable for caching purposes.
   *
   * @deprecated
   *   Still uses the SOAP API, and there are method available which already do
   *   parsing.
   *
   * @return SimpleXMLElement
   */
  function get_datastreams_list_as_SimpleXML() {
    $params = array(
      'pid' => $this->pid,
      'asOfDateTime' => "",
    );

    $this->datastreams_list = $this->soap_call('listDataStreams', $params);

    return $this->datastreams_list;
  }

  /**
   * DatastreamControlGroup controlGroup - String restricted to the values of "X", "M", "R", or "E" (InlineXML,Managed Content,Redirect, or External Referenced).
   * String ID - The datastream ID (64 characters max).
   * String versionID - The ID of the most recent datastream version
   * String[] altIDs - Alternative IDs for the datastream, if any.
   * String label - The Label of the datastream.
   * boolean versionable - Whether the datastream is versionable.
   * String MIMEType - The mime-type for the datastream, if set.
   * String formatURI - The format uri for the datastream, if set.
   * String createDate - The date the first version of the datastream was created.
   * long size - The size of the datastream in Fedora. Only valid for inline XML metadata and managed content datastreams.
   * String state - The state of the datastream. Will be "A" (active), "I" (inactive) or "D" (deleted).
   * String location - If the datastream is an external reference or redirect, the url to the contents. TODO: Managed?
   * String checksumType - The algorithm used to compute the checksum. One of "DEFAULT", "DISABLED", "MD5", "SHA-1", "SHA-256", "SHA-385", "SHA-512".
   * String checksum - The value of the checksum represented as a hexadecimal string.
   *
   * @deprecated
   *   Using calls on Tuque directly should be preferred.
   *
   * @param string $dsid
   *
   * @return datastream object
   *   get the mimetype size etc. in one shot.  instead of iterating throught the datastream list for what we need
   */
  function get_datastream_info($dsid, $as_of_date_time = '') {
    $params = array(
      'pid' => $this->pid,
      'dsID' => $dsid,
      'asOfDateTime' => $as_of_date_time,
    );

    return $this->soap_call('getDatastream', $params);
  }

  /**
   * Returns an associative array of this object's datastreams.
   * Results look like this:
   *
   *  'DC' =>
   *    array
   *      'label' => string 'Dublin Core Record for this object' (length=34)
   *      'MIMEType' => string 'text/xml' (length=8)
   *  'RELS-EXT' =>
   *    array
   *      'label' => string 'RDF Statements about this object' (length=32)
   *      'MIMEType' => string 'application/rdf+xml' (length=19)
   *
   * @return array
   */
  function get_datastreams_list_as_array() {
    $ds_list = array();
    $object = $this->get_tuque_object();
    foreach ($object as $dsid => $datastream) {
      $ds_list[$dsid] = array(
        'label' => $datastream->label,
        'MIMEType' => $datastream->mimetype,
        'URL' => t('!object_url/!datastream_id/!label', array(
          '!object_url' => $this->url(),
          '!datastream_id' => $dsid,
          '!label' => drupal_urlencode($datastream->label),
        )),
      );
    }

    return $ds_list;
  }

  /**
   * Returns a MIME type string for the given Datastream ID.
   *
   * @param string $dsid
   * @return string
   */
  function get_mimetype_of_datastream($dsid) {
    $this->get_datastreams_list_as_SimpleXML();

    $mimetype = '';
    foreach ($this->datastreams_list as $datastream) {
      foreach ($datastream as $datastreamValue) {
        if ($datastreamValue->ID == $dsid) {
          return $datastreamValue->MIMEType;
        }
      }
    }

    return '';
  }

  /**
   * Currently the Fedora API call getRelationships is reporting an uncaught
   * exception so we will parse the RELS-EXT ourselves and simulate the
   * documented behaviour.
   *
   * @param String $relationship - filter the results to match this string.
   */
  function get_relationships($relationship = NULL) {
    $relationships = array();
    
    $object = $this->get_tuque_object();
    foreach ($object->relationships->get(RELS_EXT_URI) as $rel) {
      if (empty($relationship) || preg_match("/$relationship/", $rel['predicate']['value'])) {
        $relationships[] = array(
          'subject' => $this->pid,
          'predicate' => $rel['predicate']['value'],
          'object' => $rel['object']['value'],
        );
      }
    }
    
    return $relationships;
  }

  /**
   * Retrieves RELS-EXT values from item
   *
   * @param array $namespaces
   *
   * @return array
   */
  function get_rdf_relationships($namespaces = null) {
    if ($namespaces == NULL) {
      $namespaces = array(
        RELS_EXT_URI,
        FEDORA_MODEL_URI,
        ISLANDORA_PAGE_URI,
        ISLANDORA_RELS_EXT_URI,
        ISLANDORA_RELS_INT_URI,
      );
    }
    if (!is_array($namespaces)) {
      $namespaces = array($namespaces);
    }
    $relationships = array();
    
    $object = $this->get_tuque_object();
    foreach ($namespaces as $namespace) {
      foreach ($object->relationships->get($namespace) as $rel) {
        $relationships[$rel['predicate']['value']][] = $rel['object']['value'];
      }
    }

    return $relationships;
  }

  function get_models() {
    //This is/was formerly just a copy/paste jobbie, without the parameter being passable...
    return $this->get_relationships();
  }

  /**
   * Set the object to a deleted state
   */
  function move_to_trash($log_message = 'Flagged deleted using Islandora API.', $quiet = TRUE) {
    $object = $this->get_tuque_object();
    foreach ($object as $datastream) {
      $datastream->state = 'D';
    }
    $object->logMessage = $log_message;
    $object->state = 'D';

    return TRUE;
  }

  /**
   * Removes this object form the repository.
   * @param type $log_message
   * @param type $force
   * @return type
   */
  function purge($log_message = 'Purged using Islandora API.', $force = FALSE) {
    // Flag the object to be deleted first.
    $this->move_to_trash($log_message);
    $tuque = islandora_get_tuque_connection();
    
    $result = $tuque->repository->purgeObject($this->pid);

    $this->forget();

    return $result;
  }

  /**
   * Purge datastream
   *
   * @param type $dsID
   * @param type $start_date
   * @param type $end_date
   * @param type $log_message
   * @param type $force
   *
   * @return type
   */
  function purge_datastream($dsID, $start_date = NULL, $end_date = NULL, $log_message = 'Purged datastream using Islandora API', $force = FALSE) {
    if (empty($start_date) && empty($end_date)) {
      $start = empty($start_date)?FALSE:strtotime($start_date);
      $end = empty($end_date)?FALSE:strtotime($end);

      $tuque = islandora_get_tuque_connection();
      $result = $tuque->repository->api->m->purgeDatastream($this->pid, $dsID, array(
        'startDT' => $start_date,
        'endDT' => $end_date,
        'logMessage' => $log_message,
      ));
    }
    else {
      $object = $this->get_tuque_object();
      unset($object[$dsID]);
      $result = TRUE;
    }

    // Make sure to refresh the datastream list after adding so this item stays in sync with the repository.
    $this->datastreams = $this->get_datastreams_list_as_array();
    return $result;
  }

  /**
   * URL
   * @global type $base_url
   *
   * @return type
   */
  function url() {
    return url(t('fedora/repository/!pid!label_part', array(
        '!pid' => $this->pid,
        '!label_part' => (!empty($this->objectProfile)?
            '/-/' . drupal_urlencode($this->objectProfile->objLabel):
            ''),
      )), array(
      'absolute' => TRUE,
    ));
  }

  /**
   * Get Next PID in Namespace
   *
   * @param $pid_namespace string
   *
   * @return string
   */
  static function get_next_PID_in_namespace($pid_namespace = '', $number_of_pids = 1) {
    $tuque = islandora_get_tuque_connection();
    return $tuque->repository->api->m->getNextPid($pid_namespace, $number_of_pids);
  }

  /**
   * ingest from FOXML
   *
   * @param type $foxml
   *
   * @return Fedora_Item
   */
  static function ingest_from_FOXML(DOMDocument $foxml) {
    $tuque = islandora_get_tuque_connection();
    return new Fedora_Item($tuque->repository->api->m->ingest(array(
      'string' => $foxml->saveXML(),
      'format' => 'info:fedora/fedora-system:FOXML-1.1',
      'logMessage' => 'Fedora Object Ingested',
    )));
  }

  /**
   * ingest from FOXML file
   *
   * @param type $foxml_file
   *
   * @return type
   */
  static function ingest_from_FOXML_file($foxml_file) {
    $tuque = islandora_get_tuque_connection();
    return new Fedora_Item($tuque->repository->api->m->ingest(array(
      'file' => $foxml_file,
      'format' => 'info:fedora/fedora-system:FOXML-1.1',
      'logMessage' => 'Fedora Object Ingested',
    )));
  }

  /**
   * ingest from FOXML files in directory
   *
   * @param type $path
   */
  static function ingest_from_FOXML_files_in_directory($path) {
    // Open the directory.
    $dir_handle = @opendir($path);
    // Loop through the files.
    while ($file = readdir($dir_handle)) {
      if ($file == "." || $file == ".." || strtolower(substr($file, strlen($file) - 4)) != '.xml') {
        continue;
      }

      try {
        self::ingest_from_FOXML_file($path . '/' . $file);
      } catch (exception $e) {

      }
    }
    // Close.
    closedir($dir_handle);
  }

  /**
   * Modify Object
   *
   * @param $label string
   * @param $state string
   * @param $ownerId string
   * @param $logMessage string
   * @param $quiet boolean
   *
   * @return type
   */
  function modify_object($label = '', $state = NULL, $ownerId = NULL, $logMessage = 'Modified by Islandora API', $quiet=TRUE) {
    $params = array(
      'pid' => $this->pid,
      'owner' => $this->ownerId,
      'state' => $state,
      'label' => $label,
      //'logMessage' => $logMessage,
    );
    $params = array_filter($params);
    if ($params) {
      $object = $this->get_tuque_object();
      
      foreach ($params as $param => $value) {
        $object->logMessage;
        $object->$param = $value;
      }
    }
  }

  /**
   * Work around function, due to file_create_url not URL encoding stuff.
   *
   * Parses and reassembles the URL, exploding, rawurlencoding and imploding
   * the path components along the way.
   *
   * @param $url
   *   A string containing an HTTP(S) URL to attempt.
   *
   * @return string
   *   The results of the HTTP request if successful; boolean FALSE otherwise.
   */
  protected static function try_http_get_content($url) {
    // Can throw a warning prior to 5.3.3.
    $parsed_url = @parse_url($url);
    $supported_schemes = array(
      'http',
      'https',
    );

    $content = FALSE;

    if ($parsed_url && array_key_exists('scheme', $parsed_url) && in_array($parsed_url['scheme'], $supported_schemes)) {
      $components = explode('/', $parsed_url['path']);
      $components = array_map('rawurlencode', $components);
      $fixed_url = url(
              t(
                  '!scheme://!user:!pass@!host:!port/!path',
                  array(
                    '!scheme' => $parsed_url['scheme'],
                    '!user' => rawurlencode($parsed_url['user']),
                    '!pass' => rawurlencode($parsed_url['pass']),
                    '!host' => $parsed_url['host'],
                    '!port' => $parsed_url['port'],
                    '!path' => implode('/', $components),
                  )
              ),
              array(
                'query' => $parsed_url['query'],
                'fragment' => $parsed_url['fragment'],
              )
      );
      $result = drupal_http_request($fixed_url);

      if ((int) ($result->code / 100) === 2) {
        $content = $result->data;
      }
      else {
        watchdog('fedora_repository', 'Failed making HTTP request to @URL.  Info: @info', array(
          '@URL' => $fixed_url,
          '@info' => print_r($result, TRUE),
            ), 'warning');
      }
    }

    return $content;
  }

  /**
   *  Wrap modify by value and reference
   *
   *  Wrap modify by value and reference, so that the proper one gets called
   *  in the correct instance. (value if inline XML, reference otherwise)
   *
   *  First tries to treat the passed in value as a filename, tries using as contents second.
   *  Coerces the data into what is required, and passes it on to the relevant function.
   *
   * @param string $filename_or_content
   *   Either a filename to add, or an string of content.
   * @param string $dsid
   *   The DSID to update
   * @param string $label
   *   A label to withwhich to update the datastream
   * @param string $mime_type
   *   Mimetype for the data being pushed into the datastream
   * @param boolean $force
   *   Dunno, refer to underlying functions/SOAP interface.  We just pass it like a chump.
   * @param string $logMessage
   *   A message for the audit log.
   * @param boolean $quiet
   *   Error suppression?  Refer to soap_call for usage
   *   (just passed along here).
   */
  function modify_datastream($filename_or_content, $dsid, $label, $mime_type, $force = FALSE, $logMessage='Modified by Islandora API', $quiet=FALSE) {
    $toReturn = NULL;
    $object = $this->get_tuque_object();
    $datastream = $object[$dsid];

    $datastream->logMessage = $logMessage;
    $datastream->label = $label;
    $datastream->mimetype = $mime_type;

    // Determine if it's inline xml; if it is, modify by value.
    if (in_array($datastream->controlGroup, array('X', 'M'))) {

      $content = '<null/>';
      if (is_file($filename_or_content) && is_readable($filename_or_content)) {
        $datastream->setContentFromFile($filename_or_content);
      }
      else {
        // XXX:  Get the contents to deal with fopen not being allowed for remote access
        //in some OSs
        $temp_content = self::try_http_get_content($filename_or_content);
        if ($temp_content !== FALSE) {
          $toReturn = $datastream->setContentFromString($temp_content);
        }
        else {
          $toReturn = $datastream->setContentFromString($filename_or_content);
        }
      }
    }
    else {
      $datastream->url = $filename_or_content;
      $toReturn = TRUE;
    }

    return $toReturn;
  }

  /**
   * Modify datastream by reference
   *
   * @param type $external_url
   * @param type $dsid
   * @param type $label
   * @param type $mime_type
   * @param type $force
   * @param type $logMessage
   * @param type $quiet
   *
   * @return type
   */
  function modify_datastream_by_reference($external_url, $dsid, $label, $mime_type, $force = FALSE, $logMessage = 'Modified by Islandora API', $quiet=FALSE) {
    global $base_url;

    // Fedora has some problems getting files from HTTPS connections sometimes, so if we are getting a file
    // from the local drupal, we try to pass a HTTP url instead of a HTTPS one.
    if (stripos($external_url, 'https://') !== FALSE && stripos($external_url, $base_url) !== FALSE) {
      $external_url = str_ireplace('https://', 'http://', $external_url);
    }

    $object = $this->get_tuque_object();
    $datastream = $object[$dsid];
    
    $datastream->logMessage = $logMessage;
    $datastream->label = $label;
    $datastream->mimetype = $mime_type;
    $datastream->url = $external_url;
    
    return TRUE;
  }

  /**
   * Modify datastream by value
   *
   * @param type $content
   * @param type $dsid
   * @param type $label
   * @param type $mime_type
   * @param type $force
   * @param type $logMessage
   * @param type $quiet
   *
   * @return type
   */
  function modify_datastream_by_value($content, $dsid, $label, $mime_type, $force = FALSE, $logMessage = 'Modified by Islandora API', $quiet=FALSE) {
    $object = $this->get_tuque_object();
    $datastream = $object[$dsid];
    
    $datastream->logMessage = $logMessage;
    $datastream->label = $label;
    $datastream->mimetype = $mime_type;
    $datastream->content = $content;
    
    return TRUE;
  }

  /**
   *
   * @param unknown_type $dsid
   * @param unknown_type $state
   * @param unknown_type $log_message
   * @param unknown_type $quiet
   */
  function set_datastream_state($dsid, $state, $log_message = 'Modified by Islandora API', $quiet = FALSE) {
    $object = $this->get_tuque_object();
    $datastream = $object[$dsid];
    
    $datastream->logMessage = $logMessage;
    $datastream->state = $state;
  }

  /**
   * Make a soap call to the fedora API.
   *
   * @deprecated
   *   Calls should be made via Tuque.
   *
   * @param string $function
   *   The name of the soap function to call.
   * @param array $parameters
   *   Paramters to pass onto the soap call
   * @param boolean $quiet
   *   If TRUE suppress drupal messages.
   *
   * @return mixed
   *   The return value from the soap function if successful, NULL otherwise.
   */
  static function soap_call($function, $parameters, $quiet = FALSE) {
    module_load_include('inc', 'fedora_repository', 'ObjectHelper');
    if (!self::$connection_helper) {
      module_load_include('inc', 'fedora_repository', 'ConnectionHelper');
      self::$connection_helper = new ConnectionHelper();
    }
    $url = (
        in_array($function, self::$SoapManagedFunctions) ?
            variable_get('fedora_soap_manage_url', 'http://localhost:8080/fedora/wsdl?api=API-M') :
            variable_get('fedora_soap_url', 'http://localhost:8080/fedora/services/access?wsdl')
        );
    try {
      $soap_client = self::$connection_helper->getSoapClient($url);
      if (isset($soap_client)) {
        $result = $soap_client->__soapCall($function, array('parameters' => $parameters));
      }
      else {
        if (!$quiet) {
          drupal_set_message(t('Error trying to get SOAP client connection'));
        }
        watchdog('fedora_repository', 'Error trying to get SOAP client connection.');
        return NULL;
      }
    } catch (Exception $e) {
      if (!$quiet && user_access($VIEW_FEDORA_SOAP_ERROR_MESSAGES)) {
        preg_match('/org\.fcrepo\.server\.security\.xacml\.pep\.AuthzDeniedException/', $e->getMessage()) ?
                drupal_set_message(t('Insufficient permissions to call SOAP function "%func".', array('%func' => $function)), 'error') :
                drupal_set_message(t('Error trying to call SOAP function "%func". Check watchdog logs for more information.', array('%func' => $function)), 'error');
      }
      watchdog('fedora_repository', 'Error Trying to call SOAP function "%func". Exception: @e in @f(@l)\n@t', array('%func' => $function, '@e' => $e->getMessage(), '@f' => $e->getFile(), '@l' => $e->getLine(), '@t' => $e->getTraceAsString()), NULL, WATCHDOG_ERROR);
      return NULL;
    }
    return $result;
  }
  
  protected static function create_new_tuque_object($pid = '', $state = 'A', $label = 'Untitled', $owner = '') {
    $tuque = islandora_get_tuque_connection();
    $new_object = $tuque->repository->constructObject($pid);
    $new_object->label = truncate_utf8($label, 255, TRUE, TRUE);
    $new_object->state = $state;
    
    if (empty($owner)) {
      global $user;
      // Default to current Drupal user.
      if (!empty($user->name)) {
        $new_object->owner = $user->name;
      }
      // We are annonamous user.
      elseif ($user->uid == 0) {
        $new_object->owner = 'anonymous';
      }
    }
    
    return $new_object;
  }

  /**
   * Creates the minimal FOXML for a new Fedora object, which is then passed to
   * ingest_from_FOXML to be added to the repository.
   *
   * @param string $pid if none given, getnextpid will be called.
   * @param string $state The initial state, A - Active, I - Inactive, D - Deleted
   * @param type $label
   * @param string $owner
   *   Used to set an object's ownerId attribute.  Defaults to current user's
   *   name. If we are not a Drupal user(ie. Drush) defaults to ''.
   *
   * @return DOMDocument
   */
  static function create_object_FOXML($pid = '', $state = 'A', $label = 'Untitled', $owner = '') {
    $new_object = self::create_new_tuque_object($pid, $state, $label, $owner);

    $foxml = new FoxmlDocument($new_object);    
    
    return $foxml;
  }

  /**
   * ingest new item
   *
   * @param type $pid
   * @param type $state
   * @param type $label
   * @param type $owner
   *
   * @return type
   */
  static function ingest_new_item($pid = '', $state = 'A', $label = '', $owner = '') {
    $new_object = self::create_new_tuque_object($pid, $state, $label, $owner);
    $pid = $new_object->repository->ingestObject($new_object);
    
    return new Fedora_Item($pid);
  }

  /**
   * fedora item exists
   *
   * @param type $pid
   *
   * @return type
   */
  static function fedora_item_exists($pid) {
    $item = new Fedora_Item($pid);
    return $item->exists();
  }

  /**
   * This function will retrieve the ownerId
   * object property from Fedora using the SOAP API.
   *
   * @param string $PID
   *   The Fedora PID to retrieve the
   */
  static function getOwnerId($PID) {
    $object = islandora_object_load($PID);
    return $object->owner;
  }
}
