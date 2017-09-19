<?php

namespace Drupal\tml_filefield_sources\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\InvokeCommand;
use GuzzleHttp\Client;
use Drupal\Component\Utility\UrlHelper;

/**
 * Implements the ModalBrowserForm form controller.
 */
class ModalBrowserForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'tml_modal_media_browser_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $this->getRestApiMediaLister($form, $form_state);

    $form['#prefix'] = '<div id="tml_media-modal-form">';
    $form['#suffix'] = '</div>';

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
      '#ajax' => [
        'callback' => '::ajaxSubmitForm',
        'event' => 'click',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $selected_media = array_values(array_filter($form_state->getUserInput()['media_id_select']));
    if (count($selected_media) > 1) {
      $form_state->setErrorByName('tml_media_image_url', $this->t('You can select only one media.'));
      return;
    }
    $media_id = $selected_media[0];
    $media_asset = $form_state->getValue('media_asset');
    $media_asset_urls = $form_state->get('tml_media_asset_urls');

    if ($media_asset_urls && $media_id && $media_asset) {
      $image_url = $media_asset_urls[$media_id]->{$media_asset};
    }
    if (!$image_url) {
      $form_state->setErrorByName('tml_media_image_url', $this->t("Can't fetch image from remote server."));
    }
    $form_state->set('tml_media_image_url', $image_url);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * Implements the filter submit handler for the ajax call.
   */
  public function ajaxSubmitFilterForm(array &$form, FormStateInterface $form_state) {
    $form_state->set('page', 0);
    $form_state->setRebuild();
  }

  /**
   * Implements the pager submit handler for the ajax call.
   */
  public function ajaxSubmitPagerNext(array &$form, FormStateInterface $form_state) {
    $page = $form_state->get('page');
    $form_state->set('page', ($page + 1));
    $form_state->setRebuild();
  }

  /**
   * Implements the pager submit handler for the ajax call.
   */
  public function ajaxSubmitPagerPrev(array &$form, FormStateInterface $form_state) {
    $page = $form_state->get('page');
    $form_state->set('page', ($page - 1));
    $form_state->setRebuild();
  }

  /**
   * Implements the pager submit handler for the ajax call.
   */
  public function ajaxPagerCallback(array &$form, FormStateInterface $form_state) {
    return $form['tml_entity_browser']['lister'];
  }

  /**
   * Implements the submit handler for the ajax call.
   *
   * @param array $form
   *   Render array representing from.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   Array of ajax commands to execute on submit of the modal form.
   */
  public function ajaxSubmitForm(array &$form, FormStateInterface $form_state) {
    // Clear the message set by the submit handler.
    drupal_get_messages();

    // We begin building a new ajax reponse.
    $response = new AjaxResponse();
    if ($form_state->getErrors()) {
      unset($form['#prefix']);
      unset($form['#suffix']);
      $form['status_messages'] = [
        '#type' => 'status_messages',
        '#weight' => -10,
      ];
      $response->addCommand(new HtmlCommand('#tml_media-modal-form', $form));
    }
    else {
      $image_url = $form_state->get('tml_media_image_url');
      $response->addCommand(new InvokeCommand('.filefield-source-tml_remote input', 'val', [$image_url]));
      $response->addCommand(new InvokeCommand('.filefield-source-tml_remote button', 'mousedown'));
      $response->addCommand(new CloseModalDialogCommand());
    }
    return $response;
  }

  /**
   * REST API call.
   */
  private function getRestApiMediaLister(array &$form, FormStateInterface $form_state) {
    $myConfig = \Drupal::config('tml_entity_browser');
    $rest_api_url = 'http://tml.gubo.brainsum.com//api/v1/tml_media_library';
    $username = $myConfig->get('username');
    $password = $myConfig->get('password');

    $page = $form_state->get('page');
    if ($page === NULL) {
      $form_state->set('page', 0);
      $page = 0;
    }

    $query = [];
    $query['page'] = $page;
    $user_input = $form_state->getUserInput();
    if (isset($user_input['name']) && !empty($user_input['name'])) {
      $query['name'] = $user_input['name'];
    }
    if (isset($user_input['category']) && !empty($user_input['category'])) {
      $query['category_tid'] = $user_input['category'];
    }
    $query_str = UrlHelper::buildQuery($query);
    $rest_api_url = $rest_api_url . '?' . $query_str;

    $client = new Client();
    $response = $client->get($rest_api_url, [
      'headers' => ['Authorization' => 'Basic ' . base64_encode("$username:$password")],
    ]);
    if (200 === $response->getStatusCode()) {
      $response = json_decode($response->getBody());
      $form['tml_entity_browser'] = $this->renderFormElements($response, $form_state);
    }
  }

  /**
   * Render form elements.
   */
  private function renderFormElements($response, FormStateInterface $form_state) {
    $results = $response->results;
    $render = '';
    $asset_urls = [];

    $render['filter'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'tml_media_entity_filter', 'class' => ['media-browser-lister']],
    ];
    $render['filter']['name'] = [
      '#title' => $this->t('Name'),
      '#type' => 'textfield',
      '#attributes' => ['class' => ['media-name']],
    ];
    if (!empty($response->categories)) {
      $render['filter']['category'] = [
        '#title' => $this->t('Category'),
        '#type' => 'select',
        '#options' => ['' => $this->t('- Any -')] + (array) $response->categories,
        '#attributes' => ['class' => ['media-category']],
      ];
    }
    $render['filter']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Filter'),
      '#limit_validation_errors' => [],
      '#submit' => ['::ajaxSubmitFilterForm'],
      '#ajax' => [
        'callback' => '::ajaxPagerCallback',
        'wrapper' => 'tml_media_entity_lister',
      ],
    ];

    $render['lister'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['media-browser-lister']],
      '#prefix' => '<div id="tml_media_entity_lister">',
      '#suffix' => '</div>',
    ];

    $render['lister']['media_asset'] = [
      '#title' => $this->t('Media asset'),
      '#type' => 'select',
      '#options' => (array) $response->media_assets,
      '#attributes' => ['class' => ['media-asset']],
    ];

    $render['lister']['media'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['media-lister']],
    ];
    foreach ($results as $delta => $data) {
      $media_id = $data->media_id;
      $asset_urls[$media_id] = $data->assets;
      $render['lister']['media'][$media_id] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['media-row']],
      ];
      $render['lister']['media'][$media_id]['media_id'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Select this item'),
        '#title_display' => 'invisible',
        '#return_value' => $media_id,
        '#attributes' => ['name' => "media_id_select[$media_id]"],
        '#default_value' => NULL,
      ];
      $img = [
        '#theme' => 'image',
        '#uri' => $data->thumbnail->url,
      ];
      $render['lister']['media'][$media_id]['media_id']['#field_suffix'] = drupal_render($img);
    }

    // Add navigation buttons.
    if ($response->current_page > 0) {
      $render['lister']['prev'] = [
        '#type' => 'submit',
        '#value' => $this->t('« Prev'),
        '#limit_validation_errors' => [],
        '#submit' => ['::ajaxSubmitPagerPrev'],
        '#ajax' => [
          'callback' => '::ajaxPagerCallback',
          'wrapper' => 'tml_media_entity_lister',
        ],
      ];
    }
    if ($response->total_items > count($results) * ($response->current_page + 1)) {
      $render['lister']['next'] = [
        '#type' => 'submit',
        '#value' => $this->t('Next »'),
        '#limit_validation_errors' => [],
        '#submit' => ['::ajaxSubmitPagerNext'],
        '#ajax' => [
          'callback' => '::ajaxPagerCallback',
          'wrapper' => 'tml_media_entity_lister',
        ],
      ];
    }

    $form_state->set('page', $response->current_page);
    $form_state->set('tml_media_asset_urls', $asset_urls);

    return $render;
  }

}
