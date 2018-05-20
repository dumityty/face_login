(function ($, Drupal) {
  Drupal.behaviors.face_login = {
    attach: function (context, settings) {

      Webcam.set({
        width: 320,
        height: 240,
        image_format: 'jpeg',
        jpeg_quality: 90
      });
      Webcam.attach( '#webcam' );

      $('video').click(function() {
        Webcam.snap(function(data_uri) {
          $('#webcam_image').html("<img src='" + data_uri + "'/>" );
          var base64result = data_uri.split(',')[1];
          $('input[name=target]').val(base64result);
        });
      });

    }
  };
})(jQuery, Drupal);
