<?php

require_once 'include/utils/utils.php';
require 'vendor/autoload.php';
use QuickBooksOnline\API\DataService\DataService;

function processCode()
{
    global $adb,$site_URL;

    $sql_qb_online_accounts = "SELECT * FROM qb_online_accounts WHERE qbo_account_status = 'active' limit 1";
    $result_qb_online_accounts = $adb->pquery($sql_qb_online_accounts, array());
    
    if($adb->num_rows($result_qb_online_accounts) > 0)
    {
        $qbo_client_id = $adb->query_result($result_qb_online_accounts,0,"qbo_client_id");
        $qbo_client_secret_key = $adb->query_result($result_qb_online_accounts,0,"qbo_client_secret_key");
        $qbo_account_type = $adb->query_result($result_qb_online_accounts,0,"qbo_account_type");
        $qbo_account_type = $qbo_account_type == "sandbox" ? "https://sandbox-quickbooks.api.intuit.com" : "https://quickbooks.api.intuit.com";
    }
    else
    {
        $qbo_client_id = 'ABKXMQcOKYmtPAdQ0odnXmZcv6ra3KPxruvBHc7VfBls6qSunN';
        $qbo_client_secret_key = 'OgaSXXYwMCNvMs6J5l0pdWPHKgDoZW3k6OfVo3wQ';
        $qbo_account_type = "https://sandbox-quickbooks.api.intuit.com";
    }

    $dataService = DataService::Configure(array(
        'auth_mode' => 'oauth2',
        'ClientID' => $qbo_client_id,
        'ClientSecret' =>  $qbo_client_secret_key,
        'RedirectURI' => $site_URL.'/callback.php',
        'scope' => 'com.intuit.quickbooks.accounting openid profile email phone address',
        'baseUrl' => $qbo_account_type
    ));

    $OAuth2LoginHelper = $dataService->getOAuth2LoginHelper();
    $parseUrl = parseAuthRedirectUrl($_SERVER['QUERY_STRING']);

    $accessToken = $OAuth2LoginHelper->exchangeAuthorizationCodeForToken($parseUrl['code'], $parseUrl['realmId']);
    $dataService->updateOAuth2Token($accessToken);
       
    $accessTokenJson = array('token_type' => 'bearer',
        'access_token' => $accessToken->getAccessToken(),
        'refresh_token' => $accessToken->getRefreshToken(),
        'x_refresh_token_expires_in' => $accessToken->getRefreshTokenExpiresAt(),
        'expires_in' => $accessToken->getAccessTokenExpiresAt()
    );
    $dataService->updateOAuth2Token($accessToken);
    $oauthLoginHelper = $dataService -> getOAuth2LoginHelper();
    $CompanyInfo = $dataService->getCompanyInfo();

    // Check If Same compnay ID exists
    $check_sql = "SELECT * FROM fuse5_qbprofile WHERE company_id = ? ";
    $check_result = $adb->pquery($check_sql,array($accessToken->getRealmID()));

    if ($adb->num_rows($check_result) == 0) 
    {

        // Reset default Profile
        $reset_sql = "UPDATE fuse5_qbprofile SET defaulprofile = '0' ";
        $adb->pquery($reset_sql, array());

        $profile_sql = "INSERT INTO fuse5_qbprofile (profilename,defaulprofile,profileqbuser,profilebasedon,access_token,access_token_expires,refresh_token,refresh_token_expires,client_id,client_secret,qbo_sequence_id,company_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ";

        $adb->pquery($profile_sql, array(
                $CompanyInfo->CompanyName,
                '1',
                $accessToken->getRealmID(),
                'location',
                $accessToken->getAccessToken(), 
                $accessToken->getAccessTokenExpiresAt(), 
                $accessToken->getRefreshToken(),
                $accessToken->getRefreshTokenExpiresAt(),
                $accessToken->getClientID(),
                $accessToken->getClientSecret(),
                $CompanyInfo->Id,
                $accessToken->getRealmID(),
        ));
        $last_inserted_profile_id = $adb->get_insert_id();
        // Get distinct profile from QB setting
        $dis_sql = "SELECT DISTINCT(profileid) FROM `fuse5_qbsettings` LIMIT 0,1";
        $dis_result = $adb->pquery($dis_sql, array());

        SetQBToken();
        // Copying Qb setting values based on profile id = 1
        $qb_settings_sql = "INSERT INTO fuse5_qbsettings (profileid, txtidentifier, module,field,value,defaultval,display)
                            SELECT '{$last_inserted_profile_id}', txtidentifier, module,field,value,defaultval,display FROM fuse5_qbsettings WHERE profileid = ?; ";

        $adb->pquery($qb_settings_sql, array($adb->query_result($dis_result, 0, "profileid")));

        // Sync Data for this new added profile
        sync_QbChartOfAccounts($last_inserted_profile_id,$qbo_account_type);
        sync_QbPaymentMethods($last_inserted_profile_id,$qbo_account_type);
        sync_QbCustomers($last_inserted_profile_id,$qbo_account_type);
        sync_QbProducts($last_inserted_profile_id,$qbo_account_type);

        // Adding User
        $insert_qbuser_sql = "INSERT INTO quickbooks_user (qb_username, qb_password, status,write_datetime,touch_datetime) VALUES   (?,?,?,?,?)";

        $adb->pquery($insert_qbuser_sql, array(
            $accessToken->getRealmID(),
            '1234',
            '',
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s')
        ));

         // Adding Entry in cf_qbprofile
        $insert_qbprofile_sql = "INSERT INTO vtiger_cf_qbprofile (cf_qbprofileid, cf_qbprofile) VALUES (?,?)";
         $adb->pquery($insert_qbprofile_sql, array(
            $last_inserted_profile_id,
            $CompanyInfo->CompanyName
        ));

        // $profile_sql = "UPDATE fuse5_qbprofile SET profilename = '".$CompanyInfo->CompanyName."',company_id= '".$accessToken->getRealmID()."'
        //             IF ROW_COUNT()=0
        //             INSERT INTO fuse5_qbprofile (profilename,defaulprofile,profileqbuser,profilebasedon,access_token,access_token_expires,refresh_token,refresh_token_expires,client_id,client_secret,qbo_sequence_id,company_id) VALUES ('".$CompanyInfo->CompanyName."','1','".$CompanyInfo->CompanyName."','location','".$accessToken->getAccessToken()."','".$accessToken->getAccessTokenExpiresAt(),."','".$accessToken->getRefreshToken(),."','".$accessToken->getRefreshTokenExpiresAt()."','".$accessToken->getClientID()."','".$accessToken->getClientSecret()."','".$CompanyInfo->SyncToken."','".$accessToken->getRealmID()."') ";

        // $adb->pquery($profile_sql, array());
    }
    // else
    // {
        // $profile_sql = "INSERT INTO fuse5_qbprofile (profilename,defaulprofile,profileqbuser,profilebasedon,access_token,access_token_expires,refresh_token,refresh_token_expires,client_id,client_secret,qbo_sequence_id,company_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ";

        // $adb->pquery($profile_sql, array(
        //         $CompanyInfo->CompanyName,
        //         '1',
        //         $CompanyInfo->CompanyName,
        //         'location',
        //         $accessToken->getAccessToken(), 
        //         $accessToken->getAccessTokenExpiresAt(), 
        //         $accessToken->getRefreshToken(),
        //         $accessToken->getRefreshTokenExpiresAt(),
        //         $accessToken->getClientID(),
        //         $accessToken->getClientSecret(),
        //         $CompanyInfo->Id,
        //         $accessToken->getRealmID(),
        // ));
    // }
}

function parseAuthRedirectUrl($url)
{
    parse_str($url,$qsArray);
    return array(
        'code' => $qsArray['code'],
        'realmId' => $qsArray['realmId']
    );
}

$result = processCode();

?>