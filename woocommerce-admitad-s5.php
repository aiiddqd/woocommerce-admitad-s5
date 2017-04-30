<?php
/*
Plugin Name: WooCommerce Admitad S5 (AWW)
Version: 0.7
Plugin URI: https://github.com/yumashev/woocommerce-admitad-s5
Description: Connect Admitad CPA network for WooCommerce catalog. Stack: Admitad WordPress WooCommerce (AWW)
Author: AY
Author URI: https://github.com/yumashev/
*/

require_once 'inc/class-menu-settings.php';

class woo_admitad{

  public $url;

  private $reader; //save object for read data from xml file

  function __construct(){


    add_action('admin_menu', function(){
        add_management_page(
            $page_title = 'Admitad',
            $menu_title = 'Admitad',
            $capability = 'manage_options',
            $menu_slug = 'admitad-tool',
            $function = array($this, 'user_interface')
        );
    });

    add_filter( 'upload_mimes', array($this, 'additional_mime_types') );

    //do_action('aww_product_update', $product_id, $xml);
    add_action('aww_product_update', [$this, 'update_data_other'], 10, 2);

  }

  /**
   * Ither update data for product
   */
  public function update_data_other($product_id, $xml_offer){

    update_post_meta( $product_id, '_visibility', 'visible' );
    update_post_meta( $product_id, '_stock_status', 'instock');


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
      if($thumbnail_id == $value->ID){
        continue;
      }

      $gallery[] = $value->ID;
      update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery ) );
    }


    wp_set_object_terms( $product_id, 'external', 'product_type' );

    $url = (string)$xml_offer->url;
    printf('<p>url: %s</p>', $url);

    update_post_meta( $product_id, '_product_url', $url);
    update_post_meta( $product_id, '_button_text', "Купить");

    update_post_meta( $product_id, 'xml_admitad', print_r($xml_offer, true));

    //Price Retail 'salePrices'
    $price = (string)$xml_offer->price;
    printf('<p>price: %s</p>', $price);

    if( isset($price) ){
      $price_source = floatval($price);

      if($price_source != $product->get_price()){

        update_post_meta( $product_id, '_regular_price', $price_source );
        update_post_meta( $product_id, '_price', $price_source );

        printf('<p>+ Update product price: %s</p>', $price_source);
      } else {
        printf('<p>- No update product price: %s</p>', $price_source);
      }
    }

    $post_data = array(
      'ID' => $product->id
    );

    if( (string)$xml_offer->description != (string)$product->post_content ){
      $post_data['post_content'] = (string)$xml_offer->description;
      printf('<p>+ Change content: %s</p>', $product_id);
    }

  }

  /**
   * User interface
   */
  function user_interface(){

    $this->url = $_SERVER['REQUEST_URI'];

    echo '<h1>Управление Admitad</h1>';

    if($aww_dl_xml_start = get_transient('aww_dl_xml_start')){
      printf('<p>Файл загружается: %s</p>', $aww_dl_xml_start);
    }

    if($woo_at_media_id = get_transient('woo_at_media_id')){
      printf('<p>ID файла (woo_at_media_id): %s</p>', $woo_at_media_id);
    }

    if($aww_import_count_product = get_transient('aww_import_count_product')){
      printf('<p>Количество загруженных (aww_import_count_product): %s</p>', $aww_import_count_product);

    }

    if(empty($_GET['a'])){
      printf('<p><a href="%s">Старт</a></p>', add_query_arg('a', 'start', $this->url));
      // do_action('woo_admitad_tool_actions_btns');
    } else {
      printf('<a href="%s">Вернуться...</a>', remove_query_arg( 'a', $this->url));
      // do_action('woo_admitad_tool_actions');
      $this->start_worker();
    }

  }

  /**
   * Start worker for xml file
   *
   * @param no params
   * @return return void
   */
  private function start_worker(){

    $url = get_option('admitad_url');
    if(empty($url)){
      printf('<p>No save feed URL: %s</p>', 'Go to Settings and save URL');
      return false;
    }

    if($att_id = get_transient( 'woo_at_media_id' )){
      $this->read_xml_file($att_id);
    } else {
      $this->save_xml_by_url($url);
    }

  }

  /**
   * Read xml file and update data
   */
  public function read_xml_file($att_id){

    $file = get_attached_file( $att_id );


    if(empty($file)){
      delete_transient( 'woo_at_media_id');
      printf('<p>File not found in base: %s. Cache clear. Reload page.</p>', $att_id);
      return false;
    }

    printf('<p>Work with file: %s</p>', $file);

    echo 'Нужно чуть чуть подождать...';
    // fastcgi_finish_request();

    $this->reader = new XMLReader;
    $this->reader->open($file);

    // var_dump($file); exit;


    $i = 0;
    while($this->reader->read()){

      set_transient('aww_import_count_product', $i, HOUR_IN_SECONDS);

      if ($this->reader->nodeType == XMLReader::ELEMENT && $this->reader->name == 'offer'){
        $xml_offer = simplexml_load_string($this->reader->readOuterXML());

        $i++;
        printf('<h2>%s. %s</h2>', $i, (string)$xml_offer->name);
        $this->product_save_from_offer($xml_offer);


      }

      if($i >= 100){
        break;
      }
    }

    $this->reader->close();
  }

  /**
   * Product save from XML data
   *
   * @param XMLObject $xml - xml data of offer
   * @return return HTML
   */
  function product_save_from_offer($xml_offer){

    try {
      $article = (string)$xml_offer->attributes()->{'id'};

      if(empty($article)){
        error_log(sprintf('<p>AWW: Нет артикула у продукта: %s</p>', (string)$xml_offer->name));
        return false;
      }

      printf('<p>article: %s</p>', $article);

      $product_id = wc_get_product_id_by_sku($article);
      if(empty($product_id)){
        $product_id = $this->add_product($xml_offer, $article);
        printf('<p>+ Added product: %s</p>', $product_id);
      }

      printf('<p><a href="%s" target="_blank">edit post link</a></p>', get_edit_post_link( $product_id, '' ));

      do_action('aww_product_update', $product_id, $xml_offer);

      wp_update_post( ['ID' => $product_id] );

      wp_publish_post($product_id);

      echo '<hr>';
    } catch (Exception $e) {
      error_log(print_r(debug_backtrace(), TRUE));
      error_log( sprintf( '<p>AWW: Ошибка сохранения данных: %s</p>', print_r($e, true) ) );
    }
  }

  /*
  * Add product from item XML Admitad
  */
  function add_product($xml, $article){

      $post_data = array(
        'post_type' => 'product',
        'post_author' => get_current_user_id(),
        'post_title'    => wp_filter_post_kses( (string)$xml->name ),
        'post_name'    => wp_filter_post_kses( (string)$xml->name ),
        'post_content'    => wp_filter_post_kses( (string)$xml->description ),
        'post_status' => "publish"
      );

      $post_id = wp_insert_post( $post_data );
      wp_set_object_terms($post_id, 'simple', 'product_type');
      update_post_meta( $post_id, $meta_key = '_sku', $article );

      return $post_id;

  }

  /**
  * Save file from URL
  */
  function save_xml_by_url($url){

    if( ! empty(get_transient('aww_dl_xml_start'))){
      return false;
    }

    set_transient('aww_dl_xml_start', date("Y-m-d H:i:s"), HOUR_IN_SECONDS);
    echo '<p>Файл отправлен на загрузку. Нужно вернуться и подождать пока не появится ID файла.</p>';
    fastcgi_finish_request();
    $temp_file = download_url( $url, $timeout = 555 );

    if( is_wp_error( $temp_file ) ){
      update_option('aww_dl_xml_start', 0);
      printf('<p>WP Error: %s</p>', $temp_file->get_error_messages());

      return false;

    } else {

      update_option('aww_dl_xml_start', 0);
      printf('<p>File loaded: %s</p>', $temp_file);

    }

    try {

        $file_name = 'admitad-data-' . date("Ymd-H-i-s") . '.xml';

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
        $check_unlink = unlink( $temp_file );

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

          set_transient( 'woo_at_media_id', $attach_id, DAY_IN_SECONDS );
          delete_transient('aww_dl_xml_start');

    	}

    } catch (Exception $e) {
        // printf('<p><pre>%s</pre></p>',$e);
        error_log(print_r(debug_backtrace(), TRUE));

        if(empty($check_unlink))
          unlink( $temp_file );

        delete_transient('aww_dl_xml_start');
        return false;
    }

    return false;
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
        $check_unlink = unlink( $temp_file );

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

  /**
   * Adding xml for upload
   *
   * @param array $mimes - array types for allow uploads
   * @return $mimes array
   */
  public function additional_mime_types( $mimes ) {
      	$mimes['xml'] = 'application/xml';
      	return $mimes;
  }

} new woo_admitad;
