<?php
/*
Plugin Name: WooCommerce Admitad S5
Version: 0.4
Plugin URI: ${TM_PLUGIN_BASE}
Description: Connect Admitad CPA network for WooCommerce catalog
Author: AY
Author URI: ${TM_HOMEPAGE}
*/

require_once 'inc/class-menu-settings.php';

class woo_admitad{

  public $url;

  function __construct(){


    add_action('admin_menu', function(){
        add_management_page(
            $page_title = 'Admitad',
            $menu_title = 'Admitad',
            $capability = 'manage_options',
            $menu_slug = 'admitad-tool',
            $function = array($this, 'ui_management_page_callback')
        );
    });

  }

  function ui_management_page_callback(){

    $this->url = $_SERVER['REQUEST_URI'];

    echo '<h1>Управление Admitad</h1>';

    if(empty($_GET['a'])){
      printf('<p><a href="%s">Старт</a></p>', add_query_arg('a', 'start', $this->url));
      // do_action('woo_admitad_tool_actions_btns');
    } else {
      printf('<a href="%s">Вернуться...</a>', remove_query_arg( 'a', $this->url));
      // do_action('woo_admitad_tool_actions');
      $this->start();
    }

  }

    function start(){

      $url = get_option('admitad_url');
      if(empty($url)){
        printf('<p>No save feed URL: %s</p>', 'Go to Settings and save URL');
        return false;
      }

      if($att_id = get_transient( 'woo_at_media_id' )){
        $this->work($att_id);
      } else {
        $att_id = $this->save_file_by_url($url);
        set_transient( 'woo_at_media_id', $att_id, HOUR_IN_SECONDS );
        $this->work($att_id);
      }

    }


    function work($att_id){

      $file = get_attached_file( $att_id );
      printf('<p>Work with file: %s</p>', $file);

      $reader = new XMLReader;
      $reader->open($file);


      $i = 0;
      while($reader->read()){

        // var_dump($reader->readString());
        if ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'offer'){
          $xml = simplexml_load_string($reader->readOuterXML());

          $this->product_save_from_offer($xml, $reader);

          $i++;

        }

        if($i > 111){
          break;
        }
      }

