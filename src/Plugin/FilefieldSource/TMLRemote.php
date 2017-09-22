<?php

namespace Drupal\tml_filefield_sources\Plugin\FilefieldSource;

use Drupal\filefield_sources\Plugin\FilefieldSource\Remote;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Field\WidgetInterface;

/**
 * FileField source plugin to allow downloading a file from Tieto media library.
 *
 * @FilefieldSource(
 *   id = "tml_remote",
 *   name = @Translation("TML Remote URL textfield"),
 *   label = @Translation("Tieto Media Library"),
 *   description = @Translation("Download a file from Tieto media library.")
 * )
 */
class TMLRemote extends Remote {

  const TML_REMOTE_LISTER_ITEM_NUM = 10;

  /**
   * {@inheritdoc}
   */
  public static function value(array &$element, &$input, FormStateInterface $form_state) {
    if (isset($input['tml_filefield_remote']['url']) && strlen($input['tml_filefield_remote']['url']) > 0 && UrlHelper::isValid($input['tml_filefield_remote']['url']) && $input['tml_filefield_remote']['url'] != FILEFIELD_SOURCE_REMOTE_HINT_TEXT) {
      $field = entity_load('field_config', $element['#entity_type'] . '.' . $element['#bundle'] . '.' . $element['#field_name']);
      $url = $input['tml_filefield_remote']['url'];

      // Check that the destination is writable.
      $temporary_directory = 'temporary://';
      if (!file_prepare_directory($temporary_directory, FILE_MODIFY_PERMISSIONS)) {
        \Drupal::logger('filefield_sources')->log(E_NOTICE, 'The directory %directory is not writable, because it does not have the correct permissions set.', array('%directory' => drupal_realpath($temporary_directory)));
        drupal_set_message(t('The file could not be transferred because the temporary directory is not writable.'), 'error');
        return;
      }

      // Check that the destination is writable.
      $directory = $element['#upload_location'];
      $mode = Settings::get('file_chmod_directory', FILE_CHMOD_DIRECTORY);

      // This first chmod check is for other systems such as S3, which don't
      // work with file_prepare_directory().
      if (!drupal_chmod($directory, $mode) && !file_prepare_directory($directory, FILE_CREATE_DIRECTORY)) {
        \Drupal::logger('filefield_sources')->log(E_NOTICE, 'File %file could not be copied, because the destination directory %destination is not configured correctly.', array('%file' => $url, '%destination' => drupal_realpath($directory)));
        drupal_set_message(t('The specified file %file could not be copied, because the destination directory is not properly configured. This may be caused by a problem with file or directory permissions. More information is available in the system log.', array('%file' => $url)), 'error');
        return;
      }

      // Check the headers to make sure it exists and is within the allowed
      // size.
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_HEADER, TRUE);
      curl_setopt($ch, CURLOPT_NOBODY, TRUE);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
      curl_setopt($ch, CURLOPT_HEADERFUNCTION, array(get_called_class(), 'parseHeader'));
      // Causes a warning if PHP safe mode is on.
      @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
      curl_exec($ch);
      $info = curl_getinfo($ch);
      if ($info['http_code'] != 200) {
        curl_setopt($ch, CURLOPT_HTTPGET, TRUE);
        $file_contents = curl_exec($ch);
        $info = curl_getinfo($ch);
      }
      curl_close($ch);

      if ($info['http_code'] != 200) {
        switch ($info['http_code']) {
          case 403:
            $form_state->setError($element, t('The remote file could not be transferred because access to the file was denied.'));
            break;

          case 404:
            $form_state->setError($element, t('The remote file could not be transferred because it was not found.'));
            break;

          default:
            $form_state->setError($element, t('The remote file could not be transferred due to an HTTP error (@code).', array('@code' => $info['http_code'])));
        }
        return;
      }

      // Update the $url variable to reflect any redirects.
      $url = $info['url'];
      $url_info = parse_url($url);

      // Determine the proper filename by reading the filename given in the
      // Content-Disposition header. If the server fails to send this header,
      // fall back on the basename of the URL.
      //
      // We prefer to use the Content-Disposition header, because we can then
      // use URLs like http://example.com/get_file/23 which would otherwise be
      // rejected because the URL basename lacks an extension.
      $filename = static::filename();
      if (empty($filename)) {
        $filename = rawurldecode(basename($url_info['path']));
      }

      $pathinfo = pathinfo($filename);

      // Create the file extension from the MIME header if all else has failed.
      if (empty($pathinfo['extension']) && $extension = static::mimeExtension()) {
        $filename = $filename . '.' . $extension;
        $pathinfo = pathinfo($filename);
      }

      $filename = filefield_sources_clean_filename($filename, $field->getSetting('file_extensions'));
      $filepath = file_create_filename($filename, $temporary_directory);

      if (empty($pathinfo['extension'])) {
        $form_state->setError($element, t('The remote URL must be a file and have an extension.'));
        return;
      }

      // Perform basic extension check on the file before trying to transfer.
      $extensions = $field->getSetting('file_extensions');
      $regex = '/\.(' . preg_replace('/[ +]/', '|', preg_quote($extensions)) . ')$/i';
      if (!empty($extensions) && !preg_match($regex, $filename)) {
        $form_state->setError($element, t('Only files with the following extensions are allowed: %files-allowed.', array('%files-allowed' => $extensions)));
        return;
      }

      // Check file size based off of header information.
      if (!empty($element['#upload_validators']['file_validate_size'][0])) {
        $max_size = $element['#upload_validators']['file_validate_size'][0];
        $file_size = $info['download_content_length'];
        if ($file_size > $max_size) {
          $form_state->setError($element, t('The remote file is %filesize exceeding the maximum file size of %maxsize.', array('%filesize' => format_size($file_size), '%maxsize' => format_size($max_size))));
          return;
        }
      }

      // Set progress bar information.
      $options = array(
        'key' => $element['#entity_type'] . '_' . $element['#bundle'] . '_' . $element['#field_name'] . '_' . $element['#delta'],
        'filepath' => $filepath,
      );
      static::setTransferOptions($options);

      $transfer_success = FALSE;
      // If we've already downloaded the entire file because the
      // header-retrieval failed, just ave the contents we have.
      if (isset($file_contents)) {
        if ($fp = @fopen($filepath, 'w')) {
          fwrite($fp, $file_contents);
          fclose($fp);
          $transfer_success = TRUE;
        }
      }
      // If we don't have the file contents, download the actual file.
      else {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, array(get_called_class(), 'curlWrite'));
        // Causes a warning if PHP safe mode is on.
        @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
        $transfer_success = curl_exec($ch);
        curl_close($ch);
      }
      if ($transfer_success && $file = filefield_sources_save_file($filepath, $element['#upload_validators'], $element['#upload_location'])) {
        if (!in_array($file->id(), $input['fids'])) {
          $input['fids'][] = $file->id();
        }
      }

