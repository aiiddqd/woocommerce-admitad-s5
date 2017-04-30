<?php

/**
 * Data save by hook do_action('aww_product_update', $product_id, $xml_offer);
 */
class AWW_Data_Saver  {

  function __construct() {
    add_action('aww_product_update', array($this, 'save_general_data_product'), 10, 2);
    add_action('aww_product_update', array($this, 'save_price'), 10, 2);
    add_action('aww_product_update', array($this, 'save_description'), 10, 2);
  }

  function save_description($product_id, $xml_offer){

        $product = wc_get_product($product_id);

        if( (string)$xml_offer->description != (string)$product->get_description() ){

          wp_update_post(array(
            'ID' => $product_id,
            'post_content' => (string)$xml_offer->description
          ));

          printf('<p>+ Changed content, id: %s</p>', $product_id);
        } else {
          echo '<p>- Content ok</p>';
        }



  }

  // Save price
  function save_price($product_id, $xml_offer){

    $product = wc_get_product($product_id);
    //Price Retail 'salePrices'
    $price = (string)$xml_offer->price;

    if( isset($price) ){
      $price_source = floatval($price);

      if($price_source != $product->get_price()){

        update_post_meta( $product_id, '_regular_price', $price_source );
        update_post_meta( $product_id, '_price', $price_source );

        printf('<p>+ Update product price: %s</p>', $price_source);
      } else {
        printf('<p>- Price ok: %s</p>', $price_source);
      }
    }

  }

  //General data
  function save_general_data_product($product_id, $xml_offer){
    update_post_meta( $product_id, '_visibility', 'visible' );
    update_post_meta( $product_id, '_stock_status', 'instock');


    wp_set_object_terms( $product_id, 'external', 'product_type' );

    $url = (string)$xml_offer->url;

    if(update_post_meta( $product_id, '_product_url', $url)){
      printf('<p>Save url as: %s</p>', $url);
    }
    update_post_meta( $product_id, '_button_text', "Купить");

    //Save xml for debug
    update_post_meta( $product_id, 'xml_admitad', print_r($xml_offer, true));

    echo '<p>- Update general data</p>';
  }
}
new AWW_Data_Saver;
