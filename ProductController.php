<?php

namespace app\controllers;

use Yii;
use app\models\Store;
use app\models\Product;
use app\models\Variant;
use app\models\Locations;
use app\models\VariantLocation;
use app\components\Shopify;

class ProductController extends \yii\web\Controller {

    /*
     * Removed CSRF validation
     */
    public function beforeAction($action) {

        $this->enableCsrfValidation = false;
        $session = Yii::$app->session;
        $session->open();
        $shop_name = $session->get('shop');
        if(empty($shop_name) && Yii::$app->controller->action->id !== 'reload') {

            $this->redirect(['product/reload', 'shop' => Yii::$app->request->get('shop')]);
            return false;
        }
        return parent::beforeAction($action);
    }

    /*
     * Import all the products from specific Store.
     */
    public Function actionImporter() {

        try {
            $session = Yii::$app->session;
            $session->open();

            if (!empty($session)) {
                $shop_name = $session->get('shop');
                $store = Store::find()->Where(['shop' => $session->get('shop')])->one();
                $shop_id = $store['id'];
                $shop = $store['shop'];
                $token = $store['token'];

                $sc = new Shopify($shop, $token, Yii::$app->params['APP_KEY'], Yii::$app->params['SECRET_KEY']);
                if ($store['installation_process'] == 1) {
                    $shopifyLocations = $sc->call('GET', '/admin/api/' . Yii::$app->params['API_VERSION'] . '/locations.json');
                    foreach ($shopifyLocations as $locData) {
                        $this->insertStoreLocation($shop, $locData);
                    }
                    $this->updateInstallationStep(2, $shop);
                    Yii::$app->consoleRunner->run("import/product {$session->get('shop')}");
                    return $this->redirect('importer');
                } else if ($store['installation_process'] === 2) {

                    return $this->render('importer', array('step' => $store['installation_process'], 'shop' => $shop_name));
                } else if ($store['installation_process'] === 3) {
                    return $this->redirect('list');
//                    $allproducts = Variant::find()->Where(['store_name' => $session->get('shop')])->count();
//                    $processedproducts = Variant::find()->Where(['store_name' => $session->get('shop')])->andWhere(['variant_processed' => 1])->count();
//                    return $this->render('stockupdate', array('shop' => $session->get('shop'), 'allVariant' => $allproducts, 'processedproducts' => $processedproducts));
//                    return $this->render('importer', array('process' => $store['installation_process'], 'shop' => $shop_name));
                } else {
                    return $this->redirect('list');
                }
            } else {
                $shopify_scope = Yii::$app->params['SHOPIFY_SCOPE'];
                $redirect_uri = $base_url . '/install';
                header("Location: " . $shopifyClient->getAuthorizeUrl($shopify_scope, $redirect_uri));
                exit;
            }
        } catch (Exception $ex) {
            throw new Exception("Error Processing Request", 1);
        }
    }