      // Delete the temporary file.
      @unlink($filepath);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function process(array &$element, FormStateInterface $form_state, array &$complete_form) {
    $routing_params = [
      'entity_type' => $element['#entity_type'],
      'bundle' => $element['#bundle'],
      'form_mode' => 'default',
      'field_name' => $element['#field_name'],
    ];
    $element['tml_filefield_remote'] = array(
      '#weight' => 100.5,
      '#theme' => 'filefield_sources_element',
      '#source_id' => 'tml_remote',
      // Required for proper theming.
      '#filefield_source' => TRUE,
      '#filefield_sources_hint_text' => FILEFIELD_SOURCE_REMOTE_HINT_TEXT,
      '#filefield_sources_tml_remote_routing_params' => $routing_params,
    );

    $element['tml_filefield_remote']['url'] = array(
      '#type' => 'textfield',
      '#description' => filefield_sources_element_validation_help($element['#upload_validators']),
      '#maxlength' => NULL,
    );

    $class = '\Drupal\file\Element\ManagedFile';
    $ajax_settings = [
      'callback' => [$class, 'uploadAjaxCallback'],
      'options' => [
        'query' => [
          'element_parents' => implode('/', $element['#array_parents']),
        ],
      ],
      'wrapper' => $element['upload_button']['#ajax']['wrapper'],
      'effect' => 'fade',
      'progress' => [
        'type' => 'bar',
        'path' => 'file/remote/progress/' . $element['#entity_type'] . '/' . $element['#bundle'] . '/' . $element['#field_name'] . '/' . $element['#delta'],
        'message' => t('Starting transfer...'),
      ],
    ];

    $element['tml_filefield_remote']['transfer'] = [
      '#name' => implode('_', $element['#parents']) . '_transfer',
      '#type' => 'submit',
      '#value' => t('Transfer'),
      '#validate' => array(),
      '#submit' => ['filefield_sources_field_submit'],
      '#limit_validation_errors' => [$element['#parents']],
      '#ajax' => $ajax_settings,
    ];

    return $element;
  }

