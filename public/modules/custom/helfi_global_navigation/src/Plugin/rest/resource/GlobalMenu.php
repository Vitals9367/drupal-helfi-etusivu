<?php

declare(strict_types = 1);

namespace Drupal\helfi_global_navigation\Plugin\rest\resource;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\helfi_global_navigation\Entity\GlobalMenu as GlobalMenuEntity;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\rest\ResourceResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Represents Global menu records as resources.
 *
 * @RestResource(
 *   id = "helfi_global_menu",
 *   label = @Translation("Global menu"),
 *   uri_paths = {
 *     "canonical" = "/api/v1/global-menu/{entity}",
 *     "create" = "/api/v1/global-menu/{entity}",
 *   }
 * )
 */
final class GlobalMenu extends GlobalMenuBase {

  /**
   * Gets the entity for given request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Drupal\helfi_global_navigation\Entity\GlobalMenu|null
   *   The entity or null.
   */
  private function getRequestEntity(Request $request) : ? GlobalMenuEntity {
    if (!$id = $request->attributes->get('entity')) {
      throw new BadRequestHttpException('Missing required "entity" parameter.');
    }

    if (!$entity = GlobalMenuEntity::load($id)) {
      return NULL;
    }
    return $entity;
  }

  /**
   * Callback for GET requests.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The response.
   */
  public function get(Request $request) : ResourceResponse {
    $cacheableMetadata = new CacheableMetadata();

    if (!$entity = $this->getRequestEntity($request)) {
      throw new NotFoundHttpException();
    }
    $this->assertPermission($entity, 'view');

    $cacheableMetadata->addCacheableDependency($entity);

    $entity = $this->entityRepository->getTranslationFromContext($entity, $this->getCurrentLanguageId());
    return (new ResourceResponse($entity, 200))
      ->addCacheableDependency($cacheableMetadata);
  }

  /**
   * Callback for POST requests.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Drupal\rest\ModifiedResourceResponse
   *   The response.
   */
  public function post(Request $request) : ModifiedResourceResponse {
    // Attempt to create a new entity if one does not exist yet.
    if (!$entity = $this->getRequestEntity($request)) {
      $entity = GlobalMenuEntity::createById($request->attributes->get('entity'));
      $this->assertPermission($entity, 'create');
    }
    else {
      $langcode = $this->getCurrentLanguageId();

      /** @var \Drupal\helfi_global_navigation\Entity\GlobalMenu $entity */
      $entity = $entity->hasTranslation($langcode) ?
        $entity->getTranslation($langcode) :
        $entity->addTranslation($langcode);
    }
    $this->assertPermission($entity, 'update');

    try {
      $content = \GuzzleHttp\json_decode($request->getContent());
    }
    catch (\InvalidArgumentException) {
      throw new BadRequestHttpException('Invalid JSON.');
    }

    foreach (['menu_tree', 'site_name'] as $required) {
      if (!isset($content->{$required})) {
        throw new BadRequestHttpException(sprintf('Missing required: %s', $required));
      }
    }

    try {
      $entity->setMenuTree($content->menu_tree)
        ->setLabel($content->site_name);
      $this->validate($entity);
      $entity->save();

      $responseCode = $entity->isNew() ? Response::HTTP_CREATED : Response::HTTP_OK;

      return new ModifiedResourceResponse($entity, $responseCode);
    }
    catch (EntityStorageException $e) {
      throw new HttpException(500, 'Internal Server Error', $e);
    }
  }

}
