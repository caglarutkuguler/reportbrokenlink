<?php
/**
 *    Module Name: Report Broken Link
 *
 *    Module URI: Please contact with info@megventure.com
 *    Description: This adds a button to the product pages for the visitors to report broken links.
 *    Version: 3.2.0
 *
 *  @author    MEG Venture <info@megventure.com>
 *  @copyright 2007-2021 MEG Venture
 *  @license   For Prestashop--> http://opensource.org/licenses/osl-3.2.php  Open Software License (OSL 3.2)
 *
 *    This program is not a free software: you can't redistribute it and/or modify
 *    it. All rights reserved to MEG Venture.
 *
 *    This copyright notice  and licence should be retained in all modules based on this framework.
 *    This does not affect your rights to assert copyright over your own original work.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class ReportBrokenLink extends Module
{
    private $html = '';
    private $post_errors = array();
    public $context;

    public function __construct($dont_translate = false)
    {
        $this->name = 'reportbrokenlink';
        $this->version = '3.2.0';
        $this->author = 'MEG Venture';
        $this->tab = 'front_office_features';
        $this->need_instance = 0;
        $this->secure_key = Tools::encrypt($this->name);
        $this->module_key = '010ab8b016269b5b3a436ea65c306e7d';

        parent::__construct();

        if (!$dont_translate) {
            $this->displayName = $this->l('Report Broken Links module');
            $this->description = $this->l('Adds a button to the product pages for the visitors to report broken links.');
        }
    }

    public function install()
    {
        if (version_compare(_PS_VERSION_, '1.7.0.0 ', '>')) {
            return (parent::install() && $this->registerHook('displayProductAdditionalInfo'));
        } else {
            return (parent::install() && $this->registerHook('extraLeft'));
        }
    }

    public function uninstall()
    {
        if (version_compare(_PS_VERSION_, '1.7.0.0 ', '>')) {
            return (parent::uninstall() && $this->unregisterHook('displayProductAdditionalInfo'));
        } else {
            return (parent::uninstall() && $this->unregisterHook('extraLeft'));
        }
    }

    public function hookExtraLeft()
    {
        /* Product informations */
        $product = new Product((int) Tools::getValue('id_product'), false, $this->context->language->id);

        $this->context->smarty->assign(array(
            'rpl_product' => $product,
            'rpl_secure_key' => $this->secure_key,
            'virtual' => $product->is_virtual,
            'rpl_mail' => Configuration::get('PS_SHOP_EMAIL'),
            'base_dir' => __PS_BASE_URI__,
        ));

        return $this->display(__FILE__, 'views/templates/front/reportbrokenlink-extra.tpl');
    }

    public function hookproductactions()
    {
        return $this->hookExtraLeft();
    }

    public function hookproductbuttons()
    {
        return $this->hookExtraLeft();
    }

    public function hookdisplayProductAdditionalInfo()
    {
        return $this->hookExtraLeft();
    }
}
