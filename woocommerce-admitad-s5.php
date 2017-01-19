<?php
/*
Plugin Name: WooCommerce Admitad S5
Version: 0.1
Plugin URI: ${TM_PLUGIN_BASE}
Description: Connect Admitad CPA network for WooCommerce catalog
Author: AY
Author URI: ${TM_HOMEPAGE}
*/

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


    add_filter( 'upload_mimes', array($this, 'additional_mime_types') );

  }


  function additional_mime_types( $mimes ) {
    	$mimes['xml'] = 'text/xml';
    	$mimes['tmp'] = 'text/tmp';

    	return $mimes;
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

      //Timb https://www.admitad.com/ru/webmaster/websites/583212/offers/15006/#products
      $url = 'http://export.admitad.com/ru/webmaster/websites/583212/products/export_adv_products/?user=yumashev&code=5d6968c590&feed_id=15074&format=xml';
      $file_id = $this->save_file_by_url($url);

      var_dump($file_id); exit;

      $this->work($file_id);


    }


    function work($file_id){

      var_dump($file_id); exit;

      $file = 'get_path_file from $file_id';

      $reader = new XMLReader;
      $xml = $reader->open($file);

      $i = 0;
      while($xml->read()){

        var_dump($xml);
        echo '<hr>';

        if($i > 11){
          break;
        }
      }
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
        		'type'     => 'text/xml',
        		'tmp_name' => $temp_file,
        		'error'    => 0,
        		'size'     => filesize($temp_file),
        	);

          $overrides = array(
        		// скажем WP не искать поля формы, которые обычно должны быть. По умолчанию true
        		// Мы загружаем файл с удаленного сервера, поэтому полей формы у нас нет...
        		'test_form' => false,

        		// если установить true, то WP будет пропускать пустые файлы. Не рекомендуется.
        		'test_size' => true,

        		// Правильно загруженный файл пройдет эту проверку.
        		// Поэтому не нужно изменять этот параметр.
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