      $reader->close();
    }


    function product_save_from_offer($xml, $reader){
      printf('<h2>%s</h2>', (string)$xml->name);

      printf('<p>id: %s</p>', $reader->getAttribute('id'));
      printf('<p>price: %s</p>', (string)$xml->price);
      printf('<p>url: %s</p>', (string)$xml->url);

      $img_url = (string)$xml->picture;
      printf('<p>picture: %s</p>', $img_url);

      $article = (string)$reader->getAttribute('id');
      if(empty($article))
        return false;

      $product_id = wc_get_product_id_by_sku($article);
      if(empty($product_id)){

        $product_id = $this->add_product($xml, $reader);
        printf('<p>added product: %s</p>', $product_id);

        //create
      }

      $product = wc_get_product($product_id);


      //Image product update or rest
      if( ! empty($img_url) ){
        $this->save_image_product_from_url($img_url, $product_id);
      } else {
        $this->save_image_product_from_url(null, $product_id);
      }
      //
      // //Price Retail 'salePrices'
      // if(isset($data_of_source['salePrices'][0]['value'])){
      //   $price_source = floatval($data_of_source['salePrices'][0]['value']/100);
      //
      //   if($price_source != $product->get_price()){
      //     update_post_meta( $product->id, '_regular_price', $price_source );
      //     update_post_meta( $product->id, '_price', $price_source );
      //
      //     printf('<p>+ Update product price: %s</p>', $price_source);
      //   } else {
      //     printf('<p>- No update product price: %s</p>', $price_source);
      //   }
      // }

      var_dump($xml);
      echo '<hr>';

    }

    function save_image_product_from_url($img_url, $product_id){

      if( empty($img_url) or empty($product_id) )
        return false;

      if( $this->is_image_save($product_id, $img_url) )
        return false;



      $upload = $this->upload_image_from_url( esc_url_raw( $img_url ) );

			if ( is_wp_error( $upload ) ) {
				return false;
			}

			$attachment_id = $this->set_uploaded_image_as_attachment( $upload, $product_id );


			if ( ! wp_attachment_is_image( $attachment_id ) ) {
				return false;
			}

      printf('<p>+ For product loaded image id: %s</p>', $attachment_id);

      update_post_meta($attachment_id, '_href_at', esc_url_raw( $img_url ) );

			set_post_thumbnail( $product_id, $attachment_id );

    }

    //Check saved image
    function is_image_save($product_id, $img_url){
      $data = get_posts('post_type=attachment&meta_key=_href_at&meta_value=' . esc_url_raw($img_url) );
      if( ! empty($data) ){
        return true;
      }
      return false;
    }


    function upload_image_from_url($image_url, $file_name=''){
      if(empty($file_name)){
    		$file_name  = basename( current( explode( '?', $image_url ) ) );
    	}

    	$parsed_url = @parse_url( $image_url );

    	// Check parsed URL.
    	if ( ! $parsed_url || ! is_array( $parsed_url ) ) {
    		return new WP_Error( 'woomss_invalid_image_url', sprintf( 'Invalid URL %s.', $image_url ), array( 'status' => 400 ) );
    	}

    	// Ensure url is valid.
    	$image_url = esc_url_raw( $image_url );

    	// Get the file.
    	$response = wp_safe_remote_get( $image_url, array(
    		'timeout' => 10,
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode( get_option( 'woomss_login' ) . ':' . get_option( 'woomss_pass' ) )
        )
    	));


    	if ( is_wp_error( $response ) ) {
    		return new WP_Error( 'woomss_invalid_remote_image_url', sprintf( __( 'Error getting remote image %s.', 'woocommerce' ), $image_url ) . ' ' . sprintf( __( 'Error: %s.', 'woocommerce' ), $response->get_error_message() ), array( 'status' => 400 ) );
    	} elseif ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
    		return new WP_Error( 'woomss_invalid_remote_image_url', sprintf( __( 'Error getting remote image %s.', 'woocommerce' ), $image_url ), array( 'status' => 400 ) );
    	}

    	// Ensure we have a file name and type.
    	$wp_filetype = wp_check_filetype( $file_name, wc_rest_allowed_image_mime_types() );

    	if ( ! $wp_filetype['type'] ) {
    		$headers = wp_remote_retrieve_headers( $response );
    		if ( isset( $headers['content-disposition'] ) && strstr( $headers['content-disposition'], 'filename=' ) ) {
    			$disposition = end( explode( 'filename=', $headers['content-disposition'] ) );
    			$disposition = sanitize_file_name( $disposition );
    			$file_name   = $disposition;
    		} elseif ( isset( $headers['content-type'] ) && strstr( $headers['content-type'], 'image/' ) ) {
    			$file_name = 'image.' . str_replace( 'image/', '', $headers['content-type'] );
    		}
    		unset( $headers );

    		// Recheck filetype
    		$wp_filetype = wp_check_filetype( $file_name, wc_rest_allowed_image_mime_types() );

    		if ( ! $wp_filetype['type'] ) {
    			return new WP_Error( 'woomss_invalid_image_type', __( 'Invalid image type.', 'woocommerce' ), array( 'status' => 400 ) );
    		}
    	}

    	// Upload the file.
    	$upload = wp_upload_bits( $file_name, '', wp_remote_retrieve_body( $response ) );

    	if ( $upload['error'] ) {
    		return new WP_Error( 'woomss_image_upload_error', $upload['error'], array( 'status' => 400 ) );
    	}

    	// Get filesize.
    	$filesize = filesize( $upload['file'] );

    	if ( 0 == $filesize ) {
    		@unlink( $upload['file'] );
    		unset( $upload );

    		return new WP_Error( 'woomss_image_upload_file_error', __( 'Zero size file downloaded.', 'woocommerce' ), array( 'status' => 400 ) );
    	}

    	return $upload;

    }


    function set_uploaded_image_as_attachment($upload, $product_id){
      $info    = wp_check_filetype( $upload['file'] );
      $title = '';
      $content = '';

      if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
        include_once( ABSPATH . 'wp-admin/includes/image.php' );
      }

      if ( $image_meta = wp_read_image_metadata( $upload['file'] ) ) {
        if ( trim( $image_meta['title'] ) && ! is_numeric( sanitize_title( $image_meta['title'] ) ) ) {
          $title = wc_clean( $image_meta['title'] );
        }
        if ( trim( $image_meta['caption'] ) ) {
          $content = wc_clean( $image_meta['caption'] );
        }
      }

      $attachment = array(
        'post_mime_type' => $info['type'],
        'guid'           => $upload['url'],
        'post_parent'    => $id,
        'post_title'     => $title,
        'post_content'   => $content,
      );

      $attachment_id = wp_insert_attachment( $attachment, $upload['file'], $id );
      if ( ! is_wp_error( $attachment_id ) ) {
        wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
      }

      return $attachment_id;
    }

    function add_product($xml, $reader){

        // $product = new WC_Product_Simple();
        $post_data = array(
          'post_type' => 'product',
          'post_title'    => wp_filter_post_kses( (string)$xml->name ),
          'post_status'   => 'draft'
        );

        // Вставляем запись в базу данных
        $post_id = wp_insert_post( $post_data );

        if( ! empty($reader->getAttribute('id')) ){
          update_post_meta( $post_id, $meta_key = '_sku', $article );
        }

        return $post_id;
    }


    function save_file_by_url($url){



      $temp_file = download_url( $url, $timeout = 333 );

      if( is_wp_error( $temp_file ) ){

        printf('<p>WP Error: %s</p>', $temp_file->get_error_messages());

        return false;

      } else {

        printf('<p>File loaded: %s</p>', $temp_file);

      }

      try {

          $file_name = 'admitad-data-' . date("Ymd-H-i-s") . '.xml';
          // unlink( $temp_file );

          // var_dump($file_name); exit;
          $file_data = array(
        		'name'     => $file_name,
        		'type'     => 'application/xml',
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
          unlink( $temp_file );

          if( ! empty($results['error']) ){
        		// Добавьте сюда обработчик ошибок
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

            return $attach_id;

      	}


      } catch (Exception $e) {

          printf('<p><pre>%s</pre></p>',$e);
          unlink( $temp_file );
          return false;
      }

      return false;

    }


} new woo_admitad;