  /**
   * Theme the output of the remote element.
   *
   * @todo - make modal part of the form.
   */
  public static function element($variables) {
    $element = $variables['element'];
    $element['url']['#field_suffix'] = drupal_render($element['transfer']);

    $button = [
      '#type' => 'link',
      '#title' => t('Open TML browser'),
      '#url' => Url::fromRoute(
        'tml_filefield_sources.modal_browser_form',
        $element['#filefield_sources_tml_remote_routing_params'],
        [
          'attributes' => [
            'class' => ['use-ajax'],
            'data-dialog-type' => 'modal',
            'data-dialog-options' => Json::encode(['width' => 1000]),
          ],
        ]
      ),
      '#attributes' => ['class' => ['button']],
    ];

    $rendered_button = drupal_render($button);

    // @todo - hide element with css.
    return '<div class="filefield-source filefield-source-tml_remote clear-block"><div style="display: none;">' . drupal_render($element['url']) . '</div>' . $rendered_button . '</div>';
  }

  /**
   * Implements hook_filefield_source_settings().
   */
  public static function settings(WidgetInterface $plugin) {
    $settings = $plugin->getThirdPartySetting('filefield_sources', 'filefield_sources', array(
      'source_tml_remote' => array(
        'api_url' => NULL,
        'items_per_page' => self::TML_REMOTE_LISTER_ITEM_NUM,
      ),
    ));

    $return['source_tml_remote'] = array(
      '#title' => t('TML remote settings'),
      '#type' => 'details',
      '#description' => t('Tieto media library - enable Tieto media library browser'),
      '#weight' => 10,
      '#states' => array(
        'visible' => array(
          ':input[name="fields[field_file_image][settings_edit_form][third_party_settings][filefield_sources][filefield_sources][sources][tml_remote]"]' => array('checked' => TRUE),
        ),
      ),
    );
    $return['source_tml_remote']['api_url'] = array(
      '#type' => 'textfield',
      '#title' => t('Api URL'),
      '#default_value' => isset($settings['source_tml_remote']['api_url']) ? $settings['source_tml_remote']['api_url'] : NULL,
      '#size' => 60,
      '#maxlength' => 128,
      '#description' => t('The API Url for Tieto media library.'),
    );
    $return['source_tml_remote']['items_per_page'] = array(
      '#type' => 'number',
      '#min' => 1,
      '#title' => t('Items to display'),
      '#description' => t('Number of items per page for Tieto media library.'),
      '#default_value' => isset($settings['source_tml_remote']['items_per_page']) ? $settings['source_tml_remote']['items_per_page'] : self::TML_REMOTE_LISTER_ITEM_NUM,
    );

    return $return;
  }

}
