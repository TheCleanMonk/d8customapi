<?php

namespace Drupal\comment_api\Controller;

use Drupal\comment\Entity\Comment;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Session\AccountProxy;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\user_points\Services\UserPointsService;
use Drupal\user_points\Services\UserBadgeService;

/**
 * Provides the API controller for Red Hat Comments.
 *
 * @package Drupal\comment_api\Controller
 *   Controller class.
 */
class RedhatComments extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * Drupal\Core\Session\AccountProxy definition.
   *
   * @var \Drupal\Core\Session\AccountProxy
   */
  protected $currentUser;

  /**
   * User points service.
   *
   * @var \Drupal\user_points\Services\UserPointsService
   */
  protected $userPointsService;

  /**
   * User badges service.
   *
   * @var \Drupal\user_points\Services\UserBadgeService
   */
  protected $userBadgeService;

  /**
   * Constructor for RedHatComments.
   *
   * @param \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxy $current_user
   *   Current user.
   * @param \Drupal\user_points\Services\UserPointsService $userPointsService
   *   User points service.
   * @param \Drupal\user_points\Services\UserBadgeService $userBadgeService
   *   User badges service.
   */
  public function __construct(
    EntityTypeManager $entity_type_manager,
    AccountProxy $current_user,
    UserPointsService $userPointsService,
    UserBadgeService $userBadgeService
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->userBadgeService = $userBadgeService;
    $this->userPointsService = $userPointsService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('user_points.pointservice'),
      $container->get('user_points.badgeservice')
    );
  }

  /**
   * Fetches data based on hashed source ID and returns API response.
   *
   * @param string $source_id
   *   Base64 HTML encoded source ID for the tracker entity. If it starts with
   *   dc: it is a dublin core ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The returned JSON response.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getUriHashData(string $source_id): JsonResponse {
    try {
      $nid = $this->getNidFromSourceIdHash($source_id);
      $commentStorage = $this->entityTypeManager->getStorage('comment');
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      return new JsonResponse(
        [
          'error' => $e->getMessage(),
        ],
        Response::HTTP_INTERNAL_SERVER_ERROR
      );
    }

    $cids = $commentStorage->getQuery()
      ->condition('entity_id', $nid)
      ->condition('entity_type', 'node')
      ->sort('cid', 'ASC')
      ->execute();

    $raw_comments = $commentStorage->loadMultiple($cids);

    $comments = [];
    $uids = [];
    foreach ($raw_comments as $cid => $commentObj) {
      // Transform the comment for use in the API.
      $comments[$cid] = $this->transformCommentEntity($commentObj);

      // Collect uids for profile information.
      $uids[$comments[$cid]->uid] = 1;
    }

    // First sort the array by CID ascending.
    // This way a child will never appear in the list before a parent.
    ksort($comments);

    $nested_comments = [];
    // Walk through the sorted comments.
    foreach ($comments as $cid => $comment) {
      $nested_comments[$cid] = $comment;
      // If there is no parent.
      if (!empty($comment->pid)) {
        $nested_comments[$comment->pid]->children[] = &$nested_comments[$cid];
      }
    }

    $nested = [];
    // Remove the top level nested comments with parents.
    foreach ($nested_comments as $cid => $comment) {
      if (!empty($comment->pid)) {
        continue;
      }
      $nested[] = $comment;
    }

    $comments = $nested;

    $profiles = [];
    // @todo Replace with DI version.
    $currentUid = $this->currentUser()->id();
    $currentUid = $this->currentUser->id();
    $userStorage = $this->entityTypeManager->getStorage('user');

    // Add the current user to the list of uids.
    $uids[$currentUid] = 1;
    // Get a simple array of uids.
    $uids = array_keys($uids);

    // Load the user objects of interest.
    /** @var \Drupal\user\Entity\User[] $user_profiles */
    $user_profiles = $userStorage->loadMultiple($uids);

    // Collect fids so we can look them up all at once.
    $fids = [];
    foreach ($user_profiles as $uid => $profile) {
      $fid = intval($profile->get('field_profile_img')->getValue());
      if (!empty($fid)) {
        $fids[] = $fid;
      }
    }

    // If there are files to map, load them.
    if (!empty($fids)) {
      /** @var \Drupal\file\Entity\File[] $files */
      $files = $this
        ->entityTypeManager()
        ->getStorage('file')
        ->loadMultiple($fids);
    }

    // Transform the user profiles data.
    foreach ($user_profiles as $uid => $profile) {
      $profile = $profile->toArray();

      // Get the file id (if present).
      $fid = $profile['field_profile_img'][0]['target_id'] ?? NULL;

      // Generate the path to the asset if appropriate.
      $field_profile_img = '';
      if (!empty($fid) && !empty($files[$fid])) {
        $field_profile_img = $files[$fid]->createFileUrl();
      }

      // If username is not provided, leave it blank.
      $username = $profile['name'][0]['value'] ?? '';

      // Pull the points and badges from the user profile.
      $points = intval($profile['field_userpoints'][0]['value'] ?? 0);

      // Collect all badge Ids from the user.
      $badge_ids = [];
      foreach ($profile['field_userbadges'] as $row) {
        $badge_ids[] = intval($row['value']);
      }

      // Get the community badge id for display for this user.
      $badge_ids[] = $this->userBadgeService->getCommunityBadgeIdFromPoints($points);

      // Assemble the final profile object.
      $profiles[$uid] = (object) [
        'uid' => $uid,
        'profile_img' => $field_profile_img,
        'points' => $points,
        'username' => $username,
        'full_name' => $username,
        'initials' => $username,
        'alt_img' => '<span class="user-initials">' . $profile['name'][0]['value'] . '</span>',
        'badges' => $badge_ids,
      ];
    }

    // Get the full list of badges.
    $badges = $this->userBadgeService->getAllBadges();

    // Get actual user role.
    $current_user_roles = $this->currentUser->getRoles();

    // Check if user is not admin.
    if (!in_array('administrator', $current_user_roles, TRUE)) {
      $admin_user = NULL;
    }
    // Check if user is an admin.
    if (in_array('administrator', $current_user_roles, TRUE)) {
      $admin_user = 1;
    }

    $profile = $profiles[$currentUid];
    $response = (object) [
      'page' => 0,
      'page_size' => 20,
      'nid' => $source_id,
      'comment_count' => count($comments),
      'comments' => $comments,
      'profiles' => $profiles,
      'badges' => $badges,
      'best_reply' => NULL,
      'requester' => (object) [
        'uid' => $profile->uid,
        'badges' => $badges,
        'profile' => $profile,
        'admin' => $admin_user ?? 0,
        'token' => uniqid('', TRUE),
      ],
    ];

    return new JsonResponse($response);
  }

  /**
   * Transform a comment entity for use in the API.
   *
   * @param \Drupal\Core\Entity\EntityInterface $commentEntity
   *   The comment Entity to transform.
   *
   * @return object
   *   The restructured comment for use in the API.
   */
  protected function transformCommentEntity(EntityInterface $commentEntity) {
    $comment = $commentEntity->toArray();
    return (object) [
      'cid' => $comment['cid'][0]['value'],
      'pid' => $comment['pid'][0]['target_id'] ?? NULL,
      'uid' => $comment['uid'][0]['target_id'],
      'created' => $comment['created'][0]['value'],
      'changed' => $comment['changed'][0]['value'],
      'private' => $comment['field_isprivate'][0]['value'] ?? 0,
      // @todo Revisit this prefixed newline later.
      'raw' => $comment['field_page_comment_body'][0]['value'] ?? '',
      'flags' => $comment['field_isprivate'][0]['value'] ? ['private'] : [],
    ];
  }

  /**
   * Fetch comment data and return JSON response.
   *
   * @param int $id
   *   Comment ID to return.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with status.
   */
  public function getCommentsData(int $id): JsonResponse {

    $entity_storage = $this->entityTypeManager->getStorage('comment');
    $cids = $entity_storage->getQuery()
      ->condition('cid', $id)
      ->execute();

    $commentEntities = $this
      ->entityTypeManager()
      ->getStorage('comment')
      ->loadMultiple($cids);

    $comments = [];
    foreach ($commentEntities as $comment) {
      $comments[] = $this->transformCommentEntity($comment);
    }

    return new JsonResponse($comments);
  }

  /**
   * Insert Comment method.
   *
   * @param string $source_id
   *   Source ID is the canonical url or dc:identity base64 encoded.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Request object for this request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with status.
   */
  public function insertComment(string $source_id, Request $request):JsonResponse {

    $nid = $this->getNidFromSourceIdHash($source_id);

    try {
      $requestContent = json_decode($request->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      return new JsonResponse(
        [
          'error' => $e->getMessage(),
        ],
        Response::HTTP_BAD_REQUEST
      );
    }

    // CID is not valid to provide here.
    if (!empty($requestContent['cid'])) {
      return new JsonResponse(
        [
          'error' => "Comment ID should not be provided.",
        ],
        Response::HTTP_BAD_REQUEST
      );
    }

    // Comment body cannot be empty.
    if (empty($requestContent['raw'])) {
      return new JsonResponse(
        [
          'error' => "Comment body cannot be empty",
        ],
        Response::HTTP_BAD_REQUEST
      );
    }

    // Comment subject cannot be empty.
    if (empty($requestContent['subject'])) {
      return new JsonResponse(
        [
          'error' => "Comment subject cannot be empty",
        ],
        Response::HTTP_BAD_REQUEST
      );
    }

    if (!empty($comment->pid)) {
      $values = [
        'entity_type' => 'node',
        'entity_id' => $requestContent['entity_id'] ?? $nid,
        'field_name' => 'field_c',
        'uid' => $this->currentUser->id() ?? 0,
        'pid' => $requestContent['pid'] ?? 0,
        'comment_type' => 'page_comment',
        'private' => $requestContent['private'] ?? 1,
        'status' => 1,
      ];
    }
    elseif (empty($comment->pid)) {
      $values = [
        'entity_type' => 'node',
        'entity_id' => $requestContent['entity_id'] ?? $nid,
        'field_name' => 'field_c',
        'uid' => $this->currentUser->id() ?? 0,
        'pid' => $requestContent['pid'] ?? 0,
        'comment_type' => 'page_comment',
        'private' => 1,
        'status' => 1,
      ];
    }

    $comment = Comment::create($values);
    $comment->set('field_page_comment_body', [
      'summary' => "",
      'value' => $requestContent['raw'],
      'format' => 'plain_text',
    ]);
    try {
      $comment->save();
    }
    catch (EntityStorageException $e) {
      return new JsonResponse(
        [
          'error' => $e->getMessage(),
        ],
        Response::HTTP_NOT_ACCEPTABLE
      );
    }

    return new JsonResponse([
      'success' => "1",
      'comment' => $this->transformCommentEntity($comment),
      'message' => "Comment inserted successfully",
    ]);

  }

  /**
   * Delete Comment method.
   *
   * @param int $id
   *   Comment ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Request object for this request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with status.
   */
  public function deleteComment(int $id, Request $request) {

    try {
      $storage_handler = $this->entityTypeManager()->getStorage('comment');
      $entities = $storage_handler->loadMultiple([$id]);
      if (empty($entities)) {
        return new JsonResponse([
          'message' => "Comment does not exist",
        ]);
      }
      $storage_handler->delete($entities);
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      return new JsonResponse(
        [
          'error' => $e->getMessage(),
        ],
        Response::HTTP_INTERNAL_SERVER_ERROR
      );
    }
    catch (EntityStorageException $e) {
      return new JsonResponse(
        [
          'error' => $e->getMessage(),
        ],
        Response::HTTP_BAD_REQUEST
      );
    }

    return new JsonResponse([
      'success' => "1",
      'comment_id' => $id,
      'message' => "Comment deleted successfully",
    ]);

  }

  /**
   * Update Comment method.
   *
   * @param int $id
   *   Comment ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Request object for current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with status.
   */
  public function updateComment($id, Request $request) {

    $comment_storage = $this->entityTypeManager()->getStorage('comment');
    $comment = $comment_storage->load($id);

    if (empty($comment)) {
      return new JsonResponse(
        [
          'error' => 'Record not found',
        ],
        Response::HTTP_NOT_FOUND
      );
    }

    try {
      $requestContent = json_decode($request->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      return new JsonResponse(
        [
          'error' => $e->getMessage(),
        ],
        Response::HTTP_BAD_REQUEST
      );
    }

    if (!empty($requestContent['raw'])) {
      $comment->set('field_page_comment_body', [
        'summary' => "",
        'value' => $requestContent['raw'],
        'format' => 'plain_text',
      ]);
    }

    $comment->private = $requestContent['private'];

    $comment->save();

    return new JsonResponse([
      'success' => "1",
      'comment_id' => $id,
      'comment' => $this->transformCommentEntity($comment),
      'message' => "Comment updated successfully",
    ]);

  }

  /**
   * Publish Comment method.
   *
   * @param int $id
   *   Comment ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with status.
   */
  public function publishComment(int $id) {

    // Endpoint functionality is not implemented.
    if (1) {
      return new JsonResponse([
        'message' => "Endpoint is not implemented",
      ]);
    }

    try {
      $comment_storage = $this->entityTypeManager()->getStorage('comment');
      $comment = $comment_storage->load($id);
      $comment->status = 1;
      $comment->save();
      $userPointsService = new UserPointsService();
      $userPointsService->addUserPointsForPosting();
    }
    catch (EntityStorageException | InvalidPluginDefinitionException |
    PluginNotFoundException $e) {
      return new JsonResponse([
        'error' => $e->getMessage(),
      ],
        Response::HTTP_INTERNAL_SERVER_ERROR
      );
    }

    return new JsonResponse([
      'success' => "1",
      'comment_id' => $id,
      'message' => "Comment published successfully",
    ]);

  }

  /**
   * Create Comment Tracker method.
   *
   * @param string $source_id
   *   Source ID is the canonical url or dc:identity base64 encoded.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Request object for current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with status.
   */
  public function createCommentTracker(string $source_id, Request $request):JsonResponse {
    $source_id = $this->base64UrlDecode($source_id);

    try {
      $requestContent = json_decode($request->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      return new JsonResponse(
        [
          'error' => $e->getMessage(),
        ],
        Response::HTTP_BAD_REQUEST
      );
    }

    $values = [
      'type' => 'commenttracker',
      'title' => $requestContent['title'] ?? NULL,
      'field_is_locked' => $requestContent['is_locked'] ?? FALSE,
      'field_source_id' => $source_id,
      'status' => 1,
    ];

    $node = Node::create($values);
    try {
      $node->save();
    }
    catch (EntityStorageException $e) {
      return new JsonResponse(
        [
          'error' => $e->getMessage(),
        ],
        Response::HTTP_NOT_ACCEPTABLE
      );
    }

    return new JsonResponse([
      'success' => "1",
      'message' => "Comment tracker inserted successfully",
    ]);

  }

  /**
   * Given a source id hash, retrieve the nid.
   *
   * @param string $source_id
   *   Source ID is the canonical url or dc:identity base64 encoded.
   *
   * @return int
   *   Entity id of the tracker entity.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getNidFromSourceIdHash(string $source_id) {
    $source = $this->base64UrlDecode($source_id);

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $query       = $nodeStorage->getQuery();

    $nids = $query
      ->condition('type', 'commenttracker')
      ->condition('status', 1)
      ->condition('field_source_id', $source)
      ->execute();

    return reset($nids);
  }

  /**
   * Encode an html encoded base64 string.
   *
   * @param string $input
   *   String to convert.
   *
   * @return string
   *   HTML base64 encoded value.
   */
  protected function base64UrlEncode($input) {
    return strtr(base64_encode($input), '+/=', '._-');
  }

  /**
   * Decode an html encoded base64 string.
   *
   * @param string $input
   *   Base64 string to convert.
   *
   * @return false|string
   *   FALSE on failure, string on success.
   */
  protected function base64UrlDecode($input) {
    return base64_decode(strtr($input, '._-', '+/='));
  }

}
