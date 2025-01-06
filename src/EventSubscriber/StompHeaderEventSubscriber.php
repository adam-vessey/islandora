<?php

namespace Drupal\islandora\EventSubscriber;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\islandora\Event\StompHeaderEventException;
use Drupal\islandora\Event\StompHeaderEventInterface;
use Drupal\jwt\Authentication\Event\JwtAuthEvents;
use Drupal\jwt\Authentication\Event\JwtAuthGenerateEvent;
use Drupal\jwt\JsonWebToken\JsonWebToken;
use Drupal\jwt\Transcoder\JwtDecodeException;
use Drupal\jwt\Transcoder\JwtTranscoderInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Base STOMP header listener.
 */
class StompHeaderEventSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Constructor.
   */
  public function __construct(
    protected EventDispatcherInterface $eventDispatcher,
    protected JwtTranscoderInterface $transcoder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      StompHeaderEventInterface::EVENT_NAME => [
        ['baseHeaders', -100],
        ['persistentMessages', -1000],
      ],
    ];
  }

  /**
   * Event callback; generate and add base/default headers if not set.
   */
  public function baseHeaders(StompHeaderEventInterface $stomp_event) {
    $headers = $stomp_event->getHeaders();

    if (!$headers->has('Authorization')) {
      // No header, generate one.
      $token = $this->generateToken($stomp_event);
      if (empty($token)) {
        // JWT does not seem to be properly configured.
        // phpcs:ignore DrupalPractice.General.ExceptionT.ExceptionT
        throw new StompHeaderEventException($this->t('Error getting JWT token for message. Check JWT Configuration.'));
      }

      $headers->set('Authorization', "Bearer {$token}");
      return;
    }

    // Get the token, and re-create with additional info.
    $header_value = $headers->get('Authorization');
    if (!str_starts_with($header_value, 'Bearer ')) {
      return;
    }

    $token_string = explode(' ', $header_value, 2)[1] ?: '';
    if (!$token_string) {
      return;
    }
    try {
      $jwt = $this->transcoder->decode($token_string);
      $this->setEventClaims($jwt, $stomp_event);
      $updated_token_string = $this->transcoder->encode($jwt);
      if (!$updated_token_string) {
        throw new StompHeaderEventException('Error getting updated JWT. Check JWT configuration.');
      }
      $headers->set('Authorization', "Bearer {$updated_token_string}");
    }
    catch (JwtDecodeException) {
      // No-op; failed to decode the token, presumably it was generated/provided
      // by some other means.
    }
  }

  /**
   * Generate a scoped token.
   *
   * @return string
   *   The encoded token.
   */
  protected function generateToken(StompHeaderEventInterface $event) {
    $jwt = new JsonWebToken();

    $this->setEventClaims($jwt, $event);

    $this->eventDispatcher->dispatch(
      new JwtAuthGenerateEvent($jwt),
      JwtAuthEvents::GENERATE
    );
    return $this->transcoder->encode($jwt);
  }

  /**
   * Set claims for tokens associated with STOMP requests.
   *
   * @param \Drupal\jwt\JsonWebToken\JsonWebToken $jwt
   *   The token to which to add the claim.
   * @param \Drupal\islandora\Event\StompHeaderEventInterface $event
   *   The event for which we are to add the claim.
   */
  protected function setEventClaims(JsonWebToken $jwt, StompHeaderEventInterface $event) : void {
    // Set the args into the token to not depend on headers, so we are sure
    // things were not mangled/manipulated between Islandora and Crayfish.
    $jwt->setClaim('x-islandora-event-data', $event->getData());
  }

  /**
   * Event callback; set messages to be persistent by default.
   */
  public function persistentMessages(StompHeaderEventInterface $event) : void {
    $headers = $event->getHeaders();

    // In ActiveMQ, STOMP messages are not persistent by default; however, we
    // would like them to persist, by default... make it so, unless something
    // else has already set the header.
    if (!$headers->has('persistent')) {
      $headers->set('persistent', 'true');
    }
  }

}