    /* 
     * To fetch the product data
     */
    public function actionListapi() {

        $session = Yii::$app->session;
        $session->open();

        if (!empty($session)) {
            $shop_name = $session->get('shop');
            //$shop_name = 'videoapptest.myshopify.com';
            $store = Store::find()->Where(['shop' => $session->get('shop')])->one();
            $shop = $store['shop'];
            $token = $store['token'];
            $shop_id = $store['id'];

            $sc = new Shopify($shop, $token, Yii::$app->params['APP_KEY'], Yii::$app->params['SECRET_KEY']);

            $last_record = Product::find()->select(['product_id'])->Where(['store_name' => $session->get('shop')])->orderBy(['product_id' => SORT_DESC])->one();
            $count_product = $sc->call('GET', '/admin/api/' . Yii::$app->params['API_VERSION'] . '/products/count.json');

            $page = "&page_info=";
            if (file_exists(dirname(__FILE__) . '/' . $shop . '.txt')) {
                $fileContents = file_get_contents(dirname(__FILE__) . '/' . $shop . '.txt', FALSE, NULL);
                $data = explode('&page_info=', $fileContents);
                $page .= $data[count($data) - 1];
            }

            if (!empty($last_record)) {
                $resp = $sc->callWithHeader('GET', '/admin/api/' . Yii::$app->params['API_VERSION'] . '/products.json?limit=99' . $page);
            } else {
                $resp = $sc->callWithHeader('GET', '/admin/api/' . Yii::$app->params['API_VERSION'] . '/products.json?limit=99');
            }
            $pd = json_decode($resp, true);
            $products_details = $pd['products'];
            $link = substr($pd['header'], (strpos($pd['header'], 'Link: <') + 7), (strpos($pd['header'], '>; rel="next"') - strpos($pd['header'], 'Link:') - 7));

            file_put_contents(dirname(__FILE__) . '/' . $shop . '.txt', $link);

            if (!empty($products_details)) {

                foreach ($products_details as $value) {
                    $product_exist = Product::find()->Where(['product_id' => $value['id']])->andWhere(['store_name' => $session->get('shop')])->one();

                    if (empty($product_exist)) {
                        $product = new Product();
                        $product->product_id = $value['id'];
                        $product->title = $value['title'];
                        if ($value['image']['src'] != null) {
                            $product->image_src = $value['image']['src'];
                        }
                        $product->vendor = $value['vendor'];
                        $product->product_type = $value['product_type'];
                        $product->store_id = $shop_id;
                        $product->store_name = $session->get('shop');

                        if ($product->save(false)) {
                            foreach ($value['variants'] as $var) {
                                $this->saveVariant($value['id'], $var['title'], $var['price'], $var['inventory_item_id'], $var['id'], $session->get('shop'));
                            }
                        }
                    }
                }
            }
            $all_record = Product::find()->Where(['store_name' => $session->get('shop')])->count();
            $result = array("validation" => "true", "dbProducts" => $all_record);
            $res = json_encode($result);
            echo $res;
            exit;
        } else {
            $shopify_scope = Yii::$app->params['SHOPIFY_SCOPE'];
            $redirect_uri = $base_url . '/install';
            header("Location: " . $shopifyClient->getAuthorizeUrl($shopify_scope, $redirect_uri));
            exit;
        }
    }

    /* 
     * To update the variant stock
     */
    public function actionGetstock() {
        $request = Yii::$app->request;
        $shop_name = $request->post('shop');

        $store = Store::find()->Where(['shop' => $shop_name])->one();
        $shop = $store['shop'];
        $token = $store['token'];
        $sc = new Shopify($shop, $token, Yii::$app->params['APP_KEY'], Yii::$app->params['SECRET_KEY']);

        $response = array('status' => "false", "msg" => "Something went wrong , Please reload");
        $variants = Variant::find()->Where(['store_name' => $shop_name])->andWhere(['variant_processed' => 0])->one();
        if (!empty($variants)) {
            $details = $sc->call('GET', '/admin/api/' . Yii::$app->params['API_VERSION'] . '/inventory_levels.json?inventory_item_ids=' . $variants['inventory_item_id'] . '&limit=250');
            $count = 0;
            foreach ($details as $detail) {
                if ($this->updateVariantLocation($detail['inventory_item_id'], $detail['location_id'], $shop_name, $detail['available'])) {
                    $count++;
                }
            }
            $this->saveVariant($variants['product_id'], $variants['title'], $variants['price'], $variants['inventory_item_id'], $variants['variants_id'], $variants['store_name'], 1);
            $response['msg'] = $count . " record is Processed";
            $response['status'] = true;
            $response['detail'] = $details;
        } else {
            $response['msg'] = "reload_all_stock";
            $response['status'] = true;
            $this->updateInstallationStep(4, $shop_name);
        }
        return json_encode($response);
    }

