<?php
/**
 * Upload images
 */
class AWW_Upload_Images {

  function __construct()
  {
    add_action('aww_product_update', [$this, 'update_pictures'], 10, 2);

  }


    /**
     * Other update data for product
     */
    public function update_pictures($product_id, $xml_offer){



      //$product_id
      $product = wc_get_product($product_id);


      $img_data = (array)$xml_offer->picture;
      printf('<p>Count pictures: %s</p>', count($img_data));

      //Image product update or rest
      foreach ($img_data as $key => $value) {
        $this->save_image_product_from_url($product_id, $value);
      }

      $images = get_posts('post_type=attachment&posts_per_page=-1&post_parent=' . $product_id);

      //Check and save thumbnail
      if( ! has_post_thumbnail($product_id) ){
        if(isset($images[0]->ID)){
          $thumbnail_id = $images[0]->ID;
          if(set_post_thumbnail( $product_id, $thumbnail_id )){
            printf('<p>+ Set thumbnail: %s</p>', $thumbnail_id );
          }
        }
      }

      //Save gallery
      $gallery = array();
      foreach ($images as $key => $value) {
        if($key == 0){
          continue;
        }

        $gallery[] = $value->ID;
        update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery ) );
      }

    }



      /**
       * Save image from URL
       *
       * @param int $product_id id product
       * @param string $img_url url for image
       * @return id attachment
       */
      function save_image_product_from_url( $product_id, $img_url ){

        if( empty($img_url) or empty($product_id) )
          return false;

        if( $this->check_image_save($product_id, $img_url) ){
          return true;
        }

        printf('<p>+ Start save img: %s</p>', $img_url);

        $attachment_id = $this->save_img_by_url($img_url);

        if(intval($attachment_id)){
          wp_update_post(array(
            'ID' => $attachment_id,
            'post_parent' => $product_id
          ));

          return true;
        } else {
          return false;
        }

      }

      /**
      * Save image from URL and return att id
      *
      * @return int $attach_id
      */
      function save_img_by_url($url){

        $temp_file = download_url( $url, $timeout = 333 );

        if( is_wp_error( $temp_file ) ){
          printf('<p>WP Error: %s</p>', $temp_file->get_error_messages());
          return false;
        }

        try {

            if(empty($file_name)){
          		$file_name  = basename( current( explode( '?', $url ) ) );
          	}

            $wp_filetype = wp_check_filetype( $file_name, wc_rest_allowed_image_mime_types() );

            $file_data = array(
          		'name'     => $file_name,
          		'type'     => $wp_filetype['type'],
          		'tmp_name' => $temp_file,
          		'error'    => 0,
          		'size'     => filesize($temp_file),
          	);

            $overrides = array(
          		'test_form' => false,
          		'test_size' => false,
          		'test_upload' => false,
          	);

          	// перемещаем временный файл в папку uploads
          	$results = wp_handle_sideload( $file_data, $overrides );


            if( ! empty($results['error']) ){
          		// Добавьте сюда обработчик ошибок
              $check_unlink = unlink( $temp_file );
              throw new Exception("Ошибка переноса в папку загрузки WP...<br/>" . sprintf('<pre>%s</pre>',$results['error']), 1);

          	} else {

          		$filename = $results['file']; // полный путь до файла
          		$local_url = $results['url']; // URL до файла в папке uploads
          		$type = $results['type']; // MIME тип файла

          		// делаем что-либо на основе полученных данных

              $attachment = array(
                  'guid'           => $filename,
                  'post_mime_type' => $type,
                  'post_title'     => $file_data['name'],
                  'post_content'   => '',
                  'post_status'    => 'inherit'
              );

              // Вставляем запись в базу данных.
              $attach_id = wp_insert_attachment( $attachment, $filename );

              // Создадим метаданные для вложения и обновим запись в базе данных.
              $attach_data = wp_generate_attachment_metadata( $attach_id, $filename );
              wp_update_attachment_metadata( $attach_id, $attach_data );

              //Save url to meta for check future imports
              update_post_meta($attach_id, '_href_at', esc_url_raw( $url));

              return $attach_id;

        	}


        } catch (Exception $e) {

            // printf('<p><pre>%s</pre></p>',$e);
            error_log(print_r(debug_backtrace(), TRUE));

            if(empty($check_unlink)){
              unlink( $temp_file );
            }
            return false;
        }

        return false;
      }

      /*
       * Check saved image
       *
       * @param int $product_id
       * @return bool true or false
       */
      function check_image_save($product_id, $img_url){

        $args = array(
          'post_type' => 'attachment',
          'meta_key' => '_href_at',
          'meta_value' =>esc_url_raw($img_url),
          'post_parent' => $product_id
        );

        $data = get_posts($args);
        if( ! empty($data) ){
          return true;
        }
        return false;
      }
}
new AWW_Upload_Images;
