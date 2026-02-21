<?php
namespace App\StepFunction;

use App\FinTsFactory;
use App\Step;
use App\TanHandler;
use App\ApiResponse;
use GrumpyDictator\FFIIIApiSupport\Request\GetAccountsRequest;

function parse_import_date($input, $fallback)
{
    if (is_null($input) || $input === '') {
        return $fallback;
    }

    try {
        return new \DateTime($input);
    } catch (\Exception $e) {
        return $fallback;
    }
}

function find_requested_automation($automations)
{
    $query_iban = $_GET['bank_account_iban'] ?? null;
    $query_firefly_id = $_GET['firefly_account_id'] ?? null;

    if (!is_null($query_iban) || !is_null($query_firefly_id)) {
        foreach ($automations as $automation) {
            $iban_matches = is_null($query_iban) || (($automation['bank_account_iban'] ?? null) == $query_iban);
            $firefly_matches = is_null($query_firefly_id) || ((string)($automation['firefly_account_id'] ?? '') === (string)$query_firefly_id);
            if ($iban_matches && $firefly_matches) {
                return $automation;
            }
        }
        return null;
    }

    if (count($automations) === 1) {
        return $automations[0];
    }

    return null;
}

function ChooseAccount()
{
    global $request, $session, $twig, $fin_ts, $automate_without_js;


    if ($automate_without_js && (!isset($_GET['bank_account_iban']) || $_GET['bank_account_iban'] === '')) {
        ApiResponse::send_json(
            400,
            array(
                'status' => 'missing_parameter',
                'message' => 'For automate mode, query parameter bank_account_iban is required.'
            )
        );
        return Step::DONE;
    }

    $fin_ts = FinTsFactory::create_from_session($session);
    $current_step  = new Step(Step::STEP3_CHOOSE_ACCOUNT);
    $list_accounts_handler = new TanHandler(
        function () {
            global $fin_ts;
            $get_sepa_accounts = \Fhp\Action\GetSEPAAccounts::create();
            $fin_ts->execute($get_sepa_accounts);
            return $get_sepa_accounts;
        },
        'list-accounts',
        $session,
        $twig,
        $fin_ts,
        $current_step,
        $request
    );
    if ($list_accounts_handler->needs_tan()) {
        $list_accounts_handler->pose_and_render_tan_challenge();
    } else {
        /** @var \Fhp\Action\GetSEPAAccounts $get_sepa_accounts_action */
        $get_sepa_accounts_action = $list_accounts_handler->get_finished_action();
        $bank_accounts            = $get_sepa_accounts_action->getAccounts();
        $firefly_accounts_request = new GetAccountsRequest($session->get('firefly_url'), $session->get('firefly_access_token'));
        $firefly_accounts_request->setType(GetAccountsRequest::ASSET);
        /** @var \GrumpyDictator\FFIIIApiSupport\Response\GetAccountsResponse $firefly_accounts */
        $firefly_accounts = $firefly_accounts_request->get();

        $requested_bank_index = -1;
        $automations = $session->get('choose_account_automations', array());
        $requested_automation = find_requested_automation($automations);
        $requested_bank_iban = $requested_automation['bank_account_iban'] ?? null;
        $requested_firefly_id = $requested_automation['firefly_account_id'] ?? null;
        $error = '';

        if (!is_null($requested_bank_iban)) {
            for ($i = 0; $i < count($bank_accounts); $i++) {
                if ($bank_accounts[$i]->getIban() == $requested_bank_iban) {
                    $requested_bank_index = $i;
                    break;
                }
            }
            if ($requested_bank_index == -1) {
                $error = $error . 'Could not find IBAN "' . $requested_bank_iban . '" in your bank accounts.' . "\n";
                $error = $error . 'Please review your configuration.' . "\n";
            }
        }
        if (!is_null($requested_firefly_id)) {
            $firefly_accounts->rewind();
            for ($acc = $firefly_accounts->current(); $firefly_accounts->valid(); $acc = $firefly_accounts->current()) {
                if ((string)$acc->id === (string)$requested_firefly_id) {
                    break;
                }
                $firefly_accounts->next();
            }
            if (!$firefly_accounts->valid()) {
                $error = $error . 'Could not find the Firefly ID "' . $requested_firefly_id . '" in your Firefly III account.' . "\n";
                $error = $error . 'Please review your configuration.' . "\n";
            }
            $firefly_accounts->rewind();
        }

        $default_from_date = parse_import_date($_GET['from'] ?? null, new \DateTime('now - 1 month'));
        $default_to_date = parse_import_date($_GET['to'] ?? null, new \DateTime('now'));

        $can_be_automated = !is_null($requested_bank_iban) && !is_null($requested_firefly_id);

        if (empty($error)) {
            $session->set('accounts', serialize($bank_accounts));
            if ($can_be_automated && $automate_without_js)
            {
                $request->request->set('bank_account', $requested_bank_index);
                $request->request->set('firefly_account', $requested_firefly_id);
                $request->request->set('date_from', $default_from_date->format('Y-m-d'));
                $request->request->set('date_to', $default_to_date->format('Y-m-d'));

                $session->set('persistedFints', $fin_ts->persist());
                return Step::STEP4_GET_IMPORT_DATA;
            }
            if ($automate_without_js) {
                ApiResponse::send_json(
                    400,
                    array(
                        'status' => 'missing_mapping',
                        'message' => 'No automation mapping matched the requested bank_account_iban/firefly_account_id.'
                    )
                );
                return Step::DONE;
            }
            echo $twig->render(
                'choose-account.twig',
                array(
                    'next_step' => Step::STEP4_GET_IMPORT_DATA,
                    'bank_accounts' => $bank_accounts,
                    'firefly_accounts' => $firefly_accounts,
                    'default_from_date' => $default_from_date,
                    'default_to_date' => $default_to_date,
                    'bank_account_iban' => $requested_bank_iban,
                    'bank_account_index' => $requested_bank_index,
                    'firefly_account_id' => $requested_firefly_id,
                    'auto_submit_form_via_js' => $can_be_automated
                )
            );
        } else {
            if ($automate_without_js) {
                ApiResponse::send_json(
                    400,
                    array(
                        'status' => 'invalid_configuration',
                        'message' => trim($error)
                    )
                );
            } else {
                echo $twig->render(
                    'error.twig',
                    array(
                        'error_header' => 'Failed to verify given Information',
                        'error_message' => $error
                    )
                );
            }
        }
    }
    $session->set('persistedFints', $fin_ts->persist());
    return Step::DONE;
}