    /*
     * Render product listing page
     */
    public function actionList() {

        $session = Yii::$app->session;
        $session->open();
        $shop_name = $session->get('shop');
        $store = Store::find()->Where(['shop' => $shop_name])->one();
        $step = $store['installation_process'];
        
        $loctaion_list = Locations::find()->select(['location_id', 'location_name'])->Where(['store_name' => $session->get('shop')])->andWhere(['Status' => 1])->orderBy(['location_name' => SORT_ASC])->all();

        $scroll_id = isset($_GET['product_id']) ? $_GET['product_id'] : '';
        $limit = isset($_GET['limit']) ? $_GET['limit'] : 25;

        $product_type = isset($_GET['product_type']) ? $_GET['product_type'] : '';
        $vendor = isset($_GET['vendor']) ? $_GET['vendor'] : '';
        $location = isset($_GET['location']) ? $_GET['location'] : $loctaion_list[0]->location_id;
        $query = isset($_GET['query']) ? $_GET['query'] : '';
        $pn = (isset($_GET['page']) && (!empty($_GET['page']))) ? $_GET['page'] : 1;
        $availability = (isset($_GET['availability'])) ? $_GET['availability'] : '';
        $sort = isset($_GET['sort']) ? $_GET['sort'] : 'title asc';
        $msort = (strpos($sort, "title") == false) ? $sort.', title '.substr($sort, strpos($sort, " ")) : $sort;
//        echo $msort; die('he');

        //$productQuery = "Select * from product where product_id in (Select DISTINCT(product_id) from variant_location_map where location_id='" . $location . "') and store_name='" . $session->get('shop') . "'";
        $productQuery = "SELECT product.*, max(variant_location_map.vlm_stock) as stock, max(variant_location_map.vlm_level) as level, max(variant_location_map.to_order) as to_order FROM product"
                . " LEFT JOIN variant_location_map ON product.product_id = variant_location_map.product_id and variant_location_map.location_id='" . $location . "'"
                . " where product.product_id in (Select DISTINCT(product_id) from variant_location_map"
                . " where location_id='" . $location . "') and product.store_name='" . $session->get('shop') . "'";

        if (!empty($product_type)) {
            $productQuery .= ' and product_type="' . $product_type . '"';
        }
        if (!empty($vendor)) {
            $productQuery .= ' and vendor="' . $vendor . '"';
        }
        if (!empty($availability)) {
            $productQuery .= ' and availability="' . $availability . '"';
        }

        $offset = ($pn - 1) * $limit;

        if (!empty($query)) {
            $productQuery .= ' and title like "%' . $query . '%"';
        }
        $productQuery .= " GROUP BY product.id ORDER BY $msort";
        $total_records = count(Yii::$app->db->createCommand($productQuery)->queryAll());
        $productQuery .= " limit " . $offset . "," . $limit;
        $products_details = Yii::$app->db->createCommand($productQuery)->queryAll();

        /*$offset = ($pn - 1) * $limit;
        $products_details = Product::find()
            ->joinWith(['variants', 'variantLocations'])
            ->where(["product.store_name" => $shop_name])
            ->andWhere(["variant_location_map.location_id" => $location])
            ->orderBy('title')
            ->limit($limit)
            ->offset($offset)
            ->all();
        
        $total_records = Product::find()
            ->joinWith(['variants', 'variantLocations'])
            ->where(["product.store_name" => $shop_name])
            ->andWhere(["variant_location_map.location_id" => $location])
            ->count();*/
        
        
        $variant_details = array();
        $model = new Variant();
        foreach ($products_details as $key => $value) {
            $variant_details[] = Variant::find()->Where(['product_id' => $value['product_id']])->all();
            $vquery = "SELECT v.title,v.price,v.sku, vlm.* FROM `variant_location_map` vlm  inner join variant v on v.variants_id=vlm.variant_id  where vlm.product_id='" . $value['product_id'] . "' and location_id='" . $location . "' ORDER BY  v.title ASC, CAST(v.title AS SIGNED) ASC";
            $products_details[$key]['variants'] = Yii::$app->db->createCommand($vquery)->queryAll();
        }
        $vender_list = Product::find()->select(['vendor'])->Where(['store_name' => $session->get('shop')])->orderBy(['vendor' => SORT_ASC])->distinct()->all();
        $product_type_list = Product::find()->select(['product_type'])->Where(['store_name' => $session->get('shop')])->distinct()->all();
        //echo "<pre>"; print_r($loctaion_list); echo "</pre>";
        
        $params = [
            'productlist' => $products_details,
            'vender_list' => $vender_list,
            'product_type_list' => $product_type_list,
            'model' => $model,
            'count' => $total_records,
            'page_no' => $pn,
            'sel_product' => $product_type,
            'sel_vendor' => $vendor,
            'query' => $query,
            'loctaion_list' => $loctaion_list,
            'sel_location' => $location,
            'scroll_id' => $scroll_id,
            'step' => $step,
            "shop" => $shop_name,
            'availability' => $availability,
            "sort" => $sort,
            "limit" => $limit
        ];
        return $this->render('list', $params);
    }

