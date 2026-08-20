<?php
/**
 * @author    MEG Venture <info@megventure.com>
 * @copyright 2019-2026 MEG Venture & Consulting Ltd.
 * @license   https://opensource.org/licenses/MIT MIT License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Receives one report from the product page form.
 *
 * Everything the browser sends is treated as hostile. In particular the recipient of the
 * notification e-mail is never taken from the request — it comes from the module settings and
 * nowhere else. 3.x read it from a hidden field, which turned this endpoint into an open mail
 * relay for anyone who could read the page source.
 *
 * The work happens in postProcess()/displayAjax() rather than in the constructor, so
 * PrestaShop's own init() runs first: maintenance mode, SSL handling and the shop context are
 * all in place before a single value is read.
 */
class ReportBrokenLinkReportModuleFrontController extends ModuleFrontController
{
    /** @var bool No theme header/footer or asset pipeline is needed for a JSON response. */
    public $display_header = false;

    /** @var bool */
    public $display_footer = false;

    /** @var array The JSON payload built by postProcess(). */
    private $result = array();

    public function __construct()
    {
        parent::__construct();

        // Must be set *after* the parent constructor, not as a property default: PrestaShop's
        // ControllerCore::__construct() assigns $this->ajax from the request itself, so a
        // default of true would be overwritten back to false here and Controller::run() would
        // try to render a page template instead of calling displayAjax().
        $this->ajax = true;
    }

    public function postProcess()
    {
        $this->result = $this->handleSubmission();
    }

    /**
     * Nothing to render: this controller never produces a page.
     */
    public function initContent()
    {
    }

