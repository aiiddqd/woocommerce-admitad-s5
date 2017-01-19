<?php
/*
Plugin Name: WooCommerce Admitad S5
Version: 0.3
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

          printf('<h2>%s</h2>', (string)$xml->name);

          printf('<p>id: %s</p>', $reader->getAttribute('id'));
          printf('<p>price: %s</p>', (string)$xml->price);
          printf('<p>url: %s</p>', (string)$xml->url);
          printf('<p>picture: %s</p>', (string)$xml->picture);

          var_dump($xml);
          echo '<hr>';
          $i++;

        }

        if($i > 11){
          break;
        }
      }

      $reader->close();
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
