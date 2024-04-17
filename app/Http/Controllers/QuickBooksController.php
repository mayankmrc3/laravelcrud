<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
//use App\Services\QuickBooksService;
use app\Http\Middleware\Quickbooks;

/**
 * @resource QuickBooks
 * @group QuickBooks
 * @authenticated
 */
class QuickBooksController extends Controller
{
    protected $qbService;

    /*public function __construct(QuickBooksService $qbService)
    {
        parent::__construct();
        $this->qbService = $qbService;
    }*/

    /**
     * Generate authentication url for redirecting user to QuickBooks login page for authentication
     *
     * @return string { 'status': [boolean], 'url': [string] }
     */
    public function OAuth_url()
    {
        try {
            $state = md5(rand());
            session(['state' => $state]);

            $auth_url = config('quickbooks.authorization_endpoint') . "?" . http_build_query([
                        'client_id' => config('quickbooks.client_id'),
                        'scope' => config('quickbooks.scope'),
                        'redirect_uri' => config('quickbooks.redirect_uri'),
                        'response_type' => 'code',
                        'state' => $state
            ]);
            return redirect($auth_url);
            //return response()->json(['status' => true, 'url' => $auth_url]);
        } catch (\Exception $ex) {
            return response()->json(['status' => false, 'error' => $ex->getMessage()], 400);
        }
    }

    /**
     * Complete connection to Quick-books for current account
     *
     * Read parameters from page url query string when redirected after completing QB login process
     * @bodyParam state required Read from url param when redirected after QB login
     * @bodyParam code required
     * @bodyParam realmId required Example: 4620816365067534680
     * @return string { 'status': [boolean], '[error|message]': [string] }
     */
    public function OAuth_response(Request $request)
    {
        DB::beginTransaction();

        try {
            $metadata = $this->qbService->qbSuccessConnect($request);

            DB::commit();
            return response()->json(['status' => true, 'message' => 'Great, Integration is successful.', 'metadata' => $metadata]);
        } catch (\GuzzleHttp\Exception\ClientException $ex) {
            DB::rollBack();
            return response()->json(['status' => false, 'error' => "Sorry, error in generating `access_token`."], 400);
        } catch (\Exception $ex) {
            DB::rollBack();
            return response()->json(['status' => false, 'error' => $ex->getMessage()], 400);
        }
    }

    /**
     * Check if QuickBooks integration is enabled
     */
    public function check_status()
    {
        try {
            //$integrationItem = \Inventry\Platform\Repositories\QuickBooksRepository::checkQBIntegrationEnabled(request()->user()->account_id);
            /*if (empty($integrationItem) || ($integrationItem && !$integrationItem->access_token)) {
                throw new \Exception('Quickbooks integration is not available.');
            }

            Quickbooks::sync_qb_classes();

            return response()->json(['status' => true, 'message' => 'Integration authenticated.', 'metadata' => $integrationItem->metadata()]);*/
        } catch (\Exception $ex) {
            return response()->json(['status' => false, 'error' => $ex->getMessage()]);
        }
    }

    /**
     * Disconnect from QuickBooks
     */
    public function disconnect(Request $request)
    {
        try {
            $this->qbService->disconnectQBConnect($request);
            return response()->json(['status' => true, 'message' => 'Integration unlinked successfully.']);
        } catch (\Exception $ex) {
            return response()->json(['status' => false, 'error' => $ex->getMessage()], 400);
        }
    }

    /**
     * Create queue to sync selected entity
     * @bodyParam entity string required value should be one of `account`, `chart_of_account`, `product`, `product_group`, `order`, `payment`, `credit` or `expense` Example: payment
     * @bodyParam action string required value should be one of `create`, `update` or `delete` Example: update
     * @bodyParam entity_id integer required Example: 134891
     */
    public function quickbooks_queue(Request $request)
    {
        DB::beginTransaction();
        try {
            $this->qbService->addQBSyncQueue($request);

            DB::commit();
            return response()->json(['status' => true, 'message' => 'Success']);
        } catch (\Exception $ex) {
            DB::rollBack();
            return response()->json(['status' => false, 'error' => $ex->getMessage()], 400);
        }
    }

    /**
     * Get QB accounts for configuration
     * @queryParam type required value should be one of `billpayment`, `discount` or `freight_cogs` Example: discount
     */
    public function get_account()
    {
        try {
            $accounts = $this->qbService->getQBAccount(request());

            return ['status' => true, 'accounts' => $accounts];
        } catch (\Exception $ex) {
            return response()->json(['status' => false, 'error' => $ex->getMessage()], 400);
        }
    }

    /**
     * Change QB payment accounts
     * @bodyParam type string required value should be one of `billpayment`, `discount` or `freight_cogs` Example: discount
     * @bodyParam json string required json object of selected account Example: {"value":"219","name":"Discounts"}
     */
    public function set_account(Request $request)
    {
        try {
            $result = $this->qbService->changeQBAccount($request);

            return ['status' => true, 'account' => $result];
        } catch (\Exception $ex) {
            return response()->json(['status' => false, 'error' => $ex->getMessage()], 400);
        }
    }

    /**
     * Read data from QB event via webHook and process entity in DB
     * @hideFromAPIDocumentation
     * @param $request
     * @return \Illuminate\Http\Response
     */
    public function process_qb_webHook(Request $request)
    {
        try {
            $this->qbService->processQBWebhook($request);
            return response()->json(['status' => true, 'message' => 'Bill payment updated successfully']);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function classes(Request $request)
    {
        try {
            $result = $this->qbService->getQBClasses();

            return response()->json(['status' => true, 'data' => $result]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}
