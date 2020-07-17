<?php

namespace Drupal\easy_email_override\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\easy_email_override\Service\EmailManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class EmailOverrideForm.
 */
class EmailOverrideForm extends EntityForm {

  /**
   * @var \Drupal\easy_email_override\Service\EmailManagerInterface
   */
  protected $emailManager;

  /**
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $easyEmailTypeStorage;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * EmailOverrideForm constructor.
   *
   * @param \Drupal\easy_email_override\Service\EmailManagerInterface $emailManager
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   */
  public function __construct(EmailManagerInterface $emailManager, EntityTypeManagerInterface $entityTypeManager) {
    $this->emailManager = $emailManager;
    $this->entityTypeManager = $entityTypeManager;
    $this->easyEmailTypeStorage = $entityTypeManager->getStorage('easy_email_type');
  }


  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.easy_email_override'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * @return array
   */
  protected function getEmailOptions() {
    $options = [];
    $definitions = $this->emailManager->getDefinitions();
    foreach ($definitions as $id => $definition) {
      $options[$id] = $definition['label'];
    }
    return $options;
  }

  /**
   * @return string|null
   */
  protected function getEmailDefaultValue() {
    $default_value = NULL;
    /** @var \Drupal\easy_email_override\Entity\EmailOverrideInterface $easy_email_override */
    $easy_email_override = $this->entity;
    $module = $easy_email_override->getModule();
    $key = $easy_email_override->getKey();
    if (!empty($module) && !empty($key)) {
      $definitions = $this->emailManager->getDefinitions();
      foreach ($definitions as $id => $definition) {
        if ($definition['module'] === $module && $definition['key'] === $key) {
          $default_value = $id;
          break;
        }
      }
    }
    return $default_value;
  }

  protected function getEasyEmailTemplateOptions() {
    $options = [];
    foreach ($this->easyEmailTypeStorage->loadMultiple() as $easy_email_type) {
      $options[$easy_email_type->id()] = $easy_email_type->label();
    }
    return $options;
  }

  protected function getPossibleMappings($email_id, $easy_email_type) {
    $possible_mappings = [];

    $easy_email_fields = [];
    $field_definitions = \Drupal::entityManager()->getFieldDefinitions('easy_email', $easy_email_type);
    foreach ($field_definitions as $field_name => $definition) {
      $field_info = [
        'label' => $definition->getLabel(),
        'type' => $definition->getType(),
      ];
      if ($field_info['type'] === 'entity_reference') {
        $field_info['type'] = 'entity:' . $definition->getSetting('target_type');
      }
      elseif (in_array($field_info['type'], ['string', 'string_long'])) {
        $field_info['type'] = 'string';
      }
      elseif (in_array($field_info['type'], ['text', 'text_long'])) {
        $field_info['type'] = 'text';
      }
      $easy_email_fields[$field_name] = $field_info;
    }

    $email_definition = $this->emailManager->getDefinition($email_id);
    foreach ($email_definition['params'] as $id => $param_info) {
      $param_possible_matches = [];
      foreach ($easy_email_fields as $field_name => $field_info) {
        if ($field_info['type'] === $param_info['type']) {
          $param_possible_matches[$field_name] = $field_info['label'];
        }
      }
      if (!empty($param_possible_matches)) {
        $possible_mappings[$id] = [
          'label' => $param_info['label'],
          'options' => $param_possible_matches,
        ];
      }
    }

    return $possible_mappings;
  }

  /**
   * @param string $source_id
   *
   * @return string|null
   */
  protected function getMappingDefaultValue($source_id) {
    $default_value = NULL;

    /** @var \Drupal\easy_email_override\Entity\EmailOverrideInterface $easy_email_override */
    $easy_email_override = $this->entity;

    $mappings = $easy_email_override->getParamMap();
    foreach ($mappings as $mapping) {
      if ($source_id === $mapping['source']) {
        $default_value = $mapping['destination'];
        break;
      }
    }

    return $default_value;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    $form['#tree'] = TRUE;

    /** @var \Drupal\easy_email_override\Entity\EmailOverrideInterface $easy_email_override */
    $easy_email_override = $this->entity;
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $easy_email_override->label(),
      '#description' => $this->t("Label for the Email Override."),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $easy_email_override->id(),
      '#machine_name' => [
        'exists' => '\Drupal\easy_email_override\Entity\EmailOverride::load',
      ],
      '#disabled' => !$easy_email_override->isNew(),
    ];

    $email_id = $this->getEmailDefaultValue();
    $form['email_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Email to Override'),
      '#options' => $this->getEmailOptions(),
      '#default_value' => $email_id,
      '#disabled' => !is_null($email_id),
      '#required' => TRUE,
    ];

    $easy_email_type = $easy_email_override->getEasyEmailType();
    $form['easy_email_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Easy Email Template'),
      '#options' => $this->getEasyEmailTemplateOptions(),
      '#default_value' => $easy_email_type,
      '#disabled' => !is_null($easy_email_type),
      '#required' => TRUE,
    ];

    if (!empty($email_id) && !empty($easy_email_type)) {
      $form['mappings'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Parameter Mapping'),
      ];
      $possible_mappings = $this->getPossibleMappings($email_id, $easy_email_type);
      foreach ($possible_mappings as $source_id => $mapping_info) {
        $form['mappings'][$source_id] = [
          '#type' => 'select',
          '#title' => $mapping_info['label'],
          '#options' => array_merge(['' => ''], $mapping_info['options']),
          '#default_value' => $this->getMappingDefaultValue($source_id),
        ];
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\easy_email_override\Entity\EmailOverrideInterface $easy_email_override */
    $easy_email_override = $this->entity;
    $is_new = $easy_email_override->isNew();

    $definition = $this->emailManager->getDefinition($form_state->getValue('email_id'));
    $easy_email_override->setModule($definition['module'])
      ->setKey($definition['key'])
      ->setEasyEmailType($form_state->getValue('easy_email_type'));

    if (!$is_new) {
      $param_mapping = [];
      foreach ($form_state->getValue('mappings') as $source_id => $dest_id) {
        $param_mapping[] = [
          'source' => $source_id,
          'destination' => $dest_id,
        ];
      }
      $easy_email_override->setParamMap($param_mapping);
    }

    $status = $easy_email_override->save();

    if ($is_new) {
      $form_state->setRedirectUrl($easy_email_override->toUrl('edit-form'));
    }
    else {
      $form_state->setRedirectUrl($easy_email_override->toUrl('collection'));
    }

    switch ($status) {
      case SAVED_NEW:
        \Drupal::messenger()->addStatus($this->t('Created the %label email override. Please configure any parameter mappings necessary below.', [
          '%label' => $easy_email_override->label(),
        ]));
        break;

      default:
        \Drupal::messenger()->addStatus($this->t('Saved the %label email override.', [
          '%label' => $easy_email_override->label(),
        ]));
    }
  }

}
