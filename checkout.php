<?php

    require_once dirname(__FILE__).'/vendor/autoload.php';

    define('OMISE_PUBLIC_KEY', 'pkey_test_5fm52ztnwhs1qhaaqf4');
    define('OMISE_SECRET_KEY', 'skey_test_***');

    OmiseCharge::create(array(
        'amount' => 30000,
        'currency' => 'thb',
        'card' => 
        ))

    echo '<pre>';
    print_r($_POST);
    echo '</pre>';

?>
