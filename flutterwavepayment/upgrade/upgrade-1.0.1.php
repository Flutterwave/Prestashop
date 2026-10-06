<?php
/**
 * Upgrade to 1.0.1 - make Flutterwave transaction IDs unique
 *
 * @author    Flutterwave Developers
 * @copyright 2024 Flutterwave Developers
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_1($module)
{
    $table = _DB_PREFIX_ . 'flutterwave_transaction';
    $db = Db::getInstance();

    // Duplicates mean one charge already paid for several orders; refuse to
    // hide that and leave it for the merchant to review.
    $duplicates = $db->executeS(
        'SELECT flutterwave_transaction_id, GROUP_CONCAT(id_order) AS orders
        FROM `' . $table . '`
        GROUP BY flutterwave_transaction_id
        HAVING COUNT(*) > 1'
    );

    if (!empty($duplicates)) {
        foreach ($duplicates as $row) {
            PrestaShopLogger::addLog(
                'Flutterwave upgrade: transaction ' . $row['flutterwave_transaction_id']
                . ' is linked to multiple orders (' . $row['orders'] . ')',
                3,
                null,
                'FlutterwavePayment',
                null,
                true
            );
        }

        return false;
    }

    $hasOldIndex = $db->executeS('SHOW INDEX FROM `' . $table . '` WHERE Key_name = "idx_transaction_id"');

    return $db->execute(
        'ALTER TABLE `' . $table . '`'
        . ($hasOldIndex ? ' DROP INDEX `idx_transaction_id`,' : '')
        . ' ADD UNIQUE KEY `uniq_transaction_id` (`flutterwave_transaction_id`)'
    );
}
