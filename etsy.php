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
        $db = Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS '._DB_PREFIX_.'etsy_ps_product(
            id_etsy_ps_product int(11) not null auto_increment,
            id_ps_product int(11) not null,
            id_etsy_product int(11) not null,
            PRIMARY KEY (id_etsy_ps_product)
        ) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1');

        $db = Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS '._DB_PREFIX_.'etsy_ps_category(
            id_etsy_ps_category int(11) not null auto_increment,
            id_ps_category int(11) not null,
            id_etsy_category int(11) not null,
            PRIMARY KEY (id_etsy_ps_category)
        ) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1');


        return parent::install();// && $this->registerHook('leftColumn') && $this->registerHook('');
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
    //have a cron job that check if the product has been sold on etsy

    public function  getContent() {
//in config page, show list of product that are on etsy and color the raws of product that are not in prestashop
// do the same thing with product that are on prestashop but not on etsy
//when cliicking on the row, show the option to remove the product or to add the product
        $etsy = new EtsyAPI();
        $products = $etsy->getEtsyProductByListingId($etsy->getEtsyProduct());
        if ($etsyListingId = Tools::getValue('sync_product')) {
            $etsyProduct = $products[$etsyListingId];
            $parentCategoryPs = null;
            foreach ($etsyProduct->category_path_ids as $key => $id) {
                $cat = Db::getInstance()->getRow('SELECT * FROM '._DB_PREFIX_.'etsy_ps_category WHERE id_etsy_category = '.(int)$id);
                if (!$cat) {
                    //create category
                    $category = new Category();
                    $category->name[(int)Configuration::get('PS_LANG_DEFAULT')] = $etsyProduct->category_path[$key];
                    $category->link_rewrite[(int)Configuration::get('PS_LANG_DEFAULT')] = str_replace([' ', '/'], '_', strip_tags(html_entity_decode($etsyProduct->category_path[$key])));
                    if ($parentCategoryPs) {
                        $category->id_parent = $parentCategoryPs;
                    } else {
                        $category->id_parent = 2;
                    }
                    $category->add();
                    Db::getInstance()->insert('etsy_ps_category', [
                        'id_ps_category' => (int)$category->id,
                        'id_etsy_category' => (int)$id
                    ]);
                    $parentCategoryPs = Db::getInstance()->Insert_ID();
                } else {
                    $parentCategoryPs = $cat['id'];
                }
            }

            $newProduct = $this->addPSProduct($etsyProduct, $parentCategoryPs);

            Db::getInstance()->insert('etsy_ps_product', [
                'id_ps_product' => (int)$newProduct->id,
                'id_etsy_product' => (int)$etsyProduct->listing_id
            ]);
        }

        $html = '<h4>Etsy products:</h4><table class="table table-striped">
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Price</th>
                        <th>created at</th>
                        <th>Action</th>
                    </tr>';

        $psEtsyProducts = Db::getInstance()->executeS('SELECT * FROM '._DB_PREFIX_.'etsy_ps_product');

        $etsyProducts= [];
        foreach ($psEtsyProducts as $p) {
            $etsyProducts[$p['id_etsy_product']] = $p;
        }

        foreach ($products as $product) {
            $html .= '<tr>
                        <th>'.$product->listing_id.'</th>
                        <th>'.(strlen($product->title) > 100 ? substr($product->title, 0, 100).'...' : $product->title).'</th>
                        <th>'.$product->price.'</th>
                        <th>'.date('Y-m-d H:i:s', $product->creation_tsz).'</th>
                        <th>'.($etsyProducts[$product->listing_id] ? '' : '<a href="'.AdminController::$currentIndex.'&configure='.$this->name.'&sync_product='.$product->listing_id.'&token='.Tools::getAdminTokenLite('AdminModules').'">Add</a>').'<a>Remove</a></th>
                    </tr>';
        }
        return $html.'</table';
    }

    private function addPSProduct($etsyProduct, $parentCategoryPs) {
        $newProduct = new Product();
        d($etsyProduct);
        $newProduct->name[(int)Configuration::get('PS_LANG_DEFAULT')] = substr($etsyProduct->title, 0, 128);
        $newProduct->price = $etsyProduct->price;
        $newProduct->link_rewrite[(int)Configuration::get('PS_LANG_DEFAULT')] = $etsyProduct->listing_id;
        $newProduct->description[(int)Configuration::get('PS_LANG_DEFAULT')] = nl2br($etsyProduct->description);
        $newProduct->id_category_default = $parentCategoryPs;
        $newProduct->quantity = $etsyProduct->quantity;
        $newProduct->weight = $etsyProduct->item_weight;
        $newProduct->depth = $etsyProduct->length;
        $newProduct->width = $etsyProduct->width;
        $newProduct->height = $etsyProduct->height;


        $newProduct->add();
        Tag::addTags(Configuration::get('PS_LANG_DEFAULT'), $newProduct->id, $etsyProduct->tags);
        return $newProduct;
    }
}

class EtsyAPI {

    private $api_string = '0f9qw3ig8eis8gsh09cb9gzq';

    private function _curlMeThis($url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response_body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $result = json_decode($response_body);
        return $result->results;
    }

    public function getEtsyProduct($offset = 0,$limit = 1000) {
        $url = "https://openapi.etsy.com/v2/shops/ShopRachaels/listings/active?api_key=".$this->api_string.'&sort_on=created&sort_order=down&limit='.$limit.'&offset='.$offset;
        return $this->_curlMeThis($url);
    }

    public function getEtsyProductByListingId($products) {
        $p = [];
        foreach ($products as $product) {
            $p[$product->listing_id] = $product;
        }
        return $p;
    }
}