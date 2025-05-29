// START OF user_galleries.js
console.log('user_galleries.js: File loaded. Version: 3 (User-indicated once invocation)');
console.log('user_galleries.js: At global scope, typeof Drupal:', typeof Drupal);
if (typeof Drupal !== 'undefined') {
  console.log('user_galleries.js: At global scope, typeof Drupal.once (for comparison):', typeof Drupal.once);
}
console.log('user_galleries.js: At global scope, typeof global "once" (if it exists):', typeof once);


// The 'once' in '($, Drupal, once)' is the local parameter name for the function.
// The 'once' at the VERY END ') (jQuery, Drupal, once);' is what you're saying works,
// meaning you're passing a global variable named 'once'.
(function ($, Drupal, once) { // 'once' here is the local parameter
  'use strict';

  console.log('user_galleries.js: Inside IIFE.');
  // This 'once' is the parameter received. If you passed global 'once', this log shows its type.
  console.log('user_galleries.js: IIFE received "once" parameter type:', typeof once);

  Drupal.behaviors.userGalleriesIconDelete = {
    attach: function (context, settings) {
      console.log('user_galleries.js: userGalleriesIconDelete.attach triggered.');
      // This 'once' refers to the 'once' parameter from the IIFE's function signature
      console.log('user_galleries.js: In attach, typeof local "once" variable:', typeof once);

      if (typeof once === 'function') {
        const iconLinks = once('userGalleriesIconDelete', 'a.gallery-delete-icon-trigger', context);
        console.log('user_galleries.js: "once" call completed, elements found for userGalleriesIconDelete:', iconLinks.length);
        iconLinks.forEach(function (linkElement) {
          $(linkElement).off('click.userGalleriesIconDelete').on('click.userGalleriesIconDelete', function (e) {
            e.preventDefault();
            var fid = $(this).data('fid');
            var $form = $(this).closest('form');
            console.log('user_galleries.js: Delete icon clicked for FID:', fid);
            $form.find('.gallery-action-delete-submit-fid-' + fid).trigger('mousedown');
          });
        });
      } else {
        console.error('user_galleries.js: The "once" variable is NOT a function in userGalleriesIconDelete.attach. Type of "once":', typeof once);
        // Fallback for critical testing
        $('a.gallery-delete-icon-trigger', context).each(function () {
          var $link = $(this);
          if (!$link.data('delete-handler-attached-fallback')) {
            $link.data('delete-handler-attached-fallback', true);
            $link.on('click.userGalleriesIconDeleteFallback', function (e) {
              e.preventDefault();
              var fid = $(this).data('fid');
              var $form = $(this).closest('form');
              console.warn('user_galleries.js: (Fallback click) Delete icon for FID:', fid);
              $form.find('.gallery-action-delete-submit-fid-' + fid).trigger('mousedown');
            });
          }
        });
      }
    }
  };

  Drupal.behaviors.userGalleriesClearUpload = {
    attach: function (context, settings) {
      if (settings.user_galleries && settings.user_galleries.resetUploadField) { //
        var $fidsInput = $('input[type="hidden"][name="upload_images[fids]"]', context); //
        var $widget = $fidsInput.closest('div.js-form-managed-file'); //
        var $fileItems = $widget.find('div[class*="js-form-item-upload-images-file-"]'); //
        var $removeSelectedButton = $widget.find('button[data-drupal-selector="edit-upload-images-remove-button"]'); //
        if ($removeSelectedButton.length === 0) { //
          $removeSelectedButton = $widget.find('button[name="upload_images_remove_button"]'); //
        }

        if ($fileItems.length > 0) { $fileItems.remove(); } //
        if ($fidsInput.length > 0) { $fidsInput.val('').trigger('change'); } //
        if ($removeSelectedButton.length > 0) { $removeSelectedButton.hide(); } //
        if (drupalSettings.user_galleries && drupalSettings.user_galleries.resetUploadField) { //
          delete drupalSettings.user_galleries.resetUploadField; //
        }
      }
    }
  };

  // This is the line you indicated works for you: passing a global 'once'
})(jQuery, Drupal, once);
// END OF user_galleries.js