    public function displayAjax()
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo json_encode($this->result);
        exit;
    }

    /**
     * @return array the JSON response
     */
    private function handleSubmission()
    {
        /** @var ReportBrokenLink $module */
        $module = $this->module;

        // A report changes server state and sends mail, so it must not be reachable by GET.
        // 3.x sent the whole thing as a GET query string (the jQuery call passed `post` instead
        // of `type`), which put every reporter's e-mail address into the access logs.
        if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
            return $this->fail($module->l('Invalid request.'));
        }

        if (!$module->isButtonEnabled()) {
            return $this->fail($module->l('Reporting is currently disabled on this shop.'));
        }

        // Honeypot: the field is hidden from real people, and bots fill everything in.
        // Answer with a plain success so a bot has no signal to adapt to.
        if (trim((string) Tools::getValue('rbl_website')) !== '') {
            return $this->succeed($module);
        }

        $idProduct = (int) Tools::getValue('rbl_id_product');
        if ($idProduct < 1) {
            return $this->fail($module->l('Invalid request.'));
        }

        if (!$module->verifyToken(Tools::getValue('rbl_token'), $idProduct)) {
            return $this->fail($module->l('This form has expired. Please reload the page and try again.'));
        }

        $product = $this->loadReportableProduct($idProduct);
        if (!$product) {
            return $this->fail($module->l('This product no longer exists.'));
        }

        $customer = $this->context->customer;
        $isLogged = $customer && $customer->isLogged();

        if (!$isLogged && !Configuration::get('REPORTBROKENLINK_GUEST')) {
            return $this->fail($module->l('Please sign in to report an issue.'));
        }

        $type = ReportBrokenLinkValidator::normalizeType(
            Tools::getValue('rbl_type'),
            $module->getEnabledTypes()
        );
        if ($type === null) {
            return $this->failField('type', $module->l('Please choose what is wrong with this page.'));
        }

        $message = ReportBrokenLinkValidator::sanitizeMessage(Tools::getValue('rbl_message'));
        $messageError = ReportBrokenLinkValidator::validateMessage($message);
        if ($messageError === 'required') {
            return $this->failField('message', $module->l('Please describe the issue.'));
        }
        if ($messageError === 'too_short') {
            return $this->failField('message', sprintf(
                $module->l('Please use at least %d characters so we can understand the issue.'),
                ReportBrokenLinkValidator::MESSAGE_MIN
            ));
        }
        if ($messageError !== null) {
            return $this->failField('message', sprintf(
                $module->l('Please keep your description under %d characters.'),
                ReportBrokenLinkValidator::MESSAGE_MAX
            ));
        }

        // A signed-in customer's identity comes from their account, never from the form: the
        // posted values would let anyone file a report under someone else's name.
        if ($isLogged) {
            $name = ReportBrokenLinkValidator::sanitizeName($customer->firstname . ' ' . $customer->lastname);
            $email = ReportBrokenLinkValidator::sanitizeEmail($customer->email);
        } else {
            $name = ReportBrokenLinkValidator::sanitizeName(Tools::getValue('rbl_name'));
            $email = ReportBrokenLinkValidator::sanitizeEmail(Tools::getValue('rbl_email'));

            // The address is optional — it is only used to tell the reporter we fixed it — but
            // a malformed one is worth flagging rather than silently dropping.
            if (ReportBrokenLinkValidator::validateEmail($email, false) !== null) {
                return $this->failField('email', $module->l('Please enter a valid e-mail address, or leave the field empty.'));
            }
        }

        $ip = Tools::getRemoteAddr();
        $ipHash = $module->hashIp($ip);

        if ($module->isRateLimited($ipHash, $idProduct)) {
            return $this->fail($module->l('Thanks - you have already reported this recently. Please give us a little time to look into it.'));
        }

        if (!$module->verifyRecaptcha(Tools::getValue('g-recaptcha-response'), $ip)) {
            return $this->fail($module->l('We could not verify that you are human. Please reload the page and try again.'));
        }

        // A repeat of the same issue by the same person is filed quietly as a duplicate: the
        // reporter still gets a thank-you (as far as they are concerned it worked), but the
        // merchant is not alerted twice for one problem.
        $isDuplicate = Configuration::get('REPORTBROKENLINK_DEDUP')
            && ReportBrokenLinkRepository::hasRecentDuplicate($idProduct, $type, $ipHash, $email);

        $report = array(
            'id_shop' => (int) $this->context->shop->id,
            'id_product' => $idProduct,
            'id_customer' => $isLogged ? (int) $customer->id : 0,
            'report_type' => $type,
            'customer_email' => $email,
            'customer_name' => $name,
            'message' => $message,
            'status' => $isDuplicate ? 'duplicate' : 'open',
            'ip_hash' => $ipHash,
        );

        $idReport = ReportBrokenLinkRepository::add($report);
        if (!$idReport) {
            PrestaShopLogger::addLog(
                'ReportBrokenLink: could not store a report for product ' . $idProduct . '.',
                3,
                null,
                'ReportBrokenLink'
            );

            return $this->fail($module->l('Your report could not be saved. Please try again in a moment.'));
        }

        if (!$isDuplicate) {
            $report['created_at'] = date('Y-m-d H:i:s');
            // The report is already stored, so a mail failure must not be reported to the
            // visitor as a failure — it is logged and the merchant still sees it in the list.
            // This is the difference that made 3.x lose every report silently for four versions.
            $module->sendAdminNotification(
                $report,
                (string) $product->name,
                $this->context->link->getProductLink($product)
            );
        }

        return $this->succeed($module);
    }

    /**
     * Loads the product a report is being filed against, or false when it is not something a
     * visitor could legitimately be looking at.
     *
     * @param int $idProduct
     *
     * @return Product|false
     */
    private function loadReportableProduct($idProduct)
    {
        $product = new Product($idProduct, false, (int) $this->context->language->id);

        if (!Validate::isLoadedObject($product)) {
            return false;
        }

        if (!$product->active || !$product->isAssociatedToShop((int) $this->context->shop->id)) {
            return false;
        }

        return $product;
    }

    /**
     * @param ReportBrokenLink $module
     *
     * @return array
     */
    private function succeed($module)
    {
        return array(
            'success' => true,
            'message' => $module->l('Thank you! Your report has reached our team and we will review it shortly.'),
        );
    }

    /**
     * @param string $message
     *
     * @return array
     */
    private function fail($message)
    {
        return array('success' => false, 'message' => $message);
    }

    /**
     * A failure the form can attach to a specific field.
     *
     * @param string $field
     * @param string $message
     *
     * @return array
     */
    private function failField($field, $message)
    {
        return array(
            'success' => false,
            'message' => $message,
            'errors' => array($field => $message),
        );
    }
}