    /*
     * Sync stock for specific Product
     */
    public function actionRefreshstock() {

        $session = Yii::$app->session;
        $session->open();
        $shop_name = $session->get('shop');

        $store = Store::find()->Where(['shop' => $shop_name])->one();
        $shop = $store['shop'];
        $token = $store['token'];
        $sc = new Shopify($shop, $token, Yii::$app->params['APP_KEY'], Yii::$app->params['SECRET_KEY']);
        $location_list = Locations::find()->select(['location_id', 'location_name'])->Where(['store_name' => $session->get('shop')])->andWhere(['Status' => 1])->orderBy(['location_name' => SORT_ASC])->all();
        $product_type = isset($_GET['product_type']) ? $_GET['product_type'] : '';
        $vendor = isset($_GET['vendor']) ? $_GET['vendor'] : '';
        $location = isset($_GET['location']) ? $_GET['location'] : $location_list[0]->location_id;
        $query = isset($_GET['query']) ? $_GET['query'] : '';
        $pn = isset($_GET['page']) ? $_GET['page'] : 1;
        $product_id = isset($_GET['product_id']) ? $_GET['product_id'] : '';
        $ordered = isset($_GET['ordered']) ? $_GET['ordered'] : '';
        $sort = isset($_GET['sort']) ? $_GET['sort'] : 'title asc';
        $availability = (isset($_GET['availability'])) ? $_GET['availability'] : '';

        if (!empty($product_id)) {
            $result_web = $sc->call('GET', '/admin/api/' . Yii::$app->params['API_VERSION'] . '/products/' . $product_id . '.json');

            /* Updating Product details */
            $product_exist = Product::find()->Where(['product_id' => $result_web['id']])->andWhere(['store_name' => $shop_name])->one();
            if (empty($product_exist)) {
                $product_exist = new Product();
                $product_exist->store_id = $store['id'];
            }
            $product_id = $result_web['id'];
            $product_exist->product_id = $result_web['id'];
            $product_exist->title = $result_web['title'];
            $product_exist->vendor = $result_web['vendor'];
            $product_exist->product_type = $result_web['product_type'];
            $product_exist->image_src = $result_web['image']['src'];
            $product_exist->store_name = $shop_name;
            $product_exist->save(false);

            $variant = $result_web['variants'];
            $varids = array();
            foreach ($variant as $value) {
                $varids[] = $value['id'];
                $this->saveVariant($value['product_id'], $value['title'], $value['price'], $value['inventory_item_id'], $value['id'], $shop_name, 1);
                $inventory_details = $sc->call('GET', '/admin/api/' . Yii::$app->params['API_VERSION'] . '/inventory_levels.json?inventory_item_ids=' . $value['inventory_item_id']);
                foreach ($inventory_details as $loc) {
                    $this->updateVariantLocation($value['inventory_item_id'], $loc['location_id'], $shop_name, $loc['available']);
                }
            }

            $varicount = Variant::find()->Where(['product_id' => $product_id])->count();

            if ($varicount > count($variant)) {
                $variants_db = Variant::find()->Where(['product_id' => $product_id])->all();
                foreach ($variants_db as $varidata) {
                    if (!in_array($varidata->variants_id, $varids)) {
                        Variant::deleteAll(['AND', 'variants_id = :variants_id', 'store_name = :store_name'], ['variants_id' => $varidata->variants_id, ':store_name' => $shop_name]);
                        VariantLocation::deleteAll(['AND', 'variant_id = :variant_id', 'store_name = :store_name'], [':variant_id' => $varidata->variants_id, ':store_name' => $shop_name]);
                    }
                }
            }
        }
        //return $this->redirect('productlist?page='.$pn.'&product_type='.$product_type.'&vendor='.$vendor.'&query='.$query.'&location='.$location.'&ordered='.$ordered);
        return $this->render('refreshstock', array('page' => $pn, 'product_type' => $product_type, 'vendor' => $vendor, 'query' => $query, 'location' => $location, 'ordered' => $ordered, 'product_id' => $product_id, 'availability' => $availability, 'sort' => $sort));
    }

