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

  // Drupal.behaviors.userGalleriesClearUpload = {
  //   attach: function (context, settings) {
  //     if (settings.user_galleries && settings.user_galleries.resetUploadField) { //
  //       var $fidsInput = $('input[type="hidden"][name="upload_images[fids]"]', context); //
  //       var $widget = $fidsInput.closest('div.js-form-managed-file'); //
  //       var $fileItems = $widget.find('div[class*="js-form-item-upload-images-file-"]'); //
  //       var $removeSelectedButton = $widget.find('button[data-drupal-selector="edit-upload-images-remove-button"]'); //
  //       if ($removeSelectedButton.length === 0) { //
  //         $removeSelectedButton = $widget.find('button[name="upload_images_remove_button"]'); //
  //       }

  //       if ($fileItems.length > 0) { $fileItems.remove(); } //
  //       if ($fidsInput.length > 0) { $fidsInput.val('').trigger('change'); } //
  //       if ($removeSelectedButton.length > 0) { $removeSelectedButton.hide(); } //
  //       if (drupalSettings.user_galleries && drupalSettings.user_galleries.resetUploadField) { //
  //         delete drupalSettings.user_galleries.resetUploadField; //
  //       }
  //     }
  //   }
  // };

  Drupal.behaviors.userGalleriesZoom = {
    attach: function (context, settings) {
      const galleryImages = once('gallery-image-zoom', '.gallery-image', context);

      galleryImages.forEach(function (element) {
        $(element).on('click keydown', function (e) {
          // Allow keyboard activation with Enter or Space
          if (e.type === 'keydown' && (e.key !== 'Enter' && e.key !== ' ')) {
            return;
          }
          e.stopPropagation(); // Prevent event from bubbling up if necessary

          const $this = $(this);
          const isZoomed = $this.hasClass('zoomed');

          // Remove 'zoomed' class from all other images in any gallery
          $('.gallery-image.zoomed').not(this).removeClass('zoomed');

          // Toggle 'zoomed' class on the clicked image
          $this.toggleClass('zoomed', !isZoomed);

          if (!isZoomed) {
            // Optional: Add a one-time click listener to the document to unzoom
            // when clicking anywhere else, or handle via Esc key.
            $(document).one('click.galleryZoomOut', function (eClose) {
              if (!$(eClose.target).closest('.gallery-image.zoomed').length) {
                $this.removeClass('zoomed');
              }
            });
            // Unzoom on Escape key
            $(document).on('keydown.galleryZoomOutEsc', function (eEsc) {
              if (eEsc.key === "Escape") {
                $this.removeClass('zoomed');
                $(document).off('.galleryZoomOutEsc .galleryZoomOut'); // Clean up listeners
              }
            });
          } else {
            // If it was already zoomed and clicked again, it's now unzoomed by toggleClass.
            // Clean up document-level listeners.
            $(document).off('.galleryZoomOutEsc .galleryZoomOut');
          }
        });
      });
    }
  };
  Drupal.behaviors.userGalleriesCollapse = {
    attach: function (context, settings) {
      const galleries = once('user-gallery-collapse', '.gallery-horizontal-list', context);

      galleries.forEach(function (galleryElement) {
        const $gallery = $(galleryElement);
        const galleryId = $gallery.attr('id') || 'gallery-' + Math.random().toString(36).substr(2, 9);
        $gallery.attr('id', galleryId);

        const singleRowHeightString = $gallery.css('height');
        const singleRowHeightPx = parseInt(singleRowHeightString, 10);

        if (galleryElement.scrollHeight > (singleRowHeightPx + 100)) { // Add a small tolerance

          // Create the main clickable block (div)
          const $toggleBlock = $('<div class="gallery-toggle-clickable-area" role="button" tabindex="0" aria-expanded="false"></div>')
            .attr('aria-controls', galleryId)
            .attr('title', Drupal.t('Show More')); // Accessibility: Initial title

          const $iconElement = $('<i class="bi bi-chevron-double-down"></i>'); // Initial icon
          $toggleBlock.append($iconElement); // Icon is inside the clickable block

          // Append the clickable block inside the gallery list
          $gallery.append($toggleBlock);

          // Click event handler for the entire block
          $toggleBlock.on('click.galleryToggle', function (e) {
            e.preventDefault();
            if ($gallery.hasClass('is-expanded')) {
              $gallery.removeClass('is-expanded');
              $iconElement.removeClass('bi-chevron-double-up').addClass('bi-chevron-double-down');
              $(this).attr('aria-expanded', 'false').attr('title', Drupal.t('Show More'));
            } else {
              $gallery.addClass('is-expanded');
              $iconElement.removeClass('bi-chevron-double-down').addClass('bi-chevron-double-up');
              $(this).attr('aria-expanded', 'true').attr('title', Drupal.t('Show Less'));
            }
          });

          // Allow keyboard activation (Enter/Space) for the div with role="button"
          $toggleBlock.on('keydown.galleryToggle', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
              e.preventDefault();
              $(this).trigger('click.galleryToggle');
            }
          });
        }
      });
    }
  };

  // Drupal.behaviors.userGalleriesHorizontalScroll = {
  //   attach: function (context, settings) {
  //     const galleryWrappers = once('horizontal-gallery-init', '.gallery-scroll-container-wrapper', context);

  //     galleryWrappers.forEach(function (wrapperElement) {
  //       const $wrapper = $(wrapperElement);
  //       const $scrollableList = $wrapper.find('.gallery-horizontal-list-scrollable');
  //       const $indicators = $wrapper.find('.gallery-indicator-dot');
  //       const $items = $scrollableList.find('.gallery-list-item'); // These are the direct children to scroll to

  //       if ($scrollableList.length === 0 || $items.length <= 1) {
  //         $indicators.parent().hide(); // Hide indicators container if not needed
  //         return;
  //       }

  //       // Function to scroll to an item
  //       function scrollToItem(itemId) {
  //         const $targetItem = $('#' + itemId, $scrollableList);
  //         if ($targetItem.length) {
  //           const scrollContainer = $scrollableList.get(0);
  //           const containerScrollLeft = $scrollableList.scrollLeft();
  //           const containerOffsetLeft = $scrollableList.offset().left; // For more precise calculations

  //           // Position of the item relative to the document
  //           const itemOffsetLeftDoc = $targetItem.offset().left;
  //           // Position of the item relative to the start of the scrollable container's content
  //           const itemScrollPos = itemOffsetLeftDoc - containerOffsetLeft + containerScrollLeft;

  //           $scrollableList.animate({ scrollLeft: itemScrollPos }, 300);
  //         }
  //       }

  //       // Function to update active indicator
  //       function updateActiveIndicator() {
  //         let currentActiveIndex = 0; // Default to first
  //         const scrollLeft = $scrollableList.scrollLeft();
  //         const containerWidth = $scrollableList.innerWidth(); // Use innerWidth for content area
  //         const containerScrollableWidth = $scrollableList.get(0).scrollWidth;

  //         let minDistanceToCenter = Infinity;

  //         $items.each(function (index) {
  //           const itemLeft = this.offsetLeft;
  //           const itemWidth = $(this).outerWidth();
  //           // Calculate center of item relative to scrollable area start
  //           const itemCenterInScrollable = itemLeft + itemWidth / 2;
  //           // Calculate center of visible viewport of scrollable area
  //           const viewportCenterInScrollable = scrollLeft + containerWidth / 2;

  //           const distance = Math.abs(itemCenterInScrollable - viewportCenterInScrollable);

  //           if (distance < minDistanceToCenter) {
  //             minDistanceToCenter = distance;
  //             currentActiveIndex = index;
  //           }
  //         });

  //         // Special case for scrolled to end (last item might not be centered)
  //         if (scrollLeft + containerWidth >= containerScrollableWidth - ($items.last().outerWidth() / 2)) { // Check if near the end
  //           currentActiveIndex = $items.length - 1;
  //         }


  //         const $activeIndicator = $indicators.eq(currentActiveIndex);
  //         if (!$activeIndicator.hasClass('active')) {
  //           $indicators.removeClass('active').attr('aria-selected', 'false');
  //           $activeIndicator.addClass('active').attr('aria-selected', 'true');
  //         }
  //       }

  //       // Indicator click functionality
  //       $indicators.on('click.galleryIndicator', function () {
  //         const $indicator = $(this);
  //         const targetItemId = $indicator.data('slide-to-item-id');
  //         scrollToItem(targetItemId);
  //         // Update active class immediately for responsiveness
  //         $indicators.removeClass('active').attr('aria-selected', 'false');
  //         $indicator.addClass('active').attr('aria-selected', 'true');
  //       });

  //       // Update indicators on scroll
  //       let scrollTimeout;
  //       $scrollableList.on('scroll.galleryScroll', function () {
  //         clearTimeout(scrollTimeout);
  //         scrollTimeout = setTimeout(updateActiveIndicator, 100); // Debounce
  //       });

  //       // Initial update
  //       updateActiveIndicator();
  //       // Ensure first indicator is active if list isn't scrollable initially but has items
  //       if ($scrollableList.get(0).scrollWidth <= $scrollableList.innerWidth() && $items.length > 0) {
  //         $indicators.removeClass('active').attr('aria-selected', 'false');
  //         $indicators.first().addClass('active').attr('aria-selected', 'true');
  //       }

  //     });
  //   }
  // };

  // This is the line you indicated works for you: passing a global 'once'
})(jQuery, Drupal, once);
// END OF user_galleries.js

