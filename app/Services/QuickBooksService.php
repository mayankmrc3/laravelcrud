<?php

namespace app\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use app\Http\Middleware\Quickbooks;
use QuickBooksOnline\API\Facades\Account;
use App\Models\QbSyncQueue;
use App\Models\QbClass;
use App\Repositories\QuickBooksRepository;

class QuickBooksService
{
    /**
     * var $qbRepository
     */
    protected $stockRepository;

    /**
     * QuickBooksRepository constructor
     * @param QuickBooksRepository $qbRepository
     */
    public function __construct(QuickBooksRepository $qbRepository)
    {
        $this->qbRepository = $qbRepository;
    }

    public function qbSuccessConnect(Request $request)
    {
        try {
            $created_on = Carbon::now()->toDateTimeString();

            $state = $request->state;
            $code = $request->code;
            $realmId = $request->realmId;

            //$qbIntegrationItem = QuickBooksRepository::checkQBIntegrationLinked($request->user()->account_id);

            $metadata = [];
            //if (!empty($qbIntegrationItem)) {
               // $metadata = $qbIntegrationItem->metadata();

                /*if ($metadata && ($metadata['qb_company_id'] != $realmId)) {
                    throw new \Exception('Sorry, You are trying to connect to different company. Please connect the same company which was connected before.');
                }
*/
                $qbIntegrationItem->status = 'active';
                $qbIntegrationItem->save();
            }

            $token_json = null;
            $auth_response = null;

            $auth_token = Quickbooks::generateTokenOnAuthorization($code, $realmId);
            $token_json = json_encode(
                array_merge($auth_token, ['created_on' => $created_on])
            );

            if (!empty($token_json)) {
                $integration = $this->qbRepository->createQBIntegration([
                    'account_id' => request()->user()->account_id,
                    'name' => 'Inventry Quickbooks App',
                    'access_key' => config('quickbooks.client_id'),
                    'access_secret' => config('quickbooks.client_secret'),
                    'type' => 'quickbooks',
                ]);

                $integrationItem = $this->qbRepository->createQBIntegrationItem([
                    'name' => 'Quickbooks Integration',
                    'integration_id' => $integration->id,
                    'account_id' => $request->user()->account_id,
                    'type' => 'quickbooks'
                ], [
                    'access_token' => encrypt($token_json),
                    'metadata' => json_encode(array_merge((array)$metadata, ['qb_company_id' => $realmId]))
                ]);

                try {
                    //Create COGS Shipping account
                    $tmp = $this->createQBAccounts($request);
                    $integrationItem->metadata = json_encode(array_merge((array)$integrationItem->metadata(), [
                        "accounts" => $tmp
                    ]));

                    $integrationItem->save();
                } catch (\Exception $ex) {
                    throw new \Exception('Sorry, error in creating accounts in Quickbooks, please make sure the listed accounts doesn\'t exist in Quickbooks.');
                }
            }

            $qbIntegrationItem = $request->user()->account->integrationItems->where('type', 'quickbooks')->first();

            if (!empty($qbIntegrationItem)) {
                $metadata = $qbIntegrationItem->metadata();
            }

            return $metadata;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function createQBAccounts(Request $request)
    {
        try {
            $accounts = ['billpayment' => null];

            $accountsToCreate = [
                'inventory' => Account::create([
                    'Name' => config('quickbooks.accounts.inventory.name'),
                    'FullyQualifiedName' => config('quickbooks.accounts.inventory.name'),
                    'AccountType' => config('quickbooks.accounts.inventory.type'),
                    'AccountSubType' => config('quickbooks.accounts.inventory.sub_type'),
                    'Description' => config('quickbooks.accounts.inventory.description'),
                ]),
                'income' => Account::create([
                    'Name' => config('quickbooks.accounts.income.name'),
                    'FullyQualifiedName' => config('quickbooks.accounts.income.name'),
                    'AccountType' => config('quickbooks.accounts.income.type'),
                    'AccountSubType' => config('quickbooks.accounts.income.sub_type'),
                    'Description' => config('quickbooks.accounts.income.description'),
                ]),
                'expense' => Account::create([
                    'Name' => config('quickbooks.accounts.expense.name'),
                    'FullyQualifiedName' => config('quickbooks.accounts.expense.name'),
                    'AccountType' => config('quickbooks.accounts.expense.type'),
                    'AccountSubType' => config('quickbooks.accounts.expense.sub_type'),
                    'Description' => config('quickbooks.accounts.expense.description'),
                ]),
                'discount' => Account::create([
                    'Name' => config('quickbooks.accounts.discount.name'),
                    'FullyQualifiedName' => config('quickbooks.accounts.discount.name'),
                    'AccountType' => config('quickbooks.accounts.discount.type'),
                    'AccountSubType' => config('quickbooks.accounts.discount.sub_type'),
                    'Description' => config('quickbooks.accounts.discount.description'),
                ])//Add account for PO discounts
            ];

            $checkAccounts = [
                config('quickbooks.accounts.inventory.name'),
                config('quickbooks.accounts.income.name'),
                config('quickbooks.accounts.expense.name'),
                config('quickbooks.accounts.discount.name'),
                config('quickbooks.accounts.freight_cogs.name')
            ];

            $accountsName = "('" . implode("', '", $checkAccounts) . "')";

            $QbAccounts = Quickbooks::query_entities(
                $request->user()->account->id,
                "Account",
                "Name IN " . $accountsName . " AND AccountType IN ('" . config('quickbooks.accounts.inventory.type') . "', '" . config('quickbooks.accounts.income.type') . "', '" . config('quickbooks.accounts.expense.type') . "') AND Active = true"
            );

            $freightCogsAccount = 0;
            foreach ($QbAccounts ?: [] as $QbAccount) {
                if ($QbAccount->Name == config('quickbooks.accounts.inventory.name')) {
                    $accounts['inventory'] = $QbAccount;
                    unset($accountsToCreate['inventory']);
                }

                if ($QbAccount->Name == config('quickbooks.accounts.income.name')) {
                    $accounts['income'] = $QbAccount;
                    unset($accountsToCreate['income']);
                }

                if ($QbAccount->Name == config('quickbooks.accounts.expense.name')) {
                    $accounts['expense'] = $QbAccount;
                    unset($accountsToCreate['expense']);
                }

                if ($QbAccount->Name == config('quickbooks.accounts.discount.name')) {
                    $accounts['discount'] = $QbAccount;
                    unset($accountsToCreate['discount']);
                }

                if ($QbAccount->Name == config('quickbooks.accounts.freight_cogs.name')) {
                    $accounts['freight_cogs'] = $QbAccount;
                    $freightCogsAccount = 1;
                }
            }

            $QbBillPayment = Quickbooks::query_entities($request->user()->account->id, "Account", "AccountType = '" . config('quickbooks.accounts.billpayment.type') . "' AND AccountSubType = '" . config('quickbooks.accounts.billpayment.sub_type') . "' AND Active = true");

            if (count($QbBillPayment) === 1 && $QbBillPayment = reset($QbBillPayment)) {
                $accounts['billpayment'] = $QbBillPayment;
            }

            if (!empty($accountsToCreate)) {
                $accounts = array_merge($accounts, Quickbooks::create_entities($request->user()->account->id, $accountsToCreate, true));
            }
            $tmp = [];

            foreach ($accounts ?: [] as $index => $account) {
                $tmp[$index] = (!empty($account)) ? ['value' => $account->Id, 'name' => $account->FullyQualifiedName] : null;
            }

            //Create COGS Shipping account
            if (!empty($tmp) && isset($tmp['expense']) && !empty($tmp['expense']) && $freightCogsAccount == 0) {
                $ObjCogsAccount = [
                    'freight_cogs' => Account::create([
                        'Name' => config('quickbooks.accounts.freight_cogs.name'),
                        'FullyQualifiedName' => config('quickbooks.accounts.freight_cogs.name'),
                        'SubAccount' => true,
                        'ParentRef' => [
                            'value' => $tmp['expense']['value']
                        ],
                        'AccountType' => config('quickbooks.accounts.freight_cogs.type'),
                        'AccountSubType' => config('quickbooks.accounts.freight_cogs.sub_type'),
                        'Description' => config('quickbooks.accounts.freight_cogs.description'),
                    ])
                ];
                $createCogs = Quickbooks::create_entities(request()->user()->account->id, $ObjCogsAccount, true);

                if (!empty($createCogs)) {
                    $tmp['freight_cogs'] = ['value' => $createCogs['freight_cogs']->Id, 'name' => config('quickbooks.accounts.freight_cogs.name')];
                }
            }

            return $tmp;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function disconnectQBConnect(Request $request)
    {
        //$integrationItem = \Inventry\Platform\Repositories\QuickBooksRepository::checkQBIntegrationEnabled($request->user()->account_id);
        /*if (!empty($integrationItem)) {
            $integrationItem->status = 'disabled';
            $integrationItem->save();
        }*/
        return true;
    }

    public function addQBSyncQueue(Request $request)
    {
        try {
            $accountId = $request->user()->account->id;
            $qbIntegrationItem = \Inventry\Platform\Repositories\QuickBooksRepository::checkQBIntegrationEnabled($accountId);

            if (empty($qbIntegrationItem)) {
                throw new \Exception('Quickbooks integration is not available.');
            }

            $entity = $request->entity;
            $entityId = $request->entity_id;
            $ignore = $request->ignore;

            $where = [
                ['entity', '=', $entity],
                ['entity_id', '=', $entityId],
                ['account_id', '=', $accountId],
                ['status', 'IN', ['E', 'I']]
            ];

            $queueData = $this->qbRepository->findQBSyncQueue($where);

            if (!empty($queueData)) {
                if ($ignore) {
                    $queueData->delete();
                    return true;
                }
                if ($queueData->action == 'create' && $queueData->status == 'I') {
                    return true;
                }

                $queueToDelete = $this->qbRepository->findQBSyncQueue(
                    [
                        ['entity', '=', $entity],
                        ['entity_id', '=', $entityId],
                        ['account_id', '=', $accountId],
                        ['status', 'IN', ['E', 'I']]
                    ]
                )->delete();

                $queueNew = $queueData->replicate();
                $queueNew->attempts = 0;
                $queueNew->status = 'Q';
                $queueNew->exception = null;
                $queueNew->save();
            } else {
                $metadata = $this->getMetaData($request);
                $qbreference = $metadata['qbreference'];
                unset($metadata['qbreference']);
                $metadata = json_encode($metadata);

                $action = $request->action;
                if (!empty($action)) {
                    if ($qbreference) {
                        $action = 'update';
                    }
                }

                if ($action == 'create') {
                    $queueData = $this->qbRepository->findQBSyncQueue([
                        ['entity', '=', $entity],
                        ['entity_id', '=', $entityId],
                        ['account_id', '=', $accountId],
                        ['status', 'IN', ['I', 'S']]
                    ]);

                    if (!empty($queueData)) {
                        return true;
                    }
                }

                $QbSyncQueue = $this->qbRepository->createQBSyncQueue([
                    'account_id' => $accountId,
                    'entity' => $entity,
                    'entity_id' => $entityId,
                    'status' => 'Q'
                ], [
                    'exception' => null,
                    'metadata' => $metadata
                ]);

                $QbSyncQueue->action = $action;
                $QbSyncQueue->save();
            }
            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * function for generating metadata fields for entity
     *
     * @return array
     * @throws \Exception
     */
    public function getMetaData(Request $request)
    {
        try {
            $entity = $request->entity;
            $entityId = $request->entity_id;

            $accountId = $request->user()->account->id;

            switch ($entity) {
                case 'account':
                    $account = $request->user()->account->relatedAccounts()->where('related_account_id', $entityId)->first();
                    $qbaccount = '';
                    if ($account->pivot->type == 'vendor') {
                        $qbaccount = $request->user()->account->qbvendors()->where('related_account_id', $entityId)->first();
                    } else if ($account->pivot->type == 'customer') {
                        $qbaccount = $request->user()->account->qbcustomers()->where('related_account_id', $entityId)->first();
                    }
                    return [
                        "account_id" => $accountId,
                        "type" => $account->pivot->type,
                        "ui_type" => ucfirst($account->pivot->type),
                        "name" => $account->name,
                        "qbreference" => $qbaccount
                    ];
                case 'credit':
                    $credit = \Inventry\Platform\Models\Credit::find($entityId);
                    $qbcredit = $credit->qbcredit;
                    return [
                        "account_id" => $accountId,
                        "type" => $credit->customer_account_id == $accountId ? 'vendorcredit' : 'customercredit',
                        "ui_type" => $credit->customer_account_id == $accountId ? 'Credit Memo(Vendor)' : 'Credit Memo(Customer)',
                        "qbreference" => $qbcredit
                    ];
                case 'order':
                    $order = \Inventry\Platform\Models\Order::find($entityId);
                    $qborder = $order->qborder;
                    return [
                        "account_id" => $accountId,
                        "type" => $order->customer->id == $accountId ? 'bill' : 'invoice',
                        "ui_type" => $order->customer->id == $accountId ? 'Purchase Order' : 'Sales Order',
                        "name" => $order->customer->id == $accountId ? $order->customer_order_num : $order->vendor_order_num,
                        "qbreference" => $qborder
                    ];
                case 'payment':
                    $payment = \Inventry\Platform\Models\Payment::find($entityId);
                    $qbpayment = $payment->qbpayment;
                    return [
                        "account_id" => $accountId,
                        "type" => $payment->customer_account_id == $accountId ? 'billpayment' : 'invoicepayment',
                        "ui_type" => $payment->customer_account_id == $accountId ? 'Payment (Purchase Order)' : 'Payment (Sales Order)',
                        "qbreference" => $qbpayment
                    ];
                case 'product_group':
                    $productGroup = \Inventry\Platform\Models\ProductGroup::find($entityId);
                    $qbproductGroup = $productGroup->qbChartOfAccounts;
                    return [
                        "account_id" => $accountId,
                        "ui_type" => "Product Group",
                        "name" => $productGroup->name,
                        "qbreference" => $qbproductGroup
                    ];
                case 'refund':
                    $refund = \Inventry\Platform\Models\Refund::find($entityId);
                    $qbrefund = $refund->qbrefund;
                    return [
                        "account_id" => $accountId,
                        "type" => 'refund',
                        "ui_type" => 'Refund',
                        "amount" => $refund->amount,
                        "payment_id" => $refund->payment_id,
                        "fee" => $refund->convienence_fee,
                        "qbreference" => $qbrefund
                    ];
                case 'expense':
                    $expense = \Inventry\Platform\Models\Expense::find($entityId);
                    $qbxpense = $expense->qbexpense;
                    return [
                        "account_id" => $accountId,
                        "type" => 'expense',
                        "ui_type" => 'Expense',
                        "qbreference" => $qbxpense
                    ];
                default:
                    return [];
            }
        } catch (\Exception $ex) {
            throw $ex;
        }
    }

    public function getQBAccount(Request $request)
    {
        if (request('type') == 'Class') {
            $account = request('type');
        } else {
            $account = config('quickbooks.accounts.' . request('type'));
        }

        if (empty($account)) {
            throw new \Exception('invalid account type.');
        }

        $accounts = [];

        if ($account == 'Class') {
            $QbAccounts = Quickbooks::query_entities($request->user()->account->id, $account);
        } else {
            $QbAccounts = Quickbooks::query_entities($request->user()->account->id, "Account", "AccountType = '" . $account['type'] . "' AND AccountSubType = '" . $account['sub_type'] . "' AND Active = true");
        }

        foreach ($QbAccounts ?: [] as $QbAccount) {
            $accounts[] = [
                'value' => $QbAccount->Id,
                'name' => $QbAccount->FullyQualifiedName
            ];
        }

        return $accounts;
    }

    public function changeQBAccount(Request $request)
    {
        try {
            $integrationItem = \Inventry\Platform\Repositories\QuickBooksRepository::checkQBIntegrationEnabled($request->user()->account_id);

            if (empty($integrationItem)) {
                throw new \Exception('Quickbooks integration is not available.');
            }

            $metadata = $integrationItem->metadata();

            $account = config('quickbooks.accounts.' . $request->type);

            if (empty($account)) {
                throw new \Exception('invalid account type.');
            }

            $accountInput = json_decode(request('json'));

            if (empty($accountInput)) {
                throw new \Exception('invalid account details.');
            }

            if (empty($accountInput->value) || empty($accountInput->name)) {
                throw new \Exception('invalid account settings.');
            }

            $integrationItem->metadata = json_encode(array_merge((array)$metadata, [
                'accounts' => array_merge($metadata['accounts'], [$request->type => $accountInput])
            ]));

            $integrationItem->save();

            return $accountInput;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function processQBWebhook(Request $request)
    {
        $input = $request->all();
        if (!empty($input['eventNotifications'])) {
            if (isset($input['eventNotifications'][0]) && isset($input['eventNotifications'][0]['dataChangeEvent'])) {
                $entities = $input['eventNotifications'][0]['dataChangeEvent']['entities'];
                $entity = $entities[0];
                if (!empty($entity)) {
                    if ($entity['name'] == 'BillPayment') {
                        Quickbooks::updated_bill_payment_from_qb($entity);
                    }
                }
            }
        }
        return true;
    }

    public function getQBClasses()
    {
        $qbClasses = QbClass::get();

        return $qbClasses;
    }
}