    /*
     * Update level of product
     */
    public function actionUpdatelevel() {
        if (isset($_POST['variant_id'])) {
            $variant_id = $_POST['variant_id'];
            $location_id = $_POST['location_id'];
            $level = $_POST['level_value'];
            $command = Yii::$app->db->createCommand("UPDATE variant_location_map SET vlm_level = $level WHERE variant_id = $variant_id and location_id = $location_id");
            $command->execute();

            // find queeninventory
            $inventory = VariantLocation::find()->where(['variant_id' => $variant_id])->andWhere(['location_id' => $location_id])->one();
            $response['level_value'] = $level;
            $response['variant_id'] = $variant_id;
            $response['location_id'] = $location_id;
            $response['inventory'] = $inventory['vlm_stock'];
            $response['to_order'] = $inventory['to_order'];
            $response['status'] = true;
        } else {
            $response['status'] = false;
        }
        return json_encode($response);
    }

    /*
     * Update stock of variant
     */
    public function actionUpdatestock() {
        
        if (isset($_POST['variant_id'])) {
            
            $session = Yii::$app->session;
            $session->open();
            $shop_name = $session->get('shop');
            $store = Store::find()->Where(['shop' => $shop_name])->one();
            $shop = $store['shop'];
            $token = $store['token'];
            $variant_id = $_POST['variant_id'];
            $location_id = $_POST['location_id'];
            $inventory_item_id = $_POST['inventory_item_id'];
            $stock = $_POST['stock_value'];

            $sc = new Shopify($shop, $token, Yii::$app->params['APP_KEY'], Yii::$app->params['SECRET_KEY']);
            $body = [
                "location_id" => $location_id,
                "inventory_item_id" => $inventory_item_id,
                "available" => $stock
            ];
            $result_web = $sc->call('POST', '/admin/api/' . Yii::$app->params['API_VERSION'] . '/inventory_levels/set.json', $body);
            if(array_key_exists("errors", $result_web)) {
                $response['status'] = false;
            } else {

                $command = Yii::$app->db->createCommand("UPDATE variant_location_map SET vlm_stock = {$result_web['available']} WHERE variant_id = $variant_id and location_id = $location_id");
                $command->execute();

                // find queeninventory
                $inventory = VariantLocation::find()->where(['variant_id' => $variant_id])->andWhere(['location_id' => $location_id])->one();
                $response['stock_value'] = $result_web['available'];
                $response['variant_id'] = $variant_id;
                $response['location_id'] = $location_id;
                $response['level'] = $inventory['vlm_level'];
                $response['to_order'] = $inventory['to_order'];
                $response['status'] = true;
            }
        } else {
            $response['status'] = false;
        }
        return json_encode($response);
    }

