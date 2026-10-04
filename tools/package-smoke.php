<?php
declare(strict_types=1);

// Standalone test: load only the installed ZIP's autoloader, never the checkout's vendor.
$wordpress=getenv('WMOS_WORDPRESS_PATH');
$packaged=getenv('WMOS_PACKAGED_PATH');
if(!$wordpress || !$packaged || !str_starts_with($wordpress,'/private/tmp/wmos-package-')) { throw new RuntimeException('An isolated package test directory is required.'); }
define('WP_USE_THEMES',false);
$_SERVER['HTTP_HOST']='127.0.0.1:8089'; $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['SERVER_PROTOCOL']='HTTP/1.1';
require $wordpress . '/wp-load.php';
$checks=0;
function package_check(bool $condition,string $message): void { global $checks; ++$checks; if(!$condition) { throw new RuntimeException($message); } }
$reflection=new ReflectionClass(Wmos\Platform\Plugin::class);
package_check(str_starts_with((string)$reflection->getFileName(),$packaged.'/src/'),'Runtime must load from the installed ZIP.');
package_check(!is_dir($packaged.'/vendor/phpunit'),'Development autoloader packages must be absent.');
wp_set_current_user((int)get_user_by('login','wmos_test_admin')->ID);
$health=rest_do_request(new WP_REST_Request('GET','/wmos/v1/health'));
package_check($health->get_status()===200,'Installed REST health must succeed.');
package_check($health->get_data()['active'] && $health->get_data()['encryption_configured'],'Installed runtime and external key must be ready.');
package_check($health->get_data()['compatibility']['hpos'] && $health->get_data()['compatibility']['blocks'],'Qualified compatibility evidence must ship.');
wp_set_current_user(0);
wc_load_cart();
$product=new WC_Product_Simple(); $product->set_name('Packaged checkout fixture'); $product->set_status('publish'); $product->set_regular_price('12.34'); $product->set_virtual(true); $product->set_tax_status('none'); $product->save();
function package_request(string $path,array $body,?string $cartToken=null): WP_REST_Response {
    $request=new WP_REST_Request('POST','/wc/store/v1/'.$path); $request->set_header('Nonce',wp_create_nonce('wc_store_api'));
    if($cartToken) { $request->set_header('Cart-Token',$cartToken); }
    $request->set_header('Content-Type','application/json'); $request->set_body(wp_json_encode($body));
    return rest_do_request($request);
}
$cart=package_request('cart/add-item',['id'=>$product->get_id(),'quantity'=>1]); package_check($cart->get_status()===201,'Installed Store API cart must succeed.');
$email='package-'.bin2hex(random_bytes(5)).'@example.test';
$address=['first_name'=>'Package','last_name'=>'Fixture','address_1'=>'100 Test Street','city'=>'Los Angeles','state'=>'CA','postcode'=>'90001','country'=>'US','email'=>$email,'phone'=>''];
$checkout=package_request('checkout',['billing_address'=>$address,'shipping_address'=>array_diff_key($address,['email'=>true,'phone'=>true]),'payment_method'=>'cod','payment_data'=>[],'additional_fields'=>['wmos/marketing_email'=>true]],$cart->get_headers()['Cart-Token'] ?? null);
package_check($checkout->get_status()===200,'Installed Store API checkout must succeed.');
$order=wc_get_order($checkout->get_data()['order_id']);
package_check($order instanceof WC_Order && ($order->get_meta('_wmos_email_choice',true)['selected'] ?? false),'Native Blocks checkbox must save the affirmative choice.');
do_action('woocommerce_checkout_order_processed',$order->get_id(),[],$order);
$plugin=Wmos\Platform\Plugin::instance();
for($i=0;$i<4;++$i) { $plugin->services()['queue']->tick(); }
$profile=$plugin->services()['contacts']->findByEmail($email);
package_check($profile!==null,'Affirmative checkout must queue a confirmation contact.');
package_check(!$plugin->services()['consent']->allowed($profile['uuid'],'marketing','email'),'Checkout must not grant marketing permission.');
package_check(count($plugin->database()->list('consent_tokens',['profile_id'=>$profile['id']]))===1,'Classic/Blocks replay must not duplicate the challenge.');
$count=$plugin->database()->db()->get_var($plugin->database()->db()->prepare('SELECT COUNT(*) FROM '.$plugin->database()->table('events').' WHERE name=%s AND object_id=%s','checkout.accepted',(string)$order->get_id()));
package_check((int)$count===1,'Classic/Blocks replay must retain one accepted checkout fact.');
$order->update_status('on-hold','Isolated fixture awaiting cash collection.');
$order->payment_complete();
package_check($order->get_date_paid()!==null,'The test payment must record actual paid evidence.');
for($i=0;$i<4;++$i) { $plugin->services()['queue']->tick(); }
$conversion=$plugin->database()->find('conversions','order_id',$order->get_id());
package_check($conversion!==null && (int)$conversion['net_minor']===1234,'Installed order reconciliation must preserve exact minor units.');
$before=$plugin->database()->get('profiles',$profile['uuid']);
Wmos\Platform\Lifecycle::deactivate();
package_check(!(bool)get_option('wmos_active') && $before===$plugin->database()->get('profiles',$profile['uuid']),'Deactivation must preserve history and stop effects.');
echo json_encode(['passed'=>true,'version'=>WMOS_VERSION,'php'=>PHP_VERSION,'wordpress'=>$GLOBALS['wp_version'],'woocommerce'=>WC_VERSION,'checks'=>$checks,'production_autoload'=>true,'blocks_affirmative_optin'=>true,'consent_not_inferred'=>true,'idempotent_checkout'=>true,'order_minor'=>1234,'deactivation_preserves_history'=>true],JSON_THROW_ON_ERROR)."\n";
