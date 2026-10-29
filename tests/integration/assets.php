<?php
// Standalone regression checks for asset scope and confirmation markup. No live API calls.
define( 'ABSPATH', __DIR__ . '/' );
define( 'FINLINKS_WC_URL', 'https://shop.example/plugins/finlinks-stablecoin-payments/' );
define( 'FINLINKS_WC_VERSION', '1.1.2' );
class WC_Payment_Gateway {}
function __( $s, $domain = '' ) { return $s; }
function esc_html__( $s, $domain = '' ) { return htmlspecialchars( $s ); }
function esc_attr( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function absint( $s ) { return abs( (int) $s ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ); }
function wp_unslash( $s ) { return stripslashes( $s ); }
function current_user_can( $cap ) { return $GLOBALS['allowed']; }
function get_current_screen() { return (object) array( 'id' => $GLOBALS['screen'] ); }
function wp_enqueue_script( $handle, $url, $deps, $version, $footer ) { $GLOBALS['queued'][] = $handle; }
function is_order_received_page() { return $GLOBALS['received']; }
function get_query_var( $key ) { return 123; }
function wc_get_order( $id ) { return $GLOBALS['order']; }
class TestOrder {
 public $method = 'finlinks'; public $status = 'pending';
 function get_payment_method() { return $this->method; }
 function has_status( $status ) { return $this->status === $status; }
 function is_paid() { return $this->status === 'processing'; }
 function get_meta( $key ) { return ''; }
}
require dirname( __DIR__, 2 ) . '/includes/class-finlinks-gateway.php';
require dirname( __DIR__, 2 ) . '/includes/class-finlinks-admin.php';
$count = 0;
function check( $condition, $message ) {
 if ( ! $condition ) { throw new RuntimeException( $message ); }
 ++$GLOBALS['count'];
}
$allowed = true; $screen = 'woocommerce_page_wc-settings';
$_GET = array( 'tab' => 'checkout', 'section' => 'finlinks' );
$queued = array(); Finlinks_Admin::enqueue_scripts(); check( $queued === array('finlinks-admin'), 'Missing settings script' );
foreach ( array('dashboard', 'plugins', 'woocommerce_page_wc-orders') as $screen ) {
 $queued = array(); Finlinks_Admin::enqueue_scripts(); check( !$queued, 'Asset escaped settings scope' );
 ob_start(); Finlinks_Admin::clock_notice(); check( ob_get_clean() === '', 'Notice escaped settings scope' );
}
$screen = 'woocommerce_page_wc-settings'; $_GET['section'] = 'bacs'; $queued = array(); Finlinks_Admin::enqueue_scripts(); check( !$queued, 'Other gateway loaded script' );
$_GET['section'] = array('finlinks'); check( !Finlinks_Admin::is_settings_screen(), 'Array screen input accepted' );
$_GET['section'] = 'finlinks'; $allowed = false; $queued = array(); Finlinks_Admin::enqueue_scripts(); check( !$queued, 'Unprivileged asset loaded' );
$received = true; $order = new TestOrder(); $queued = array(); Finlinks_Gateway::enqueue_confirmation_script(); check( $queued === array('finlinks-confirmation'), 'Missing confirmation script' );
foreach ( array('bacs','finlinks') as $method ) {
 $order->method = $method; $order->status = 'processing'; $queued = array(); Finlinks_Gateway::enqueue_confirmation_script(); check( !$queued, 'Paid/unrelated order loaded script' );
}
$order->method = 'bacs'; $order->status = 'pending'; $queued = array(); Finlinks_Gateway::enqueue_confirmation_script(); check( !$queued, 'Unrelated pending order loaded script' );
$order->method = 'finlinks'; $received = false; $queued = array(); Finlinks_Gateway::enqueue_confirmation_script(); check( !$queued, 'Script loaded outside order return' );
$gateway = (new ReflectionClass('Finlinks_Gateway'))->newInstanceWithoutConstructor();
foreach (array(0 => 'scheduled', 11 => 'scheduled', 12 => 'stopped') as $wait => $expected) {
 $_GET = array('finlinks_wait' => $wait);
 ob_start(); $gateway->thankyou_page(123); $html = ob_get_clean();
 check( strpos($html, 'data-finlinks-refresh="'.$expected.'"') !== false, 'Refresh boundary incorrect' );
 check( strpos($html, 'data-finlinks-next="'.($wait+1).'"') !== false, 'Refresh count incorrect' );
 check( strpos($html, '<script') === false, 'Inline script returned' );
}
echo "PASS: $count PHP asset/notice/confirmation regression assertions\n";
