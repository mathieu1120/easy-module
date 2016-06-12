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
//when cliicking on the row, show the option to remove the product or to add the product
        $etsy = new EtsyAPI();
        $products = $etsy->getEtsyProductByListingId($etsy->getEtsyProduct());
	$html = '';
        if ($etsyListingId = Tools::getValue('sync_product')) {
	           $psEtsyProductId = Db::getInstance()->getValue('SELECT id_etsy_ps_product FROM '._DB_PREFIX_.'etsy_ps_product where id_etsy_product = '.(int)$etsyListingId);
		   if ($psEtsyProductId) {
		      d('product already sync');
		   }
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
                    $parentCategoryPs = $cat['id_ps_category'];
                }
            }

            $newProduct = $this->addPSProduct($etsyProduct, $parentCategoryPs);

            Db::getInstance()->insert('etsy_ps_product', [
                'id_ps_product' => (int)$newProduct->id,
                'id_etsy_product' => (int)$etsyProduct->listing_id
            ]);

            $images = $etsy->getEtsyProductImages($etsyProduct->listing_id);
            foreach ($images as $image) {
                $path = $this->addToFiles($image->listing_image_id, $image->{'url_fullxfull'}, $image->full_width, $image->full_height);
		$image = new Image();
		$image->id_product = (int)$newProduct->id;
		$image->position = Image::getHighestPosition($newProduct->id) + 1;
if (!Image::getCover($image->id_product)) {
                $image->cover = 1;
            	} else {
                $image->cover = 0;
            	}
		if (!$image->add()) {
		   d('error adding image');
		} else {
		   if (!$new_path = $image->getPathForCreation()) {
                    d('An error occurred during new folder creation');
                                 }

                $error = 0;
                if (!ImageManager::resize($path, $new_path.'.'.$image->image_format, null, null, 'jpg', false, $error)) {
                    switch ($error) {
                        case ImageManager::ERROR_FILE_NOT_EXIST :
                            d('An error occurred while copying image, the file does not exist anymore.');
                            break;

                        case ImageManager::ERROR_FILE_WIDTH :
                            d('An error occurred while copying image, the file width is 0px.');
                            break;

                        case ImageManager::ERROR_MEMORY_LIMIT :
                            d('An error occurred while copying image, check your memory limit.');
                            break;

                        default:
                            d('An error occurred while copying image.');
                            break;
                    }
                    continue;
                } else {
                    $imagesTypes = ImageType::getImagesTypes('products');
                    $generate_hight_dpi_images = (bool)Configuration::get('PS_HIGHT_DPI');

                    foreach ($imagesTypes as $imageType) {
                        if (!ImageManager::resize($path, $new_path.'-'.stripslashes($imageType['name']).'.'.$image->image_format, $imageType['width'], $imageType['height'], $image->image_format)) {
                            d('An error occurred while copying image:').' '.stripslashes($imageType['name']);
                            continue;
                        }

                        if ($generate_hight_dpi_images) {
                            if (!ImageManager::resize($path, $new_path.'-'.stripslashes($imageType['name']).'2x.'.$image->image_format, (int)$imageType['width']*2, (int)$imageType['height']*2, $image->image_format)) {
                                d('An error occurred while copying image:').' '.stripslashes($imageType['name']);
                                continue;
                            }
                        }
                    }
                }

                unlink($path);
                 if (!$image->update()) {
                    d('Error while updating status');
                    continue;
                }
		}
	    }
	    $html .= 'Product added<br/>';
        } else if ($etsyListingId = Tools::getValue('unsync_product')) {
	        $psEtsyProduct = Db::getInstance()->getRow('SELECT * FROM '._DB_PREFIX_.'etsy_ps_product where id_etsy_product = '.(int)$etsyListingId);
		if (!$psEtsyProduct) {
		   d('cannot unsynv a product that is not sync');
		}
		Db::getInstance()->delete(_DB_PREFIX_.'etsy_ps_product', 'id_etsy_product = '.(int)$psEtsyProduct['id_etsy_product']);
		$psProduct = new Product((int)$psEtsyProduct['id_ps_product']);
		$psProduct->delete();
	}

        $html .= '<h4>Etsy products:</h4><table class="table table-striped">
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
                        <th>'.(isset($etsyProducts[$product->listing_id]) ? '<a href="'.AdminController::$currentIndex.'&configure='.$this->name.'&unsync_product='.$product->listing_id.'&token='.Tools::getAdminTokenLite('AdminModules').'">Remove</a>' : '<a href="'.AdminController::$currentIndex.'&configure='.$this->name.'&sync_product='.$product->listing_id.'&token='.Tools::getAdminTokenLite('AdminModules').'">Add</a>').'</th>
                    </tr>';
        }
        return $html.'</table';
    }

    private function addPSProduct($etsyProduct, $parentCategoryPs) {
        $newProduct = new Product();
        $newProduct->name[(int)Configuration::get('PS_LANG_DEFAULT')] = substr(str_replace(['&', ';', '#'], '', $etsyProduct->title), 0, 128);
        $newProduct->price = $etsyProduct->price;
        $newProduct->link_rewrite[(int)Configuration::get('PS_LANG_DEFAULT')] = $etsyProduct->listing_id;
        $newProduct->description[(int)Configuration::get('PS_LANG_DEFAULT')] = nl2br($etsyProduct->description);
        $newProduct->id_category_default = $parentCategoryPs;
        $newProduct->quantity = $etsyProduct->quantity;

        $newProduct->weight = $etsyProduct->item_weight;
        $newProduct->depth = $etsyProduct->item_length;
        $newProduct->width = $etsyProduct->item_width;
        $newProduct->height = $etsyProduct->item_height;

        $newProduct->add();
        Tag::addTags(Configuration::get('PS_LANG_DEFAULT'), $newProduct->id, $etsyProduct->tags);
        StockAvailable::setQuantity($newProduct->id, null, $etsyProduct->quantity);
        return $newProduct;
    }

    private function addToFiles($key, $url, $width, $height)
    {
        $tempName = tempnam(_PS_TMP_IMG_DIR_, 'php_files_'.$key);
        $originalName = basename(parse_url($url, PHP_URL_PATH));
	$imgRawData = file_get_contents($url);    
        file_put_contents($tempName, $imgRawData);

	$salt = sha1(microtime());
        $pathinfo = pathinfo($originalName);
        $img_name = $salt . '_' . Tools::str2url($pathinfo['filename']) . '.' . $pathinfo['extension'];
        if (ImageManager::resize($tempName, dirname(__FILE__) . '/img/' . $img_name, $width, $height)) {
             $res = true;
        }

        if ($res) {
            return dirname(__FILE__) . '/img/' . $img_name;
        }
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

    public function getEtsyProductImages($listingId) {
        $url = "https://openapi.etsy.com/v2/listings/".$listingId."/images?api_key=".$this->api_string;
        return $this->_curlMeThis($url);
    }
}