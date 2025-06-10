(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.userGalleriesZoom = {
    attach: function (context, settings) {
      const galleryImages = once('gallery-image-zoom', '.gallery-image', context);

      galleryImages.forEach(function (element) {
        $(element).on('click keydown', function (e) {
          // Allow keyboard activation with Enter or Space
          if (e.type === 'keydown' && (e.key !== 'Enter' && e.key !== ' ')) {
            return;
          }
          e.stopPropagation();

          const $this = $(this);
          const isZoomed = $this.hasClass('zoomed');

          $('.gallery-image.zoomed').not(this).removeClass('zoomed');

          // Toggle 'zoomed' class on the clicked image
          $this.toggleClass('zoomed', !isZoomed);

          if (!isZoomed) {
            $(document).one('click.galleryZoomOut', function (eClose) {
              if (!$(eClose.target).closest('.gallery-image.zoomed').length) {
                $this.removeClass('zoomed');
              }
            });
            $(document).on('keydown.galleryZoomOutEsc', function (eEsc) {
              if (eEsc.key === "Escape") {
                $this.removeClass('zoomed');
                $(document).off('.galleryZoomOutEsc .galleryZoomOut');
              }
            });
          } else {
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

        if (galleryElement.scrollHeight > (singleRowHeightPx + 100)) {

          const $toggleBlock = $('<div class="gallery-toggle-clickable-area" role="button" tabindex="0" aria-expanded="false"></div>')
            .attr('aria-controls', galleryId)
            .attr('title', Drupal.t('Show More'));

          const $iconElement = $('<i class="bi bi-chevron-double-down"></i>');
          $toggleBlock.append($iconElement);

          $gallery.append($toggleBlock);

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

})(jQuery, Drupal, once);
