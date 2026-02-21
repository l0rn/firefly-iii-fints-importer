<?php
namespace App\StepFunction;

use App\FinTsFactory;
use App\ConfigurationFactory;
use App\Step;

function CollectData()
{
    global $request, $session, $twig, $automate_without_js;

    if($request->request->get('data_collect_mode') == "createNewDataset"){
        echo $twig->render(
            'collecting-data.twig',
            array(
                'next_step' => Step::STEP1p5_CHOOSE_2FA_DEVICE
            ));
    } else {
        $state_directory = $session->get('state_directory', 'data/state');
        $config_basename = $session->get('config_basename');

        $session->invalidate();
        $session->start();
        $session->set('state_directory', $state_directory);

        $filename = $request->request->get('data_collect_mode');
        if (is_null($config_basename) || $config_basename === '') {
            $config_basename = pathinfo(basename($filename), PATHINFO_FILENAME);
        }
        $session->set('config_basename', $config_basename);

        $configuration = ConfigurationFactory::load_from_file($filename);

        if ($request->request->has('bank_username')) {
            $configuration->bank_username = $request->request->get('bank_username');
        }
        if ($request->request->has('bank_password')) {
            $configuration->bank_password = $request->request->get('bank_password');
        }
        if ($configuration->bank_username == "" || $configuration->bank_password == "") {
            echo $twig->render(
                'collecting-data.twig',
                array(
                    'next_step' => Step::STEP1_COLLECTING_DATA,
                    'configuration' => $configuration,
                    'data_collect_mode' => $filename
                ));
            return;
        }

        $state_file = rtrim($state_directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $config_basename . '.state';

        $session->set('bank_username',           $configuration->bank_username);
        $session->set('bank_password',           $configuration->bank_password);
        $session->set('bank_url',                $configuration->bank_url);
        $session->set('bank_code',               $configuration->bank_code);
        $session->set('bank_2fa',                $configuration->bank_2fa);
        if($configuration->bank_2fa_device) {
            $session->set('bank_2fa_device',         $configuration->bank_2fa_device);
        }
        if (file_exists($state_file)) {
            $session->set('fints_persistence', file_get_contents($state_file));
        }
        $session->set('firefly_url',             $configuration->firefly_url);
        $session->set('firefly_access_token',    $configuration->firefly_access_token);
        $session->set('skip_transaction_review', $configuration->skip_transaction_review);
        $session->set('choose_account_automations', $configuration->choose_account_automations);
        $session->set('description_regex_match', $configuration->description_regex_match);
        $session->set('description_regex_replace', $configuration->description_regex_replace);
        $session->set('force_mt940',             $configuration->force_mt940);

        $fin_ts   = FinTsFactory::create_from_session($session);
        $tan_mode = FinTsFactory::get_tan_mode($fin_ts, $session);

        if ($tan_mode->needsTanMedium() and !$session->has('bank_2fa_device')) {
            $tan_devices = $fin_ts->getTanMedia($tan_mode);
            if (count($tan_devices) == 1) {
                $auto_skip_form = true;
            } else {
                $auto_skip_form = false;
            }
            echo $twig->render(
                'choose-2fa-device.twig',
                array(
                    'next_step' => Step::STEP2_LOGIN,
                    'devices' => $fin_ts->getTanMedia($tan_mode),
                    'auto_skip_form' => $auto_skip_form
                ));
        } else {
            if ($automate_without_js) {
                return Step::STEP2_LOGIN;
            }
            $message = "Your chosen tan mode does not require you to choose a device.";
            if($tan_mode->needsTanMedium()){
                $message = "Your chosen tan device is '" . $session->get("bank_2fa_device") . "'.";
            }
            echo $twig->render(
                'skip-form.twig',
                array(
                    'next_step' => Step::STEP2_LOGIN,
                    'message' => $message
                )
            );
        }
    }
    return Step::DONE;
}
