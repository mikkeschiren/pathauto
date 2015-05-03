<?php

/**
 * @file
 * Contains \Drupal\pathauto\Plugin\AliasType\TaxonomyTermAliasType.
 */

namespace Drupal\pathauto\Plugin\pathauto\AliasType;

use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\pathauto\AliasTypeBatchUpdateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A pathauto alias type plugin for taxonomy term entities.
 *
 * @AliasType(
 *   id = "taxonomy_term",
 *   label = @Translation("Taxonomy term paths"),
 *   types = {"term"},
 *   provider = "taxonomy",
 * )
 */
class TaxonomyTermAliasType extends AliasTypeBase implements AliasTypeBatchUpdateInterface, ContainerFactoryPluginInterface {

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The language manager service.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * Constructs a NodeAliasType instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager service.
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ModuleHandlerInterface $module_handler, LanguageManagerInterface $language_manager, EntityManagerInterface $entity_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->moduleHandler = $module_handler;
    $this->languageManager = $language_manager;
    $this->entityManager = $entity_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('module_handler'),
      $container->get('language_manager'),
      $container->get('entity.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getPatternDescription() {
    return $this->t('Default path pattern (applies to all vocabularies with blank patterns below)');
  }

  /**
   * {@inheritdoc}
   */
  public function getPatterns() {
    $patterns = [];
    $languages = $this->languageManager->getLanguages();
    foreach ($this->getVocabularyNames() as $vid => $name) {
      if (count($languages) && $this->isContentTranslationEnabled($vid)) {
        $patterns[$vid] = $this->t('Default path pattern for %vocab-name (applies to all %vocab-name vocabularies with blank patterns below)', array('@node_type' => $name));
        foreach ($languages as $language) {
          $patterns[$vid . '_' . $language->getId()] = t('Pattern for all @language %vocab-name paths', array('%vocab-name' => $name, '@language' => $language->getName()));
        }
      }
      else {
        $patterns[$vid] = t('Pattern for all %vocab-name paths', array('%vocab-name' => $name));
      }
    }
    return $patterns;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return array('default' => array('[term:vocabulary]/[term:name]')) + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function batchUpdate(&$context) {
    if (!isset($context['sandbox']['current'])) {
      $context['sandbox']['count'] = 0;
      $context['sandbox']['current'] = 0;
    }

    $query = db_select('taxonomy_term_data', 'td');
    $query->leftJoin('url_alias', 'ua', "CONCAT('taxonomy/term/', td.tid) = ua.source");
    $query->addField('td', 'tid');
    $query->isNull('ua.source');
    $query->condition('td.tid', $context['sandbox']['current'], '>');
    // Exclude the forums terms.
    if ($forum_vid = 'forums') {
      $query->condition('td.vid', $forum_vid, '<>');
    }
    $query->orderBy('td.tid');
    $query->addTag('pathauto_bulk_update');
    $query->addMetaData('entity', 'taxonomy_term');

    // Get the total amount of items to process.
    if (!isset($context['sandbox']['total'])) {
      $context['sandbox']['total'] = $query->countQuery()->execute()->fetchField();

      // If there are no nodes to update, the stop immediately.
      if (!$context['sandbox']['total']) {
        $context['finished'] = 1;
        return;
      }
    }

    $query->range(0, 25);
    $tids = $query->execute()->fetchCol();

    pathauto_taxonomy_term_update_alias_multiple($tids, 'bulkupdate');
    $context['sandbox']['count'] += count($tids);
    $context['sandbox']['current'] = max($tids);
    $context['message'] = t('Updated alias for term @tid.', array('@tid' => end($tids)));

    if ($context['sandbox']['count'] != $context['sandbox']['total']) {
      $context['finished'] = $context['sandbox']['count'] / $context['sandbox']['total'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getSourcePrefix() {
    return 'taxonomy/term/';
  }

  /**
   * Returns vocabulary names.
   *
   * @return array
   *   An array of node type names, keyed by type.
   */
  protected function getVocabularyNames() {
    return array_map(function ($bundle_info) {
      return $bundle_info['label'];
    }, $this->entityManager->getBundleInfo('taxonomy_term'));
  }

  /**
   * Checks if content translation is neabled.
   *
   * @param string $vocabulary
   *   The vocabulary ID.
   *
   * @return bool
   *   TRUE if content translation is enabled for the vocabulary.
   */
  protected function isContentTranslationEnabled($vocabulary) {
    return $this->moduleHandler->moduleExists('content_translation') && \Drupal::service('content_translation.manager')->isEnabled('taxonomy_term', $vocabulary);
  }

}