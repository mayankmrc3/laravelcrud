<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\validate;
use Illuminate\Http\Request;
use App\Services\Signupservice;
use App\Http\Controllers\Alvee\WorldPay\lib\Worldpay;
use DB;
use Illuminate\Support\Facades\File;

use QuickBooksOnline\API\Data\IPPPaymentMethod;
use QuickBooksOnline\API\Data\IPPTerm;
use QuickBooksOnline\API\DataService\DataService as Ds;
use QuickBooksOnline\API\Facades\Customer;
use QuickBooksOnline\API\Facades\Invoice;
use QuickBooksOnline\API\Facades\Item;
use QuickBooksOnline\API\Facades\Account;
use QuickBooksOnline\API\Facades\Vendor;
use QuickBooksOnline\API\Facades\RefundReceipt;
use Carbon\Carbon;

use QuickBooksOnline\API\Facades\Line;
use Illuminate\Support\Facades\Log;
use QuickBooksOnline\API\Data\IPPCustomField;

use QuickBooksOnline\Payments\PaymentClient;
use QuickBooksOnline\Payments\Modules\Card;
use QuickBooksOnline\Payments\Operations\ChargeOperations;
use QuickBooksOnline\Payments\Operations\CardOperations;
use QuickBooksOnline\Payments\OAuth\{DiscoverySandboxURLs, DiscoveryURLs, OAuth2Authenticator, OAuth1Encrypter};
use QuickBooksOnline\Payments\HttpClients\Request\{RequestInterface, IntuitRequest, RequestFactory};


use QuickBooksOnline\Payments\HttpClients\core\{HttpClientInterface, HttpCurlClient};
class SignupController extends Controller
{
    private $taskservice;

    public function __construct()
    {
        $this->taskservice = new Signupservice();
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        //dd($request->all());
        $members = User::sorted()->latest()->paginate(5);

        if(isset($request['code']))
        {
            /*DB::table("qb_profile")->insert([
                'realmId'=>$request->realmId,
                'scope'=>$request->scope,
                'code'=>$request->code
            ]);*/
            
            $state = $request->state;
            $code = $request->code;
            $realmId = $request->realmId;

            $created_on = Carbon::now()->toDateTimeString();

            $auth_token = $this->generateTokenOnAuthorization($code, $realmId);
            
            $token_json1 = json_encode(
                array_merge($auth_token, ['created_on' => $created_on])
            );

            $token_json = json_decode($token_json1);

            //if($request->realmId != '4620816365040396740')
            {
                DB::table("qb_integration")->insert([
                    'type'=>$token_json->token_type,
                    'access_token'=>$token_json->access_token,
                    'refresh_token'=>$token_json->refresh_token,
                    'metadata'=>$token_json1,
                    'created_on'=>$created_on,
                    'token_type'=>$token_json->token_type,
                    'expires_in'=>$token_json->expires_in,
                    'x_refresh_token_expires_in'=>$token_json->x_refresh_token_expires_in,
                    'realmId'=>$request->realmId,
                    'scope'=>$request->scope,
                    'code'=>$request->code
                ]);
            }
            
           // $this->check_and_create_customer($token_json,$token_json);
            //dd("here");
            return view('signup',compact('members'))->with('i', (request()->input('page', 1) - 1) * 5);
        }
        return view('signup',compact('members'))->with('i', (request()->input('page', 1) - 1) * 5);
    } 
    
    public function makeorder()
    {
        return view('create-order');
    } 
   
