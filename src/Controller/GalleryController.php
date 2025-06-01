<?php

namespace Drupal\user_galleries\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\user_galleries\Entity\Gallery; // Assuming Gallery is your entity class
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for gallery admin operations.
 */
class GalleryController extends ControllerBase
{

  /**
   * Redirects to the gallery management form with necessary context.
   *
   * This is used by the admin edit link for a gallery entity.
   *
   * @param \Drupal\user_galleries\Entity\Gallery $gallery
   * The gallery entity.
   * @param \Symfony\Component\HttpFoundation\Request $request
   * The current request.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   * A redirect response to the actual gallery management form.
   */
  public function adminEditRedirect(Gallery $gallery, Request $request)
  {
    if (!$gallery->getOwner() || !$gallery->get('gallery_type')->value) {
      // Handle cases where owner or gallery_type might be missing, though unlikely for existing entities.
      $this->messenger()->addError($this->t('Cannot edit gallery due to missing owner or type information.'));
      return $this->redirect('<front>'); // Redirect to a safe page
    }

    $route_parameters = [
      'user' => $gallery->getOwnerId(),
      'gallery_type' => $gallery->get('gallery_type')->value,
    ];

    // Preserve destination query parameter if present.
    if ($request->query->has('destination')) {
      $route_parameters['destination'] = $request->query->get('destination');
    }

    // Assuming 'user_galleries.admin_manage_gallery' is the route that takes 'user' and 'gallery_type'
    // and displays your GalleryManagementForm.
    return $this->redirect('user_galleries.admin_manage_gallery', $route_parameters);
  }

  /**
   * Title callback for the admin edit redirect route.
   *
   * @param \Drupal\user_galleries\Entity\Gallery $gallery
   * The gallery entity.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   * The page title.
   */
  public function adminEditRedirectTitle(Gallery $gallery)
  {
    return $this->t('Edit gallery @title', ['@title' => $gallery->label()]);
  }
}
