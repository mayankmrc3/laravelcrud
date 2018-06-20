<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/welcome', function () {
    return view('welcome');
});

Route::get('/',['as'=> "index","uses" => "SignupController@index"]);
Route::get('/makeorder',['as'=> "makeorder","uses" => "SignupController@makeorder"]);
Route::post('/checkout',['as'=> "checkouts","uses" => "SignupController@checkout"]);
Route::get('/signup',['as'=> "signup","uses" => "SignupController@create"]);
Route::post('/signup',['as'=> "signup","uses" => "SignupController@store"]);
Route::get('/edit/{id}',['as'=> "edit","uses" => "SignupController@edit"]);
Route::post('/destroy/{id}',['as'=> "destroy","uses" => "SignupController@destroy"]);
Route::post('/update/{id}',['as'=> "update","uses" => "SignupController@update"]);

//payment form
Route::get('/paypalpay', 'PaymentController@index');
// route for processing payment
Route::post('paypal', 'PaymentController@payWithpaypal');
// route for check status of the payment
Route::get('status', 'PaymentController@getPaymentStatus');

Route::get('/worldpay', function () {
    return view('vendor/alvee/worldpay');
});
/*Route::post('/checkout', function (\Illuminate\Http\Request $request) {
$token    = $request->input( 'token' );
    $total    = 50;
    $key      = config('worldpay.sandbox.service');
    $worldPay = new Alvee\WorldPay\lib\Worldpay($key);

    $billing_address = array(
        'address1'    => 'Address 1 here',
        'address2'    => 'Address 2 here',
        'address3'    => 'Address 3 here',
        'postalCode'  => 'postal code here',
        'city'        => 'city here',
        'state'       => 'state here',
        'countryCode' => 'GB',
    );

    try {
        $response = $worldPay->createOrder(array(
            'token'             => $token,
            'directOrder'       => true,
            'amount'            => (int)($total . "00"),
            'currencyCode'      => 'GBP',
            'name'              => "Name on Card",
            'billingAddress'    => $billing_address,
            'orderDescription'  => 'Order description',
            'customerOrderCode' => 'Order code'
        ));
        if ($response['paymentStatus'] === 'SUCCESS') {
            $worldpayOrderCode = $response['orderCode'];
            
           echo "<pre>";
           print_r($response);
           return view('welcome');
        } else {
            // The card has been declined
            throw new \Alvee\WorldPay\lib\WorldpayException(print_r($response, true));
        }
    } catch (Alvee\WorldPay\lib\WorldpayException $e) {
        echo 'Error code: ' . $e->getCustomCode() . '
              HTTP status code:' . $e->getHttpStatusCode() . '
              Error description: ' . $e->getDescription() . '
              Error message: ' . $e->getMessage();

        // The card has been declined
    } catch (\Exception $e) {
        // The card has been declined
        echo 'Error message: ' . $e->getMessage();
    }
});*/