<?php

class Etsy extends Module {
    public function __construct()
    {
        $this->name = 'etsy';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Mathieu Bertholino';
        $this->need_instance = 1;
        $this->bootstrap = true;
 
        parent::__construct();
 
        $this->displayName = $this->l('Etsy');
        $this->description = $this->l('Sync up your product from etsy, or send your prestashop product to etsy.');
    }

    public function install()
    {
        return parent::install() && $this->registerHook('leftColumn') && $this->registerHook('');
    }

    public function uninstall()
    {
        if (!parent::uninstall())
            return false;
        return true;
    }


   //on the front office
   //when product page is displayed and the product has already been sold from etsy, update the quantity
//when product has been sold on prestashop, update the etsy product

    public function  getContent() {
//in config page, show list of product that are on etsy and color the raws of product that are not in prestashop
// do the same thing with product that are on prestashop but not on etsy
//when cliicking on the row, show the option to remove the product or to add the product
        $etsy = new EtsyAPI('0f9qw3ig8eis8gsh09cb9gzq');
        $products = $etsy->getEtsyProduct();
        $html = '<table class="table table-striped">
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Price</th>
                        <th>created at</th>
                    </tr>';
        foreach ($products as $product) {
            $html .= '<tr>
                        <th>'.$product->listing_id.'</th>
                        <th>'.(strlen($product->title) > 100 ? substr($product->title, 0, 100).'...' : $product->title).'</th>
                        <th>'.$product->price.'</th>
                        <th>'.date('Y-m-d H:i:s', $product->creation_tsz).'</th>
                    </tr>';
        }
        return $html.'</table';
    }
}

class EtsyAPI {

    private $api_string;

    public function __construct($api_string) {
        $this->api_string = $api_string;
    }

    public function getEtsyProduct() {
        $url = "https://openapi.etsy.com/v2/shops/ShopRachaels/listings/active?api_key=".$this->api_string.'&sort_on=created&sort_order=down&limit=0';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response_body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $result = json_decode($response_body);
        return $result->results;
    }
}