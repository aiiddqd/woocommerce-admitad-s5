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
require_once 'inc/class-data-saving.php';
require_once 'inc/class-upload-img.php';
require_once 'inc/class-save-categories.php';

class AWW_Core{

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


  }


  /**
   * User interface
   */
  function user_interface(){

    $this->url = $_SERVER['REQUEST_URI'];

    echo '<h1>Управление Admitad</h1>';

    printf('<p>Ссылка файла для загрузки: %s</p>',get_option('admitad_url'));
    printf('<p>Количество предложений в файле: %s</p>',get_transient('aww_count_products'));

    if($woo_at_media_id = get_transient('woo_at_media_id')){
      printf('<p>ID файла (woo_at_media_id): %s</p>', $woo_at_media_id);
    }

    if($aww_import_count_product = get_transient('aww_import_count_product')){
      printf('<p>Количество загруженных (aww_import_count_product): %s</p>', $aww_import_count_product);

    }

    if(empty($_GET['a'])){
      printf('<p><a href="%s">Старт</a></p>', add_query_arg('a', 'start', $this->url));
    } else {
      printf('<a href="%s">Вернуться...</a>', remove_query_arg( 'a', $this->url));
      $this->start();
    }

  }

  /**
   * Start worker for xml file
   *
   * @param no params
   * @return return void
   */
  private function start(){

    //Check URL for load file
    $url = get_option('admitad_url');
    if(empty($url)){
      printf('<p>No save feed URL: %s</p>', 'Go to Settings and save URL');
      return false;
    }

    if($att_id = get_transient( 'woo_at_media_id' )){


      $file = get_attached_file( $att_id );

      if(empty($file)){
        delete_transient( 'woo_at_media_id');
        printf('<p>File not found in base: %s. Cache clear. Reload page.</p>', $att_id);
        return false;
      }

      //Get and save count offers in file
      $this->get_count_offers($file);

      $this->read_xml_file($file);
    } else {
      $this->save_xml_by_url($url);
    }

  }

  /**
   * Read xml file and update data
   */
  public function read_xml_file($file){
    printf('<p>Work with file: %s</p>', $file);

    $this->reader = new XMLReader;
    $this->reader->open($file);

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
   * undocumented function summary
   *
   * Undocumented function long description
   *
   * @param type var Description
   * @return return type
   */
  public function get_count_offers($file){

    $this->reader = new XMLReader;
    $this->reader->open($file);

    $i = 0;
    while($this->reader->read()){
      if ($this->reader->nodeType == XMLReader::ELEMENT && $this->reader->name == 'offer'){
        $i++;
      }
    }

    $this->reader->close();

    set_transient('aww_count_products', $i, DAY_IN_SECONDS);

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

      wp_publish_post($product_id);

      $product = wc_get_product($product_id);
      $check = $product->save();

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

    try {

      $temp_file = download_url( $url, $timeout = 555 );

      if( is_wp_error( $temp_file ) ){
        printf('<p>WP Error: %s</p>', $temp_file->get_error_messages());

        return false;

      } else {
        printf('<p>File loaded: %s</p>', $temp_file);
      }

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

        set_transient( 'woo_at_media_id', $attach_id, DAY_IN_SECONDS );

    	}

    } catch (Exception $e) {
        // printf('<p><pre>%s</pre></p>',$e);
        error_log(print_r(debug_backtrace(), TRUE));

        if(empty($check_unlink))
          unlink( $temp_file );

        return false;
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

} new AWW_Core;
