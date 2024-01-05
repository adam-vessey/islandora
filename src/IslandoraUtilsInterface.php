<?php

namespace Drupal\islandora;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Utility functions for figuring out when to fire derivative reactions.
 */
interface IslandoraUtilsInterface {

  const EXTERNAL_URI_FIELD = 'field_external_uri';

  const MEDIA_OF_FIELD = 'field_media_of';

  const MEDIA_USAGE_FIELD = 'field_media_use';
  const MEMBER_OF_FIELD = 'field_member_of';
  const MODEL_FIELD = 'field_model';

  /**
   * Gets nodes that a media belongs to.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The Media whose node you are searching for.
   *
   * @return \Drupal\node\NodeInterface|null
   *   Parent node.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   *   Method $field->first() throws if data structure is unset and no item can
   *   be created.
   */
  public function getParentNode(MediaInterface $media) : ?NodeInterface;

  /**
   * Gets media that belong to a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The parent node.
   *
   * @return \Drupal\media\MediaInterface[]
   *   The children Media.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   *   Calling getStorage() throws if the entity type doesn't exist.
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   *   Calling getStorage() throws if the storage handler couldn't be loaded.
   */
  public function getMedia(NodeInterface $node) : array;

  /**
   * Gets media that belong to a node with the specified term.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The parent node.
   * @param \Drupal\taxonomy\TermInterface $term
   *   Taxonomy term.
   *
   * @return \Drupal\media\MediaInterface|null
   *   The child Media.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   *   Calling getStorage() throws if the entity type doesn't exist.
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   *   Calling getStorage() throws if the storage handler couldn't be loaded.
   */
  public function getMediaWithTerm(NodeInterface $node, TermInterface $term) : ?MediaInterface;

  /**
   * Gets Media that reference a File.
   *
   * @param int $fid
   *   File id.
   *
   * @return \Drupal\media\MediaInterface[]
   *   Array of media.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   *   Calling getStorage() throws if the entity type doesn't exist.
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   *   Calling getStorage() throws if the storage handler couldn't be loaded.
   */
  public function getReferencingMedia($fid) : array;

  /**
   * Gets the taxonomy term associated with an external uri.
   *
   * @param string $uri
   *   External uri.
   *
   * @return \Drupal\taxonomy\TermInterface|null
   *   Term or NULL if not found.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   *   Calling getStorage() throws if the entity type doesn't exist.
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   *   Calling getStorage() throws if the storage handler couldn't be loaded.
   */
  public function getTermForUri($uri) : ?TermInterface;

  /**
   * Gets the taxonomy term associated with an external uri.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   Taxonomy term.
   *
   * @return string|null
   *   URI or NULL if not found.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   *   Method $field->first() throws if data structure is unset and no item can
   *   be created.
   */
  public function getUriForTerm(TermInterface $term) : ?string;

  /**
   * Gets every field name that might contain an external uri for a term.
   *
   * @return string[]
   *   Field names for fields that a term may have as an external uri.
   */
  public function getUriFieldNamesForTerms() : array;

  /**
   * Executes context reactions for a Node.
   *
   * @param string $reaction_type
   *   Reaction type.
   * @param \Drupal\node\NodeInterface $node
   *   Node to evaluate contexts and pass to reaction.
   */
  public function executeNodeReactions($reaction_type, NodeInterface $node) : void;

  /**
   * Executes context reactions for a Media.
   *
   * @param string $reaction_type
   *   Reaction type.
   * @param \Drupal\media\MediaInterface $media
   *   Media to evaluate contexts and pass to reaction.
   */
  public function executeMediaReactions($reaction_type, MediaInterface $media) : void;

  /**
   * Executes context reactions for a File.
   *
   * @param string $reaction_type
   *   Reaction type.
   * @param \Drupal\file\FileInterface $file
   *   File to evaluate contexts and pass to reaction.
   */
  public function executeFileReactions($reaction_type, FileInterface $file) : void;

