<?php

namespace Drupal\views_send\Plugin\views\field;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\style\Table;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Drupal\views\Plugin\views\field\UncacheableFieldHandlerTrait;

/**
 * Defines a simple send mass mail form element.
 *
 * @ViewsField("views_send_bulk_form")
 */
class ViewsSend extends FieldPluginBase {

  use UncacheableFieldHandlerTrait;

  /**
   * {@inheritdoc}
   */
  public function getValue(ResultRow $values, $field = NULL) {
    return '<!--form-item-' . $this->options['id'] . '--' . $values->index . '-->';
  }

  /**
   * Form constructor for the views_send form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function viewsForm(array &$form, FormStateInterface $form_state) {

    // The view is empty, abort.
    if (empty($this->view->result)) {
      return;
    }

    // @todo Evaluate this again in https://www.drupal.org/node/2503009.
    $form['#cache']['max-age'] = 0;
    $form['#attached']['library'][] = 'core/drupal.tableselect';

    // Add the custom CSS for all steps of the form.
    $form['#attached']['library'][] = 'views_send/views_send.form';

    $step = $form_state->get('step');
    if ($step == 'views_form_views_form') {
      $form['actions']['submit']['#value'] = $this->t('Send e-mail');
      $form['#prefix'] = '<div class="views-send-selection-form">';
      $form['#suffix'] = '</div>';
      $form[$this->options['id']]['#tree'] = TRUE;
      foreach ($this->view->result as $row_index => $row) {
        $form[$this->options['id']][$row_index] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Update this item'),
          '#title_display' => 'invisible',
          '#default_value' => !empty($form_state->getValue($this->options['id'])[$row_index]) ? 1 : NULL,
          '#return_value' => 1,
        ];
      }

      // Replace the form submit button label.
      $form['actions']['submit']['#value'] = $this->t('Apply to selected items');
    }
    else {
      // Hide the normal output from the view.
      unset($form['output']);
      $step($form, $form_state, $this->view);
    }
  }

  /**
   * Submit handler for the views send form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Thrown when the user tried to access an action without access to it.
   */
  public function viewsFormSubmit(array &$form, FormStateInterface $form_state) {
    switch ($form_state->get('step')) {
      case 'views_form_views_form':
        $field_name = $this->options['id'];
        $selection = array_filter($form_state->getValue($field_name));
        $form_state->set('selection', array_keys($selection));
        // It seems that the search_api data is lost in multi-step forms, so we
        // will create a copy of the data outside of the view in order of
        // preserve it.
        // @todo make sure that the search_api data is lost in the multi-steps
        // forms and create an issue in the search_api module.
        $view_data = [];
        foreach ($this->view->result as $row_id => $resultRow) {
          foreach ($this->view->field as $field_name => $field) {
            $view_data[$row_id][$field_name] = $this->view->style_plugin->getFieldValue($row_id, $field_name);
          }
        }

        $form_state->set('view_data', $view_data);
        $form_state->set('step', 'views_send_config_form');
        $form_state->setRebuild(TRUE);
        break;

      case 'views_send_config_form':
        $display = $form['display']['#value'];
        $config = \Drupal::configFactory()->getEditable('views_send.user_settings');
        $config_basekey = $display . '.uid:' . \Drupal::currentUser()->id();
        $form_state_values = $form_state->getValues();
        if ($form_state->getValue('views_send_remember')) {
          foreach ($form_state_values as $key => $value) {
            $key = ($key == 'format') ? 'views_send_message_format' : $key;
            if (substr($key, 0, 11) == 'views_send_') {
              $config->set($config_basekey . '.' . substr($key, 11), $value);
            }
          }
          $config->save();
        }
        else {
          $config->clear($config_basekey);
          $config->save();
        }
        $form_state->set('configuration', $form_state_values);

        // If a file was uploaded, process it.
        if (VIEWS_SEND_MIMEMAIL && \Drupal::currentUser()->hasPermission('attachments with views_send') &&
            isset($_FILES['files']) && is_uploaded_file($_FILES['files']['tmp_name']['views_send_attachments'])) {
          // Attempt to save the uploaded file.
          $dir = file_default_scheme() . '://views_send_attachments';
          file_prepare_directory($dir, FILE_CREATE_DIRECTORY);
          $file = file_save_upload('views_send_attachments', $form_state, [], $dir);
          // Set error if file was not uploaded.
          if (!$file) {
            $form_state->setErrorByName('views_send_attachment', $this->t('Error uploading file.'));
          }
          else {
            // Set files to form_state, to process when form is submitted.
            // @todo: when we add a multifile formfield then loop through to add each file to attachments array
            $form_state->set(['configuration', 'views_send_attachments'], (array) $file);
          }
        }

        $form_state->set('step', 'views_send_confirm_form');
        $form_state->setRebuild(TRUE);
        break;

      case 'views_send_confirm_form':

        // Queue the email for sending.
        views_send_queue_mail($form_state->get('configuration'), $form_state->get('selection'), $this->view);

        $form_state->setRedirectUrl($this->view->getUrl());
        break;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function viewsFormValidate(&$form, FormStateInterface $form_state) {
    if ($form_state->get('step') != 'views_form_views_form') {
      return;
    }
    // Only the first initial form is handled here.
    $field_name = $this->options['id'];
    $selection = array_filter($form_state->getValue($field_name));

    if (empty($selection)) {
      $form_state->setErrorByName($field_name, $this->t('Please select at least one item.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function preRender(&$values) {
    parent::preRender($values);

    // If the view is using a table style, provide a placeholder for a
    // "select all" checkbox.
    if (!empty($this->view->style_plugin) && $this->view->style_plugin instanceof Table) {
      // Add the tableselect css classes.
      $this->options['element_label_class'] .= 'select-all';
      // Hide the actual label of the field on the table header.
      $this->options['label'] = '';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
  }

}
