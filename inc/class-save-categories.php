<?php

/**
 * Save categories for products
 */
class AWW_Save_Categorie{

  public $categories = array();

  function __construct() {
    add_action('aww_product_update', [$this, 'save_category'], 10, 2);
  }

  // Main function 
  function save_category($product_id, $xml_offer){

    $xml_cat_id = (int)$xml_offer->categoryId;

    if(empty($this->categories)){
      $this->categories = $this->get_categories_from_xml();
    }

    echo '<pre>';
    var_dump($this->categories[$xml_cat_id]);
    var_dump($xml_cat_id);
    echo '</pre>';

    $term_id = $this->get_term_id_by_xml_id($xml_cat_id);

    if(empty($term_id)){
      $term_id = $this->add_term($xml_cat_id);
    }

    wp_set_object_terms( $product_id, $term_id, 'product_cat' );

  }

  //Get term_id by id from xml file
  function get_term_id_by_xml_id($xml_cat_id){

    $args = array(
      'taxonomy' => 'product_cat',
      'meta_key' => 'aww_id',
      'meta_value' => $xml_cat_id
    );
    $check_terms = get_terms( $args );

    if(empty($check_terms)){
      return false;
    } else {
      $term_id = $check_terms[0]->term_id;
    }

  }

  //Add term recursive by id from xml file
  //Also save xml_id as metafield 'aww_id' for future check
  function add_term($xml_cat_id){
    $data_category = $this->categories[$xml_cat_id];
    $name = $data_category['name'];
    $xml_cat_parent_id = $data_category['parent_id'];

    //Если родителя нет, то просто добавляем термин
    if(empty($xml_cat_parent_id)){
      $args = array(
        'cat_name'    => $name,
        'taxonomy' => 'product_cat'
      );
      $term_id = wp_insert_category( $args  );
      update_term_meta( $term_id, 'aww_id', $xml_cat_id );
      return $term_id;
    }

    $term_parent_id = $this->get_term_id_by_xml_id($xml_cat_parent_id);
    if(empty($term_parent_id)){
      $term_parent_id = $this->add_term($xml_cat_parent_id);
    }

    $args = array(
      'cat_name'    => $name,
      'category_parent' => $term_parent_id,
      'taxonomy' => 'product_cat'
    );

    $term_id = wp_insert_category( $args  );
    update_term_meta( $term_id, 'aww_id', $xml_cat_id );
    return $term_id;

  }

  //Get categories data and save in $this->categories as array
  // Also save data as cache set_transient('aww_categories', $data, DAY_IN_SECONDS);
  function get_categories_from_xml(){

    $att_id = get_transient( 'woo_at_media_id' );
    $file = get_attached_file( $att_id );
    if(empty($file)){
      delete_transient( 'woo_at_media_id');
      printf('<p>File not found in base: %s. Cache clear. Reload page.</p>', $att_id);
      return false;
    }

    $data = get_transient('aww_categories');

    if( ! empty($data) ){
      return $data;
    } else {
      $reader = new XMLReader;
      $reader->open($file);

      $i = 0;
      $data = array();
      while($reader->read()) {
        $i++;
        if($i>10000) break;

        if($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'category'){
          $data[$reader->getAttribute('id')] = array(
            'name' => $reader->readString(),
            'parent_id' => $reader->getAttribute('parentId')
          );
        }
      }
      $reader->close();

      set_transient('aww_categories', $data, DAY_IN_SECONDS);

      return $data;
    }
  }
}
new AWW_Save_Categorie;