  /**
   * Executes context reactions for a File.
   *
   * @param string $reaction_type
   *   Reaction type.
   * @param \Drupal\taxonomy\TermInterface $term
   *   Term to evaluate contexts and pass to reaction.
   */
  public function executeTermReactions($reaction_type, TermInterface $term) : void;

  /**
   * Executes derivative reactions for a Media and Node.
   *
   * @param string $reaction_type
   *   Reaction type.
   * @param \Drupal\node\NodeInterface $node
   *   Node to pass to reaction.
   * @param \Drupal\media\MediaInterface $media
   *   Media to evaluate contexts.
   */
  public function executeDerivativeReactions($reaction_type, NodeInterface $node, MediaInterface $media) : void;

  /**
   * Evaluates if fields have changed between two instances of a ContentEntity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The updated entity.
   * @param \Drupal\Core\Entity\ContentEntityInterface $original
   *   The original entity.
   *
   * @return bool
   *   TRUE if the fields have changed.
   */
  public function haveFieldsChanged(ContentEntityInterface $entity, ContentEntityInterface $original) : bool;

  /**
   * Returns a list of all available filesystem schemes.
   *
   * @return string[]
   *   List of all available filesystem schemes.
   */
  public function getFilesystemSchemes() : array;

  /**
   * Get array of media ids that have fields that reference $node and $term.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to reference.
   * @param \Drupal\taxonomy\TermInterface $term
   *   The term to reference.
   *
   * @return int[]|null
   *   Array of media IDs or NULL.
   */
  public function getMediaReferencingNodeAndTerm(NodeInterface $node, TermInterface $term) : ?array;

  /**
   * Get the fields on an entity of $entity_type that reference a $target_type.
   *
   * @param string $entity_type
   *   Type of entity to search for.
   * @param string $target_type
   *   Type of entity the field references.
   *
   * @return string[]
   *   Array of fields.
   */
  public function getReferencingFields(string $entity_type, string $target_type) : array;

  /**
   * Gets the id URL of an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity whose URL you want.
   *
   * @return string
   *   The entity URL.
   *
   * @throws \Drupal\Core\Entity\Exception\UndefinedLinkTemplateException
   *   Thrown if the given entity does not specify a "canonical" template.
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  public function getEntityUrl(EntityInterface $entity) : string;

  /**
   * Gets the downloadable URL for a file.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file whose URL you want.
   *
   * @return string
   *   The file URL.
   */
  public function getDownloadUrl(FileInterface $file) : string;

  /**
   * Gets the URL for an entity's REST endpoint.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity whose REST endpoint you want.
   * @param string $format
   *   REST serialization format.
   *
   * @return string
   *   The REST URL.
   */
  public function getRestUrl(EntityInterface $entity, $format = '') : string;

  /**
   * Determines if an entity type and bundle make an 'Islandora' type entity.
   *
   * @param string $entity_type
   *   The entity type ('node', 'media', etc...).
   * @param string $bundle
   *   Entity bundle ('article', 'page', etc...).
   *
   * @return bool
   *   TRUE if the bundle has the correct fields to be an 'Islandora' type.
   */
  public function isIslandoraType(string $entity_type, string $bundle) : bool;

  /**
   * Util function for access handlers .
   *
   * @param string $entity_type
   *   Entity type such as 'node', 'media', 'taxonomy_term', etc..
   * @param string $bundle_type
   *   Bundle type such as 'node_type', 'media_type', 'vocabulary', etc...
   *
   * @return bool
   *   If user can create _at least one_ of the 'Islandora' types requested.
   */
  public function canCreateIslandoraEntity(string $entity_type, string $bundle_type) : bool;

  /**
   * Recursively finds ancestors of an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being checked.
   * @param array $fields
   *   An optional array where the values are the field names to be used for
   *   retrieval.
   * @param bool|int $max_height
   *   How many levels of checking should be done when retrieving ancestors.
   *
   * @return array
   *   An array where the keys and values are the node IDs of the ancestors.
   */
  public function findAncestors(ContentEntityInterface $entity, array $fields = [self::MEMBER_OF_FIELD], bool|int $max_height = FALSE) : array;

}
