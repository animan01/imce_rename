<?php

namespace Drupal\imce_rename_plugin\Plugin\ImcePlugin;

use Drupal\Core\Database\Connection;
use Drupal\Core\Messenger\Messenger;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\imce\Imce;
use Drupal\imce\ImceFM;
use Drupal\imce\ImcePluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Transliteration\TransliterationInterface;

/**
 * Defines Imce Rename plugin.
 *
 * @ImcePlugin(
 *   id = "rename",
 *   label = "Rename",
 *   operations = {
 *     "rename" = "opRename"
 *   }
 * )
 */
class Rename extends ImcePluginBase implements ContainerFactoryPluginInterface {

  /**
   * The variable containing the messenger service.
   *
   * @var \Drupal\Core\Messenger\Messenger
   */
  protected $messenger;

  /**
   * The variable containing the database service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The transliteration service to use.
   *
   * @var \Drupal\Component\Transliteration\TransliterationInterface
   */
  protected $transliteration;

  /**
   * Dependency injection through the constructor.
   *
   * @param array $configuration
   *   The block configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Messenger\Messenger $messenger
   *   The messenger service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database service.
   * @param \Drupal\Component\Transliteration\TransliterationInterface $transliteration
   *   The transliteration service.
   */
  public function __construct(array $configuration,
                              $plugin_id,
                              $plugin_definition,
                              Messenger $messenger,
                              Connection $database,
                              TransliterationInterface $transliteration
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->messenger = $messenger;
    $this->database = $database;
    $this->transliteration = $transliteration;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('messenger'),
      $container->get('database'),
      $container->get('transliteration')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function permissionInfo() {
    return [
      'rename_files' => $this->t('Rename files'),
      'rename_folders' => $this->t('Rename folders'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildPage(array &$page, ImceFM $fm) {
    $check_perm = $fm->hasPermission('rename_files') || $fm->hasPermission('rename_folders');
    // Check if rename permission exists.
    if ($check_perm) {
      $page['#attached']['library'][] = 'imce_rename_plugin/drupal.imce.rename';
    }
  }

  /**
   * Operation handler: rename.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function opRename(ImceFM $fm) {
    $items = $fm->getSelection();

    // Check type.
    switch ($items[0]->type) {
      case 'file':

        if ($this->validateRename($fm, $items)) {
          $this->renameFile($fm, $items[0]->name);
        }
        else {
          $this->messenger->addMessage($this->t('You do not have the right to rename a file "@old_item"', [
            '@old_item' => utf8_encode($items[0]->name),
          ]), 'error');
        }
        break;

      case 'folder':
        if ($this->validateRename($fm, $items)) {
          $this->renameFolder($fm, $items[0]->name);
        }
        else {
          $this->messenger->addMessage($this->t('You do not have the right to rename a folder "@old_item"', [
            '@old_item' => utf8_encode($items[0]->name),
          ]), 'error');
        }
        break;
    }
  }

  /**
   * Checks permissions of the given items.
   */
  public function validateRename(ImceFM $fm, array $items) {
    return $items && $fm->validatePermissions($items, 'rename_files', 'rename_folders') && $fm->validatePredefinedPath($items);
  }

  /**
   * Renames file by name.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function renameFile(ImceFM $fm, string $old_name) {
    $new_name = $this->getNewName($fm);
    $folder = $fm->activeFolder;
    $uri = preg_match('/^public:\/\/$/', $folder->getUri()) >= 1 ? $folder->getUri() : $folder->getUri() . '/';
    // Add extension to file name.
    $new_name = $new_name . '.' . pathinfo($old_name, PATHINFO_EXTENSION);
    $new_uri = $uri . $new_name;
    $old_uri = $uri . $old_name;

    if (file_exists($new_uri)) {
      $this->messenger->addMessage($this->t('Failed to rename file because "@old_item" already exists', [
        '@old_item' => utf8_encode($old_name),
      ]), 'error');
      return;
    }

    // Check access to write file and try to change chmod.
    if (!is_writable($old_uri) && !chmod($old_uri, 0664)) {
      $this->messenger->addMessage($this->t('No permissions to write file "@old_item". Please upload the file via IMCE.', [
        '@old_item' => utf8_encode($old_name),
      ]), 'error');
      return;
    }

    $file = Imce::getFileEntity($old_uri);
    // Create entity when there is no entity for the file.
    $file = empty($file) ? Imce::createFileEntity($old_uri) : $file;
    $move = file_move($file, $new_uri, FileSystemInterface::EXISTS_ERROR);
    $move->setFilename($new_name);
    $move->save();

    // Validate message.
    if ($move) {
      $this->messenger->addMessage($this->t('Rename successful! Renamed "@old_item" to "@new_item"', [
        '@old_item' => utf8_encode($old_name),
        '@new_item' => utf8_encode($new_name),
      ]));
      $folder->addFile($new_name)->addToJs();
      $folder->getItem($old_name)->removeFromJs();
    }
    else {
      $this->messenger->addMessage($this->t('Failed to rename file "@old_item" to "@new_item".', [
        '@old_item' => utf8_encode($old_name),
        '@new_item' => utf8_encode($new_name),
      ]), 'error');
    }
  }

  /**
   * Renames folder by name.
   */
  public function renameFolder(ImceFM $fm, string $old_name) {
    $new_name = $this->getNewName($fm);
    $folder = $fm->activeFolder;
    $uri = preg_match('/^public:\/\/$/', $folder->getUri()) >= 1 ? $folder->getUri() : $folder->getUri() . '/';
    $new_uri = $uri . $new_name;
    $old_uri = $uri . $old_name;

    if (file_exists($new_uri)) {
      $this->messenger->addMessage($this->t('Failed to rename folder because "@old_item" already exists', [
        '@old_item' => utf8_encode($old_name),
      ]), 'error');
      return;
    }

    if (rename($old_uri, $new_uri)) {
      $this->messenger->addMessage($this->t('Rename successful! Renamed "@old_item" to "@new_item"', [
        '@old_item' => utf8_encode($old_name),
        '@new_item' => utf8_encode($new_name),
      ]));
      $folder->addSubfolder($new_name)->addToJs();
      $folder->getItem($old_name)->removeFromJs();

      // Update file uri's in table file_managed.
      $sql_old_uri = $old_uri . "/";
      $sql_new_uri = $new_uri . "/";

      $query = $this->database->select('file_managed', 'fm');
      $query->fields('fm');
      $query->condition('uri', $sql_old_uri . '%', 'LIKE');
      $file_managed = $query->execute()->fetchAll();

      if (!empty($file_managed)) {
        $this->database->update('file_managed', [])
          ->condition('uri', $sql_old_uri . '%', 'LIKE')
          ->expression('uri', "REPLACE(uri, " . "'" . $sql_old_uri . "', '" . $sql_new_uri . "')")
          ->execute();
        $this->messenger->addMessage($this->t('Updated path for @items files.', [
          '@items' => count($file_managed),
        ]));
      }
    }
    else {
      $this->messenger->addMessage($this->t('Sorry, but something wrong when rename a folder'), 'error');
    }
  }

  /**
   * Get name and filtered special symbols.
   *
   * @param \Drupal\imce\ImceFM $fm
   *   Imce File Manager instance.
   *
   * @return int|mixed|string|string[]|null
   *   New name.
   */
  public function getNewName(ImceFM $fm) {
    // Crop string up to 50 characters.
    $name = mb_substr($fm->getPost('new_name'), 0, 50);
    // Transliteration name.
    $name = $this->transliteration->transliterate($name);
    // Replace space to dash.
    $name = str_replace(' ', '-', $name);;
    // Delete special symbols.
    $name = preg_replace('/[^\w_-]+/u', '', $name);
    // Set timestamp when name empty.
    $name = empty($name) ? time() : $name;

    return $name;
  }

}
