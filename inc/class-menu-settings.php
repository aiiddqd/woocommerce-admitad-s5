<?php

class woo_admitad_settings {
  function __construct(){

    add_action('admin_menu', function () {
        add_options_page(
          $page_title = 'Admitad',
          $menu_title = "Admitad",
          $capability = 'manage_options',
          $menu_slug = 'admitad-settings',
          $function = array($this, 'settings_display')
        );
    });

    add_action( 'admin_init', array($this, 'settings_init'), $priority = 10, $accepted_args = 1 );
  }

  function settings_init(){

    add_settings_section(
    	'admitad_section_main',
    	'Основные',
    	null,
    	'admitad-settings'
    );

    add_settings_field(
      $id = 'admitad_url',
      $title = 'URL',
      $callback = [$this, 'admitad_url_display'],
      $page = 'admitad-settings',
      $section = 'admitad_section_main'
    );


    add_settings_field(
      $id = 'admitad_debug',
      $title = 'Режим отладки',
      $callback = [$this, 'admitad_debug_display'],
      $page = 'admitad-settings',
      $section = 'admitad_section_main'
    );

    register_setting('admitad-settings', 'admitad_url');
    register_setting('admitad-settings', 'admitad_debug');

  }


  function admitad_debug_display(){
    $f = 'admitad_debug';
    printf('<input type="checkbox" name="%s" value="1" %s />', $f, checked( 1, get_option($f), false ));
  }

  function admitad_url_display(){
    $f = 'admitad_url';
    printf('<p><small>Вставьте ссылку на XML фид из <a href="%s" target="_blank">Admitad</a></small></p>', 'https://www.admitad.com/ru/webmaster/');
    printf('<input type="text" name="%s" value="%s"/ size="50">', $f, get_option($f));
  }

  function settings_display(){
    ?>

    <form method="POST" action="options.php">
      <h1>Настройки Admitad</h1>
      <?php
        settings_fields( 'admitad-settings' );
        do_settings_sections( 'admitad-settings' );
        submit_button();
      ?>
    </form>

    <?php
  }



}
new woo_admitad_settings;
