/* Posts → Authors: pick or remove the author photo with the media library. */
(function ($) {
  'use strict';

  var frame = null;
  var target = null;

  function setPhoto(field, id, url) {
    field.find('input[name="mf_photo"]').val(id || '');
    var preview = field.find('.mf-photo-field__preview').empty();
    if (id && url) {
      preview.append($('<img>', { src: url, alt: '', width: 96, height: 96 }));
    }
    field.toggleClass('has-photo', Boolean(id));
  }

  $(document).on('click', '[data-mf-photo-choose]', function (e) {
    e.preventDefault();
    target = $(this).closest('.mf-photo-field');
    if (!frame) {
      frame = wp.media({
        title: 'Author photo',
        button: { text: 'Use this photo' },
        library: { type: 'image' },
        multiple: false
      });
      frame.on('select', function () {
        var a = frame.state().get('selection').first().toJSON();
        var size = (a.sizes && (a.sizes.thumbnail || a.sizes.medium)) || a;
        setPhoto(target, a.id, size.url);
      });
    }
    frame.open();
  });

  $(document).on('click', '[data-mf-photo-remove]', function (e) {
    e.preventDefault();
    setPhoto($(this).closest('.mf-photo-field'), '', '');
  });

  // "Add new author" saves over AJAX and clears only visible text fields; clear the photo too.
  $(document).ajaxSuccess(function (event, xhr, settings) {
    if (settings && typeof settings.data === 'string' && settings.data.indexOf('action=add-tag') !== -1) {
      $('#addtag .mf-photo-field').each(function () { setPhoto($(this), '', ''); });
    }
  });
})(jQuery);
