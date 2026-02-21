<?php


namespace App;

class Configuration {
    public $bank_username;
    public $bank_password;
    public $bank_url;
    public $bank_code;
    public $bank_2fa;
    public $bank_2fa_device;
    public $firefly_url;
    public $firefly_access_token;
    public $skip_transaction_review;
    public $choose_account_automations;
    public $description_regex_match;
    public $description_regex_replace;
    public $force_mt940;
}

class ConfigurationFactory
{
    static function load_from_file($fileName)
    {
        $jsonFileContent = file_get_contents($fileName);
        $contentArray = json_decode($jsonFileContent, true);

        $configuration = new Configuration();
        $configuration->bank_username           = $contentArray["bank_username"];
        $configuration->bank_password           = $contentArray["bank_password"];
        $configuration->bank_url                = $contentArray["bank_url"];
        $configuration->bank_code               = $contentArray["bank_code"];
        $configuration->bank_2fa                = $contentArray["bank_2fa"];
        $configuration->bank_2fa_device         = @$contentArray["bank_2fa_device"];
        $configuration->firefly_url             = $contentArray["firefly_url"];
        $configuration->firefly_access_token    = $contentArray["firefly_access_token"];
        $configuration->skip_transaction_review = filter_var($contentArray["skip_transaction_review"], FILTER_VALIDATE_BOOLEAN);
        $configuration->choose_account_automations = array();
        if (isset($contentArray["choose_account_automations"])) {
            $configuration->choose_account_automations = $contentArray["choose_account_automations"];
        } elseif (isset($contentArray["choose_account_automation"])) {
            // backwards compatibility with previous singular key
            $configuration->choose_account_automations = array($contentArray["choose_account_automation"]);
        }
        $configuration->description_regex_match   = $contentArray["description_regex_match"];
        $configuration->description_regex_replace = $contentArray["description_regex_replace"];
        $configuration->force_mt940               = filter_var($contentArray["force_mt940"] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $configuration;
    }
}
