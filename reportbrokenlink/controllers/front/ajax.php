<?php
/**
 *    Module Name: Report Broken Link
 *
 *    Module URI: Please contact with info@megventure.com
 *    Description: This adds a button to the product pages for the visitors to report broken links.
 *    Version: 3.2.1
 *
 *  @author    MEG Venture <info@megventure.com>
 *  @copyright 2007-2023 MEG Venture
 *  @license   For Prestashop--> http://opensource.org/licenses/osl-3.2.php  Open Software License (OSL 3.2)
 *
 *    This program is not a free software: you can't redistribute it and/or modify
 *    it. All rights reserved to MEG Venture.
 *
 *    This copyright notice  and licence should be retained in all modules based on this framework.
 *    This does not affect your rights to assert copyright over your own original work.
 */

class ReportBrokenLinkAjaxModuleFrontController extends ModuleFrontController
{
    public function __construct()
    {
        parent::__construct();

        // header('Content-Type: application/json'); // set json response headers
        $outData = $this->save(); // a function to upload the bootstrap-fileinput files
        echo json_encode($outData); // return json data
        exit(); // terminate
    }

    public function save()
    {
        $ReportBrokenLink = new ReportBrokenLink();
        if (Tools::getValue('action') == 'ReportLink' && Tools::getValue('secure_key') == $ReportBrokenLink->secure_key) {
            $report_datas = json_decode(Tools::getValue('report'));
            $report_name = '';
            $report_mail = '';
            $id_product = null;
            $visitoremail = '';
            $report1 = $report2 = $report3 = $report4 = $report5 = $report6 = $report7 = '';
            foreach ($report_datas as $entry) {
                if ($entry->key == 'virtual_product_name') {
                    $report_name = $entry->value;
                } else if ($entry->key == 'shop_email') {
                    $report_mail = $entry->value;
                } else if ($entry->key == 'id_product') {
                    $id_product = $entry->value;
                } else if ($entry->key == 'visitoremail') {
                    $visitoremail = $entry->value;
                } else if (($entry->key == 'checkbox1') && ($entry->checked == true)) {
                    $report1 = $ReportBrokenLink->l('Main product image/images are not displayed.', 'reportbrokenlink_ajax');
                } else if (($entry->key == 'checkbox2') && ($entry->checked == true)) {
                    $report2 = $ReportBrokenLink->l('Thumbnail product image/images of the main product are not displayed.', 'reportbrokenlink_ajax');
                } else if (($entry->key == 'checkbox3') && ($entry->checked == true)) {
                    $report3 = $ReportBrokenLink->l('There are some image files not displayed/found on the page.', 'reportbrokenlink_ajax');
                } else if (($entry->key == 'checkbox4') && ($entry->checked == true)) {
                    $report4 = $ReportBrokenLink->l('I purchased the virtual product, but not received the download file.', 'reportbrokenlink_ajax');
                } else if (($entry->key == 'checkbox5') && ($entry->checked == true)) {
                    $report5 = $ReportBrokenLink->l('The download file is missing or download link is not working.', 'reportbrokenlink_ajax');
                } else if (($entry->key == 'checkbox6') && ($entry->checked == true)) {
                    $report6 = $ReportBrokenLink->l('Some external links on this page are broken, not working.', 'reportbrokenlink_ajax');
                } else if (($entry->key == 'checkbox7') && ($entry->checked == true)) {
                    $report7 = $ReportBrokenLink->l('I cannot click any buttons on the page, there is something wrong.', 'reportbrokenlink_ajax');
                }
            }

            /* Email generation */
            $product = new Product((int) $id_product, false, $ReportBrokenLink->context->language->id);
            $product_link = $ReportBrokenLink->context->link->getProductLink($product);
            $customer = $ReportBrokenLink->context->cookie->customer_firstname ? $ReportBrokenLink->context->cookie->customer_firstname . ' '
            . $ReportBrokenLink->context->cookie->customer_lastname : $ReportBrokenLink->l('A customer', 'reportbrokenlink_ajax');

            $template_vars = array(
                '{product}' => $product->name,
                '{product_link}' => $product_link,
                '{customer}' => $customer,
                '{customeremail}' => $visitoremail,
                '{report1}' => $report1,
                '{report2}' => $report2,
                '{report3}' => $report3,
                '{report4}' => $report4,
                '{report5}' => $report5,
                '{report6}' => $report6,
                '{report7}' => $report7,
            );

            /* Email sending */
            if (!Mail::Send((int) $ReportBrokenLink->context->cookie->id_lang,
                'report_broken_link',
                sprintf(Mail::l('%1$s sent you a broken link report for %2$s',
                    (int) $ReportBrokenLink->context->cookie->id_lang),
                    $customer,
                    $product->name),
                $template_vars,
                $report_mail,
                null,
                ($ReportBrokenLink->context->cookie->email ? $ReportBrokenLink->context->cookie->email : null),
                ($ReportBrokenLink->context->cookie->customer_firstname ? $ReportBrokenLink->context->cookie->customer_firstname . ' '
                    . $ReportBrokenLink->context->cookie->customer_lastname : null),
                null,
                null,
                dirname(__FILE__) . '/mails/')
            ) {
                die('0');
            }
        }

        return true;
    }
    /**
     * @deprecated Deprecated since 1.7.0
     * Use json_decode instead
     * jsonDecode convert json string to php array / object
     *
     * @param string $data
     * @param bool $assoc (since 1.4.2.4) if true, convert to associative array
     * @param int $depth
     * @param int $options
     *
     * @return array
     */
    public static function jsonDecode($data, $assoc = false, $depth = 512, $options = 0)
    {
        return json_decode($data, $assoc, $depth, $options);
    }
}
