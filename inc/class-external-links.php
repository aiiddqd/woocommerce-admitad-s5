<?php

/**
 * External links extansion
 */
class AWW_External_Links {

  function __construct(){

    add_action('init', array($this, 'wpcee_add_rewrite_endpoint'));

    add_filter('woocommerce_product_add_to_cart_url', array($this, 'wcpee_change_url'), 10, 2);

    add_action( 'template_redirect', array($this, 'wcpee_redirect_url'));

    //Добавляем метабокс, который показывает число переходов
    add_action('add_meta_boxes', function(){
      add_meta_box( 'wcpee_count', 'Переходы по ссылке', array($this, 'wcpee_count_metabox_cb'), 'product', 'side' );
    });

    add_action('template_redirect', array($this, 'wcpee_remove_ext_url'));

    add_action( 'woocommerce_external_add_to_cart', array($this, 'wcpee_url_blank'), 30 );

    add_action( 'wp_loaded', array( $this, 'flush_rules') );

    add_filter('woocommerce_loop_add_to_cart_link', array($this, 'replace_buy_url_in_loop'), 10, 2);

  }

  /*
  * Подмена ссылки на странице списка продуктов
  * use hook apply_filters( 'woocommerce_loop_add_to_cart_link', $url, $product);
  */
  function replace_buy_url_in_loop($url, $product){
    return sprintf( '<a target="_blank" rel="nofollow" href="%s" data-quantity="%s" data-product_id="%s" data-product_sku="%s" class="%s">%s</a>',
		  esc_url( $product->add_to_cart_url() ),
		  esc_attr( isset( $quantity ) ? $quantity : 1 ),
		  esc_attr( $product->get_id() ),
		  esc_attr( $product->get_sku() ),
		  esc_attr( isset( $class ) ? $class : 'button' ),
		  esc_html( $product->add_to_cart_text() )
	  );
  }

  /*
  * Проверка наличия правила адресации и если нет то сброс
  */
  function flush_rules(){
      $rules = get_option( 'rewrite_rules' );

      if ( ! isset( $rules['product/([^/]+)/gurl(/(.*))?/?$'] ) ) {
        // var_dump($rules); exit;
        flush_rewrite_rules( false );
      }
  }

  /*
  * Убираем хук добавления типовой кнопки Купить чтобы затем заменить на свою
  */
  function wcpee_remove_ext_url(){
    if(is_singular('product'))
      remove_action( 'woocommerce_external_add_to_cart', 'woocommerce_external_add_to_cart', 30 );
  }

  /*
  * Выводим число переходов в метабоксе продукта
  */
  function wcpee_count_metabox_cb(){
    $post = get_post();
    echo "Число переходов по ссылке продукта: " . get_post_meta($post->ID, 'wcpee_count', true);
  }

  /*
  * Добавляем Эндпоинт для маскировки ссылок
  */
  function wpcee_add_rewrite_endpoint() {
    add_rewrite_endpoint( $name = 'gurl', EP_PERMALINK );
  }


  //Делаем ссылку с атрибутом target=_blank
  function wcpee_url_blank(){
      global $product;
      if ( ! $product->add_to_cart_url() ) {
          return;
      }
      $product_url = $product->add_to_cart_url();
      $button_text = $product->single_add_to_cart_text();
      do_action( 'woocommerce_before_add_to_cart_button' ); ?>
      <p class="cart">
          <a href="<?php echo esc_url( $product_url ); ?>" target="_blank" rel="nofollow" class="single_add_to_cart_button button alt">
            <?php echo esc_html( $button_text ); ?>
          </a>
      </p>
      <?php do_action( 'woocommerce_after_add_to_cart_button' );
  }

  /*
  * Добавляем окончание для маскировки ссылки
  */
  function wcpee_change_url($url, $product){
    if(! empty($url)){
      $url = get_permalink($product->id) . 'gurl/';
    }
    return $url;
  }

  /*
  * Редирект при переходе по замаскированной ссылке
  * Также двигает счетчик переходов
  */
  function wcpee_redirect_url(){

    if( ! is_singular( $post_types = 'product' ))
      return;

    $check = get_query_var('gurl', false);

    if('' !== $check)
      return;

    $product = wc_get_product();

    $url = $product->get_product_url();
    $url = wp_specialchars_decode($url);

    //Уточняем число переходов в метаполе если адрес есть и будет переход
    if( ! empty($url) ){

      $count = get_post_meta($product->id, 'wcpee_count', true);
      if( ! empty($count) ) {
        update_post_meta($product->id, 'wcpee_count', (int)$count +1);
      } else {
        update_post_meta($product->id, 'wcpee_count', 1);
      }

      wp_redirect($url, 301);
      exit;

    } else {

      wp_redirect(home_url(), 302);
      exit;

    }
  }
}
new AWW_External_Links;
