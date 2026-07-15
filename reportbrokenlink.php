<?php
/**
 * 2019-2026 MEG Venture
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 *
 *  @author    MEG Venture
 *  @copyright 2019-2026 MEG Venture
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/ReportBrokenLinkValidator.php';
require_once dirname(__FILE__) . '/classes/ReportBrokenLinkRepository.php';

class ReportBrokenLink extends Module
{
    /** @var int Reports one IP may file across the whole catalogue per hour, whatever the per-product limit is. */
    const RATE_LIMIT_GLOBAL_PER_HOUR = 10;

    /** @var int A form submitted faster than this many seconds after it was rendered is a bot. */
    const TOKEN_MIN_SECONDS = 2;

    /** @var int A form older than this many seconds must be reloaded (2 hours). */
    const TOKEN_MAX_SECONDS = 7200;

    /** @var float reCAPTCHA v3 scores below this are treated as bots. */
    const RECAPTCHA_MIN_SCORE = 0.5;

    /** @var int Hard cap on rows the CSV export will produce. */
    const EXPORT_MAX_ROWS = 50000;

    private $html = '';

    /** @var bool Guards against the button being rendered twice if both position hooks fire. */
    private $rendered = false;

    public function __construct()
    {
        $this->name = 'reportbrokenlink';
        $this->tab = 'front_office_features';
        $this->version = '4.0.0';
        $this->author = 'MEG Venture';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->module_key = '010ab8b016269b5b3a436ea65c306e7d';
        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => _PS_VERSION_);

        parent::__construct();

        $this->displayName = $this->l('Report an Issue - Customer Product Page Feedback');
        $this->description = $this->l('Let customers tell you when something is wrong on a product page - a broken link, a wrong price, missing information - through a quick form, and manage every report from one dashboard instead of an inbox.');
        $this->confirmUninstall = $this->l('Are you sure? This will permanently delete every stored report and all module settings. Export them to CSV first if you may need them again.');

        if ($this->id && !$this->getNotificationRecipients()) {
            $this->warning = $this->l('No valid notification e-mail address is configured, so nobody will be alerted about new reports.');
        }
    }

    /* -------------------------------------------------------------------- */
    /* Install / uninstall                                                    */
    /* -------------------------------------------------------------------- */

    public function install()
    {
        return parent::install()
            && $this->registerHook('displayProductAdditionalInfo')
            && $this->registerHook('displayFooterProduct')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('actionProductDelete')
            && $this->registerHook('displayDashboardTop')
            && include dirname(__FILE__) . '/sql/install.php';
    }

    public function uninstall()
    {
        // parent::uninstall() removes the hook registrations itself; calling unregisterHook()
        // afterwards (as 3.x did) operates on a module row that no longer exists and can make a
        // successful uninstall report as failed.
        return parent::uninstall()
            && include dirname(__FILE__) . '/sql/uninstall.php';
    }

    /* -------------------------------------------------------------------- */
    /* Settings access                                                        */
    /* -------------------------------------------------------------------- */

    /**
     * @return bool whether the button should be shown at all on this shop
     */
    public function isButtonEnabled()
    {
        return (bool) Configuration::get('REPORTBROKENLINK_ENABLED');
    }

    /**
     * The report types the merchant has switched on, in their canonical order.
     *
     * Falls back to every known type: a shop that somehow ends up with an empty list would
     * otherwise show a form with no options and reject every submission.
     *
     * @return string[]
     */
    public function getEnabledTypes()
    {
        $stored = (string) Configuration::get('REPORTBROKENLINK_TYPES');
        $enabled = array_filter(array_map('trim', explode(',', $stored)));
        $enabled = array_values(array_intersect(ReportBrokenLinkRepository::REPORT_TYPES, $enabled));

        return $enabled ? $enabled : ReportBrokenLinkRepository::REPORT_TYPES;
    }

    /**
     * Notification recipients, filtered down to the addresses that are actually valid.
     *
     * The recipient list comes from configuration and *only* from configuration. 3.x read it
     * from a hidden field in the page, which let anyone use the shop as a mail relay.
     *
     * @return string[]
     */
    public function getNotificationRecipients()
    {
        $valid = array();
        foreach (explode(',', (string) Configuration::get('REPORTBROKENLINK_EMAILS')) as $address) {
            $address = trim($address);
            if ($address !== '' && Validate::isEmail($address)) {
                $valid[] = $address;
            }
        }

        return $valid;
    }

    /**
     * @return array type => human label
     */
    public function getTypeLabels()
    {
        return array(
            'broken_link' => $this->l('Broken link'),
            'wrong_price' => $this->l('Wrong price'),
            'missing_info' => $this->l('Missing information'),
            'other' => $this->l('Something else'),
        );
    }

    /**
     * @return array status => human label
     */
    public function getStatusLabels()
    {
        return array(
            'open' => $this->l('Open'),
            'in_progress' => $this->l('In progress'),
            'resolved' => $this->l('Resolved'),
            'duplicate' => $this->l('Duplicate'),
            'spam' => $this->l('Spam'),
        );
    }

    /* -------------------------------------------------------------------- */
    /* Anti-spam                                                              */
    /* -------------------------------------------------------------------- */

    /**
     * An HMAC-signed timestamp, bound to the product the form was rendered for.
     *
     * Session-bound tokens are not usable here: the product page is served from PrestaShop's
     * full-page cache to anonymous visitors, so a per-session value would be cached and handed
     * to the wrong people. Signing with _COOKIE_KEY_ instead means the token cannot be forged,
     * and binding it to the product id means a token harvested once cannot be replayed against
     * every other product in the catalogue.
     *
     * @param int $idProduct
     *
     * @return string
     */
    public function makeToken($idProduct)
    {
        $timestamp = time();

        return $timestamp . '.' . $this->signToken((int) $idProduct, $timestamp);
    }

    /**
     * @param string $token
     * @param int $idProduct
     *
     * @return bool
     */
    public function verifyToken($token, $idProduct)
    {
        $token = (string) $token;
        if (strpos($token, '.') === false) {
            return false;
        }

        list($timestamp, $signature) = explode('.', $token, 2);
        if (!ctype_digit($timestamp)) {
            return false;
        }

        if (!hash_equals($this->signToken((int) $idProduct, (int) $timestamp), $signature)) {
            return false;
        }

        $elapsed = time() - (int) $timestamp;

        return $elapsed >= self::TOKEN_MIN_SECONDS && $elapsed <= self::TOKEN_MAX_SECONDS;
    }

    /**
     * @param int $idProduct
     * @param int $timestamp
     *
     * @return string
     */
    private function signToken($idProduct, $timestamp)
    {
        return hash_hmac('sha256', $this->name . '|' . (int) $idProduct . '|' . (int) $timestamp, _COOKIE_KEY_);
    }

    /**
     * Formats a stored DATETIME for display, with the time included.
     *
     * Tools::displayDate() dropped its deprecated $id_lang and $separator parameters in
     * PrestaShop 8, so the four-argument 1.7 call would silently pass null as $full there and
     * print the date without its time. Both signatures have to be called correctly.
     *
     * Timestamps are stored and rendered in the shop's own timezone: PrestaShop applies
     * PS_TIMEZONE process-wide, so date() here and NOW() in MySQL agree with each other and
     * with what the merchant sees elsewhere in the back office.
     *
     * @param string $date
     *
     * @return string
     */
    private function formatDate($date)
    {
        if (version_compare(_PS_VERSION_, '8.0.0', '>=')) {
            return Tools::displayDate($date, true);
        }

        return Tools::displayDate($date, null, true);
    }

    /**
     * True when this reporter has already hit either rate limit.
     *
     * @param string $ipHash
     * @param int $idProduct
     *
     * @return bool
     */
    public function isRateLimited($ipHash, $idProduct)
    {
        $perProduct = (int) Configuration::get('REPORTBROKENLINK_RATE_LIMIT');

        if ($perProduct > 0
            && ReportBrokenLinkRepository::countRecentByIpForProduct($ipHash, $idProduct) >= $perProduct) {
            return true;
        }

        return ReportBrokenLinkRepository::countRecentByIp($ipHash) >= self::RATE_LIMIT_GLOBAL_PER_HOUR;
    }

    /**
     * Keyed hash of the current visitor's IP. The address itself is never stored.
     *
     * @param string $ip
     *
     * @return string
     */
    public function hashIp($ip)
    {
        return ReportBrokenLinkValidator::hashIp($ip, _COOKIE_KEY_);
    }

    /**
     * Server-side reCAPTCHA v3 verification. Returns true when the feature is switched off, so
     * callers can call it unconditionally.
     *
     * @param string $response the g-recaptcha-response token from the browser
     * @param string $ip
     *
     * @return bool
     */
    public function verifyRecaptcha($response, $ip)
    {
        if (!Configuration::get('REPORTBROKENLINK_RECAPTCHA')) {
            return true;
        }

        $secret = trim((string) Configuration::get('REPORTBROKENLINK_RECAPTCHA_SECRET'));
        if ($secret === '') {
            // Misconfigured rather than under attack: refusing every report because the merchant
            // forgot to paste a key would be worse than accepting them. The configure page shows
            // a loud health warning for exactly this state.
            return true;
        }

        $raw = Tools::file_get_contents(
            'https://www.google.com/recaptcha/api/siteverify?' . http_build_query(array(
                'secret' => $secret,
                'response' => (string) $response,
                'remoteip' => (string) $ip,
            ))
        );

        if (!$raw) {
            // Google unreachable. Do not punish the visitor for our outbound network.
            return true;
        }

        $result = json_decode($raw, true);
        if (!is_array($result) || empty($result['success'])) {
            return false;
        }

        // v3 always returns a score; treat a missing one as a pass rather than a silent block.
        return !isset($result['score']) || (float) $result['score'] >= self::RECAPTCHA_MIN_SCORE;
    }

    /* -------------------------------------------------------------------- */
    /* Front office hooks                                                     */
    /* -------------------------------------------------------------------- */

    /**
     * Registers the module's stylesheet and script, on product pages only.
     */
    public function hookActionFrontControllerSetMedia()
    {
        // Storefront hook: a fatal here would white-screen the product page. Catch Throwable,
        // not Exception — an undefined method or a TypeError is an Error, which Exception does
        // not catch, so a catch(Exception) here would look like a guard while catching nothing.
        try {
            $this->registerAssets();
        } catch (Throwable $e) {
            PrestaShopLogger::addLog(
                'ReportBrokenLink: failed to register assets - ' . $e->getMessage(),
                3,
                null,
                'ReportBrokenLink'
            );
        }
    }

    private function registerAssets()
    {
        if (!$this->isButtonEnabled() || !$this->isProductPage()) {
            return;
        }

        $this->context->controller->registerStylesheet(
            'modules-reportbrokenlink',
            'modules/' . $this->name . '/views/css/front.css',
            array('media' => 'all', 'priority' => 150)
        );

        $this->context->controller->registerJavascript(
            'modules-reportbrokenlink',
            'modules/' . $this->name . '/views/js/front.js',
            array('position' => 'bottom', 'priority' => 150)
        );

        if (Configuration::get('REPORTBROKENLINK_RECAPTCHA')
            && trim((string) Configuration::get('REPORTBROKENLINK_RECAPTCHA_SITE')) !== '') {
            $this->context->controller->registerJavascript(
                'modules-reportbrokenlink-recaptcha',
                'https://www.google.com/recaptcha/api.js?render=' . urlencode(Configuration::get('REPORTBROKENLINK_RECAPTCHA_SITE')),
                array('server' => 'remote', 'position' => 'bottom', 'priority' => 149)
            );
        }

        Media::addJsDef(array('reportBrokenLinkConfig' => array(
            'url' => $this->context->link->getModuleLink($this->name, 'report', array(), true),
            'recaptchaSite' => Configuration::get('REPORTBROKENLINK_RECAPTCHA')
                ? (string) Configuration::get('REPORTBROKENLINK_RECAPTCHA_SITE')
                : '',
            'messageMin' => ReportBrokenLinkValidator::MESSAGE_MIN,
            'messageMax' => ReportBrokenLinkValidator::MESSAGE_MAX,
            'i18n' => array(
                'typeRequired' => $this->l('Please choose what is wrong with this page.'),
                'messageRequired' => $this->l('Please describe the issue.'),
                'messageTooShort' => sprintf($this->l('Please use at least %d characters so we can understand the issue.'), ReportBrokenLinkValidator::MESSAGE_MIN),
                'emailInvalid' => $this->l('Please enter a valid e-mail address, or leave the field empty.'),
                'emailRequired' => $this->l('Please enter your e-mail address.'),
                'networkError' => $this->l('Your report could not be sent. Please check your connection and try again.'),
                'sending' => $this->l('Sending...'),
            ),
        )));
    }

    /**
     * The product page's "additional information" area, next to the description.
     *
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayProductAdditionalInfo(array $params)
    {
        return $this->renderButton($params, 'info');
    }

    /**
     * Below the product description / tabs.
     *
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayFooterProduct(array $params)
    {
        return $this->renderButton($params, 'footer');
    }

    /**
     * @param array $params
     * @param string $position the position this hook represents
     *
     * @return string
     */
    private function renderButton(array $params, $position)
    {
        // Same reasoning as hookActionFrontControllerSetMedia: never let this hook take the
        // product page down with it. The button disappearing is recoverable; a white screen on
        // every product page is not.
        try {
            return $this->buildButton($params, $position);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog(
                'ReportBrokenLink: failed to render the report button - ' . $e->getMessage(),
                3,
                null,
                'ReportBrokenLink'
            );

            return '';
        }
    }

    /**
     * @param array $params
     * @param string $position
     *
     * @return string
     */
    private function buildButton(array $params, $position)
    {
        if ($this->rendered
            || !$this->isButtonEnabled()
            || Configuration::get('REPORTBROKENLINK_POSITION') !== $position) {
            return '';
        }

        $idProduct = $this->resolveProductId($params);
        if ($idProduct < 1) {
            return '';
        }

        $customer = isset($this->context->customer) ? $this->context->customer : null;
        $isLogged = $customer && $customer->isLogged();

        // When guest reports are off, don't show a button the visitor cannot use: the
        // controller would reject them anyway, but only after they had written the whole thing.
        if (!$isLogged && !Configuration::get('REPORTBROKENLINK_GUEST')) {
            return '';
        }

        $types = array();
        $labels = $this->getTypeLabels();
        foreach ($this->getEnabledTypes() as $type) {
            $types[] = array('value' => $type, 'label' => $labels[$type]);
        }

        $this->context->smarty->assign(array(
            'rbl_id_product' => $idProduct,
            'rbl_token' => $this->makeToken($idProduct),
            'rbl_types' => $types,
            // Signed-in customers get no name/email fields: their identity is read from their
            // account server-side, so rendering the fields would only invite them to be ignored.
            'rbl_is_logged' => $isLogged,
            'rbl_message_max' => ReportBrokenLinkValidator::MESSAGE_MAX,
            'rbl_gdpr_module_id' => $this->getGdprModuleId(),
        ));

        $this->rendered = true;

        return $this->display(__FILE__, 'views/templates/front/button.tpl');
    }

    /**
     * The id of the official GDPR module, so the form can render its consent block when the
     * merchant has it installed. 0 when it is not available.
     *
     * @return int
     */
    private function getGdprModuleId()
    {
        if (!Module::isInstalled('psgdpr') || !Module::isEnabled('psgdpr')) {
            return 0;
        }

        return (int) Module::getModuleIdByName('psgdpr');
    }

    /**
     * The product id for the page being rendered.
     *
     * PrestaShop 1.7+ hands display hooks a ProductLazyArray rather than a Product object, and
     * older/other callers may pass either. 3.x sidestepped this by reading id_product straight
     * off the query string, which silently produced product 0 anywhere the hook was rendered
     * without it. Read the hook parameter first and only fall back to the request.
     *
     * @param array $params
     *
     * @return int
     */
    private function resolveProductId(array $params)
    {
        if (isset($params['product'])) {
            $product = $params['product'];

            if (is_object($product) && !($product instanceof ArrayAccess)) {
                if (isset($product->id_product)) {
                    return (int) $product->id_product;
                }
                if (isset($product->id)) {
                    return (int) $product->id;
                }
            }

            if (is_array($product) || $product instanceof ArrayAccess) {
                if (isset($product['id_product'])) {
                    return (int) $product['id_product'];
                }
                if (isset($product['id'])) {
                    return (int) $product['id'];
                }
            }
        }

        return (int) Tools::getValue('id_product');
    }

    /**
     * @return bool
     */
    private function isProductPage()
    {
        return isset($this->context->controller->php_self)
            && $this->context->controller->php_self === 'product';
    }

    /**
     * Reports for a deleted product would otherwise linger forever pointing at nothing.
     *
     * @param array $params
     */
    public function hookActionProductDelete(array $params)
    {
        $idProduct = 0;
        if (isset($params['id_product'])) {
            $idProduct = (int) $params['id_product'];
        } elseif (isset($params['product']) && is_object($params['product'])) {
            $idProduct = (int) $params['product']->id;
        }

        if ($idProduct > 0) {
            ReportBrokenLinkRepository::deleteByProduct($idProduct);
        }
    }

    /* -------------------------------------------------------------------- */
    /* Back office dashboard widget                                           */
    /* -------------------------------------------------------------------- */

    /**
     * @return string
     */
    public function hookDisplayDashboardTop()
    {
        // This hook fires on more admin pages than the Dashboard on some versions/themes.
        if (!($this->context->controller instanceof AdminDashboardController)) {
            return '';
        }

        $idShop = (int) $this->context->shop->id;
        $counts = ReportBrokenLinkRepository::countByStatus($idShop);
        $open = $counts['open'] + $counts['in_progress'];

        if ($open < 1) {
            return '';
        }

        $this->context->smarty->assign(array(
            'rbl_open_count' => $open,
            'rbl_week_count' => ReportBrokenLinkRepository::countSince($idShop, 7),
            'rbl_dashboard_link' => $this->getConfigureUrl(),
        ));

        return $this->display(__FILE__, 'views/templates/admin/dashboard.tpl');
    }

    /* -------------------------------------------------------------------- */
    /* Notifications                                                          */
    /* -------------------------------------------------------------------- */

    /**
     * Tells the shop's staff about a freshly submitted report.
     *
     * Every value substituted into the templates is escaped first: PrestaShop replaces mail
     * template variables with a plain str_replace and does no escaping of its own, so an
     * unescaped visitor string here would let a reporter inject markup — a phishing link, a
     * fake form — into an e-mail that genuinely comes from the merchant's own shop.
     *
     * @param array $report as stored
     * @param string $productName
     * @param string $productLink
     *
     * @return bool
     */
    public function sendAdminNotification(array $report, $productName, $productLink)
    {
        $recipients = $this->getNotificationRecipients();
        if (!$recipients) {
            PrestaShopLogger::addLog(
                'ReportBrokenLink: a report was saved but no valid notification e-mail is configured.',
                2,
                null,
                'ReportBrokenLink'
            );

            return false;
        }

        $typeLabels = $this->getTypeLabels();
        $reporter = $report['customer_name'] !== ''
            ? $report['customer_name']
            : $this->l('A visitor');

        $sent = (bool) Mail::Send(
            (int) $this->context->language->id,
            'report_broken_link',
            $this->l('New issue reported on a product page'),
            array(
                '{reporter}' => $this->escapeForMail($reporter),
                '{reporter_email}' => $this->escapeForMail($report['customer_email'] !== '' ? $report['customer_email'] : $this->l('Not provided')),
                '{product}' => $this->escapeForMail($productName),
                '{product_link}' => $this->escapeForMail($productLink),
                '{report_type}' => $this->escapeForMail(isset($typeLabels[$report['report_type']]) ? $typeLabels[$report['report_type']] : $report['report_type']),
                '{message}' => $this->escapeForMail($report['message']),
                '{report_date}' => $this->escapeForMail($this->formatDate($report['created_at'])),
                '{admin_link}' => $this->escapeForMail($this->getCachedAdminLink()),
            ),
            $recipients,
            null,
            // From: must stay the shop's own address. 3.x put the customer's address here, which
            // makes the shop's mail server send mail claiming to be from a domain it has no SPF
            // or DKIM authority over, so the notifications get spam-foldered or rejected.
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . $this->name . '/mails/',
            false,
            (int) $this->context->shop->id,
            null,
            // The reporter belongs in Reply-To, which is what a merchant hitting "reply" wants.
            $report['customer_email'] !== '' ? $report['customer_email'] : null
        );

        if (!$sent) {
            PrestaShopLogger::addLog(
                'ReportBrokenLink: Mail::Send() failed for the new-report notification.',
                3,
                null,
                'ReportBrokenLink'
            );
        }

        return $sent;
    }

    /**
     * Tells the reporter their report was dealt with. Only ever sent when the merchant enabled
     * the feature and the reporter actually left an address.
     *
     * @param array $report as stored, after the status change
     * @param string $productName
     *
     * @return bool
     */
    public function sendCustomerResolutionEmail(array $report, $productName)
    {
        if (!Configuration::get('REPORTBROKENLINK_NOTIFY_CUSTOMER')
            || $report['customer_email'] === ''
            || !Validate::isEmail($report['customer_email'])) {
            return false;
        }

        $response = trim((string) $report['admin_response']);

        return (bool) Mail::Send(
            (int) $this->context->language->id,
            'report_resolved',
            $this->l('Thank you - the issue you reported has been fixed'),
            array(
                '{reporter}' => $this->escapeForMail($report['customer_name'] !== '' ? $report['customer_name'] : $this->l('there')),
                '{product}' => $this->escapeForMail($productName),
                '{message}' => $this->escapeForMail($report['message']),
                '{admin_response}' => $this->escapeForMail($response !== '' ? $response : $this->l('We have looked into it and the page should be correct now.')),
                '{shop_name}' => $this->escapeForMail(Configuration::get('PS_SHOP_NAME')),
            ),
            $report['customer_email'],
            null,
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . $this->name . '/mails/',
            false,
            (int) $this->context->shop->id
        );
    }

    /**
     * Escapes a value for safe substitution into the HTML mail template.
     *
     * The same array is substituted into both the .html and the .txt template by PrestaShop,
     * and there is no way to vary it per format. Escaping wins over the cosmetic cost of an
     * entity showing up in the rarely-read plain-text part; the templates keep line breaks with
     * CSS (white-space: pre-wrap) rather than <br>, so nothing HTML-only leaks into the text.
     *
     * @param string $value
     *
     * @return string
     */
    private function escapeForMail($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Link::getAdminLink() cannot be trusted from front-office code: it needs a logged-in
     * employee to resolve the admin base and its token, which never exists there. getContent()
     * caches a tokenless admin link every time an employee opens the configure page; this reads
     * that cached value so a notification e-mail never carries a broken link.
     *
     * @return string
     */
    private function getCachedAdminLink()
    {
        $cached = Configuration::get('REPORTBROKENLINK_ADMIN_LINK');
        if ($cached) {
            return $cached;
        }

        if (defined('_PS_BASE_URL_') && defined('__PS_BASE_URI__')) {
            return _PS_BASE_URL_ . __PS_BASE_URI__;
        }

        return Tools::getShopDomainSsl(true, true);
    }

    /* -------------------------------------------------------------------- */
    /* Configuration page                                                     */
    /* -------------------------------------------------------------------- */

    public function getContent()
    {
        $this->html = '';

        Configuration::updateValue(
            'REPORTBROKENLINK_ADMIN_LINK',
            $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name
        );

        if (Tools::isSubmit('rbl_export')) {
            // Exits after streaming the file; only returns if it could not start.
            $this->processExport();
        }

        if (Tools::isSubmit('submitReportbrokenlinkSettings')) {
            $this->processSettingsForm();
        } elseif (Tools::isSubmit('rbl_save_report')) {
            $this->processReportUpdate();
        } elseif (Tools::isSubmit('rbl_bulk_resolve')
            || Tools::isSubmit('rbl_bulk_progress')
            || Tools::isSubmit('rbl_bulk_spam')
            || Tools::isSubmit('rbl_bulk_delete')) {
            $this->processBulkAction();
        }

        $this->addFlashMessage();

        return $this->html
            . $this->renderIntroduction()
            . $this->renderReportList()
            . $this->renderSettingsForm();
    }

    /**
     * Turns the rbl_conf parameter left by a post/redirect/get cycle into a confirmation
     * message, so refreshing the page never repeats an action.
     */
    private function addFlashMessage()
    {
        $conf = (int) Tools::getValue('rbl_conf');
        $count = (int) Tools::getValue('rbl_count');

        if ($conf === 1) {
            $this->html .= $this->displayConfirmation($this->l('Settings updated successfully.'));
        } elseif ($conf === 2) {
            $this->html .= $this->displayConfirmation($this->l('Report updated successfully.'));
        } elseif ($conf === 3) {
            $this->html .= $this->displayConfirmation(sprintf($this->l('%d report(s) updated.'), $count));
        } elseif ($conf === 4) {
            $this->html .= $this->displayConfirmation(sprintf($this->l('%d report(s) deleted.'), $count));
        } elseif ($conf === 5) {
            $this->html .= $this->displayConfirmation($this->l('Report updated and the customer has been notified by e-mail.'));
        }
    }

    /**
     * @param string $params extra query string, already URL-safe
     *
     * @return string
     */
    private function getConfigureUrl($params = '')
    {
        return $this->context->link->getAdminLink('AdminModules', true)
            . '&configure=' . $this->name . $params;
    }

    /* ------------------------- introduction panel ------------------------ */

    /**
     * @return string
     */
    private function renderIntroduction()
    {
        $idShop = (int) $this->context->shop->id;
        $idLang = (int) $this->context->language->id;
        $counts = ReportBrokenLinkRepository::countByStatus($idShop);

        $this->context->smarty->assign(array(
            'rbl_checks' => $this->getHealthChecks(),
            'rbl_counts' => $counts,
            'rbl_open_count' => $counts['open'] + $counts['in_progress'],
            'rbl_week_count' => ReportBrokenLinkRepository::countSince($idShop, 7),
            'rbl_month_count' => ReportBrokenLinkRepository::countSince($idShop, 30),
            'rbl_top_products' => ReportBrokenLinkRepository::getTopReportedProducts($idShop, $idLang),
            'rbl_status_labels' => $this->getStatusLabels(),
            'rbl_configure_url' => $this->getConfigureUrl(),
            'rbl_this_path' => $this->_path,
        ));

        return $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');
    }

    /**
     * Real checks against the live configuration, rather than a list of instructions the
     * merchant has to verify by hand.
     *
     * @return array each entry: status (ok|warning|error), label
     */
    private function getHealthChecks()
    {
        $checks = array();

        $checks[] = $this->isButtonEnabled()
            ? array('status' => 'ok', 'label' => $this->l('The report button is switched on.'))
            : array('status' => 'warning', 'label' => $this->l('The report button is switched off, so visitors cannot report anything right now.'));

        $recipients = $this->getNotificationRecipients();
        if ($recipients) {
            $checks[] = array('status' => 'ok', 'label' => sprintf(
                $this->l('New reports will be e-mailed to %d address(es).'),
                count($recipients)
            ));
        } else {
            $checks[] = array('status' => 'error', 'label' => $this->l('No valid notification e-mail address is set. Reports will still be saved and listed below, but nobody will be alerted.'));
        }

        $types = $this->getEnabledTypes();
        $checks[] = count($types) === count(ReportBrokenLinkRepository::REPORT_TYPES)
            ? array('status' => 'ok', 'label' => $this->l('Visitors can report all four issue types.'))
            : array('status' => 'ok', 'label' => sprintf($this->l('Visitors can report %d of the 4 issue types.'), count($types)));

        $rateLimit = (int) Configuration::get('REPORTBROKENLINK_RATE_LIMIT');
        $checks[] = $rateLimit > 0
            ? array('status' => 'ok', 'label' => sprintf($this->l('Spam protection is on: at most %d report(s) per hour per visitor per product.'), $rateLimit))
            : array('status' => 'warning', 'label' => $this->l('The per-product rate limit is off. Only the global limit of 10 reports per hour per visitor still applies.'));

        if (Configuration::get('REPORTBROKENLINK_RECAPTCHA')) {
            $configured = trim((string) Configuration::get('REPORTBROKENLINK_RECAPTCHA_SITE')) !== ''
                && trim((string) Configuration::get('REPORTBROKENLINK_RECAPTCHA_SECRET')) !== '';
            $checks[] = $configured
                ? array('status' => 'ok', 'label' => $this->l('reCAPTCHA v3 is enabled and configured.'))
                : array('status' => 'error', 'label' => $this->l('reCAPTCHA is enabled but the site key or secret key is missing, so it is being skipped. Add both keys or switch reCAPTCHA off.'));
        }

        return $checks;
    }

    /* --------------------------- report list ----------------------------- */

    /**
     * Reads the list filters out of the request.
     *
     * @return array
     */
    private function getListFilters()
    {
        return array(
            'id_shop' => (int) $this->context->shop->id,
            'status' => (string) Tools::getValue('rbl_status', ''),
            'report_type' => (string) Tools::getValue('rbl_type', ''),
            'id_product' => (int) Tools::getValue('rbl_product', 0),
            'id_category' => (int) Tools::getValue('rbl_category', 0),
            'date_from' => (string) Tools::getValue('rbl_from', ''),
            'date_to' => (string) Tools::getValue('rbl_to', ''),
            'search' => trim((string) Tools::getValue('rbl_search', '')),
        );
    }

    /**
     * The current filters as a query string, so pagination links keep them.
     *
     * @return string
     */
    private function getFilterQueryString()
    {
        $map = array(
            'rbl_status' => 'status',
            'rbl_type' => 'report_type',
            'rbl_product' => 'id_product',
            'rbl_category' => 'id_category',
            'rbl_from' => 'date_from',
            'rbl_to' => 'date_to',
            'rbl_search' => 'search',
        );

        $filters = $this->getListFilters();
        $query = '';
        foreach ($map as $param => $key) {
            if (!empty($filters[$key])) {
                $query .= '&' . $param . '=' . urlencode($filters[$key]);
            }
        }

        $orderBy = (string) Tools::getValue('rbl_order_by', '');
        $orderWay = (string) Tools::getValue('rbl_order_way', '');
        if ($orderBy !== '') {
            $query .= '&rbl_order_by=' . urlencode($orderBy) . '&rbl_order_way=' . urlencode($orderWay);
        }

        return $query;
    }

    /**
     * @return string
     */
    private function renderReportList()
    {
        $idLang = (int) $this->context->language->id;
        $filters = $this->getListFilters();

        $perPage = max(5, min(300, (int) Configuration::get('REPORTBROKENLINK_PER_PAGE')));
        $total = ReportBrokenLinkRepository::countSearch($filters);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) Tools::getValue('rbl_page', 1)), $pages);
        $offset = ($page - 1) * $perPage;

        $orderBy = (string) Tools::getValue('rbl_order_by', 'created_at');
        $orderWay = (string) Tools::getValue('rbl_order_way', 'DESC');

        $reports = ReportBrokenLinkRepository::search($filters, $idLang, $orderBy, $orderWay, $offset, $perPage);
        $reports = $this->decorateReports($reports);

        $this->context->smarty->assign(array(
            'rbl_reports' => $reports,
            'rbl_total' => $total,
            'rbl_page' => $page,
            'rbl_pages' => $pages,
            'rbl_configure_url' => $this->getConfigureUrl(),
            'rbl_filter_query' => $this->getFilterQueryString(),
            'rbl_export_url' => $this->getConfigureUrl('&rbl_export=1' . $this->getFilterQueryString()),
            'rbl_filters' => $filters,
            'rbl_order_by' => $orderBy,
            'rbl_order_way' => $orderWay,
            'rbl_type_labels' => $this->getTypeLabels(),
            'rbl_status_labels' => $this->getStatusLabels(),
            'rbl_categories' => $this->getCategoryOptions($idLang),
            'rbl_has_filters' => $this->hasActiveFilters($filters),
            'rbl_notify_enabled' => (bool) Configuration::get('REPORTBROKENLINK_NOTIFY_CUSTOMER'),
            'rbl_this_path' => $this->_path,
            'rbl_admin_token' => Tools::getAdminTokenLite('AdminModules'),
        ));

        return $this->context->smarty->fetch($this->local_path . 'views/templates/admin/reports.tpl');
    }

    /**
     * @param array $filters
     *
     * @return bool
     */
    private function hasActiveFilters(array $filters)
    {
        foreach (array('status', 'report_type', 'id_product', 'id_category', 'date_from', 'date_to', 'search') as $key) {
            if (!empty($filters[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adds the display-only fields the list template needs.
     *
     * @param array $reports
     *
     * @return array
     */
    private function decorateReports(array $reports)
    {
        $typeLabels = $this->getTypeLabels();

        foreach ($reports as &$report) {
            $report['type_label'] = isset($typeLabels[$report['report_type']])
                ? $typeLabels[$report['report_type']]
                : $report['report_type'];

            $report['product_deleted'] = ($report['product_name'] === null || $report['product_name'] === '');
            $report['product_display'] = $report['product_deleted']
                ? sprintf($this->l('Deleted product #%d'), (int) $report['id_product'])
                : $report['product_name'];

            $report['front_link'] = $report['product_deleted']
                ? ''
                : $this->context->link->getProductLink((int) $report['id_product']);

            $report['edit_link'] = $report['product_deleted']
                ? ''
                : $this->context->link->getAdminLink('AdminProducts', true, array(
                    'id_product' => (int) $report['id_product'],
                    'updateproduct' => 1,
                ));

            $report['customer_link'] = (int) $report['id_customer'] > 0
                ? $this->context->link->getAdminLink('AdminCustomers', true, array(
                    'id_customer' => (int) $report['id_customer'],
                    'viewcustomer' => 1,
                ))
                : '';

            $report['customer_display'] = trim((string) $report['customer_firstname'] . ' ' . (string) $report['customer_lastname']);
            if ($report['customer_display'] === '') {
                $report['customer_display'] = $report['customer_name'] !== ''
                    ? $report['customer_name']
                    : $this->l('Guest');
            }

            $report['created_display'] = $this->formatDate($report['created_at']);
            $report['updated_display'] = $this->formatDate($report['updated_at']);
            $report['excerpt'] = $this->excerpt($report['message'], 90);
        }

        return $reports;
    }

    /**
     * @param string $text
     * @param int $length
     *
     * @return string
     */
    private function excerpt($text, $length)
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));

        if (ReportBrokenLinkValidator::length($text) <= $length) {
            return $text;
        }

        return Tools::substr($text, 0, $length) . '...';
    }

    /**
     * @param int $idLang
     *
     * @return array
     */
    private function getCategoryOptions($idLang)
    {
        $categories = Category::getSimpleCategories($idLang);

        return is_array($categories) ? $categories : array();
    }

    /* ------------------------- list write actions ------------------------ */

    /**
     * Saves the status and the internal response of a single report, and — when the merchant
     * asked for it — tells the reporter their issue was resolved.
     */
    private function processReportUpdate()
    {
        $idShop = (int) $this->context->shop->id;

        // The list is one big form, so the row being saved is identified by the value of the
        // submit button that was clicked, and its fields are suffixed with the same id. Nesting
        // a per-row <form> inside the bulk-action form instead would be invalid HTML.
        $idReport = (int) Tools::getValue('rbl_save_report');

        // Scoped by shop, so a crafted id cannot reach another shop's reports.
        $report = ReportBrokenLinkRepository::getById($idReport, $idShop);
        if (!$report) {
            $this->html .= $this->displayError($this->l('That report no longer exists.'));

            return;
        }

        $status = ReportBrokenLinkValidator::normalizeStatus(
            Tools::getValue('rbl_report_status_' . $idReport),
            ReportBrokenLinkRepository::STATUSES
        );

        if ($status === null) {
            $this->html .= $this->displayError($this->l('Unknown status.'));

            return;
        }

        $response = ReportBrokenLinkValidator::sanitizeAdminResponse(
            Tools::getValue('rbl_admin_response_' . $idReport)
        );

        ReportBrokenLinkRepository::updateReport($idReport, $idShop, $status, $response);

        $notified = false;
        // Only notify on the transition into resolved, and only when asked — re-saving a report
        // that was already resolved must not mail the customer again.
        if ($status === 'resolved'
            && $report['status'] !== 'resolved'
            && Tools::getValue('rbl_notify_customer_' . $idReport)) {
            $updated = array_merge($report, array('status' => $status, 'admin_response' => $response));
            $notified = $this->sendCustomerResolutionEmail($updated, $this->getProductName((int) $report['id_product']));
        }

        Tools::redirectAdmin($this->getConfigureUrl(
            '&rbl_conf=' . ($notified ? 5 : 2) . $this->getFilterQueryString()
        ));
    }

    /**
     * Applies a bulk action to the checked rows.
     */
    private function processBulkAction()
    {
        $idShop = (int) $this->context->shop->id;
        $ids = array_map('intval', (array) Tools::getValue('rbl_ids'));
        $ids = array_values(array_filter($ids));

        if (!$ids) {
            $this->html .= $this->displayError($this->l('Please tick at least one report first.'));

            return;
        }

        if (Tools::isSubmit('rbl_bulk_delete')) {
            ReportBrokenLinkRepository::bulkDelete($ids, $idShop);
            $conf = 4;
        } else {
            $status = 'resolved';
            if (Tools::isSubmit('rbl_bulk_spam')) {
                $status = 'spam';
            } elseif (Tools::isSubmit('rbl_bulk_progress')) {
                $status = 'in_progress';
            }
            ReportBrokenLinkRepository::bulkUpdateStatus($ids, $idShop, $status);
            $conf = 3;
        }

        Tools::redirectAdmin($this->getConfigureUrl(
            '&rbl_conf=' . $conf . '&rbl_count=' . count($ids) . $this->getFilterQueryString()
        ));
    }

    /**
     * @param int $idProduct
     *
     * @return string
     */
    private function getProductName($idProduct)
    {
        $product = new Product((int) $idProduct, false, (int) $this->context->language->id);

        return Validate::isLoadedObject($product) ? (string) $product->name : '';
    }

    /* ---------------------------- CSV export ----------------------------- */

    /**
     * Streams the currently filtered reports as CSV and exits.
     *
     * This runs inside the configure page rather than from a standalone PHP file, so it is
     * covered by PrestaShop's admin authentication and the AdminModules token — a public
     * export endpoint would hand every reporter's e-mail address to anyone who found the URL.
     */
    private function processExport()
    {
        // getContent() runs before the admin layout is rendered, so discarding the buffers and
        // sending our own headers works. If some future version (or another module) has already
        // flushed output, streaming the CSV would append it to a half-rendered HTML page and
        // hand the merchant a corrupt file, so bail out with a visible error instead.
        if (headers_sent()) {
            $this->html .= $this->displayError(
                $this->l('The export could not start because the page had already begun rendering. Please try again, and report this if it keeps happening.')
            );

            return;
        }

        $rows = ReportBrokenLinkRepository::getForExport(
            $this->getListFilters(),
            (int) $this->context->language->id
        );

        $typeLabels = $this->getTypeLabels();
        $statusLabels = $this->getStatusLabels();

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="reportbrokenlink-' . date('Ymd-His') . '.csv"');
        header('Cache-Control: no-store, no-cache');

        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel detects the encoding

        fputcsv($output, array(
            'id_report',
            'created_at',
            'updated_at',
            'id_product',
            'product',
            'report_type',
            'status',
            'customer_name',
            'customer_email',
            'id_customer',
            'message',
            'admin_response',
        ), ';');

        foreach (array_slice($rows, 0, self::EXPORT_MAX_ROWS) as $row) {
            fputcsv($output, array(
                (int) $row['id_report'],
                $row['created_at'],
                $row['updated_at'],
                (int) $row['id_product'],
                (string) $row['product_name'],
                isset($typeLabels[$row['report_type']]) ? $typeLabels[$row['report_type']] : $row['report_type'],
                isset($statusLabels[$row['status']]) ? $statusLabels[$row['status']] : $row['status'],
                $this->csvCell($row['customer_name']),
                $this->csvCell($row['customer_email']),
                (int) $row['id_customer'],
                $this->csvCell($row['message']),
                $this->csvCell($row['admin_response']),
            ), ';');
        }

        fclose($output);
        exit;
    }

    /**
     * Neutralises CSV formula injection.
     *
     * A reporter can type "=HYPERLINK(...)" into the message field; Excel and LibreOffice will
     * happily execute that when the merchant opens the export. Prefixing with a tab makes the
     * spreadsheet treat the cell as text while staying readable.
     *
     * @param string $value
     *
     * @return string
     */
    private function csvCell($value)
    {
        $value = (string) $value;

        if ($value !== '' && strpos("=+-@\t\r", $value[0]) !== false) {
            return "\t" . $value;
        }

        return $value;
    }

    /* --------------------------- settings form --------------------------- */

    private function processSettingsForm()
    {
        $errors = array();

        $emails = trim((string) Tools::getValue('REPORTBROKENLINK_EMAILS'));
        $valid = array();
        foreach (explode(',', $emails) as $address) {
            $address = trim($address);
            if ($address === '') {
                continue;
            }
            if (!Validate::isEmail($address)) {
                $errors[] = sprintf($this->l('"%s" is not a valid e-mail address.'), $address);
                continue;
            }
            $valid[] = $address;
        }

        if (!$valid) {
            $errors[] = $this->l('Please enter at least one notification e-mail address.');
        }

        $types = array();
        foreach (ReportBrokenLinkRepository::REPORT_TYPES as $type) {
            if (Tools::getValue('REPORTBROKENLINK_TYPES_' . $type)) {
                $types[] = $type;
            }
        }
        if (!$types) {
            $errors[] = $this->l('Please enable at least one issue type, otherwise the form has nothing to offer.');
        }

        $rateLimit = (int) Tools::getValue('REPORTBROKENLINK_RATE_LIMIT');
        if ($rateLimit < 0 || $rateLimit > 100) {
            $errors[] = $this->l('The rate limit must be between 0 (off) and 100.');
        }

        $perPage = (int) Tools::getValue('REPORTBROKENLINK_PER_PAGE');
        if ($perPage < 5 || $perPage > 300) {
            $errors[] = $this->l('Reports per page must be between 5 and 300.');
        }

        $position = (string) Tools::getValue('REPORTBROKENLINK_POSITION');
        if (!in_array($position, array('info', 'footer'), true)) {
            $errors[] = $this->l('Please choose a valid button position.');
        }

        $recaptcha = (bool) Tools::getValue('REPORTBROKENLINK_RECAPTCHA');
        $siteKey = trim((string) Tools::getValue('REPORTBROKENLINK_RECAPTCHA_SITE'));
        $secretKey = trim((string) Tools::getValue('REPORTBROKENLINK_RECAPTCHA_SECRET'));
        if ($recaptcha && ($siteKey === '' || $secretKey === '')) {
            $errors[] = $this->l('To enable reCAPTCHA, both the site key and the secret key are required.');
        }

        if ($errors) {
            foreach ($errors as $error) {
                $this->html .= $this->displayError($error);
            }

            return;
        }

        Configuration::updateValue('REPORTBROKENLINK_ENABLED', (bool) Tools::getValue('REPORTBROKENLINK_ENABLED'));
        Configuration::updateValue('REPORTBROKENLINK_EMAILS', implode(',', $valid));
        Configuration::updateValue('REPORTBROKENLINK_TYPES', implode(',', $types));
        Configuration::updateValue('REPORTBROKENLINK_POSITION', $position);
        Configuration::updateValue('REPORTBROKENLINK_GUEST', (bool) Tools::getValue('REPORTBROKENLINK_GUEST'));
        Configuration::updateValue('REPORTBROKENLINK_RATE_LIMIT', $rateLimit);
        Configuration::updateValue('REPORTBROKENLINK_DEDUP', (bool) Tools::getValue('REPORTBROKENLINK_DEDUP'));
        Configuration::updateValue('REPORTBROKENLINK_NOTIFY_CUSTOMER', (bool) Tools::getValue('REPORTBROKENLINK_NOTIFY_CUSTOMER'));
        Configuration::updateValue('REPORTBROKENLINK_PER_PAGE', $perPage);
        Configuration::updateValue('REPORTBROKENLINK_RECAPTCHA', $recaptcha);
        Configuration::updateValue('REPORTBROKENLINK_RECAPTCHA_SITE', $siteKey);
        Configuration::updateValue('REPORTBROKENLINK_RECAPTCHA_SECRET', $secretKey);

        Tools::redirectAdmin($this->getConfigureUrl('&rbl_conf=1'));
    }

    /**
     * @return string
     */
    private function renderSettingsForm()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->identifier = $this->identifier;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->title = $this->displayName;
        $helper->submit_action = 'submitReportbrokenlinkSettings';
        $helper->tpl_vars = array('fields_value' => $this->getConfigFormValues());

        return $helper->generateForm(array($this->getConfigForm()));
    }

    /**
     * @return array
     */
    private function getConfigFormValues()
    {
        $values = array(
            'REPORTBROKENLINK_ENABLED' => (bool) Configuration::get('REPORTBROKENLINK_ENABLED'),
            'REPORTBROKENLINK_EMAILS' => (string) Configuration::get('REPORTBROKENLINK_EMAILS'),
            'REPORTBROKENLINK_POSITION' => (string) Configuration::get('REPORTBROKENLINK_POSITION'),
            'REPORTBROKENLINK_GUEST' => (bool) Configuration::get('REPORTBROKENLINK_GUEST'),
            'REPORTBROKENLINK_RATE_LIMIT' => (int) Configuration::get('REPORTBROKENLINK_RATE_LIMIT'),
            'REPORTBROKENLINK_DEDUP' => (bool) Configuration::get('REPORTBROKENLINK_DEDUP'),
            'REPORTBROKENLINK_NOTIFY_CUSTOMER' => (bool) Configuration::get('REPORTBROKENLINK_NOTIFY_CUSTOMER'),
            'REPORTBROKENLINK_PER_PAGE' => (int) Configuration::get('REPORTBROKENLINK_PER_PAGE'),
            'REPORTBROKENLINK_RECAPTCHA' => (bool) Configuration::get('REPORTBROKENLINK_RECAPTCHA'),
            'REPORTBROKENLINK_RECAPTCHA_SITE' => (string) Configuration::get('REPORTBROKENLINK_RECAPTCHA_SITE'),
            'REPORTBROKENLINK_RECAPTCHA_SECRET' => (string) Configuration::get('REPORTBROKENLINK_RECAPTCHA_SECRET'),
        );

        $enabled = $this->getEnabledTypes();
        foreach (ReportBrokenLinkRepository::REPORT_TYPES as $type) {
            $values['REPORTBROKENLINK_TYPES_' . $type] = in_array($type, $enabled, true);
        }

        return $values;
    }

    /**
     * @return array
     */
    private function getConfigForm()
    {
        $typeLabels = $this->getTypeLabels();
        $typeChoices = array();
        foreach (ReportBrokenLinkRepository::REPORT_TYPES as $type) {
            $typeChoices[] = array('id' => $type, 'name' => $typeLabels[$type], 'val' => $type);
        }

        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'html',
                        'name' => 'divider_general',
                        'html_content' => '<h3 style="margin-top:0;">' . $this->l('1. The button') . '</h3><hr>',
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Show the report button'),
                        'name' => 'REPORTBROKENLINK_ENABLED',
                        'is_bool' => true,
                        'desc' => $this->l('Turn the whole feature off without uninstalling the module. Reports already collected stay in the list below.'),
                        'values' => array(
                            array('id' => 'enabled_on', 'value' => 1, 'label' => $this->l('Enabled')),
                            array('id' => 'enabled_off', 'value' => 0, 'label' => $this->l('Disabled')),
                        ),
                    ),
                    array(
                        'type' => 'radio',
                        'label' => $this->l('Button position'),
                        'name' => 'REPORTBROKENLINK_POSITION',
                        'class' => 't',
                        'desc' => $this->l('Where the button appears on the product page. Both positions work with the default theme; if your theme is heavily customised, try the other one.'),
                        'values' => array(
                            array('id' => 'position_info', 'value' => 'info', 'label' => $this->l('Next to the product information (recommended)')),
                            array('id' => 'position_footer', 'value' => 'footer', 'label' => $this->l('Below the product description')),
                        ),
                    ),
                    array(
                        'type' => 'checkbox',
                        'label' => $this->l('Issue types visitors can pick'),
                        'name' => 'REPORTBROKENLINK_TYPES',
                        'desc' => $this->l('Untick a type to remove it from the dropdown. At least one is required.'),
                        'values' => array(
                            'query' => $typeChoices,
                            'id' => 'id',
                            'name' => 'name',
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Let visitors report without an account'),
                        'name' => 'REPORTBROKENLINK_GUEST',
                        'is_bool' => true,
                        'desc' => $this->l('When disabled, only signed-in customers see the button. Guests are usually the ones who spot broken pages, so leaving this on is recommended.'),
                        'values' => array(
                            array('id' => 'guest_on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'guest_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                    array(
                        'type' => 'html',
                        'name' => 'divider_notifications',
                        'html_content' => '<h3>' . $this->l('2. Notifications') . '</h3><hr>',
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Send new reports to'),
                        'name' => 'REPORTBROKENLINK_EMAILS',
                        'desc' => $this->l('One or more addresses, separated by commas, e.g. web@example.com,shop@example.com. Reports are always saved to the list below even if e-mail fails.'),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('E-mail the customer when you resolve a report'),
                        'name' => 'REPORTBROKENLINK_NOTIFY_CUSTOMER',
                        'is_bool' => true,
                        'desc' => $this->l('Adds a "notify the customer" tick box when you set a report to Resolved. Only ever sent if the reporter left an e-mail address.'),
                        'values' => array(
                            array('id' => 'notify_on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'notify_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                    array(
                        'type' => 'html',
                        'name' => 'divider_spam',
                        'html_content' => '<h3>' . $this->l('3. Spam protection') . '</h3><p class="text-muted">'
                            . $this->l('A honeypot field and a signed form token are always active and need no configuration.')
                            . '</p><hr>',
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Reports per hour, per visitor, per product'),
                        'name' => 'REPORTBROKENLINK_RATE_LIMIT',
                        'class' => 'fixed-width-xs',
                        'desc' => $this->l('0 turns this limit off. A separate global limit of 10 reports per hour per visitor always applies. Visitors are counted by a one-way hash of their IP address; the address itself is never stored.'),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Flag repeat reports as duplicates'),
                        'name' => 'REPORTBROKENLINK_DEDUP',
                        'is_bool' => true,
                        'desc' => $this->l('If the same person reports the same issue on the same product twice within 24 hours, the second one is filed as "Duplicate" instead of alerting you again.'),
                        'values' => array(
                            array('id' => 'dedup_on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'dedup_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Use reCAPTCHA v3'),
                        'name' => 'REPORTBROKENLINK_RECAPTCHA',
                        'is_bool' => true,
                        'desc' => $this->l('Optional. Adds Google reCAPTCHA v3 to the form. This loads a Google script on your product pages, which may require a mention in your privacy policy.'),
                        'values' => array(
                            array('id' => 'recaptcha_on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'recaptcha_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('reCAPTCHA site key'),
                        'name' => 'REPORTBROKENLINK_RECAPTCHA_SITE',
                        'desc' => $this->l('From google.com/recaptcha, using the v3 setting. Only needed when reCAPTCHA is switched on.'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('reCAPTCHA secret key'),
                        'name' => 'REPORTBROKENLINK_RECAPTCHA_SECRET',
                        'desc' => $this->l('Keep this private. Only needed when reCAPTCHA is switched on.'),
                    ),
                    array(
                        'type' => 'html',
                        'name' => 'divider_list',
                        'html_content' => '<h3>' . $this->l('4. Back office') . '</h3><hr>',
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Reports per page'),
                        'name' => 'REPORTBROKENLINK_PER_PAGE',
                        'class' => 'fixed-width-xs',
                        'desc' => $this->l('How many reports the list above shows at a time. Between 5 and 300.'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }
}
