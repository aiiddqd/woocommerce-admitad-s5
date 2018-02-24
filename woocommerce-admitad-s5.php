<?php
/*
Plugin Name: WooCommerce Admitad S5 (AWW)
Version: 0.9
Plugin URI: https://github.com/yumashev/woocommerce-admitad-s5
Description: Connect Admitad CPA network for WooCommerce catalog. Stack: Admitad WordPress WooCommerce (AWW)
Author: AY
Author URI: https://github.com/yumashev/
*/

require_once 'inc/class-menu-settings.php';
require_once 'inc/class-data-saving.php';
require_once 'inc/class-upload-img.php';
require_once 'inc/class-save-categories.php';
require_once 'inc/class-external-links.php';

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

    add_action( "wp_ajax_nopriv_aww_load", array( $this, "worker_by_url" ) );
    add_action( "wp_ajax_aww_load", array( $this, "worker_by_url" ) );

  }

  /**
   * User interface
   */
  function user_interface(){

    $this->url = $_SERVER['REQUEST_URI'];
    ?>

    <h1>Управление Admitad</h1>

    <div class="aww_instructions">
      <h2>Инструкции и информация</h2>
      <ul>
        <li>Перед началом убедитесь что на странице настроек указана ссылка на xml фид загрузки товаров</li>
        <li>Вы можете запустить загрузку вручную в фоновом режиме</li>
      </ul>

      <?php
        printf('<p>Ссылка файла для загрузки: %s</p>',get_option('admitad_url'));

        if($woo_at_media_id = get_transient('woo_at_media_id')){
          printf('<p>Загруженный файл (woo_at_media_id: %s). Ссылка %s</p>', $woo_at_media_id, get_attachment_link( $woo_at_media_id ));
        }

        printf('<p>Количество предложений в файле: %s</p>',get_transient('aww_count_products'));

        if($aww_import_count_product = get_transient('aww_import_count_product')){
          printf('<p>Количество загруженных продуктов за последние сутки (aww_import_count_product): %s</p>', $aww_import_count_product);
        }


      ?>

    </div>

    <div class="aww_background">
      <h2>Фоновая загрузка</h2>
      <div class="notice notice-success is-dismissible">
        <?php
          //Вывод количества обработок в фоне если есть данные
          if( ! empty(get_transient('aww_count_redirect_load'))){
            printf('<p>Количество редиректов загрузки: %s</p>',get_transient('aww_count_redirect_load'));
          }

          //Вывод последней отметки времени о итерации
          if( ! empty(get_transient('aww_redirect_timestamp'))){
            printf('<p>Время последней итерации загрузки: %s</p>',get_transient('aww_redirect_timestamp'));
          }

          //Вывод последней остановки
          if( ! empty(get_transient('aww_stop_load'))){
            printf('<p>Время последней остановки: %s</p>',get_transient('aww_stop_load'));
          }

          //Последняя полная загрузка - время остановки
          if( ! empty(get_option('aww_end_load_timestamp'))){
            printf('<p>Время последней полной загрузки: %s</p>',get_option('aww_end_load_timestamp'));
          }
        ?>
      </div>

      <?php

        if(empty($_GET['a'])){
          printf('<p><a href="%s" class="button">Старт фоновой работы</a></p>', add_query_arg('a', 'start-bg', $this->url));
        }

        if(empty($_GET['a'])){
          printf('<p><a href="%s" class="button">Стоп фоновой работы</a></p>', add_query_arg('a', 'stop', $this->url));
        }
      ?>

    </div>

    <div class="aww_manual">
      <h2>Ручная загрузка для отладки</h2>
      <p>Ручная загрузка сделана тупо. Но позволяет понять характер проблем в случае их наличия. Она не может обрабатывать большой объем данных ввиду ограничений времени сессии на большинстве сайтов.</p>
      <?php
        if(empty($_GET['a'])){
          printf('<p><a href="%s" class="button">Старт ручной загрузки</a></p>', add_query_arg('a', 'start', $this->url));
        }
      ?>
    </div>
    <hr>
    <?php


    if(isset($_GET['a'])){
      printf('<a href="%s">Вернуться...</a>', remove_query_arg( 'a', $this->url));
      switch ($_GET['a']) {
          case 'start':
            $this->start();
            break;
          case 'start-bg':
            $this->start_background();
            break;
          case 'stop':
            $this->stop();
            break;
      }

    }

  }

  /*
  * Запуск фоновой обработки из UI
  */
  function start_background(){
    $url = add_query_arg(array('action' => 'aww_load', 'article' => 0, 'count' => 1), admin_url( 'admin-ajax.php' ));
    wp_remote_get($url);
    wp_redirect(remove_query_arg( 'a', $this->url), 302);
    exit;
  }

  function worker_by_url(){


    if( ! empty(get_transient('aww_stop')) ){
      delete_transient('aww_stop');
      delete_transient('aww_redirect_timestamp');
      set_transient('aww_stop_load', date("Y-m-d H:i:s"));
      return false;
    }

    delete_transient('aww_stop_load');

    set_transient('aww_redirect_timestamp', date("Y-m-d H:i:s"), DAY_IN_SECONDS);


    if(empty($_GET['article'])){
      $article_end = 0;
    } else {
      $article_end = sanitize_text_field($_GET['article']);
    }

    if(! empty($_GET['count'])){
      $count = $_GET['count'];

    }

    //Сброс счетчиков
    if( 1 == $count ){
      delete_transient('aww_count_redirect_load');
      delete_transient('aww_import_count_product');
    }

    set_transient('aww_count_redirect_load', $count, DAY_IN_SECONDS);
    $count_new = get_transient('aww_count_redirect_load')+1;

    $last_article = $this->load_data_start_by_article($article_end);

    $url_start = add_query_arg(array('action' => 'aww_load', 'article' => $last_article, 'count' => $count_new), admin_url( 'admin-ajax.php' ));

    if($count_new > 10000) return;
    $o = wp_remote_get($url_start);
    var_dump($o); exit;
  }

  function load_data_start_by_article($article_end = 0){

    $att_id = get_transient( 'woo_at_media_id' );
    $file = get_attached_file( $att_id );

    printf('<p>Work with file: %s</p>', $file);

    $this->reader = new XMLReader;
    $this->reader->open($file);

    if( ! empty($article_end)){
      //Промотка до нужного артикула
      $this->reader_ff($article_end);
    }

    $i = 0;
    while($this->reader->read()){


      if ($this->reader->nodeType == XMLReader::ELEMENT && $this->reader->name == 'offer'){
        $xml_offer = simplexml_load_string($this->reader->readOuterXML());
        $article = (string)$xml_offer->attributes()->{'id'};

        $i++;
        set_transient('aww_import_count_product', $i + get_transient('aww_import_count_product'), DAY_IN_SECONDS);

        $this->product_save_from_offer($xml_offer);

        if($i >= 3){
          $this->reader->close();
          return $article;
        }

      }
    }

    //Отметка времени о завершении работы
    add_option($name ='aww_end_load_timestamp', $value = date("Y-m-d H:i:s"), '', 'no');

    //По этому транзиту фоновый скрипт понимает что пора остановиться
    set_transient('aww_stop', 1, HOUR_IN_SECONDS);


    $this->reader->close();

  }

  //Функция промотки ридера
  function reader_ff($article_end){
    while($this->reader->read()){
      if ($this->reader->nodeType == XMLReader::ELEMENT && $this->reader->name == 'offer'){
        $xml_offer = simplexml_load_string($this->reader->readOuterXML());
        $article = (string)$xml_offer->attributes()->{'id'};
        if($article == $article_end){
          return true;
        }
      }
    }
    return false;
  }


  function stop(){
    set_transient('aww_stop', 1, HOUR_IN_SECONDS);
    wp_redirect(remove_query_arg( 'a', $this->url));
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
        $article = (string)$xml_offer->attributes()->{'id'};

        $i++;
        printf('<h2>%s. %s</h2>', $i, (string)$xml_offer->name);
        printf('<strong>Article: %s</strong>', $article);

        $this->product_save_from_offer($xml_offer);


      }

      if($i >= 33){
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
