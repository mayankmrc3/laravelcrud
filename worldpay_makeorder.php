$token    = $request->input( 'token' );
        $total    = 50;
        $key      = config('worldpay.sandbox.service');
        $worldPay = new \Alvee\WorldPay\lib\Worldpay($key);

        $billing_address = array
        (
            'address1'    => 'Address 1 here',
            'address2'    => 'Address 2 here',
            'address3'    => 'Address 3 here',
            'postalCode'  => 'postal code here',
            'city'        => 'city here',
            'state'       => 'state here',
            'countryCode' => 'GB',
        );

        try 
        {
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
            if ($response['paymentStatus'] === 'SUCCESS') 
            {
                $worldpayOrderCode = $response['orderCode'];
                
               /*echo "<pre>";
               print_r($response);*/
               return redirect('/')->with('order_success', ['your message,here']); ;
            } 
            else 
            {
                // The card has been declined
                throw new \Alvee\WorldPay\lib\WorldpayException(print_r($response, true));
            }
        } 
        catch (\Alvee\WorldPay\lib\WorldpayException $e) 
        {
            echo 'Error code: ' . $e->getCustomCode() . '
                  HTTP status code:' . $e->getHttpStatusCode() . '
                  Error description: ' . $e->getDescription() . '
                  Error message: ' . $e->getMessage();

            // The card has been declined
        } 
        catch (\Exception $e) 
        {
            // The card has been declined
            echo 'Error message: ' . $e->getMessage();
        }

        //$worldpay = new Alvee\WorldPay\lib\Worldpay(env("WORLDPAY_KEY"));

        // Sometimes your SSL doesnt validate locally
        // DONT USE IN PRODUCTION
        $worldpay->disableSSLCheck(true);

        $directOrder = isset($data['direct-order']) ? $data['direct-order'] : false;
        $token = (isset($data['token'])) ? $data['token'] : null;
        $name = $data['name'];
        $shopperEmailAddress = $data['shopper-email'];

        $amount = 0;
        if (isset($data['amount']) && !empty($data['amount'])) {
            $amount = is_numeric($data['amount']) ? $data['amount']*100 : -1;
        }

        $orderType = $data['order-type'];

        $_3ds = (isset($data['3ds'])) ? $data['3ds'] : false;
        $authorizeOnly = (isset($data['authorizeOnly'])) ? $data['authorizeOnly'] : false;
        $customerIdentifiers = (!empty($data['customer-identifiers'])) ? json_decode($data['customer-identifiers']) : array();

        include('header.php');

        // Try catch
        try {
            // Customers billing address
            $billing_address = array(
                "address1"=> $data['address1'],
                "address2"=> $data['address2'],
                "address3"=> $data['address3'],
                "postalCode"=> $data['postcode'],
                "city"=> $data['city'],
                "state"=> $data['state'],
                "countryCode"=> $data['countryCode'],
                "telephoneNumber"=> $data['telephoneNumber']
            );

            // Customers delivery address
            $delivery_address = array(
                "firstName" => $data['delivery-firstName'],
                "lastName" => $data['delivery-lastName'],
                "address1"=> $data['delivery-address1'],
                "address2"=> $data['delivery-address2'],
                "address3"=> $data['delivery-address3'],
                "postalCode"=> $data['delivery-postcode'],
                "city"=> $data['delivery-city'],
                "state"=> $data['delivery-state'],
                "countryCode"=> $data['delivery-countryCode'],
                "telephoneNumber"=> $data['delivery-telephoneNumber']
            );

            if ($orderType == 'APM') {

                $obj = array(
                    'orderDescription' => $data['description'], // Order description of your choice
                    'amount' => $amount, // Amount in pence
                    'currencyCode' => $data['currency'], // Currency code
                    'settlementCurrency' => $data['settlement-currency'], // Settlement currency code
                    'name' => $name, // Customer name
                    'shopperEmailAddress' => $shopperEmailAddress, // Shopper email address
                    'billingAddress' => $billing_address, // Billing address array
                    'deliveryAddress' => $delivery_address, // Delivery address array
                    'customerIdentifiers' => (!is_null($customerIdentifiers)) ? $customerIdentifiers : array(), // Custom indentifiers
                    'statementNarrative' => $data['statement-narrative'],
                    'orderCodePrefix' => $data['code-prefix'],
                    'orderCodeSuffix' => $data['code-suffix'],
                    'customerOrderCode' => $data['customer-order-code'], // Order code of your choice
                    'successUrl' => $data['success-url'], //Success redirect url for APM
                    'pendingUrl' => $data['pending-url'], //Pending redirect url for APM
                    'failureUrl' => $data['failure-url'], //Failure redirect url for APM
                    'cancelUrl' => $data['cancel-url'] //Cancel redirect url for APM
                );

                if ($directOrder) {
                    $obj['directOrder'] = true;
                    $obj['shopperLanguageCode'] = isset($data['language-code']) ? $data['language-code'] : "";
                    $obj['reusable'] = (isset($data['chkReusable']) && $data['chkReusable'] == 'on') ? true : false;

                    $apmFields = array();
                    if (isset($data['swiftCode'])) {
                        $apmFields['swiftCode'] = $data['swiftCode'];
                    }

                    if (isset($data['shopperBankCode'])) {
                        $apmFields['shopperBankCode'] = $data['shopperBankCode'];
                    }

                    if (empty($apmFields)) {
                        $apmFields =  new stdClass();
                    }

                    $obj['paymentMethod'] = array(
                          "apmName" => $data['apm-name'],
                          "shopperCountryCode" => $data['countryCode'],
                          "apmFields" => $apmFields
                    );
                }
                else {
                    $obj['token'] = $token; // The token from WorldpayJS
                }

                $response = $worldpay->createApmOrder($obj);

                if ($response['paymentStatus'] === 'PRE_AUTHORIZED') {
                    // Redirect to URL
                    $_SESSION['orderCode'] = $response['orderCode'];
                    ?>
                    <script>
                        window.location.replace("<?php echo $response['redirectURL'] ?>");
                    </script>
                    <?php
                } 
                else 
                {
                    // Something went wrong
                    echo '<p id="payment-status">' . $response['paymentStatus'] . '</p>';
                    throw new WorldpayException(print_r($response, true));
                }

            }
            else 
            {

                $obj = array(
                    'orderDescription' => $data['description'], // Order description of your choice
                    'amount' => $amount, // Amount in pence
                    'is3DSOrder' => $_3ds, // 3DS
                    'authorizeOnly' => $authorizeOnly,
                    'siteCode' => $data['site-code'],
                    'orderType' => $data['order-type'], //Order Type: ECOM/MOTO/RECURRING
                    'currencyCode' => $data['currency'], // Currency code
                    'settlementCurrency' => $data['settlement-currency'], // Settlement currency code
                    'name' => ($_3ds && true) ? '3D' : $name, // Customer name
                    'shopperEmailAddress' => $shopperEmailAddress, // Shopper email address
                    'billingAddress' => $billing_address, // Billing address array
                    'deliveryAddress' => $delivery_address, // Delivery address array
                    'customerIdentifiers' => (!is_null($customerIdentifiers)) ? $customerIdentifiers : array(), // Custom indentifiers
                    'statementNarrative' => $data['statement-narrative'],
                    'orderCodePrefix' => $data['code-prefix'],
                    'orderCodeSuffix' => $data['code-suffix'],
                    'customerOrderCode' => $data['customer-order-code'] // Order code of your choice
                );

                if ($directOrder) {
                    $obj['directOrder'] = true;
                    $obj['shopperLanguageCode'] = isset($data['language-code']) ? $data['language-code'] : "";
                    $obj['reusable'] = (isset($data['chkReusable']) && $data['chkReusable'] == 'on') ? true : false;
                    $obj['paymentMethod'] = array(
                          "name" => $data['name'],
                          "expiryMonth" => $data['expiration-month'],
                          "expiryYear" => $data['expiration-year'],
                          "cardNumber"=>$data['card'],
                          "cvc"=>$data['cvc']
                    );
                }
                else {
                    $obj['token'] = $token; // The token from WorldpayJS
                }

                $response = $worldpay->createOrder($obj);

                if ($response['paymentStatus'] === 'SUCCESS' ||  $response['paymentStatus'] === 'AUTHORIZED') 
                {
                    // Create order was successful!
                    $worldpayOrderCode = $response['orderCode'];
                    echo '<p>Order Code: <span id="order-code">' . $worldpayOrderCode . '</span></p>';
                    echo '<p>Token: <span id="token">' . $response['token'] . '</span></p>';
                    echo '<p>Payment Status: <span id="payment-status">' . $response['paymentStatus'] . '</span></p>';
                    echo '<pre>' . print_r($response, true). '</pre>';
                    // TODO: Store the order code somewhere..
                } 
                elseif ($response['is3DSOrder']) 
                {
                    // Redirect to URL
                    // STORE order code in session
                    $_SESSION['orderCode'] = $response['orderCode'];
                    ?>
                    <form id="submitForm" method="post" action="<?php echo $response['redirectURL'] ?>">
                        <input type="hidden" name="PaReq" value="<?php echo $response['oneTime3DsToken']; ?>"/>
                        <input type="hidden" id="termUrl" name="TermUrl" value="http://localhost/3ds_redirect.php"/>
                        <script>
                            document.getElementById('termUrl').value = window.location.href.replace('create_order.php', '3ds_redirect.php');
                            document.getElementById('submitForm').submit();
                        </script>
                    </form>
                    <?php
                } 
                else 
                {
                    // Something went wrong
                    echo '<p id="payment-status">' . $response['paymentStatus'] . '</p>';
                    throw new WorldpayException(print_r($response, true));
                }
            }
        } 
        catch (WorldpayException $e) 
        { // PHP 5.3+
            // Worldpay has thrown an exception
            echo 'Error code: ' . $e->getCustomCode() . '<br/>
            HTTP status code:' . $e->getHttpStatusCode() . '<br/>
            Error description: ' . $e->getDescription()  . ' <br/>
            Error message: ' . $e->getMessage();
        }