<?php

namespace Drupal\face_login\Form;

use Aws\Rekognition\RekognitionClient;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\UserStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class FaceLoginForm.
 */
class FaceLoginForm extends FormBase {

  /**
   * The user storage.
   *
   * @var \Drupal\user\UserStorageInterface
   */
  protected $userStorage;

  /**
   * Constructs a new FaceLoginForm.
   * @param \Drupal\user\UserStorageInterface $user_storage
   *   The user storage.
   */
  public function __construct(UserStorageInterface $user_storage) {
    $this->userStorage = $user_storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.manager')->getStorage('user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'face_login_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['webcam'] = [
      '#markup' => '<div id="webcam"></div><div id="webcam_image"></div>',
    ];

    $form['username'] = [
      '#type' => 'textfield',
      '#title' => 'Username',
      '#required' => TRUE,
    ];

    $form['target'] = [
      '#type' => 'hidden',
      '#value' => '',
    ];

    $form['#validate'][] = '::validateFaces';

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
    ];

    $form['#attached']['library'][] = 'face_login/webcamjs';
    $form['#attached']['library'][] = 'face_login/face_login';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  public function validateFaces(array &$form, FormStateInterface $form_state) {
    // Load user by username.
    $accounts = $this->userStorage->loadByProperties(['name' => $form_state->getValue('username')]);
    $account = reset($accounts);
    // Load user profile picture.
    $user_picture = $account->user_picture->first();
    $target_id = $user_picture->getValue('target_id')['target_id'];
    $image_profile = \Drupal\file\Entity\File::load($target_id);

    // Webcam shot is saved in base64 string so need to decode that into image bytes.
    $image_login = $form_state->getUserInput()['target'];
    $image_login = base64_decode($image_login);

    // If both images have been provided then proceed,
    if ($image_profile && $image_login) {

      // Load the profile picture into image bytes.
      $image_profile = file_get_contents($image_profile->getFileUri());

      // Prepare some AWS config.
      $options = [
        'region' => 'eu-west-1',
        'version' => '2016-06-27',
        'profile' => \Drupal::config('aws')->get('profile'),
      ];

      try {
        $client = new RekognitionClient($options);

        $result = $client->compareFaces([
          'SimilarityThreshold' => 90,
          'SourceImage' => [
            'Bytes' => $image_profile,
          ],
          'TargetImage' => [
            'Bytes' => $image_login,
          ],
        ]);

        if (count($result['FaceMatches']) == 0) {
          $form_state->setErrorByName('form', $this->t('Login unsuccessful.'));
        }
        else {
          $similarity = $result['FaceMatches'][0]['Similarity'];
          $markup = 'Successful login. Login image is ' . $similarity . '% similar to the Profile picture';
          drupal_set_message($markup);

          // Save the user account id to be used in submit handler.
          $form_state->set('uid', $account->id());
        }
      }
      catch (\Exception $e) {
        $form_state->setErrorByName('form', $this->t('An error occurred.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Load the user account.
    $account = $this->userStorage->load($form_state->get('uid'));

    // Log the user in.
    user_login_finalize($account);

    // Redirect to user profile page.
    $form_state->setRedirect('user.page');

  }
}
