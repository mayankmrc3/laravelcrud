<?php

namespace app\Http\Middleware;

use Illuminate\Support\Facades\File;
use Inventry\Platform\Models\ChartOfAccount;
use Inventry\Platform\Models\Payment;
use Inventry\Platform\Models\QbPaymentMethod;
use Inventry\Platform\Models\QbRefund;
use Inventry\Platform\Models\QbTerm;
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
use App\Models\QbChartOfAccount;
use App\Models\QbAccount;
use App\Models\QbProduct;
use App\Models\QbPayment;
use App\Models\QbClass;
use QuickBooksOnline\API\Facades\Line;
use Illuminate\Support\Facades\Log;
use QuickBooksOnline\API\Data\IPPCustomField;
use App\Helpers\LogsHelper;

class Quickbooks
{

    public static function generateTokenOnAuthorization($code, $realmId)
    {
        try {
            $dataService = Ds::Configure([
                'auth_mode' => 'oauth2',
                'ClientID' => config('quickbooks.client_id'),
                'ClientSecret' => config('quickbooks.client_secret'),
                'RedirectURI' => config('quickbooks.redirect_uri'),
                'baseUrl' => "development",
                'scope' => "com.intuit.quickbooks.accounting"
            ]);

            $OAuth2LoginHelper = $dataService->getOAuth2LoginHelper();

            $accessTokenObj = $OAuth2LoginHelper->exchangeAuthorizationCodeForToken($code, $realmId);

            return [
                'token_type' => 'bearer',
                'expires_in' => $accessTokenObj->getAccessTokenExpiresAt(),
                'refresh_token' => $accessTokenObj->getRefreshToken(),
                'x_refresh_token_expires_in' => $accessTokenObj->getAccessTokenExpiresAt(),
                'access_token' => $accessTokenObj->getAccessToken()
            ];
        } catch (\Exception $e) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'generateTokenOnAuthorization Exception', $e->getMessage());
            throw $e;
        }
    }

    /**
     * function will return DataService instance to be used for calling Quickbooks api
     * @param $account_id
     * @throws \Exception
     * @return \QuickBooksOnline\API\DataService\DataService
     */
    public static function ds($account_id)
    {
        try {
            $created_on = Carbon::now()->toDateTimeString();

            $qb_integration_item = \Inventry\Platform\Models\Account::find($account_id)
                    ->integrationItems
                    ->where('type', 'quickbooks')
                    ->where('status', 'active')
                    ->first();

            if (empty($qb_integration_item)) {
                throw new \Exception('Quickbooks integration is not available.');
            }

            $token_json = json_decode(decrypt($qb_integration_item->access_token), true);

            if (!empty($token_json)) {
                /*$ds = Ds::Configure([
                            'auth_mode' => 'oauth2',
                            'ClientID' => config('quickbooks.client_id'),
                            'ClientSecret' => config('quickbooks.client_secret'),
                            'accessTokenKey' => $token_json['access_token'],
                            'refreshTokenKey' => $token_json['refresh_token'],
                            'QBORealmID' => $qb_integration_item->metadata()['qb_company_id'],
                            'baseUrl' => config('quickbooks.baseUrl')
                ]);*/

                $ds = Ds::Configure([
                            'auth_mode' => 'oauth2',
                            'ClientID' => config('quickbooks.client_id'),
                            'ClientSecret' => config('quickbooks.client_secret'),
                            'accessTokenKey' => $token_json['access_token'],
                            'refreshTokenKey' => $token_json['refresh_token'],
                            'QBORealmID' => $qb_integration_item->metadata()['qb_company_id'],
                            'baseUrl' => config('quickbooks.baseUrl')
                ]);

                $tcreated_on = Carbon::createFromFormat('Y-m-d H:i:s', $token_json['created_on'])
                    ->addSeconds($token_json['expires_in']);

                if ($tcreated_on->lt(Carbon::now())) {
                    $OAuth2LoginHelper = $ds->getOAuth2LoginHelper();

                    $accessToken = $OAuth2LoginHelper->refreshAccessTokenWithRefreshToken($token_json['refresh_token']);

                    $error = $OAuth2LoginHelper->getLastError();

                    if ($error != null) {
                        if ($error->getResponseBody() === config('quickbooks.refresh_token_error')) {
                            $qb_integration_item->status = 'disabled';
                            $qb_integration_item->save();
                            throw new \Exception('Sorry, You are disconnected from Quickbooks, please reauthorize in order to sync your data.');
                        }

                        throw new \Exception($error->getIntuitErrorMessage() ?: $error->getResponseBody(), $error->getIntuitErrorCode() ?: $error->getHttpStatusCode());
                    }
                    $ds->updateOAuth2Token($accessToken);

                    $qb_integration_item->access_token = encrypt(json_encode([
                        'token_type' => 'bearer',
                        'expires_in' => $accessToken->getAccessTokenExpiresAt(),
                        'refresh_token' => $accessToken->getRefreshToken(),
                        'x_refresh_token_expires_in' => $accessToken->getAccessTokenExpiresAt(),
                        'access_token' => $accessToken->getAccessToken(),
                        'created_on' => $created_on
                    ]));
                    $qb_integration_item->save();
                }
            }

            if (!is_dir(config('quickbooks.log_location'))) {
                File::makeDirectory(config('quickbooks.log_location'), 0775);
            }

            $ds->enableLog();
            $ds->setLogLocation(config('quickbooks.log_location'));

            return $ds;
        } catch (\Exception $ex) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'DS exception', $ex->getMessage());
            throw $ex;
        }
    }

    public static function query_entities($account_id, $entity, $where = null, $start = 0, $limit = 500)
    {
        try {
            $ds = self::ds($account_id);

            //$ds->setMinorVersion(9);

            $entities = $ds->Query("SELECT * FROM {$entity}" . (($where) ? " WHERE {$where}" : "") . ($start ? " startPosition {$start}" : "") . ($limit ? " maxResults {$limit}" : ""));

            $error = $ds->getLastError();

            if ($error != null) {
                throw new \Exception($error->getIntuitErrorMessage() ?: $error->getResponseBody(), $error->getIntuitErrorCode() ?: $error->getHttpStatusCode());
            }

            return $entities ?: [];
        } catch (\Exception $ex) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'query_entities exception', $ex->getMessage());
            throw $ex;
        }
    }

    public static function create_entities($account_id, $entities, $throw = false)
    {
        try {
            $ds = self::ds($account_id);

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

            if ($error != null) {
                throw new \Exception($error->getIntuitErrorMessage() ?: $error->getResponseBody(), $error->getIntuitErrorCode() ?: $error->getHttpStatusCode());
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
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'create_entities exception ' . $ex->getCode(), $ex->getMessage());
            throw $ex;
        }
    }

    public static function check_and_create_vendor($account_id, $vendor)
    {
        try {
            // getting qb Vendor mapping details
            $qbvendor = \Inventry\Platform\Models\Account::find($account_id)->qbvendors()->where('related_account_id', $vendor->id)->first();

            if (!empty($qbvendor)) {
                return $qbvendor->qb_account_id;
            }

            // getting Vendor details from Quickbooks
            $QbVendor = self::query_entities($account_id, 'Vendor', "DisplayName IN ('" . addslashes($vendor->name) . "') AND Active = true");

            if (empty($QbVendor = reset($QbVendor))) {
                $billingAddress = $vendor->defaultAddress;
                if (!$billingAddress) {
                    $billingAddress = $vendor->addresses()->where('type', 'billing')->first();
                }

                $person = (object) ['title' => null, 'first_name' => null, 'last_name' => null, 'phone' => null];

                foreach ($vendor->people ?: [] as $p) {
                    $person->title = $p->title;
                    $person->first_name = $p->first_name;
                    $person->last_name = $p->last_name;
                    $person->phone = $p->phone;
                    break;
                }

                $qbPaymentTerm = self::check_and_create_terms($account_id, $vendor->payment_terms);

                $vendorObj = [
                    "BillAddr" => [
                        "Line1" => @$billingAddress->street1,
                        "Line2" => @$billingAddress->street2,
                        "City" => @$billingAddress->city,
                        "Country" => @$billingAddress->country,
                        "CountrySubDivisionCode" => @$billingAddress->state,
                        "PostalCode" => @$billingAddress->zip
                    ],
                    // "Title" => $person->title,
                    // "GivenName" => $person->first_name,
                    // "FamilyName" => $person->last_name,
                    "CompanyName" => $vendor->name,
                    "DisplayName" => $vendor->name,
                    "Notes" => $vendor->notes,
                    "PrintOnCheckName" => $vendor->name,
                    "PrimaryPhone" => [
                        "FreeFormNumber" => $vendor->phone
                    ],
                    "Mobile" => [
                        "FreeFormNumber" => $person->phone
                    ],
                    "PrimaryEmailAddr" => [
                        "Address" => $vendor->email
                    ],
                    "WebAddr" => [
                        "URI" => $vendor->website
                    ]
                ];

                if (!empty($qbPaymentTerm)) {
                    $termObj = QbTerm::where('qb_term_id', $qbPaymentTerm)->first();
                    $vendorObj["TermRef"] = [
                        "value" => $qbPaymentTerm,
                        "name" => $termObj->name
                    ];
                }
                // creating Vendor in Quickbooks
                $QbVendor = self::create_entities($account_id, ["vendor" => Vendor::create($vendorObj)], true);

                if (empty($QbVendor = reset($QbVendor))) {
                    throw new \Exception('Vendor not created in Quickbooks.');
                }
            }

            // adding the mapping entry for Vendor in DB

            QbAccount::firstOrCreate([
                "account_id" => $account_id,
                "related_account_id" => $vendor->id,
                "type" => "vendor",
                "qb_account_id" => $QbVendor->Id
            ]);

            return $QbVendor->Id;
        } catch (\Exception $ex) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'CREATE VENDOR', $ex->getMessage(), $vendor);
            throw $ex;
        }
    }

    public static function check_and_update_vendor($account_id, $vendor)
    {
        try {
            // getting qb Vendor mapping details
            $qbvendor = \Inventry\Platform\Models\Account::find($account_id)->qbvendors()->where('related_account_id', $vendor->id)->first();

            if (empty($qbvendor)) {
                throw new \Exception('Vendor mapping entry not found.');
            }

            // getting Vendor details from Quickbooks
            $QbVendor = self::query_entities($account_id, 'Vendor', "Id IN ('{$qbvendor->qb_account_id}') AND Active = true");

            if (empty($QbVendor = reset($QbVendor))) {
                throw new \Exception('Vendor not found in Quickbooks.');
            }

            $billingAddress = $vendor->defaultAddress;
            if (!$billingAddress) {
                $billingAddress = $vendor->addresses()->where('type', 'billing')->first();
            }
            $person = (object) ['title' => null, 'first_name' => null, 'last_name' => null, 'phone' => null];

            foreach ($vendor->people ?: [] as $p) {
                $person->title = $p->title;
                $person->first_name = $p->first_name;
                $person->last_name = $p->last_name;
                $person->phone = $p->phone;
                break;
            }

            $qbPaymentTerm = self::check_and_create_terms($account_id, $vendor->payment_terms);

            $vendorObj = [
                "BillAddr" => [
                    "Line1" => @$billingAddress->street1,
                    "Line2" => @$billingAddress->street2,
                    "City" => @$billingAddress->city,
                    "Country" => @$billingAddress->country,
                    "CountrySubDivisionCode" => @$billingAddress->state,
                    "PostalCode" => @$billingAddress->zip
                ],
                // "Title" => $person->title,
                // "GivenName" => $person->first_name,
                // "FamilyName" => $person->last_name,
                "CompanyName" => $vendor->name,
                "DisplayName" => $vendor->name,
                "Notes" => $vendor->notes,
                "PrintOnCheckName" => $vendor->name,
                "PrimaryPhone" => [
                    "FreeFormNumber" => $vendor->phone
                ],
                "Mobile" => [
                    "FreeFormNumber" => $person->phone
                ],
                "PrimaryEmailAddr" => [
                    "Address" => $vendor->email
                ],
                "WebAddr" => [
                    "URI" => $vendor->website
                ]
            ];

            $vendorObj = (!empty($qbPaymentTerm)) ? array_merge($vendorObj, ["TermRef" => ["value" => $qbPaymentTerm]]) : $vendorObj;

            // creating Vendor in Quickbooks
            $resObjArr = self::create_entities($account_id, ['vendor' => Vendor::update($QbVendor, $vendorObj)], true);

            if (empty($QbVendor = reset($resObjArr))) {
                throw new \Exception('Vendor not updated in Quickbooks.');
            }

            return $QbVendor->Id;
        } catch (\Exception $ex) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'UPDATE VENDOR', $ex->getMessage(), $vendor);
            throw $ex;
        }
    }

    /**
     * function will first check for customer entry in database, if not found then it will query in to Quickbooks
     * and still not found then create an entry for new customer in Quickbooks
     * @param $account_id
     * @param $customer
     * @return mixed
     * @throws \Exception
     */
    public static function check_and_create_customer($account_id, $customer)
    {
        try {
            $checkCustomer = \Inventry\Platform\Models\Account::find($account_id)->qbcustomers()->where('related_account_id', $customer->id)->first();

            if (!$checkCustomer) {
                $customerData = self::query_entities($account_id, "Customer", "DisplayName IN ('" . addslashes($customer->name) . "') AND Active = true");

                if ($customerData) {
                    $qbCustomer = $customerData[0];
                } else {
                    $billAddr = null;
                    $shipAddr = null;

                    foreach ($customer->addresses as $addresses) {
                        if (!empty($billAddr) && !empty($shipAddr)) {
                            break;
                        }

                        if ($addresses->type == 'billing' && empty($billAddr)) {
                            $billAddr = [
                                "Line1" => $addresses->street1,
                                "City" => $addresses->city,
                                "Country" => $addresses->country,
                                "CountrySubDivisionCode" => $addresses->state,
                                "PostalCode" => $addresses->zip
                            ];
                        }

                        if ($addresses->type == 'shipping' && empty($shipAddr)) {
                            $shipAddr = [
                                "Line1" => $addresses->street1,
                                "City" => $addresses->city,
                                "Country" => $addresses->country,
                                "CountrySubDivisionCode" => $addresses->state,
                                "PostalCode" => $addresses->zip
                            ];
                        }
                    }

                    $contactData = null;
                    foreach ($customer->people as $contacts) {
                        if ($contactData) {
                            break;
                        }
                        if ($contacts->first_name && !$contactData) {
                            $contactData['title'] = $contacts->title;
                            $contactData['first_name'] = $contacts->first_name;
                            $contactData['last_name'] = $contacts->last_name;
                        }
                    }
                    $qbTermId = self::check_and_create_terms($account_id, $customer->payment_terms);

                    $customerObj = [
                        // "Title" => $contactData['title'],
                        // "GivenName" => $contactData['first_name'],
                        // "FamilyName" => $contactData['last_name'],
                        "BillAddr" => $billAddr,
                        "ShipAddr" => $shipAddr,
                        "Notes" => $customer->notes,
                        "DisplayName" => $customer->name,
                        "CompanyName" => $customer->name,
                        "WebAddr" => [
                            "URI" => $customer->website
                        ],
                        "PrimaryPhone" => [
                            "FreeFormNumber" => $customer->phone
                        ],
                        "PrimaryEmailAddr" => [
                            "Address" => $customer->email
                        ]
                    ];

                    $customerObj = (!empty($qbTermId)) ? array_merge($customerObj, ["SalesTermRef" => ["value" => $qbTermId]]) : $customerObj;

                    $qbCustomerRes = self::create_entities($account_id, ["customer" => Customer::create($customerObj)], true);

                    if (empty($qbCustomer = reset($qbCustomerRes))) {
                        throw new \Exception('Customer not created in Quickbooks.');
                    }
                }

                $QbAccount = new QbAccount();
                $QbAccount->account_id = $account_id;
                $QbAccount->related_account_id = $customer->id;
                $QbAccount->type = 'customer';
                $QbAccount->qb_account_id = $qbCustomer->Id;
                $QbAccount->save();

                return $QbAccount->qb_account_id;
            } else {
                return $checkCustomer->qb_account_id;
            }
        } catch (\Exception $ex) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'CREATE CUSTOMER', $ex->getMessage(), $customer);
            throw $ex;
        }
    }

    /**
     * function will check for customer entry in qb_accounts table and if found then update the customer in Quickbooks.
     * @param $customer
     * @param $account_id
     * @return array|null
     * @throws \Exception
     */
    public static function check_and_update_customer($account_id, $customer)
    {
        try {
            $qbcustomer = \Inventry\Platform\Models\Account::find($account_id)->qbcustomers()->where('related_account_id', $customer->id)->first();

            if (empty($qbcustomer)) {
                throw new \Exception('Customer mapping entry not found.');
            }

            $qbCustomerData = self::query_entities($account_id, "Customer", "Id IN ('{$qbcustomer->qb_account_id}') AND Active = true");

            if (empty($qbCustomerData = reset($qbCustomerData))) {
                throw new \Exception('Customer not found in Quickbooks.');
            }

            $billAddr = [
                "Line1" => null,
                "City" => null,
                "Country" => null,
                "CountrySubDivisionCode" => null,
                "PostalCode" => null
            ];

            $shipAddr = [
                "Line1" => null,
                "City" => null,
                "Country" => null,
                "CountrySubDivisionCode" => null,
                "PostalCode" => null
            ];
            $setBillArrdVal = 0;
            $setShipArrdVal = 0;
            foreach ($customer->addresses ?: [] as $addresses) {
                if ($addresses->type == 'billing' && $setBillArrdVal == 0) {
                    $billAddr = [
                        "Line1" => $addresses->street1,
                        "City" => $addresses->city,
                        "Country" => $addresses->country,
                        "CountrySubDivisionCode" => $addresses->state,
                        "PostalCode" => $addresses->zip
                    ];
                    $setBillArrdVal = 1;
                }

                if ($addresses->type == 'shipping' && $setShipArrdVal == 0) {
                    $shipAddr = [
                        "Line1" => $addresses->street1,
                        "City" => $addresses->city,
                        "Country" => $addresses->country,
                        "CountrySubDivisionCode" => $addresses->state,
                        "PostalCode" => $addresses->zip
                    ];
                    $setShipArrdVal = 1;
                }
            }

            $person = (object) ['title' => null, 'first_name' => null, 'last_name' => null, 'phone' => null];

            foreach ($customer->people ?: [] as $p) {
                $person->title = $p->title;
                $person->first_name = $p->first_name;
                $person->last_name = $p->last_name;
                $person->phone = $p->phone;
                break;
            }

            $qbTermId = self::check_and_create_terms($account_id, $customer->payment_terms);

            $customerObjForQB = [
                // "Title" => $person->title,
                // "GivenName" => $person->first_name,
                // "FamilyName" => $person->last_name,
                "BillAddr" => $billAddr,
                "ShipAddr" => $shipAddr,
                "Notes" => $customer->notes,
                "DisplayName" => $customer->name,
                "CompanyName" => $customer->name,
                "WebAddr" => [
                    "URI" => $customer->website
                ],
                "PrimaryPhone" => [
                    "FreeFormNumber" => $customer->phone
                ],
                "Mobile" => [
                    "FreeFormNumber" => $person->phone
                ],
                "PrimaryEmailAddr" => [
                    "Address" => $customer->email
                ],
                "sparse" => true
            ];

            $customerObjForQB = (!empty($qbTermId)) ? array_merge($customerObjForQB, ["SalesTermRef" => ["value" => $qbTermId]]) : $customerObjForQB;

            $QbCustomer = self::create_entities($account_id, ["customer" => Customer::update($qbCustomerData, $customerObjForQB)], true);

            if (empty($QbCustomer = reset($QbCustomer))) {
                throw new \Exception('Customer not update in Quickbooks.');
            }

            return true;
        } catch (\Exception $ex) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'UPDATE CUSTOMER', $ex->getMessage(), $customer);
            throw $ex;
        }
    }

    public static function check_and_create_products($account_id, $products = [])
    {
        try {
            // getting parent account setting of product
            $qb_integration_item = \Inventry\Platform\Models\Account::find($account_id)
                    ->integrationItems
                    ->where('type', 'quickbooks')
                    ->where('status', 'active')
                    ->first();

            if (empty($qb_integration_item)) {
                throw new \Exception('Quickbooks integration is not available.');
            }

            $productsToCreate = [];

            foreach ($products ?: [] as $product) {
                $accountsToCreate = [];

                // checking qb product mapping details
                if (empty($product->qbproduct)) {
                    $productGroup = $product->parentProduct->productGroup;

                    // getting product account mapping details
                    $qbChartOfAccounts = $productGroup->qbChartOfAccounts;

                    $accounts = [];

                    // preparing the list of accounts needed in order to sync the product in Quickbooks
                    $accountsToCreate = [
                        "inventory_{$productGroup->id}" => Account::create([
                            'Name' => "Inventory - ({$productGroup->name})",
                            'FullyQualifiedName' => "Inventory - ({$productGroup->name})",
                            'SubAccount' => true,
                            'ParentRef' => [
                                'value' => $qb_integration_item->metadata()['accounts']['inventory']['value']
                            ],
                            'AccountType' => config('quickbooks.accounts.inventory.type'),
                            'AccountSubType' => config('quickbooks.accounts.inventory.sub_type'),
                            'Description' => config('quickbooks.accounts.inventory.description'),
                        ]),
                        "income_{$productGroup->id}" => Account::create([
                            'Name' => "Sales - ({$productGroup->name})",
                            'FullyQualifiedName' => "Sales - ({$productGroup->name})",
                            'SubAccount' => true,
                            'ParentRef' => [
                                'value' => $qb_integration_item->metadata()['accounts']['income']['value']
                            ],
                            'AccountType' => config('quickbooks.accounts.income.type'),
                            'AccountSubType' => config('quickbooks.accounts.income.sub_type'),
                            'Description' => config('quickbooks.accounts.income.description'),
                        ]),
                        "expense_{$productGroup->id}" => Account::create([
                            'Name' => "Cost of Goods Sold - ({$productGroup->name})",
                            'FullyQualifiedName' => "Cost of Goods Sold - ({$productGroup->name})",
                            'SubAccount' => true,
                            'ParentRef' => [
                                'value' => $qb_integration_item->metadata()['accounts']['expense']['value']
                            ],
                            'AccountType' => config('quickbooks.accounts.expense.type'),
                            'AccountSubType' => config('quickbooks.accounts.expense.sub_type'),
                            'Description' => config('quickbooks.accounts.expense.description'),
                        ]),
                    ];

                    // unsetting the account which mapping entry exist in DB
                    foreach ($qbChartOfAccounts ?: [] as $qbCOA) {
                        unset($accountsToCreate["{$qbCOA->type}_{$productGroup->id}"]);
                    }

                    if (!empty($accountsToCreate)) {
                        // getting product accounts from Quickbooks by Name
                        $QbAccounts = self::query_entities($account_id, 'Account', "Name IN ('" . implode("', '", array_map(function ($element) {
                            return addslashes($element);
                        }, array_column(json_decode(json_encode($accountsToCreate), true), "Name"))) . "') AND Active = true");

                        // unsetting the account which are already exist in Quickbooks
                        foreach ($QbAccounts ?: [] as $QbAccount) {
                            if ($QbAccount->Name == "Inventory - ({$productGroup->name})") {
                                $accounts["inventory_{$productGroup->id}"] = $QbAccount;
                                unset($accountsToCreate["inventory_{$productGroup->id}"]);
                            }

                            if ($QbAccount->Name == "Sales - ({$productGroup->name})") {
                                $accounts["income_{$productGroup->id}"] = $QbAccount;
                                unset($accountsToCreate["income_{$productGroup->id}"]);
                            }

                            if ($QbAccount->Name == "Cost of Goods Sold - ({$productGroup->name})") {
                                $accounts["expense_{$productGroup->id}"] = $QbAccount;
                                unset($accountsToCreate["expense_{$productGroup->id}"]);
                            }
                        }

                        // creating remaining account in Quickbooks
                        if (!empty($accountsToCreate)) {
                            $accounts = array_merge($accounts, self::create_entities($account_id, $accountsToCreate, true));
                        }
                    }

                    // creating the mapping entry in DB
                    foreach ($accounts ?: [] as $key => $account) {
                        $arr = explode('_', $key);

                        $QbChartOfAccount = new QbChartOfAccount();
                        $QbChartOfAccount->type = $arr[0];
                        $QbChartOfAccount->product_group_id = $arr[1];
                        $QbChartOfAccount->qb_product_account_id = $account->Id;
                        $QbChartOfAccount->save();
                    }

                    // loading the update records for product accounts
                    $qbChartOfAccounts = $productGroup->load('qbChartOfAccounts')->qbChartOfAccounts;

                    // preparing array for product that needs to be created in Quickbooks
                    $productsToCreate["product_{$product->id}"] = Item::create([
                                "Name" => $product->name,
                                "Sku" => $product->code,
                                "Description" => $product->description,
                                "Active" => true,
                                "FullyQualifiedName" => $product->full_name,
                                "Taxable" => false,
                                "UnitPrice" => (double) $product->price,
                                "Type" => "Inventory",
                                "IncomeAccountRef" => [
                                    "value" => $qbChartOfAccounts->where('type', 'income')->first()->qb_product_account_id
                                ],
                                "PurchaseDesc" => null,
                                "PurchaseCost" => (double) $product->cost,
                                "ExpenseAccountRef" => [
                                    "value" => $qbChartOfAccounts->where('type', 'expense')->first()->qb_product_account_id
                                ],
                                "AssetAccountRef" => [
                                    "value" => $qbChartOfAccounts->where('type', 'inventory')->first()->qb_product_account_id
                                ],
                                "TrackQtyOnHand" => true,
                                "QtyOnHand" => (int) $product->available[0]->available,
                                "InvStartDate" => ($product->created_at) ? Carbon::parse($product->created_at)->subMonths(3) : Carbon::now()->subMonths(3) // Substracting 3 months from created or current date to resolve Transaction date is prior to start date for inventory item issue
                    ]);
                }
            }

            // creating products in batch of 25
            if (!empty($productsToCreate)) {
                // getting the product's details from Quickbooks by Name
                $QbItems = self::query_entities($account_id, "Item", "Name IN ('" . implode("', '", array_map(function ($element) {
                    return addslashes($element);
                }, array_column(json_decode(json_encode($productsToCreate), true), "Name"))) . "') AND Active = true");

                // preparing Key-value pair array for search
                $QbItemsNameExist = (!empty($QbItems)) ? array_column(json_decode(json_encode($QbItems), true), 'Name', 'Id') : [];

                if (!empty($QbItemsNameExist)) {
                    foreach ($productsToCreate ?: [] as $index => $p) {
                        if ($qb_product_id = array_search($p->Name, $QbItemsNameExist)) {
                            // creating mapping entry for products
                            $QbProduct = new QbProduct();
                            $QbProduct->product_id = explode('_', $index)[1];
                            $QbProduct->qb_product_id = $qb_product_id;
                            $QbProduct->save();

                            // unsetting the products from create araay
                            unset($productsToCreate[$index]);
                        }
                    }
                }

                // final products that needs to be created in Quickbooks
                if (!empty($productsToCreate)) {
                    // processing the final product array in batch of 25 for creating in Quickbooks
                    foreach (array_chunk($productsToCreate, 25, true) ?: [] as $arr) {
                        // creating product in batch of 25
                        $createdBatch = self::create_entities($account_id, $arr);

                        foreach ($arr ?: [] as $key => $p) {
                            // retrieving product details created in Quickbooks
                            $qbProduct = $createdBatch[$key];

                            // creating the mapping entry for products
                            $QbProduct = new QbProduct();
                            $QbProduct->product_id = explode('_', $key)[1];
                            $QbProduct->qb_product_id = $qbProduct->Id;
                            $QbProduct->save();
                        }
                    }
                }
            }

            return true;
        } catch (\Exception $ex) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'CREATE PRODUCTS', $ex->getMessage(), $products);
            throw $ex;
        }
    }

    public static function check_and_create_new_product($account_id, $product)
    {
        try {
            $qb_integration_item = \Inventry\Platform\Models\Account::find($account_id)
                ->integrationItems
                ->where('type', 'quickbooks')
                ->where('status', 'active')
                ->first();

            if (empty($qb_integration_item)) {
                throw new \Exception('Quickbooks integration is not available.');
            }
            // checking qb product mapping details
            if (empty($product->qbproduct)) {
                $productGroup = $product->parentProduct->productGroup;
                $qbChartOfAccounts = $productGroup->qbChartOfAccounts;
                if (empty($qbChartOfAccounts) || count($qbChartOfAccounts) == 0) {
                    self::check_and_create_product_chart_of_accounts($account_id, $productGroup);
                    $qbChartOfAccounts = $productGroup->load('qbChartOfAccounts')->qbChartOfAccounts;
                }
                // preparing array for product that needs to be created in Quickbooks
                $productToCreate = [
                        "Name" => $product->name,
                        "Sku" => $product->code,
                        "Description" => $product->description,
                        "Active" => true,
                        "FullyQualifiedName" => $product->full_name,
                        "Taxable" => false,
                        "UnitPrice" => (double) $product->price,
                        "Type" => "Inventory",
                        "IncomeAccountRef" => [
                            "value" => $qbChartOfAccounts->where('type', 'income')->first()->qb_product_account_id
                        ],
                        "PurchaseDesc" => null,
                        "PurchaseCost" => (double) $product->cost,
                        "ExpenseAccountRef" => [
                            "value" => $qbChartOfAccounts->where('type', 'expense')->first()->qb_product_account_id
                        ],
                        "AssetAccountRef" => [
                            "value" => $qbChartOfAccounts->where('type', 'inventory')->first()->qb_product_account_id
                        ],
                        "TrackQtyOnHand" => true,
                        "QtyOnHand" => (int) $product->available[0]->available,
                        "InvStartDate" => ($product->created_at) ? Carbon::parse($product->created_at)->subMonths(3) : Carbon::now()->subMonths(3) // Substracting 3 months from created or current date to resolve Transaction date is prior to start date for inventory item issue
                ];
            }

            if (!empty($productToCreate)) {
                // getting the product's details from Quickbooks by Name
                $QbItem = self::query_entities($account_id, "Item", "Name = '" . addslashes($productToCreate['Name']) . "' AND Active = true");
                if (!empty($QbItem)) {
                    $QbItem = json_decode(json_encode($QbItem), true);
                    if (is_array($QbItem) && count($QbItem) > 0) {
                        $QbItemObj = $QbItem[0];
                    } else {
                        $QbItemObj = null;
                    }
                }
                if (!empty($QbItemObj)) {
                    // creating mapping entry for products
                    $QbProduct = new QbProduct();
                    $QbProduct->product_id = $product->id;
                    $QbProduct->qb_product_id = $QbItemObj['Id'];
                    $QbProduct->save();
                }

                if (empty($QbItemObj)) {
                    $qbProductRes = self::create_entities($account_id, ["item" => Item::create($productToCreate)], true);
                    $qbProductRes = json_decode(json_encode($qbProductRes), true);
                    if (empty($qbProductRes)) {
                        return false;
                    }
                    $QbProduct = new QbProduct();
                    $QbProduct->product_id = $product->id;
                    $QbProduct->qb_product_id = $qbProductRes['item']['Id'];
                    $QbProduct->save();
                }
            }
            return true;
        } catch (\Exception $ex) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'NEW PRODUCT', $ex->getMessage(), $product);
            throw $ex;
        }
    }
    /**
     * function will check for existing term in database, if not found then it will query in to Quickbooks,
     * and if still not found then it will create an entry for Term at Quickbooks
     * @param $account_id
     * @param $paymentTerm
     * @throws \Exception
     * @return mixed
     */
    public static function check_and_create_terms($account_id, $paymentTerm)
    {
        if (empty($paymentTerm)) {
            return null;
        }

        try {
            switch ($paymentTerm) {
                case 'cod':
                    $term = 'cash on delivery';
                    $due_days = 0;
                    break;
                case 'eom':
                    $term = 'end of month';
                    $due_days = 31;
                    break;
                case 'pia':
                    $term = 'payment in advance';
                    $due_days = 0;
                    break;
                case strpos($paymentTerm, 'net'):
                    $parts = explode('_', $paymentTerm);
                    $term = $parts[0] . ' ' . $parts[1];
                    break;
                case 'due_on_receipt':
                    $term = 'due on receipt';
                    $due_days = 0;
                    break;
                default:
                    return null;
            }

            $checkQbTerm = QbTerm::where([['term', '=', $term], ['account_id', '=', $account_id]])->first();

            if (!empty($checkQbTerm)) {
                return $checkQbTerm->qb_term_id;
            }

            $termData = self::query_entities($account_id, "term", "Name IN ('" . addslashes($term) . "') AND Active = true");

            if (!empty($termData = reset($termData))) {
                $qb_term_id = $termData->Id;
            } else {
                $termObj = new IPPTerm();
                $termObj->Name = ucfirst($term);

                if (strpos($paymentTerm, 'net') !== false) {
                    $parts = explode('_', $paymentTerm);
                    $termObj->DueDays = $parts[1];
                    $termObj->Type = 'STANDARD';
                } else {
                    $termObj->DueDays = $due_days;
                    $termObj->Type = 'DATE_DRIVEN';
                }

                $entityObj = self::create_entities($account_id, ["term" => $termObj], true);

                if (empty($entityObj = reset($entityObj))) {
                    throw new \Exception('Term not created in Quickbooks.');
                }

                $qb_term_id = $entityObj->Id;
            }

            $qbTerm = new QbTerm();
            $qbTerm->account_id = $account_id;
            $qbTerm->term = $term;
            $qbTerm->qb_term_id = $qb_term_id;
            $qbTerm->save();

            return $qb_term_id;
        } catch (\Exception $ex) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'CREATE TERM', $ex->getMessage(), $paymentTerm);
            throw $ex;
        }
    }

    public static function check_and_delete_bill($account_id, $bill_id)
    {
        try {
            // retrieving the bill from Quickbooks
            $QbBills = self::query_entities($account_id, "Bill", "Id IN ('{$bill_id}')");

            if (!empty($QbBills)) {
                $QbBill = $QbBills[0];
            }

            if (empty($QbBill)) {
                throw new \Exception('Bill not found in Quickbooks.');
            }

            $ds = self::ds($account_id);

            // deleting the bill from Quickbooks
            if (!empty($ds->Delete($QbBill))) {
                return true;
            }

            throw new \Exception("Bill couldn't be deleted in Quickbooks.");
        } catch (\Exception $ex) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'DELETE BILL => Entity #' . $bill_id, $ex->getMessage());
            throw $ex;
        }
    }

    public static function prepare_bill_object($account_id, $order)
    {
        // getting vendor details
        $vendor = \Inventry\Platform\Models\Account::find($account_id)->vendors()->where('related_account_id', $order->vendor_account_id)
                ->with('defaultAddress', 'people')
                ->getQuery()
                ->first();

        if (empty($vendor)) {
            throw new \Exception('Vendor not found.');
        }

        // getting vendor Id
        $qbVenderId = self::check_and_create_vendor($account_id, $vendor);

        $items = $order->items;

        $products = [];

        // preparing the product array for processing in Quickbooks
        foreach ($items ?: [] as $item) {
            $products[] = $item->product;
        }

        // creating products in Quickbooks if not available
        self::check_and_create_products($account_id, $products);

        // initializing the line object to be used for bill creation
        $lineObj = [];

        // creating Line Object
        foreach ($order->items ?: [] as $index => $item) {
            $itemLine = [
                "Id" => $index + 1,
                "Amount" => round((float) $item->price * $item->quantity, 2),
                "Description" => $item->product->unit_measurement,
                "DetailType" => "ItemBasedExpenseLineDetail",
                "ItemBasedExpenseLineDetail" => [
                    "ItemRef" => [
                        "value" => $item->product->load('qbproduct')->qbproduct->qb_product_id
                    ],
                    "UnitPrice" => (float) $item->price,
                    "Qty" => $item->quantity
                ]
            ];

            if ($order->qbClass) {
                $itemLine['ItemBasedExpenseLineDetail']['ClassRef'] = [
                    "value" => $order->qbClass->class_id
                ];
            }

            $lineObj[] = Line::create($itemLine);
        }

        //Purchase category details
        foreach ($order->categoryDetails ?: [] as $index => $item) {
            if ($item->chartOfAccount && $item->chartOfAccount->qb_account_id) {
                $categoryLine = [
                    "Amount" => round((float)$item->amount, 2),
                    "Description" => $item->internal_notes ?: null,
                    "DetailType" => "AccountBasedExpenseLineDetail",
                    "AccountBasedExpenseLineDetail" => [
                        "AccountRef" => [
                            "name" => $item->chartOfAccount->name,
                            "value" => $item->chartOfAccount->qb_account_id
                        ]
                    ]
                ];

                if ($order->qbClass) {
                    $categoryLine['AccountBasedExpenseLineDetail']['ClassRef'] = [
                        "value" => $order->qbClass->class_id
                    ];
                }

                $lineObj[] = Line::create($categoryLine);
            }
        }

        //Account line detail for discounts & Freight cost
        $qb_integration_item = \Inventry\Platform\Models\Account::find($account_id)
                                ->integrationItems
                                ->where('type', 'quickbooks')
                                ->where('status', 'active')
                                ->first();
        if (!empty($qb_integration_item)) {
            $metaDataObj = json_decode($qb_integration_item->metadata, true);
            $accountsObj = $metaDataObj['accounts'];
            if (!empty($accountsObj)) {
                if ($order->shipping_price > 0 && !empty($qb_integration_item->metadata()['accounts']['freight_cogs'])) {
                    $categoryLine = [
                        "Amount" => round((float) $order->shipping_price, 2),
                        "Description" => 'Freight Cost',
                        "DetailType" => "AccountBasedExpenseLineDetail",
                        "AccountBasedExpenseLineDetail" => [
                            "AccountRef" => [
                                "name" => $accountsObj['freight_cogs']['name'],
                                "value" => $accountsObj['freight_cogs']['value']
                            ],
                        ]
                    ];

                    if ($order->qbClass) {
                        $categoryLine['AccountBasedExpenseLineDetail']['ClassRef'] = [
                            "value" => $order->qbClass->class_id
                        ];
                    }

                    $lineObj[] = Line::create($categoryLine);
                }
                if ($order->discount > 0 && !empty($qb_integration_item->metadata()['accounts']['discount'])) {
                    $categoryLine = [
                        "Amount" => round((float) (-1) * $order->discount, 2),
                        "Description" => 'Discount given',
                        "DetailType" => "AccountBasedExpenseLineDetail",
                        "AccountBasedExpenseLineDetail" => [
                            "AccountRef" => [
                                "name" => $accountsObj['discount']['name'],
                                "value" => $accountsObj['discount']['value']
                            ],
                        ]
                    ];

                    if ($order->qbClass) {
                        $categoryLine['AccountBasedExpenseLineDetail']['ClassRef'] = [
                            "value" => $order->qbClass->class_id
                        ];
                    }

                    $lineObj[] = Line::create($categoryLine);
                }
            }
        }
        //Account line detail for discounts & Freight cost
        // checking the Line items for Bill
        if (!empty($lineObj)) {
            $salesTermRef = self::check_and_create_terms($account_id, $order->payment_terms);

            $billObj = [
                "DocNumber" => $order->ref_num,
                "DueDate" => $order->payment_due_at->toDateString(),
                "TxnDate" => $order->delivery_due_at->toDateString(),
                "Line" => $lineObj,
                "VendorRef" => [
                    "value" => $qbVenderId
                ],
                "PrivateNote" => $order->customer_order_num,
            ];

            return (!empty($salesTermRef)) ? array_merge($billObj, ["SalesTermRef" => ["value" => $salesTermRef]]) : $billObj;
        }

        return false;
    }

    /**
     * function will check for invoice entry in quickbooks and if found then it will delete it
     * @param $account_id
     * @param $invoice_id
     * @return bool
     * @throws \Exception
     */
    public static function check_and_delete_invoice($account_id, $invoice_id)
    {
        try {
            // retrieving the bill from Quickbooks
            $QbInvoices = self::query_entities($account_id, "Invoice", "Id IN ('{$invoice_id}')");

            if (!empty($QbInvoices)) {
                $QbInvoice = $QbInvoices[0];
            }
            if (empty($QbInvoice)) {
                throw new \Exception('Invoice not found in Quickbooks.');
            }
            $ds = self::ds($account_id);
            // deleting the invoice from Quickbooks
            $deleteInvoice = $ds->Delete($QbInvoice);
            if (!empty($deleteInvoice)) {
                return true;
            }
            throw new \Exception("Invoice couldn't be deleted in Quickbooks.");
        } catch (\Exception $ex) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'DELETE INVOICE => Entity # ' . $invoice_id, $ex->getMessage());
            throw $ex;
        }
    }

    /**
     * function will prepare the object which will be used to create or update the Invoice in Quickbooks
     * @param $account_id
     * @param $order
     * @return array
     * @throws \Exception
     */
    public static function prepare_invoice_object($account_id, $order)
    {

        try {
            //Check for Customer and Create if not exists
            $customer = \Inventry\Platform\Models\Account::find($account_id)->customers()
                    ->with('defaultAddress', 'addresses', 'people')
                    ->where('related_account_id', '=', $order->customer_account_id)
                    ->getQuery()
                    ->first();

            $qbCustomerId = self::check_and_create_customer($account_id, $customer);

            if ($qbCustomerId) {
                $items = $order->items;

                $products = [];

                // preparing the product array for processing in Quickbooks
                foreach ($items ?: [] as $item) {
                    $products[] = $item->product;
                }

                // creating products in Quickbooks if not available
                self::check_and_create_products($account_id, $products);

                // initializing the line object to be used for bill creation
                $lineObj = [];

                // creating Line Object
                foreach ($order->items ?: [] as $index => $item) {
                    $lineObj[] = Line::create([
                                "Id" => $index + 1,
                                "Amount" => round((float) $item->price * $item->quantity, 2),
                                "DetailType" => "SalesItemLineDetail",
                                "Description" => $item->product->unit_measurement,
                                "SalesItemLineDetail" => [
                                    "ItemRef" => [
                                        "value" => $item->product->load('qbproduct')->qbproduct->qb_product_id
                                    ],
                                    "UnitPrice" => (float) $item->price,
                                    "Qty" => $item->quantity
                                ]
                    ]);
                }

                if (!empty($lineObj)) {
                    $qbTermId = self::check_and_create_terms($account_id, $order->payment_terms);
                    $BillAddr = null;
                    if (!empty($order->toAddress)) {
                        $BillAddr = [
                            "Line1" => $order->toAddress->street1,
                            "Line2" => $order->toAddress->street2,
                            "City" => $order->toAddress->city,
                            "Country" => $order->toAddress->country,
                            "CountrySubDivisionCode" => $order->toAddress->state,
                            "PostalCode" => $order->toAddress->zip
                        ];
                    }
                    $invoiceObj = [
                        "Line" => $lineObj,
                        "DocNumber" => $order->vendor_order_num,
                        "TxnDate" => $order->delivery_due_at->toDateString(),
                        "CustomerRef" => [
                            "value" => $qbCustomerId
                        ],
                        "BillAddr" => $BillAddr,
                        "BillEmail" => ["Address" => $order->customer->email],
                        "SalesTermRef" => [
                            "value" => $qbTermId
                        ],
                        "PrivateNote" => $order->internal_notes,
                        "CustomerMemo" => [
                            "value" => $order->notes
                        ],
                        "DueDate" => $order->payment_due_at->toDateString()
                    ];

                    return $invoiceObj;
                }
            }
        } catch (\Exception $ex) {
            throw $ex;
        }
    }

    /**
     * function will prepare an data object which will be used to Create or Update Payment
     * @param $account_id
     * @param $payment
     * @return array
     * @throws \Exception
     */
    public static function prepare_invoice_payment_object($account_id, $payment)
    {
        try {
            $qbIntegrationItem = \Inventry\Platform\Models\Account::find($account_id)->integrationItems()
                ->where('type', 'quickbooks')
                ->where('status', 'active')
                ->first();

            if (empty($qbIntegrationItem)) {
                throw new \Exception('Quickbooks integration is not available.');
            }

            //Check for Customer and Create if not exists
            $customer = \Inventry\Platform\Models\Account::find($account_id)->customers()
                    ->with('defaultAddress', 'addresses', 'people')
                    ->where('related_account_id', '=', $payment->customer_account_id)
                    ->getQuery()
                    ->first();

            $qbCustomerId = self::check_and_create_customer($account_id, $customer);

            //Check and Create Payment Method
            $paymentMethod = ucwords(str_replace('_', ' ', $payment->method));
            $qbPaymentMethodId = self::check_and_create_methods($account_id, $paymentMethod);

            //Create Payment
            $Line = [];
            $CreditLines = [];
            foreach ($payment->items ?: [] as $paymentItem) {
                if (!empty($paymentItem->order->qborder)) {
                    $Line[] = [
                        "Amount" => (float) $paymentItem->amount,
                        "LinkedTxn" => [
                            [
                                "TxnId" => $paymentItem->order->qborder->qb_order_id,
                                "TxnType" => "Invoice"
                            ]
                        ]
                    ];
                }

                if (!empty($paymentItem->credit) && !empty($paymentItem->credit->qbcredit)) {
                    $QBCreditId = $paymentItem->credit->qbcredit->qb_credit_id;
                    if (!isset($CreditLines[$QBCreditId])) {
                        $CreditLines[$QBCreditId]["Amount"] = $paymentItem->amount;
                    } else {
                        $CreditLines[$QBCreditId]["Amount"] += $paymentItem->amount;
                    }
                }
            }

            foreach ($CreditLines ?: [] as $key => $creditItem) {
                $Line[] = [
                    "Amount" => (float) round($creditItem['Amount'], 2),
                    "LinkedTxn" => [
                        [
                            "TxnId" => $key,
                            "TxnType" => "CreditMemo"
                        ]
                    ]
                ];
            }

            //Process Customer Fee
            if (!empty($payment->customer_fee) && $payment->customer_fee > 0) {
                $feeInvoiceId = self::check_and_create_customer_fee_invoice($account_id, $payment, $qbCustomerId);
                if (!empty($feeInvoiceId)) {
                    $Line[] = [
                        "Amount" => (float) $payment->customer_fee,
                        "LinkedTxn" => [
                            [
                                "TxnId" => $feeInvoiceId,
                                "TxnType" => "Invoice"
                            ]
                        ]
                    ];
                }
            }
            //Process Customer Fee

            $DepositToAccountRef = "";
            if (array_key_exists('billpayment', $qbIntegrationItem->metadata()['accounts']) && $qbIntegrationItem->metadata()['accounts']['billpayment']) {
                $DepositToAccountRef = [
                    $qbIntegrationItem->metadata()['accounts']['billpayment']
                ];
            }

            if (!empty($Line)) {
                return [
                    "CustomerRef" => [
                        "value" => $qbCustomerId
                    ],
                    "TxnDate" => $payment->paid_at->toDateString(),
                    "PrivateNote" => $payment->internal_notes,
                    "PaymentMethodRef" => [
                        "value" => $qbPaymentMethodId
                    ],
                    "PaymentRefNum" => $payment->ref_num,
                    "TotalAmt" => $payment->amount + ($payment->customer_fee ? $payment->customer_fee : 0),
                    "Line" => $Line,
                    "DepositToAccountRef" => $DepositToAccountRef
                ];
            }


            throw new \Exception('Payment is not associated with any sync orders or credits.');
        } catch (\Exception $ex) {
            throw $ex;
        }
    }

    /**
     * function will check for existing payment method in database, if not found then it will query in to Quickbooks,
     * and if still not found then it will create an entry for Term at Quickbooks
     * @param $account_id
     * @param $paymentMethod
     * @return mixed
     */
    public static function check_and_create_methods($account_id, $paymentMethod)
    {

        $checkMethod = QbPaymentMethod::where('payment_method', $paymentMethod)->where('account_id', $account_id)->first();

        if (!empty($checkMethod)) {
            return $checkMethod->qb_payment_method_id;
        }

        $methodData = self::query_entities($account_id, "PaymentMethod", "Name = '" . addslashes($paymentMethod) . "'");

        if (!empty($methodData = reset($methodData))) {
            $qb_method_id = $methodData->Id;
        } else {
            $methodObj = new IPPPaymentMethod();
            $methodObj->Name = $paymentMethod;
            if ($paymentMethod == 'Credit Card') {
                $methodObj->Type = 'CREDIT_CARD';
            } else {
                $methodObj->Type = 'NON_CREDIT_CARD';
            }

            $entityObj = self::create_entities($account_id, ["method" => $methodObj], true);

            if (empty($entityObj = reset($entityObj))) {
                throw new \Exception('Payment method not created in Quickbooks.');
            }

            $qb_method_id = $entityObj->Id;
        }

        $qbMethod = new QbPaymentMethod();
        $qbMethod->account_id = $account_id;
        $qbMethod->payment_method = $paymentMethod;
        $qbMethod->qb_payment_method_id = $qb_method_id;
        $qbMethod->save();

        return $qb_method_id;
    }

    public static function check_and_delete_payment($account_id, $payment_id)
    {
        try {
            // retrieving the bill from Quickbooks
            $QbPayments = self::query_entities($account_id, "Payment", "Id IN ('{$payment_id}')");

            if (!empty($QbPayments)) {
                $QbPayment = $QbPayments[0];
            }
            if (empty($QbPayment)) {
                throw new \Exception('Payment not found in Quickbooks.');
            }
            $ds = self::ds($account_id);
            // deleting the invoice from Quickbooks
            $deletePayment = $ds->Delete($QbPayment);
            if (!empty($deletePayment)) {
                return true;
            }
            throw new \Exception("Payment couldn't be deleted in Quickbooks.");
        } catch (\Exception $ex) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'DELETE INVOICE PAYMENT => Entity #' . $payment_id, $ex->getMessage());
            throw $ex;
        }
    }

    public static function check_and_void_payout($account_id, $payment)
    {
        try {
            $ObjQbPayment = QbPayment::where(['payment_id' => $payment->id])->first();
            if (empty($ObjQbPayment)) {
                throw new \Exception('Quickbooks payment id not found.');
            }

            $QBPayments = self::query_entities($account_id, "BillPayment", "Id IN ('{$ObjQbPayment->qb_payment_id}')");

            if (!empty($QBPayments)) {
                $QbPayment = $QBPayments[0];
            }
            if (empty($QbPayment)) {
                throw new \Exception('Payment not found in Quickbooks.');
            }

            $ds = self::ds($account_id);

            $voidPayment = $ds->Delete($QbPayment);

            if (!empty($voidPayment)) {
                return true;
            }
            throw new \Exception("Payment couldn't be voided in Quickbooks.");
        } catch (\Exception $ex) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'VOID BILL PAYMENT => Entity #' . $payment->id, $ex->getMessage());
            throw $ex;
        }
    }

    public static function check_and_void_payment($account_id, $payment)
    {
        try {
            $ObjQbPayment = QbPayment::where(['payment_id' => $payment->id])->first();
            if (empty($ObjQbPayment)) {
                throw new \Exception('Quickbooks payment id not found.');
            }

            $QBPayments = self::query_entities($account_id, "Payment", "Id IN ('{$ObjQbPayment->qb_payment_id}')");

            if (!empty($QBPayments)) {
                $QbPayment = $QBPayments[0];
            }
            if (empty($QbPayment)) {
                throw new \Exception('Payment not found in Quickbooks.');
            }

            $ds = self::ds($account_id);
            $voidPayment = $ds->Void($QbPayment);
            if (!empty($voidPayment)) {
                // void Fee invoice from Quickbooks
                if (!empty($payment->qb_fee_invoice_id)) {
                    self::check_and_delete_invoice($account_id, $payment->qb_fee_invoice_id);
                }
                return true;
            }
            throw new \Exception("Payment couldn't be voided in Quickbooks.");
        } catch (\Exception $ex) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'VOID INVOICE PAYMENT => Entity #' . $payment->id, $ex->getMessage());
            throw $ex;
        }
    }

    public static function prepare_billpayment_object($account_id, $payment)
    {
        try {
            $qbIntegrationItem = \Inventry\Platform\Models\Account::find($account_id)->integrationItems()
                    ->where('type', 'quickbooks')
                    ->where('status', 'active')
                    ->first();

            if (empty($qbIntegrationItem)) {
                throw new \Exception('Quickbooks integration is not available.');
            }

            $vendor = \Inventry\Platform\Models\Account::find($account_id)->vendors()->where('related_account_id', $payment->vendor_account_id)
                    ->with('defaultAddress', 'people')
                    ->getQuery()
                    ->first();

            $qbVendorId = self::check_and_create_vendor($account_id, $vendor);

            $txns = $CreditLines = $BillLines = [];

            foreach ($payment->items ?: [] as $item) {
                if (!empty($item->order->qborder)) {
                    $QbBillId = $item->order->qborder->qb_order_id;
                    if (!isset($BillLines[$QbBillId])) {
                        $BillLines[$QbBillId]["Amount"] = (float)$item->amount;
                    } else {
                        $BillLines[$QbBillId]["Amount"] += (float)$item->amount;
                    }
                }

                if (!empty($item->credit) && !empty($item->credit->qbcredit)) {
                    if (!empty($item->credit->qbcredit)) {
                        $QBCreditId = $item->credit->qbcredit->qb_credit_id;
                        if (!isset($CreditLines[$QBCreditId])) {
                            $CreditLines[$QBCreditId]["Amount"] = (float)$item->amount;
                        } else {
                            $CreditLines[$QBCreditId]["Amount"] += (float)$item->amount;
                        }
                    }
                }
            }

            foreach ($BillLines ?: [] as $key => $paymentLine) {
                $txns[] = [
                    "Amount" => (float)round($paymentLine['Amount'], 2),
                    "LinkedTxn" => [
                        [
                            "TxnId" => $key,
                            "TxnType" => "Bill"
                        ]
                    ]
                ];
            }

            foreach ($CreditLines ?: [] as $key => $creditItem) {
                $txns[] = [
                    "Amount" => (float)round($creditItem['Amount'], 2),
                    "LinkedTxn" => [
                        [
                            "TxnId" => $key,
                            "TxnType" => "VendorCredit"
                        ]
                    ]
                ];
            }

            if (empty($qbIntegrationItem->metadata()['accounts']['billpayment'])) {
                throw new \Exception("Bank Account is required for processing Bill payments.");
            }

            if (!empty($txns)) {
                $objCheckPayment = [
                    "BankAccountRef" => $qbIntegrationItem->metadata()['accounts']['billpayment']
                ];

                if (in_array($payment->method, ['ach', 'cash', 'credit_card', 'wire_transfer'])) {
                    $objCheckPayment["PrintStatus"] = "NotSet";
                }

                $syncObj = [
                    "VendorRef" => [
                        "name" => $vendor->name,
                        "value" => $qbVendorId
                    ],
                    "TxnDate" => $payment->paid_at->toDateString(),
                    "PayType" => "Check",
                    "CheckPayment" => $objCheckPayment,
                    "TotalAmt" => (float) $payment->amount,
                    "PrivateNote" => $payment->internal_notes,
                    "Line" => $txns
                ];

                if (empty($payment->check_no)) {
                    $syncObj['DocNumber'] = $payment->ref_num;
                }

                return $syncObj;
            }

            throw new \Exception('Payment is not associated with any sync orders or credits.');
        } catch (\Exception $ex) {
            throw $ex;
        }
    }

    public static function check_and_delete_billpayment($account_id, $billpayment_id)
    {
        try {
            // retrieving the bill from Quickbooks
            $QbBillPayment = self::query_entities($account_id, "BillPayment", "Id IN ('{$billpayment_id}')");

            if (empty($QbBillPayment = reset($QbBillPayment))) {
                throw new \Exception('Bill payment not found in Quickbooks.');
            }

            $ds = self::ds($account_id);

            // deleting the bill from Quickbooks
            if (!empty($ds->Delete($QbBillPayment))) {
                return true;
            }

            throw new \Exception("Bill payment couldn't be deleted in Quickbooks.");
        } catch (\Exception $ex) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'DELETE BILL PAYMENT => Entity #' . $billpayment_id, $ex->getMessage());
            throw $ex;
        }
    }

    /**
     * function will prepare the object to create or update customer credit memo in Quickbooks.
     * @param $account_id
     * @param $creditMemo
     * @return array
     * @throws \Exception
     */
    public static function prepare_customer_credit_memo_object($account_id, $creditMemo)
    {
        try {
            //Check for Customer and Create if not exists
            $customer = \Inventry\Platform\Models\Account::find($account_id)->customers()
                    ->with('defaultAddress', 'addresses', 'people')
                    ->where('related_account_id', '=', $creditMemo->customer_account_id)
                    ->getQuery()
                    ->first();
            if (!$customer) {
                throw new \Exception("Customer not found in Inventry");
            }

            $qbCustomerId = self::check_and_create_customer($account_id, $customer);


            //Create Credit Memo

            $products = [];

            // preparing the product array for processing in Quickbooks
            foreach ($creditMemo->items ?: [] as $item) {
                $products[] = $item->orderItem->product;
            }


            // creating products in Quickbooks if not available
            self::check_and_create_products($account_id, $products);

            // initializing the line object to be used for bill creation
            $lineObj = [];

            // creating Line Object
            foreach ($creditMemo->items ?: [] as $index => $item) {
                $lineObj[] = Line::create([
                            "Id" => $index + 1,
                            "Amount" => round((float) $item->rate * $item->quantity, 2),
                            "DetailType" => "SalesItemLineDetail",
                            "SalesItemLineDetail" => [
                                "ItemRef" => [
                                    "value" => $item->orderItem->product->load('qbproduct')->qbproduct->qb_product_id
                                ],
                                "UnitPrice" => (float) $item->rate,
                                "Qty" => $item->quantity
                            ]
                ]);
            }

            $BillAddr = null;
            if ($creditMemo->billingAddress) {
                $BillAddr = [
                    "Line1" => $creditMemo->billingAddress->street1,
                    "Line2" => $creditMemo->billingAddress->street2,
                    "City" => $creditMemo->billingAddress->city,
                    "Country" => $creditMemo->billingAddress->country,
                    "CountrySubDivisionCode" => $creditMemo->billingAddress->state,
                    "PostalCode" => $creditMemo->billingAddress->zip
                ];
            }

            return [
                "TxnDate" => $creditMemo->credited_at,
                "PrivateNote" => $creditMemo->internal_notes,
                "CustomerMemo" => [
                    "value" => $creditMemo->external_notes
                ],
                "CustomerRef" => $qbCustomerId,
                "BillAddr" => $BillAddr,
                "TotalAmt" => $creditMemo->balance,
                "Line" => $lineObj
            ];
            throw new \Exception('Something went wrong while creating or updating Credit Memo');
        } catch (\Exception $ex) {
            throw $ex;
        }
    }

    /**
     * function will check for customer credit memo entry in quickbooks and if found then it will delete it
     * @param $account_id
     * @param $credit_id
     * @return bool
     * @throws \Exception
     */
    public static function check_and_delete_customer_credit($account_id, $credit_id)
    {
        try {
            // retrieving the credit memo from Quickbooks
            $QbCredit = self::query_entities($account_id, "CreditMemo", "Id IN ('{$credit_id}')");

            if (empty($QbCredit = reset($QbCredit))) {
                throw new \Exception('CreditMemo not found in Quickbooks.');
            }

            $ds = self::ds($account_id);

            // deleting the bill from Quickbooks
            if (!empty($ds->Delete($QbCredit))) {
                return true;
            }

            throw new \Exception("CreditMemo couldn't be deleted in Quickbooks.");
        } catch (\Exception $ex) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'DELETE CUSTOMER CREDIT => Entity #' . $credit_id, $ex->getMessage());
            throw $ex;
        }
    }

    public static function prepare_vendor_credit_memo_object($account_id, $credit)
    {
        try {
            // getting vendor details
            $vendor = \Inventry\Platform\Models\Account::find($account_id)->vendors()->where('related_account_id', $credit->vendor_account_id)
                    ->with('defaultAddress', 'people')
                    ->getQuery()
                    ->first();

            if (empty($vendor)) {
                throw new \Exception('Vendor not found.');
            }

            $qbVendorId = self::check_and_create_vendor($account_id, $vendor);

            $products = [];

            // preparing the product array for processing in Quickbooks
            foreach ($credit->items ?: [] as $item) {
                $products[] = $item->product;
            }


            // creating products in Quickbooks if not available
            self::check_and_create_products($account_id, $products);

            // initializing the line object to be used for bill creation
            $lineObj = [];

            $qb_integration_item = \Inventry\Platform\Models\Account::find($account_id)
                ->integrationItems
                ->where('type', 'quickbooks')
                ->where('status', 'active')
                ->first();

            // creating Line Object
            $lineObj[] =  Line::create([
                "Amount" => $credit->amount,
                "DetailType" => "AccountBasedExpenseLineDetail",
                "AccountBasedExpenseLineDetail" => [
                    "AccountRef" => [
                        "value" => $qb_integration_item->metadata()['accounts']['discount']['value'],
                        "name" => $qb_integration_item->metadata()['accounts']['discount']['name']
                    ]
                ]
            ]);

            $vendorDetail = (object)[];
            $vendorDetail->value = $qbVendorId;
            $vendorDetail->name = $vendor->name;

            return [
                "TxnDate" => $credit->credited_at->toDateString(),
                "PrivateNote" => $credit->internal_notes,
                "Line" => $lineObj,
                "VendorRef" => [
                    "name" => $vendor->name,
                    "value" => $qbVendorId
                ],
                "TotalAmt" => $credit->amount,
                "DocNumber" => $credit->id
            ];
        } catch (\Exception $ex) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'VENDOR CREDIT OBJECT => Entity #' . $credit->id . ', Line : ' . $ex->getLine(), $ex->getMessage());
            throw $ex;
        }
    }

    public static function check_and_delete_vendor_credit($account_id, $vendorcredit_id)
    {
        try {
            // retrieving the credit memo from Quickbooks
            $QbCredit = self::query_entities($account_id, "VendorCredit", "Id IN ('{$vendorcredit_id}')");

            if (empty($QbCredit = reset($QbCredit))) {
                throw new \Exception('VendorCredit not found in Quickbooks.');
            }

            $ds = self::ds($account_id);

            // deleting the bill from Quickbooks
            if (!empty($ds->Delete($QbCredit))) {
                return true;
            }

            throw new \Exception("VendorCredit not deleted in Quickbooks.");
        } catch (\Exception $ex) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'DELETE VENDOR CREDIT => Entity #' . $vendorcredit_id, $ex->getMessage());
            throw $ex;
        }
    }

    /**
     * function will check for Product chart of accounts in Quickbooks and if not found then it will create it in Quickbooks.
     * @param $account_id
     * @param $productGroup
     * @return array
     */
    public static function check_and_create_product_chart_of_accounts($account_id, $productGroup)
    {
        try {
            // getting parent account setting of product
            $qb_integration_item = \Inventry\Platform\Models\Account::find($account_id)
                    ->integrationItems
                    ->where('type', 'quickbooks')
                    ->where('status', 'active')
                    ->first();

            if (empty($qb_integration_item)) {
                throw new \Exception('Quickbooks integration is not available.');
            }

            $accountsToCreate = [];
            $qbProdCOA = [];
            // getting product account mapping details
            $qbChartOfAccounts = $productGroup->qbChartOfAccounts;

            $accounts = [];

            // preparing the list of accounts needed in order to sync the product in Quickbooks
            $accountsToCreate = [
                "inventory_{$productGroup->id}" => Account::create([
                    'Name' => "Inventory - ({$productGroup->name})",
                    'FullyQualifiedName' => "Inventory - ({$productGroup->name})",
                    'SubAccount' => true,
                    'ParentRef' => [
                        'value' => $qb_integration_item->metadata()['accounts']['inventory']['value']
                    ],
                    'AccountType' => config('quickbooks.accounts.inventory.type'),
                    'AccountSubType' => config('quickbooks.accounts.inventory.sub_type'),
                    'Description' => config('quickbooks.accounts.inventory.description'),
                ]),
                "income_{$productGroup->id}" => Account::create([
                    'Name' => "Sales - ({$productGroup->name})",
                    'FullyQualifiedName' => "Sales - ({$productGroup->name})",
                    'SubAccount' => true,
                    'ParentRef' => [
                        'value' => $qb_integration_item->metadata()['accounts']['income']['value']
                    ],
                    'AccountType' => config('quickbooks.accounts.income.type'),
                    'AccountSubType' => config('quickbooks.accounts.income.sub_type'),
                    'Description' => config('quickbooks.accounts.income.description'),
                ]),
                "expense_{$productGroup->id}" => Account::create([
                    'Name' => "Cost of Goods Sold - ({$productGroup->name})",
                    'FullyQualifiedName' => "Cost of Goods Sold - ({$productGroup->name})",
                    'SubAccount' => true,
                    'ParentRef' => [
                        'value' => $qb_integration_item->metadata()['accounts']['expense']['value']
                    ],
                    'AccountType' => config('quickbooks.accounts.expense.type'),
                    'AccountSubType' => config('quickbooks.accounts.expense.sub_type'),
                    'Description' => config('quickbooks.accounts.expense.description'),
                ]),
            ];

            // unsetting the account which mapping entry exist in DB
            foreach ($qbChartOfAccounts ?: [] as $qbCOA) {
                $qbProdCOA[] = $qbCOA->qb_product_account_id;
                unset($accountsToCreate["{$qbCOA->type}_{$productGroup->id}"]);
            }

            if (!empty($accountsToCreate)) {
                // getting product accounts from Quickbooks by Name
                $QbAccounts = self::query_entities($account_id, 'Account', "Name IN ('" . implode("', '", array_map(function ($element) {
                    return addslashes($element);
                }, array_column(json_decode(json_encode($accountsToCreate), true), "Name"))) . "') AND Active = true");

                // unsetting the account which are already exist in Quickbooks
                foreach ($QbAccounts ?: [] as $QbAccount) {
                    if ($QbAccount->Name == "Inventory - ({$productGroup->name})") {
                        $accounts["inventory_{$productGroup->id}"] = $QbAccount;
                        unset($accountsToCreate["inventory_{$productGroup->id}"]);
                    }

                    if ($QbAccount->Name == "Sales - ({$productGroup->name})") {
                        $accounts["income_{$productGroup->id}"] = $QbAccount;
                        unset($accountsToCreate["income_{$productGroup->id}"]);
                    }

                    if ($QbAccount->Name == "Cost of Goods Sold - ({$productGroup->name})") {
                        $accounts["expense_{$productGroup->id}"] = $QbAccount;
                        unset($accountsToCreate["expense_{$productGroup->id}"]);
                    }
                }

                // creating remaining account in Quickbooks
                if (!empty($accountsToCreate)) {
                    $accounts = array_merge($accounts, self::create_entities($account_id, $accountsToCreate, true));
                }

                // creating the mapping entry in DB
                foreach ($accounts ?: [] as $key => $account) {
                    $arr = explode('_', $key);

                    $QbChartOfAccount = new QbChartOfAccount();
                    $QbChartOfAccount->type = $arr[0];
                    $QbChartOfAccount->product_group_id = $arr[1];
                    $QbChartOfAccount->qb_product_account_id = $account->Id;
                    $QbChartOfAccount->save();
                }
            } else {
                return $qbProdCOA;
            }
        } catch (\Exception $ex) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'CREATE PRODUCT ACCOUNTS', $ex->getMessage(), $productGroup);
            throw $ex;
        }
    }

    /**
     * function will check for existing refund in database,
     * and if not found then it will create an entry for refund at Quickbooks
     * @param $account_id
     * @param $refund
     * @return mixed
     */
    public static function check_and_create_refunds($account_id, $refund)
    {
        try {
            $checkRefund = $refund->qbrefund;

            if (!$checkRefund) {
                $qbIntegrationItem = \Inventry\Platform\Models\Account::find($account_id)->integrationItems()
                    ->where('type', 'quickbooks')
                    ->where('status', 'active')
                    ->first();

                if (empty($qbIntegrationItem)) {
                    throw new \Exception('Quickbooks integration is not available.');
                }

                //Check for Customer and Create if not exists
                $customer = \Inventry\Platform\Models\Account::find($account_id)->customers()
                    ->with('defaultAddress', 'addresses', 'people')
                    ->where('related_account_id', '=', $refund->payment->customer_account_id)
                    ->getQuery()
                    ->first();

                $qbCustomerId = self::check_and_create_customer($account_id, $customer);
                $DepositToAccountRef = $qbIntegrationItem->metadata()['accounts']['billpayment'];

                if (empty($DepositToAccountRef)) {
                    throw new \Exception("Bank Account is required for processing payment refund.");
                }

                $paymentMethod = ucwords(str_replace('_', ' ', $refund->payment->method));
                $qbPaymentMethod = QbPaymentMethod::where('payment_method', $paymentMethod)->where('account_id', $account_id)->first();

                $LineItemRef = self::check_and_create_sales_service($account_id, $qbIntegrationItem);
                $lineObj = [];
                foreach ($refund->items as $refundItem) {
                    $lineObj[] = Line::create([
                        "Amount" => (float) $refundItem->amount,
                        "DetailType" => "SalesItemLineDetail",
                        "SalesItemLineDetail" => [
                            "ItemRef" => [
                                "value" => $LineItemRef
                            ],
                        ],
                        "Description" => $refundItem->order->vendor_order_num
                    ]);
                }
                if (!empty($refund->convienence_fee) && $refund->convienence_fee > 0) {
                    $lineObj[] = Line::create([
                        "Amount" => (float) $refund->convienence_fee,
                        "DetailType" => "SalesItemLineDetail",
                        "SalesItemLineDetail" => [
                            "ItemRef" => [
                                "value" => $LineItemRef
                            ],
                        ],
                        "Description" => "Convenience Fee"
                    ]);
                }

                $refundObject = [
                    "Line" => $lineObj,
                    "DepositToAccountRef" => $DepositToAccountRef,
                    "CustomerRef" => [
                        "value" => $qbCustomerId
                    ],
                    "PaymentMethodRef" => [
                        "value" => $qbPaymentMethod->qb_payment_method_id
                    ],
                ];

                $qbRefundRes = self::create_entities($account_id, ["refund_receipt" => RefundReceipt::create($refundObject)], true);

                if (empty($qbRefundReceipt = reset($qbRefundRes))) {
                    throw new \Exception('Refund Receipt not created in Quickbooks.');
                }

                $qbRefund = new QbRefund();
                $qbRefund->refund_id = $refund->id;
                $qbRefund->qb_refund_id = $qbRefundReceipt->Id;
                $qbRefund->save();

                return $qbRefundReceipt->Id;
            } else {
                return $checkRefund->qb_refund_id;
            }
        } catch (\Exception $ex) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'CREATE REFUND', $ex->getMessage(), $refund);
            throw $ex;
        }
    }

    /**
     * function will check for existing sales service in QB,
     * and if not found then it will create an entry for sales service at Quickbooks
     * @param $account_id
     * @param $qbIntegrationItem
     * @return $QbSalesService->Id
     */
    public static function check_and_create_sales_service($account_id, $qbIntegrationItem)
    {
        try {
            $QbService = self::query_entities($account_id, 'Item', "Name = 'Sales' AND Type='Service' AND Active = true");

            if (empty($QbSalesService = reset($QbService))) {
                $IncomeAccountRef = $qbIntegrationItem->metadata()['accounts']['income'];
                if (empty($IncomeAccountRef)) {
                    throw new \Exception("Income Account is required for creating Service.");
                }

                $serviceToCreate = Item::create([
                    "Name" => "Sales",
                    "Type" => "Service",
                    "IncomeAccountRef" => $IncomeAccountRef
                ]);

                $qbServiceRes = self::create_entities($account_id, ["item" => $serviceToCreate], true);

                if (empty($ObjServiceRes = reset($qbServiceRes))) {
                    throw new \Exception('Service not created in Quickbooks.');
                }

                return $ObjServiceRes->Id;
            } else {
                return $QbSalesService->Id;
            }
        } catch (\Exception $ex) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'CREATE SALES SERVICE', $ex->getMessage());
            throw $ex;
        }
    }

    /**
     * function that will create an Invoice for payment customer Fee
     * @param $account_id
     * @param $payment
     * @param $qbCustomerId
     * @return $QbInvoice->Id
     */

    public static function check_and_create_customer_fee_invoice($account_id, $payment, $qbCustomerId)
    {
        try {
            $feeInvoiceId = $payment->qb_fee_invoice_id;
            if (empty($feeInvoiceId)) {
                $qbIntegrationItem = \Inventry\Platform\Models\Account::find($account_id)->integrationItems()
                    ->where('type', 'quickbooks')
                    ->where('status', 'active')
                    ->first();

                if (empty($qbIntegrationItem)) {
                    throw new \Exception('Quickbooks integration is not available.');
                }

                $lineObj = [];
                $LineItemRef = self::check_and_create_sales_service($account_id, $qbIntegrationItem);
                $lineObj[] = Line::create([
                    "Amount" => (float)$payment->customer_fee,
                    "DetailType" => "SalesItemLineDetail",
                    "Description" => 'Customer Fee',
                    "SalesItemLineDetail" => [
                        "ItemRef" => [
                            "value" => $LineItemRef
                        ],
                        "UnitPrice" => (float)$payment->customer_fee,
                        "Qty" => 1
                    ]
                ]);
                if (!empty($lineObj)) {
                    $customFieldObj = [];
                    $customField = new IPPCustomField();
                    $customField->DefinitionId = 2;
                    $customField->Type = 'StringType';
                    $customField->Name = 'Custom 1';
                    $customField->StringValue = 'Inventry Payment #' . $payment->id;
                    $customFieldObj[] = $customField;

                    $qbTermId = self::check_and_create_terms($account_id, 'due_on_receipt');

                    $order = $payment->items[0]->order;

                    $BillAddr = null;
                    if (!empty($order->toAddress)) {
                        $BillAddr = [
                            "Line1" => $order->toAddress->street1,
                            "Line2" => $order->toAddress->street2,
                            "City" => $order->toAddress->city,
                            "Country" => $order->toAddress->country,
                            "CountrySubDivisionCode" => $order->toAddress->state,
                            "PostalCode" => $order->toAddress->zip
                        ];
                    }
                    $invoiceObj = [
                        "CustomField" => $customFieldObj,
                        "Line" => $lineObj,
                        "TxnDate" => $payment->paid_at->toDateString(),
                        "CustomerRef" => [
                            "value" => $qbCustomerId
                        ],
                        "BillAddr" => $BillAddr,
                        "BillEmail" => ["Address" => $order->customer->email],
                        "SalesTermRef" => [
                            "value" => $qbTermId
                        ],
                        "DueDate" => $payment->paid_at->toDateString(),
                    ];

                    $qbInvoice = Quickbooks::create_entities($account_id, ["invoice" => Invoice::create($invoiceObj)], true);

                    if (!empty($qbInvoice = reset($qbInvoice))) {
                        $payment->qb_fee_invoice_id = $qbInvoice->Id;
                        $payment->save();
                        $feeInvoiceId = $qbInvoice->Id;
                        return $feeInvoiceId;
                    }
                    throw new \Exception('Fee Invoice not created in Quickbooks.');
                }
            } else {
                return $feeInvoiceId;
            }
        } catch (\Exception $ex) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'CREATE CUSTOMER FEE INVOICE', $ex->getMessage(), $payment);
            throw $ex;
        }
    }

    public static function create_chart_of_account($account_id, $chartOfAccount)
    {
        try {
            $parentRef = '';
            if ($chartOfAccount->is_sub_account && $chartOfAccount->parent_account_id) {
                $parentRef = ChartOfAccount::find($chartOfAccount->parent_account_id)->qb_account_id;
            }

            $entityObj = Account::create([
                'Name' => $chartOfAccount->name,
                'FullyQualifiedName' => $chartOfAccount->name,
                'AccountType' => $chartOfAccount->account_type,
                'AccountSubType' => $chartOfAccount->account_sub_type,
                'SubAccount' => $chartOfAccount->is_sub_account,
                'ParentRef' => [
                    'value' => $parentRef
                ]
            ]);

            $newAccount = self::create_entities($account_id, ["account" => $entityObj], true);

            if (!empty($newAccount = reset($newAccount))) {
                $chartOfAccount->qb_account_id = $newAccount->Id;
                $chartOfAccount->save();
            }

            return true;
        } catch (\Exception $ex) {
            throw $ex;
        }
    }

    public static function update_chart_of_account($account_id, $chartOfAccount)
    {
        try {
            $QbAccountObj = Quickbooks::query_entities($account_id, 'Account', "Id IN ('" . $chartOfAccount->qb_account_id . "')");

            if (empty($QbAccountObj = reset($QbAccountObj))) {
                throw new \Exception('Chart of Account not found in Quickbooks.');
            }

            $parentRef = '';
            if ($chartOfAccount->is_sub_account && $chartOfAccount->parent_account_id) {
                $parentRef = ChartOfAccount::find($chartOfAccount->parent_account_id)->qb_account_id;
            }

            $entityObj = Account::update($QbAccountObj, [
                'Name' => $chartOfAccount->name,
                'FullyQualifiedName' => $chartOfAccount->name,
                'SubAccount' => $chartOfAccount->is_sub_account == 1 ? true : false,
                'AccountType' => $chartOfAccount->account_type,
                'AccountSubType' => $chartOfAccount->account_sub_type,
                'ParentRef' => [
                    'value' => $parentRef
                ]
            ]);

            $newAccount = self::create_entities($account_id, ["account" => $entityObj], true);

            if (!empty($newAccount = reset($newAccount))) {
                return true;
            }

            throw new \Exception('Chart of Account not updated in Quickbooks.');
        } catch (\Exception $ex) {
            throw $ex;
        }
    }

    public static function delete_chart_of_account($account_id, $chartOfAccount)
    {
        try {
            $QbAccount = Quickbooks::query_entities($account_id, 'Account', "Id IN ('" . $chartOfAccount->qb_account_id . "')");
            if ($QbAccount = reset($QbAccount)) {
                $entityObj = Account::update($QbAccount, [
                    "Active" => false
                ]);

                $newAccount = self::create_entities($account_id, ["account" => $entityObj], true);

                if (!empty($newAccount = reset($newAccount))) {
                    return true;
                }
            }
            throw new \Exception('Chart of Account not updated in Quickbooks.');
        } catch (\Exception $ex) {
            throw $ex;
        }
    }

    public static function prepare_expense_object($account_id, $expense)
    {
        try {
            // vendor details
            $vendor = \Inventry\Platform\Models\Account::find($account_id)->vendors()->where('related_account_id', $expense->vendor_account_id)
                ->with('defaultAddress', 'people')
                ->getQuery()
                ->first();

            if (empty($vendor)) {
                throw new \Exception('Vendor not found.');
            }

            $qbVenderId = self::check_and_create_vendor($account_id, $vendor);

            $paymentMethod = ucwords(str_replace('_', ' ', $expense->method));
            $qbPaymentMethodId = self::check_and_create_methods($account_id, $paymentMethod);

            $lineObj = [];

            //Purchase category details/Line items for Expense/Purchase
            foreach ($expense->items ?: [] as $index => $item) {
                if ($item->chartOfAccount && $item->chartOfAccount->qb_account_id) {
                    $lineObj[] = Line::create([
                        "Amount" => round((float)$item->amount, 2),
                        "Description" => $item->internal_notes ?: null,
                        "DetailType" => "AccountBasedExpenseLineDetail",
                        "AccountBasedExpenseLineDetail" => [
                            "AccountRef" => [
                                "name" => $item->chartOfAccount->name,
                                "value" => $item->chartOfAccount->qb_account_id
                            ],
                        ]
                    ]);
                }
            }

            if (!empty($lineObj)) {
                $expenseObj = [
                    "DocNumber" => $expense->id,
                    "TxnDate" => $expense->paid_at->toDateString(),
                    "Line" => $lineObj,
                    "EntityRef" => [ // Expense vendor
                        "value" => $qbVenderId,
                        "type" => "Vendor"
                    ],
                    "AccountRef" => [ // Payment account in QB Expense
                        "value" => $expense->paymentAccount->qb_account_id,
                    ],
                    "TotalAmt" => (float)$expense->amount,
                    "PrivateNote" => $expense->ref_num,
                    "PaymentType" => "Cash",
                    "PaymentMethodRef" => [ // Payment method
                        "value" => $qbPaymentMethodId
                    ],
                ];

                return $expenseObj;
            }

            return false;
        } catch (\Exception $ex) {
            throw $ex;
        }
    }

    public static function check_and_delete_expense($account_id, $expense_id)
    {
        try {
            $QbExpenses = self::query_entities($account_id, "Purchase", "Id IN ('{$expense_id}')");

            if (!empty($QbExpenses)) {
                $QbPurchase = $QbExpenses[0];
            }

            if (empty($QbPurchase)) {
                throw new \Exception('Expense not found in Quickbooks.');
            }

            $ds = self::ds($account_id);

            // deleting the purchase/expense from Quickbooks
            if (!empty($ds->Delete($QbPurchase))) {
                return true;
            }

            throw new \Exception("Expense couldn't be deleted in Quickbooks.");
        } catch (\Exception $ex) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'DELETE EXPENSE => Entity #' . $expense_id, $ex->getMessage());
            throw $ex;
        }
    }

    public static function updated_bill_payment_from_qb($qb_entity)
    {
        try {
            $qbPayment = QbPayment::where('qb_payment_id', $qb_entity['id'])->whereHas('payment')->first();
            if ($qbPayment) {
                $inv_payment = Payment::find($qbPayment->payment_id);
                if (empty($inv_payment->check_no)) {
                    $paymentObj = self::query_entities($inv_payment->customer_account_id, "BillPayment", "Id IN ('{$qbPayment->qb_payment_id}')");
                    if (empty($paymentObj = reset($paymentObj))) {
                        throw new \Exception('Bill Payment not found in QuickBooks.');
                    }
                    if (!empty($paymentObj->CheckPayment)) {
                        if ($paymentObj->CheckPayment->PrintStatus == "PrintComplete" && !empty($paymentObj->DocNumber)) {
                            $inv_payment->check_no = $paymentObj->DocNumber;
                            //$inv_payment->save();
                        }
                    }
                }

                return true;
            }

            throw new \Exception("Bill payment not found");
        } catch (\Exception $ex) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'UPDATE BILL PAYMENT CHECK NO => Entity #' . $qb_entity['id'], $ex->getMessage());
            throw $ex;
        }
    }

    public static function sync_qb_classes()
    {
        try {
            $qbClasses = self::query_entities(1, 'Class');

            foreach ($qbClasses as $class) {
                QbClass::firstOrCreate([
                    'class_id' => $class->Id,
                    'name' => $class->Name
                ]);
            }

            return true;
        } catch (\Exception $ex) {
            LogsHelper::LogErrorInfo('QUICKBOOKS', 'Sync quickbook classes', $ex->getMessage());
            throw $ex;
        }
    }
}