    public function checkout(Request $request)
    {
        $data = $request->all();

        $get_qb_data = DB::table('qb_integration')->first();
        $this->check_and_create_creditcard($get_qb_data,$request);
        dd("card created successfully");

    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('signup');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        /*request()->validate([
            'name' => 'required',
            'email' => 'required',
        ]);*/
        
        $data= $request->all();

        //$message = $this->taskservice->insertsave($data);

        
        $get_qb_data = DB::table('qb_integration')->first();
       // dd($get_qb_data);

        $this->check_and_create_customer($get_qb_data,$request);


        return redirect()->route('index')->with('success','Member created successfully');
        echo json_encode($message);      
       
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        
        $user = User::where('id', $id)
                        ->first();
        $members = User::latest()->paginate(5);
        return view('signup',compact('members','user','id'))->with('i', (request()->input('page', 1) - 1) * 5);
        
        //return view('edit',compact('member'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $user = User::find($id);
        $user->name = $request->get('mname');
        $user->email = $request->get('email');
        //$user->password = $request->get('password');
        $user->save();
        //return("inserted successfully");
        return redirect()->route('index')->with('success','User updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $user = User::find($id);
        $user->delete();
        return redirect()->route('index')->with('success','User deleted successfully');
    }

    public function insertsave($data)
    {
        throw new \Exception('Method not implemented');
    }

    public static function ds($token_json)
    {
        try {
            $created_on = Carbon::now()->toDateTimeString();


            $ds = Ds::Configure([
                            'auth_mode' => 'oauth2',
                            'ClientID' => config('quickbooks.client_id'),
                            'ClientSecret' => config('quickbooks.client_secret'),
                            'accessTokenKey' => $token_json->access_token,
                            'refreshTokenKey' => $token_json->refresh_token,
                            'QBORealmID' => $token_json->realmId,
                            'baseUrl' => config('quickbooks.baseUrl')
                        ]);

                $tcreated_on = Carbon::createFromFormat('Y-m-d H:i:s', $token_json->created_on)
                    ->addSeconds($token_json->expires_in);

                if ($tcreated_on->lt(Carbon::now()))
                {
                    $OAuth2LoginHelper = $ds->getOAuth2LoginHelper();

                    $accessToken = $OAuth2LoginHelper->refreshAccessTokenWithRefreshToken($token_json->refresh_token);

                    $error = $OAuth2LoginHelper->getLastError();

                   
                    $accessTokenValue = $accessToken->getAccessToken();
                $refreshTokenValue = $accessToken->getRefreshToken();
                $access_token_expiry_time = $accessToken->getAccessTokenExpiresAt();
                $refresh_token_expiry_time = $accessToken->getRefreshTokenExpiresAt();

               // dd($refresh_token_expiry_time);
                    $accessTokenExpiresAta = Carbon::parse($access_token_expiry_time)->format('Y-m-d H:i:s');
                    $refresh_token_expiry_timea = Carbon::parse($refresh_token_expiry_time)->format('Y-m-d H:i:s');

                    DB::table("qb_integration")->where('id',1)->update([
                    //'type'=>$accessToken->tokenType,
                    'access_token'=>$accessTokenValue,
                    'refresh_token'=>$refreshTokenValue,
                    'metadata'=>json_encode($accessToken),
                    
                    //'token_type'=>$accessToken->token_type,
                    'expires_in'=>$accessTokenExpiresAta,
                    'x_refresh_token_expires_in'=>$refresh_token_expiry_timea,
                    
                ]);
                }
         

            return $ds;
        } catch (\Exception $ex) {
            //LogsHelper::LogErrorInfo('QUICKBOOKS', 'DS exception', $ex->getMessage());
            throw $ex;
        }
    }

    public static function generateTokenOnAuthorization($code, $realmId)
    {
        try {
            $dataService = Ds::Configure([
                'auth_mode' => 'oauth2',
                'ClientID' => config('quickbooks.client_id'),
                'ClientSecret' => config('quickbooks.client_secret'),
                'RedirectURI' => config('quickbooks.redirect_uri'),
                'baseUrl' => "development",
                'scope' => "com.intuit.quickbooks.payment"
            ]);

            $OAuth2LoginHelper = $dataService->getOAuth2LoginHelper();

            $accessToken = $OAuth2LoginHelper->exchangeAuthorizationCodeForToken($code, $realmId);
            $accessTokenValue = $accessToken->getAccessToken();
            $refreshTokenValue = $accessToken->getRefreshToken();
            //dd($accessToken);

            /*$authorizationCodeUrl = $OAuth2LoginHelper->getAuthorizationCodeURL();
            //dd($authorizationCodeUrl);
            return redirect($authorizationCodeUrl);
            $accessTokenObj = $OAuth2LoginHelper->exchangeAuthorizationCodeForToken($code, $realmId);*/
            //dd($accessTokenObj);
            $dataService1 = Ds::Configure(array(
                'auth_mode'       => 'oauth2',
                'ClientID'        => config('quickbooks.client_id'),
                'ClientSecret'    => config('quickbooks.client_secret'),
                'accessTokenKey'  => $accessTokenValue,
                'refreshTokenKey' => $refreshTokenValue,
                'QBORealmID'      => $realmId,
                'scope' => "com.intuit.quickbooks.accounting openid profile email phone address",
                'baseUrl'         => "development"
              ));
             // $dataService->setLogLocation("/Users/hlu2/Desktop/newFolderForLog");
              
             // $dataService->throwExceptionOnError(true);
              //Add a new Invoice
              $theResourceObj = Invoice::create([
                   "Line" => [
                        [
                            "Amount" => 100.00,
                            "DetailType" => "SalesItemLineDetail",
                            "SalesItemLineDetail" => 
                            [
                                "ItemRef" => 
                                [
                                    "value" => 20,
                                    "name" => "Hours"
                                ]
                            ]
                        ]
                    ],
                    "CustomerRef"=> 
                    [
                        "value"=> 58,
                        "name"=>"Maan John"
                    ]
              ]);
              //dd($theResourceObj);
              $resultingObj = $dataService1->Add($theResourceObj);
              
              
              $error = $dataService1->getLastError();
              if ($error) {
                  echo "The Status code is: " . $error->getHttpStatusCode() . "\n";
                  echo "The Helper message is: " . $error->getOAuthHelperError() . "\n";
                  echo "The Response message is: " . $error->getResponseBody() . "\n";
              }
              else {
                  echo "Created Id={$resultingObj->Id}. Reconstructed response body:\n\n";
                  $xmlBody = XmlObjectSerializer::getPostXmlFromArbitraryEntity($resultingObj, $urlResource);
                  echo $xmlBody . "\n";
              }
            return [
                'token_type' => 'bearer',
                'expires_in' => $accessToken->getAccessTokenExpiresAt(),
                'refresh_token' => $accessToken->getRefreshToken(),
                'x_refresh_token_expires_in' => $accessToken->getAccessTokenExpiresAt(),
                'access_token' => $accessToken->getAccessToken()
            ];
        } catch (\Exception $e) {
          //  LogsHelper::LogErrorInfo('QUICKBOOKS', 'generateTokenOnAuthorization Exception', $e->getMessage());
            throw $e;
        }
    }


    public static function check_and_create_customer($token_json,$request)
    {
        try {
                    $billAddr = null;
                    $shipAddr = null;

                    $customerObj = [
                        "Title" => "MR",
                        "GivenName" => $request->mname,
                        "FamilyName" => $request->mname."_qbo_test",
                       /* "BillAddr" => $billAddr,
                        "ShipAddr" => $shipAddr,
                        "Notes" => $customer->notes,*/
                        "DisplayName" => $request->mname,
                        "CompanyName" => $request->mname.".test.com",
                        /*"WebAddr" => [
                            "URI" => $customer->website
                        ],
                        "PrimaryPhone" => [
                            "FreeFormNumber" => $customer->phone
                        ],
                        "PrimaryEmailAddr" => [
                            "Address" => $customer->email
                        ]*/
                    ];

                    //$customerObj = (!empty($qbTermId)) ? array_merge($customerObj, ["SalesTermRef" => ["value" => $qbTermId]]) : $customerObj;
//dd($customerObj);
                    $qbCustomerRes = self::create_entities(["customer" => Customer::create($customerObj)], true,$token_json);
                    
                    if (empty($qbCustomer = reset($qbCustomerRes))) {
                        throw new \Exception('Customer not created in Quickbooks.');
                    }
               

               
                return true; 
        } catch (\Exception $ex) {
          //  LogsHelper::LogErrorInfo('QUICKBOOKS', 'CREATE CUSTOMER', $ex->getMessage(), $customer);
            throw $ex;
        }
    }
    public static function createCardBody()
    {
        $cardBody = CardOperations::buildFrom([
        "expMonth"=> "12",
            "address"=> [
              "postalCode"=> "44112",
              "city"=> "Richmond",
              "streetAddress"=> "1245 Hana Rd",
              "region"=> "VA",
              "country"=> "US"
            ],
            "number"=> "4131979708684369",
            "name"=> "Test User",
            "expYear"=> "2026"
      ]);
        //dd($cardBody);
        return $cardBody;
    }    
    public static function check_and_create_creditcard($token_json,$request)
    {
        try {
               
                $cartObj = [
                    "expMonth" => $request->mm_exp,
                    "expYear" => $request->year_exp,
                    "number" => $request->cardnumber,
                    "name" => $request->cardName,
                    "cvc" => $request->cvv,
                    "address"=> [
                    "postalCode"=> "44112", 
                    "city"=> "Richmond", 
                    "streetAddress"=> "1245 Hana Rd", 
                    "region"=> "VA", 
                    "country"=> "US"
                    ], 
                ];
                
                $card = self::createCardBody();
//dd($token_json->access_token);
                $client = new PaymentClient([
                        'access_token' => $token_json->access_token,
                        'environment' => "sandbox"
                    ]);
                $clientId =$request->custid;
                
               // dd($card);
                /*$charge = ChargeOperations::buildFrom($cartObj);
                
                $response = $client->charge($charge);*/
                //$qbCustomerRes =  $client->createCard($card,$clientId);
               // $res = $client->getCard($clientId,374245455400126);
               // $id1 = $qbCustomerRes->getBody();
               // dd($id1);
                $response2 = $client->getCard($clientId,"101181771467005695754369");
                dd($response2);
               
                $id2 = $response2->getBody()->id;
                $secureCardNumber2 = $response->getBody()->number;

                dd($id2);
                
                if (empty($qbCustomer = reset($qbCustomerRes))) {
                    throw new \Exception('cards not created in Quickbooks.');
                }
                return true; 
        } catch (\Exception $ex) {
          //  LogsHelper::LogErrorInfo('QUICKBOOKS', 'CREATE CUSTOMER', $ex->getMessage(), $customer);
            throw $ex;
        }
    }

    public static function create_entities( $entities, $throw = false,$token_json)
    {
        try 
        {
           // dd($token_json);
            $ds = self::ds($token_json);

            if (!defined('QUICKBOOKS_API_TIMEOUT')) {
                define('QUICKBOOKS_API_TIMEOUT', 90);
            }

            //$ds->setMinorVersion(12);
            $batch = $ds->CreateNewBatch();

            $res = [];
            foreach ($entities ?: [] as $key => $entity) {
                $res[$key] = null;
                $batch->AddEntity($entity, $key, "add");
            }

            $batch->Execute();

            $error = $batch->getLastError();
            //dd($error->getResponseBody());
            if ($error != null) {
               // throw new \Exception($error->getIntuitErrorMessage() ?: $error->getResponseBody(), $error->getIntuitErrorCode() ?: $error->getHttpStatusCode());
            }

            foreach ($batch->intuitBatchItemResponses ?: [] as $response) {
                $res[$response->batchItemId] = ($response->responseType == 1) ? $response->entity : null;

                if ($response->responseType == 3) {
                    if ($throw === true) {
                        throw $response->exception;
                    }
                }
            }

            return $res;
        } catch (\Exception $ex) {
            //LogsHelper::LogErrorInfo('QUICKBOOKS', 'create_entities exception ' . $ex->getCode(), $ex->getMessage());
            throw $ex;
        }
    }

}