    /*
     * Export data into CSV file
     */
    public function actionExport() {

        $session = Yii::$app->session;
        $session->open();
        $product_type = isset($_GET['product_type']) ? $_GET['product_type'] : '';
        $vendor = isset($_GET['vendor']) ? $_GET['vendor'] : '';
        $query = isset($_GET['query']) ? $_GET['query'] : '';
        $sel_loc = isset($_GET['sel_location']) ? $_GET['sel_location'] : '';
//        $productQuery = "Select * from product where store_name='" . $session->get('shop') . "'";
        $productQuery = "SELECT product.*, max(variant_location_map.vlm_stock) as stock, max(variant_location_map.vlm_level) as level, max(variant_location_map.to_order) as to_order FROM product"
                . " LEFT JOIN variant_location_map ON product.product_id = variant_location_map.product_id and variant_location_map.location_id='" . $sel_loc . "'"
                . " where product.product_id in (Select DISTINCT(product_id) from variant_location_map"
                . " where location_id='" . $sel_loc . "') and product.store_name='" . $session->get('shop') . "'";

        $sort = isset($_GET['sort']) ? $_GET['sort'] : 'title asc';
        $msort = (strpos($sort, "title") == false) ? $sort.', title '.substr($sort, strpos($sort, " ")) : $sort;
        $availability = (isset($_GET['availability'])) ? $_GET['availability'] : '';

        if (!empty($product_type)) {
            $productQuery .= ' and product_type="' . $product_type . '"';
        }
        if (!empty($vendor)) {
            $productQuery .= ' and vendor="' . $vendor . '"';
        }
        if (!empty($availability)) {
            $productQuery .= ' and availability="' . $availability . '"';
        }

        if (!empty($query)) {
            $productQuery .= " and title like '%" . $query . "%'";
        }
//        if (!empty($sel_loc)) {
//            $productQuery .= " and product_id in (select product_id from variant_location_map where location_id=" . $sel_loc . ")";
//        }
        $productQuery .= " GROUP BY product.id order by $msort";

        $products_details = Yii::$app->db->createCommand($productQuery)->queryAll();
        $locations = Locations::find()->select(['location_name'])->Where(['store_name' => $session->get('shop')])->andWhere(['Status' => 1, 'location_id' => $sel_loc])->all();
        $variant_details = array();

        foreach ($products_details as $key => $value) {
            $variant_details[] = Variant::find()->Where(['product_id' => $value['product_id']])->all();
            $vquery = "SELECT v.title,v.price,v.sku, vlm.* FROM `variant_location_map` vlm inner join variant v on v.variants_id=vlm.variant_id  where vlm.product_id='" . $value['product_id'] . "' and location_id=" . $sel_loc . " ORDER BY  v.title ASC, CAST(v.title AS SIGNED) ASC";
            $products_details[$key]['variants'] = Yii::$app->db->createCommand($vquery)->queryAll();
        }
        $delimiter = ",";
        $filename = "levelapp_" . $locations[0]['location_name'] . "_" . date('Y-m-d') . ".csv";
        $f = fopen('php://memory', 'w');
        $i = 0;

        $fields = array('Product ID', 'Title', 'SKU', 'Vendor', 'Product Type');
        foreach ($locations as $location) {
            $fields[] = $location['location_name'] . ' Level';
            $fields[] = $location['location_name'] . ' Stock';
            $fields[] = $location['location_name'] . ' To Order';
        }
        fputcsv($f, $fields, $delimiter);
        $lineData = array();
        foreach ($products_details as $value) {
            $lineData = array($i, $value['title'], '', $value['vendor'], $value['product_type']);
            fputcsv($f, $lineData, $delimiter);
            foreach ($value['variants'] as $variant_pr) {
                $order = $variant_pr['to_order'];//$variant_pr['vlm_level'] - $variant_pr['vlm_stock'];
                if ($order < 0) {
                    $order = 0;
                }
                if ($variant_pr['product_id'] == $value['product_id']) {
                    $lineData = array($variant_pr['variant_id'], $variant_pr['title'], $variant_pr['sku'], $value['vendor'], $value['product_type'], $variant_pr['vlm_level'], $variant_pr['vlm_stock'], $order);
                    fputcsv($f, $lineData, $delimiter);
                }
            }
            $i++;
        }
        fseek($f, 0);
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '";');
        fpassthru($f);
        exit;
    }

    /*
     * Redirect to apps when session empty.
     */
    public function actionReload($shop) {
        
        
        $session = Yii::$app->session;
        $session->open();
        if($shop) {
            $session->set('shop', $shop);
            return $this->redirect(Yii::$app->request->referrer);
        }
        
//        $shop = Yii::$app->request->get('shop');
//        echo "<pre>"; print_r($_REQUEST); die('end');
        return $this->render('reload', ['shop' => $shop]);
    }

    /*
     * Create/Update variant
     */
    private function saveVariant($id, $title, $price, $inventory_item_id, $variantid, $shop, $processed = 0) {

        $variant = Variant::find()->Where(['variants_id' => $variantid])->one();
        if (empty($variant)) {
            $variant = new Variant();
        }
        $variant->product_id = $id;
        $variant->title = $title;
        $variant->price = $price;
        $variant->inventory_item_id = $inventory_item_id;
        $variant->variants_id = $variantid;
        $variant->store_name = $shop;
        $variant->variant_processed = $processed;
        return $variant->save(false);
    }

    /*
     * Insert all store locations in app database specific to store
     */
    private function insertStoreLocation($shop, $locData) {

        return Yii::$app->db->createCommand()->insert('locations', [
                    'store_name' => $shop,
                    'location_id' => $locData['id'],
                    'location_name' => $locData['name'],
                ])->execute();
    }

    /*
     * Update Varinat location in app database specific to store
     */
    private function updateVariantLocation($inventory_item_id, $location_id, $shop, $stock) {

        $variant = VariantLocation::find()->Where(['inventory_item_id' => $inventory_item_id])->andWhere(['location_id' => $location_id, 'store_name' => $shop])->one();
        if (!empty($variant)) {
            if (!is_null($stock)) {
                $variant->vlm_stock = $stock;
                $variant->updated_at = date('Y-m-d H:i:s');
                return $variant->save(false);
            } else {
                return $variant->delete();
            }
        } else if (!is_null($stock)) {

            $variant = Variant::find()->Where(['inventory_item_id' => $inventory_item_id])->one();
            $variantLocation = VariantLocation::find()->Where(['inventory_item_id' => $inventory_item_id])->andWhere(['location_id' => $location_id, 'store_name' => $shop])->one();
            if (empty($variantLocation)) {
                $variantLocation = new VariantLocation();
                $variantLocation->vlm_level = 100;
            }
            $variantLocation->vlm_stock = $stock;
            $variantLocation->inventory_item_id = $inventory_item_id;
            $variantLocation->location_id = $location_id;
            $variantLocation->store_name = $shop;
            $variantLocation->variant_id = $variant->variants_id;
            $variantLocation->product_id = $variant->product_id;
            $variantLocation->updated_at = date('Y-m-d H:i:s');
            return $variantLocation->save(false);
        } else {
            return true;
        }
    }

    private function updateInstallationStep($nextstep, $shop) {
        return Yii::$app->db->createCommand()->update('store', ['installation_process' => $nextstep], 'shop = "' . $shop . '"')->execute();
    }
}