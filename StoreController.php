<?php

namespace app\controllers;

use Yii;
use app\models\Store;
use yii\web\Controller;
use app\components\Shopify;

/**
 * StoreController implements the CRUD actions for Store model.
 */
class StoreController extends Controller {
    /*
     * Removed CSRF validation
     */

    public function beforeAction($action) {
        $this->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    /**
     * Install application on new Store.
     * @return redirect
     */
    public function actionInstall() {

        $shopify_shop = Yii::$app->getRequest()->getQueryParam('shop');
        $code = Yii::$app->getRequest()->getQueryParam('code');

        $app_key = Yii::$app->params['APP_KEY'];
        $app_secret = Yii::$app->params['SECRET_KEY'];
        $app_installed = Store::find()->Where(['shop' => $shopify_shop])->one();
        $shopify_token = '';
        if (!empty($app_installed)) {

            $shopify_shop = $app_installed->shop;
            $shopify_token = $app_installed->token;
            $session = Yii::$app->session;
            $session->open();
            $session->set('shop', $shopify_shop);

            if ($app_installed->installation_process > 4) {
                return $this->redirect(['product/list']);
            } else {
                return $this->redirect(['product/importer']);
            }
        } else {

            $shopifyClient = new Shopify($shopify_shop, "", $app_key, $app_secret);
            $shopify_token = $shopifyClient->getAccessToken($code);

            if (empty($shopify_token)) {
                /* installing app on the non existant sore */
                $shopify_scope = Yii::$app->params['SHOPIFY_SCOPE'];
                $redirect_uri = Yii::$app->params['REDIRECT_URI'];
                header("Location: " . $shopifyClient->getAuthorizeUrl($shopify_scope, $redirect_uri));
                exit;
            } else {

                $this->saveStoreSecrets($shopify_shop, $shopify_token, $code, $app_key, $app_secret);
                header('Location:' . 'https://' . $shopify_shop . '/admin/apps/level-dev-3');
                exit;
            }
        }
    }

    /*
     * Save data in Store table
     * @return mixed
     */

    private function saveStoreSecrets($shopify_shop, $shopify_token, $code, $app_key, $app_secret) {

        try {
            $store = new Store();
            $store->shop = $shopify_shop;
            $store->token = $shopify_token;
            $store->code = $code;
            $store->status = 1;
            $store->installation_process = 1;
            $sc = new Shopify($shopify_shop, $shopify_token, $app_key, $app_secret);
            $this->ShopifyHooks($sc, $shopify_shop);
            return $store->save(false);
        } catch (Exception $ex) {
            $this->exceptionLog($ex->getMessage(), 'saveStoreSecrets');
        }
    }

    /*
     * Create Shopify webhooks
     */

   public function ShopifyHooks($shopifyClient, $shopify_shop) {

        $debugContent = "\n[date:" . date("Y-m-d H:i:s A") . "]======Initiate hooks for $shopify_shop=======\n";
        $base_url = Yii::$app->params['BASE_URL'];
        $hooks = array();
        try {
            $hooks_tag = $shopifyClient->call('GET', '/admin/api/' . Yii::$app->params['API_VERSION'] . '/webhooks.json');
            $debugContent .= "\n Hook list:" . json_encode($hooks_tag);
            foreach ($hooks_tag as $exist_hooks) {
                if (isset($exist_hooks['topic']))
                    $hooks[] = $exist_hooks['topic'];
            }
            /* start installing hooks */
            if (!in_array("app/uninstalled", $hooks)) {
                $uninstall_hook = array(
                    "webhook" => array(
                        "topic" => "app/uninstalled",
                        "address" => $base_url . "/webhook/uninstall?shop=" . $shopify_shop . "",
                        "format" => "json"
                    )
                );
                $hooks_tag = $shopifyClient->call('POST', '/admin/api/' . Yii::$app->params['API_VERSION'] . '/webhooks.json', $uninstall_hook);
                $debugContent .= "\n uninstalled Hook set";
            }
            if (!in_array("products/create", $hooks)) {
                $product_create_hook = array(
                    "webhook" => array(
                        "topic" => "products/create",
                        "address" => $base_url . "/webhook/product-create?shop=" . $shopify_shop . "",
                        "format" => "json"
                    )
                );
                $create_tag = $shopifyClient->call('POST', '/admin/api/' . Yii::$app->params['API_VERSION'] . '/webhooks.json', $product_create_hook);
                $debugContent .= "\n products/create Hook set";
            }
            if (!in_array("products/update", $hooks)) {
                $product_update_hook = array(
                    "webhook" => array(
                        "topic" => "products/update",
                        "address" => $base_url . "/webhook/product-update?shop=" . $shopify_shop . "",
                        "format" => "json"
                    )
                );
                $create_tag = $shopifyClient->call('POST', '/admin/api/' . Yii::$app->params['API_VERSION'] . '/webhooks.json', $product_update_hook);

                $debugContent .= "\n products/update Hook set";
            }
            if (!in_array("products/delete", $hooks)) {
                $product_delete_hook = array(
                    "webhook" => array(
                        "topic" => "products/delete",
                        "address" => $base_url . "/webhook/product-delete?shop=" . $shopify_shop . "",
                        "format" => "json"
                    )
                );
                $delete_tag = $shopifyClient->call('POST', '/admin/api/' . Yii::$app->params['API_VERSION'] . '/webhooks.json', $product_delete_hook);
                $debugContent .= "\n products/delete Hook set";
            }

            if (!in_array("locations/create", $hooks)) {
                $location_create_hook = array(
                    "webhook" => array(
                        "topic" => "locations/create",
                        "address" => $base_url . "/webhook/locations-create?shop=" . $shopify_shop . "",
                        "format" => "json"
                    )
                );
                $create_tag = $shopifyClient->call('POST', '/admin/api/' . Yii::$app->params['API_VERSION'] . '/webhooks.json', $location_create_hook);
                $debugContent .= "\n locations/create Hook set";
            }

            if (!in_array("locations/update", $hooks)) {
                $location_update_hook = array(
                    "webhook" => array(
                        "topic" => "locations/update",
                        "address" => $base_url . "/webhook/locations-update?shop=" . $shopify_shop . "",
                        "format" => "json"
                    )
                );
                $update_tag = $shopifyClient->call('POST', '/admin/api/' . Yii::$app->params['API_VERSION'] . '/webhooks.json', $location_update_hook);
                $debugContent .= "\n locations/update Hook set";
            }

            if (!in_array("locations/delete", $hooks)) {
                $location_delete_hook = array(
                    "webhook" => array(
                        "topic" => "locations/delete",
                        "address" => $base_url . "/webhook/locations-delete?shop=" . $shopify_shop . "",
                        "format" => "json"
                    )
                );
                $delete_tag = $shopifyClient->call('POST', '/admin/api/' . Yii::$app->params['API_VERSION'] . '/webhooks.json', $location_delete_hook);
                $debugContent .= "\n locations/delete Hook set";
            }
            
            if (!in_array("inventory_levels/update", $hooks)) {
                $inventory_levels_hook = array(
                    "webhook" => array(
                        "topic" => "inventory_levels/update",
                        "address" => $base_url . "/webhook/inventory-levels-update?shop=" . $shopify_shop . "",
                        "format" => "json"
                    )
                );
                $update_tag = $shopifyClient->call('POST', '/admin/api/' . Yii::$app->params['API_VERSION'] . '/webhooks.json', $inventory_levels_hook);
                $debugContent .= "\n inventory_levels/update Hook set";
            }

            /* if (!in_array("inventory_items/update", $hooks)) {
              $product_create_hook = array(
              "webhook" => array(
              "topic" => "inventory/update",
              "address" => $base_url . "/webhook/inventory?shop=" . $shopify_shop . "",
              "format" => "json"));
              $create_tag = $shopifyClient->call('POST', '/admin/api/' . Yii::$app->params['API_VERSION'] . '/webhooks.json', $product_create_hook);
              $debugContent .= "\n inventory_items/update Hook set";
              } */ 
            $this->fileDebug('hooks.txt', 'a', $debugContent);
        } catch (Exception $exc) {
            $this->exceptionLog($exc->getMessage(), 'ShopifyHooks');
        }
    }

    /* Get current processing step for syncing */

    public function actionGetStep() {

        $request = Yii::$app->request;
        $shop_name = $request->post('shop');
        $store = Store::find()->Where(['shop' => $shop_name])->one();

        $response = array('status' => false, "msg" => "Invalid request.", 'listing' => false, 'step' => 0);
        if ($store !== null) {

            $step = $store['installation_process'];
            $message = $step < 5 ? "Products syncing is in-progress." : "";
            $listing = $step === 5 ? true : false;
            $response = array('status' => "true", "msg" => $message, 'listing' => $listing, 'step' => $step);
        }
        return json_encode($response);
    }

    /*
     * Create logs according to the debug mode
     */

    private function fileDebug($filename, $mode, $content) {
        if (Yii::$app->params['debug']) {
            $logfile = fopen($filename, $mode) or die("Unable to open " . $filename . "!");
            fwrite($logfile, $content);
            fclose($logfile);
        }
    }

    private function exceptionLog($message, $filename) {
        $content = "\n[date: " . date('Y-m-d H:i:s') . "|| " . $filename . " ]: " . $message;
        $logfile = fopen('exception.log', 'a') or die("Unable to open " . $filename . "!");
        fwrite($logfile, $content);
        fclose($logfile);
    } 



}
