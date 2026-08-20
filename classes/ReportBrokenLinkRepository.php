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
 * Every read and write against `reportbrokenlink_report` lives here.
 *
 * Keeping the SQL in one class means the front controller, the admin page and the dashboard
 * widget cannot each invent their own filtering or their own escaping. Callers pass plain PHP
 * values; this class is responsible for making them safe.
 */
class ReportBrokenLinkRepository
{
    /** @var string Table name, without the shop's table prefix. */
    const TABLE = 'reportbrokenlink_report';

    /** @var string[] Every report type the module understands. */
    const REPORT_TYPES = array('broken_link', 'wrong_price', 'missing_info', 'other');

    /** @var string[] Every status a report can hold. */
    const STATUSES = array('open', 'in_progress', 'resolved', 'duplicate', 'spam');

    /** @var string[] Statuses that still need someone to look at them. */
    const OPEN_STATUSES = array('open', 'in_progress');

    /** @var string[] Columns the admin list may be sorted by, mapped to their SQL expression. */
    private static $sortable = array(
        'id_report' => 'r.id_report',
        'product' => 'product_name',
        'report_type' => 'r.report_type',
        'status' => 'r.status',
        'customer' => 'r.customer_email',
        'created_at' => 'r.created_at',
    );

    /* ---------------------------------------------------------------- */
    /* Writes                                                            */
    /* ---------------------------------------------------------------- */

    /**
     * Stores a new report. Callers must have validated and sanitised the values already.
     *
     * @param array $report
     *
     * @return int the new id, or 0 when the insert failed
     */
    public static function add(array $report)
    {
        $now = date('Y-m-d H:i:s');

        $ok = Db::getInstance()->insert(self::TABLE, array(
            'id_shop' => (int) $report['id_shop'],
            'id_product' => (int) $report['id_product'],
            'id_customer' => $report['id_customer'] ? (int) $report['id_customer'] : null,
            'report_type' => pSQL($report['report_type']),
            'customer_email' => pSQL($report['customer_email']),
            'customer_name' => pSQL($report['customer_name']),
            'message' => pSQL($report['message']),
            'status' => pSQL($report['status']),
            'admin_response' => '',
            'ip_hash' => pSQL($report['ip_hash']),
            'created_at' => pSQL($now),
            'updated_at' => pSQL($now),
        ));

        return $ok ? (int) Db::getInstance()->Insert_ID() : 0;
    }

    /**
     * Updates the staff-editable fields of a single report.
     *
     * created_at is never touched — the timestamp of a report is a fact about when the visitor
     * submitted it, not something the back office is allowed to rewrite.
     *
     * @param int $idReport
     * @param int $idShop scoping the update so one shop cannot edit another shop's reports
     * @param string $status already normalised against STATUSES
     * @param string $adminResponse already sanitised
     *
     * @return bool
     */
    public static function updateReport($idReport, $idShop, $status, $adminResponse)
    {
        return (bool) Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE . '`
             SET `status` = "' . pSQL($status) . '",
                 `admin_response` = "' . pSQL($adminResponse) . '",
                 `updated_at` = "' . pSQL(date('Y-m-d H:i:s')) . '"
             WHERE `id_report` = ' . (int) $idReport . ' AND `id_shop` = ' . (int) $idShop
        );
    }

    /**
     * @param int[] $ids
     * @param int $idShop
     * @param string $status already normalised against STATUSES
     *
     * @return bool
     */
    public static function bulkUpdateStatus(array $ids, $idShop, $status)
    {
        $ids = self::sanitizeIdList($ids);
        if (!$ids) {
            return false;
        }

        return (bool) Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE . '`
             SET `status` = "' . pSQL($status) . '", `updated_at` = "' . pSQL(date('Y-m-d H:i:s')) . '"
             WHERE `id_shop` = ' . (int) $idShop . ' AND `id_report` IN (' . implode(',', $ids) . ')'
        );
    }

    /**
     * @param int[] $ids
     * @param int $idShop
     *
     * @return bool
     */
    public static function bulkDelete(array $ids, $idShop)
    {
        $ids = self::sanitizeIdList($ids);
        if (!$ids) {
            return false;
        }

        return (bool) Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `id_shop` = ' . (int) $idShop . ' AND `id_report` IN (' . implode(',', $ids) . ')'
        );
    }

    /**
     * Drops every report attached to a product. Called from actionProductDelete, standing in
     * for the ON DELETE CASCADE that a MyISAM shop could not give us.
     *
     * @param int $idProduct
     *
     * @return bool
     */
    public static function deleteByProduct($idProduct)
    {
        return (bool) Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_product` = ' . (int) $idProduct
        );
    }

    /* ---------------------------------------------------------------- */
    /* Reads                                                             */
    /* ---------------------------------------------------------------- */

    /**
     * @param int $idReport
     * @param int $idShop
     *
     * @return array|false
     */
    public static function getById($idReport, $idShop)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `id_report` = ' . (int) $idReport . ' AND `id_shop` = ' . (int) $idShop
        );
    }

    /**
     * The admin list. Products are LEFT JOINed so a report whose product has since been
     * deleted still appears (with an empty product name) instead of vanishing from the list.
     *
     * @param array $filters see buildWhere()
     * @param int $idLang
     * @param string $orderBy one of the keys of self::$sortable
     * @param string $orderWay ASC|DESC
     * @param int $offset
     * @param int $limit
     *
     * @return array
     */
    public static function search(array $filters, $idLang, $orderBy = 'created_at', $orderWay = 'DESC', $offset = 0, $limit = 20)
    {
        $orderBy = isset(self::$sortable[$orderBy]) ? self::$sortable[$orderBy] : self::$sortable['created_at'];
        $orderWay = strtoupper($orderWay) === 'ASC' ? 'ASC' : 'DESC';

        $rows = Db::getInstance()->executeS(
            'SELECT r.*, pl.name AS product_name, c.firstname AS customer_firstname, c.lastname AS customer_lastname
             FROM `' . _DB_PREFIX_ . self::TABLE . '` r
             LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                 ON pl.`id_product` = r.`id_product`
                 AND pl.`id_lang` = ' . (int) $idLang . '
                 AND pl.`id_shop` = r.`id_shop`
             LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON c.`id_customer` = r.`id_customer`
             WHERE ' . self::buildWhere($filters) . '
             ORDER BY ' . $orderBy . ' ' . $orderWay . ', r.`id_report` DESC
             LIMIT ' . (int) $offset . ', ' . (int) $limit
        );

        return is_array($rows) ? $rows : array();
    }

    /**
     * @param array $filters
     *
     * @return int
     */
    public static function countSearch(array $filters)
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '` r WHERE ' . self::buildWhere($filters)
        );
    }

    /**
     * Every matching report, ignoring pagination, for the CSV export.
     *
     * @param array $filters
     * @param int $idLang
     *
     * @return array
     */
    public static function getForExport(array $filters, $idLang)
    {
        return self::search($filters, $idLang, 'created_at', 'DESC', 0, 50000);
    }

    /**
     * Report counts per status for the current shop, for the badges above the list.
     *
     * @param int $idShop
     *
     * @return array status => count, with every known status present
     */
    public static function countByStatus($idShop)
    {
        $counts = array_fill_keys(self::STATUSES, 0);

        $rows = Db::getInstance()->executeS(
            'SELECT `status`, COUNT(*) AS total FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `id_shop` = ' . (int) $idShop . '
             GROUP BY `status`'
        );

        foreach ((array) $rows as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = (int) $row['total'];
            }
        }

        return $counts;
    }

    /**
     * @param int $idShop
     * @param int $days
     *
     * @return int reports created in the last $days days
     */
    public static function countSince($idShop, $days)
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `id_shop` = ' . (int) $idShop . '
             AND `created_at` > DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)'
        );
    }

    /**
     * Most-reported products, for the stats panel.
     *
     * @param int $idShop
     * @param int $idLang
     * @param int $limit
     *
     * @return array
     */
    public static function getTopReportedProducts($idShop, $idLang, $limit = 5)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT r.`id_product`, pl.`name` AS product_name, COUNT(*) AS total
             FROM `' . _DB_PREFIX_ . self::TABLE . '` r
             LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                 ON pl.`id_product` = r.`id_product`
                 AND pl.`id_lang` = ' . (int) $idLang . '
                 AND pl.`id_shop` = r.`id_shop`
             WHERE r.`id_shop` = ' . (int) $idShop . '
             AND r.`status` NOT IN ("spam", "duplicate")
             GROUP BY r.`id_product`, pl.`name`
             ORDER BY total DESC
             LIMIT ' . (int) $limit
        );

        return is_array($rows) ? $rows : array();
    }

    /* ---------------------------------------------------------------- */
    /* Anti-spam                                                         */
    /* ---------------------------------------------------------------- */

    /**
     * How many reports this hashed IP filed for this product in the last hour.
     *
     * @param string $ipHash
     * @param int $idProduct
     *
     * @return int
     */
    public static function countRecentByIpForProduct($ipHash, $idProduct)
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `ip_hash` = "' . pSQL($ipHash) . '"
             AND `id_product` = ' . (int) $idProduct . '
             AND `created_at` > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );
    }

    /**
     * How many reports this hashed IP filed across *all* products in the last hour.
     *
     * Without this, a per-product limit of 1/hour still lets one bot file a report against
     * every product in the catalogue in a single pass.
     *
     * @param string $ipHash
     *
     * @return int
     */
    public static function countRecentByIp($ipHash)
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `ip_hash` = "' . pSQL($ipHash) . '"
             AND `created_at` > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );
    }

    /**
     * True when the same reporter already filed the same kind of report about the same product
     * within the last 24 hours. "Same reporter" is the hashed IP, or the e-mail address when one
     * was supplied — a visitor who reports from home and then from their phone is still one person.
     *
     * @param int $idProduct
     * @param string $reportType
     * @param string $ipHash
     * @param string $email
     *
     * @return bool
     */
    public static function hasRecentDuplicate($idProduct, $reportType, $ipHash, $email)
    {
        $identity = '`ip_hash` = "' . pSQL($ipHash) . '"';
        if ($email !== '') {
            $identity = '(' . $identity . ' OR `customer_email` = "' . pSQL($email) . '")';
        }

        return (bool) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `id_product` = ' . (int) $idProduct . '
             AND `report_type` = "' . pSQL($reportType) . '"
             AND ' . $identity . '
             AND `created_at` > DATE_SUB(NOW(), INTERVAL 24 HOUR)'
        );
    }

    /* ---------------------------------------------------------------- */
    /* Internals                                                         */
    /* ---------------------------------------------------------------- */

    /**
     * Turns the admin filter array into a WHERE clause.
     *
     * Every value is cast or escaped here. Unknown statuses and types are dropped rather than
     * escaped through, so a hand-edited query string cannot widen the result set.
     *
     * @param array $filters
     *
     * @return string always a valid, non-empty condition
     */
    private static function buildWhere(array $filters)
    {
        $where = array('r.`id_shop` = ' . (isset($filters['id_shop']) ? (int) $filters['id_shop'] : 0));

        if (!empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $where[] = 'r.`status` = "' . pSQL($filters['status']) . '"';
        }

        if (!empty($filters['report_type']) && in_array($filters['report_type'], self::REPORT_TYPES, true)) {
            $where[] = 'r.`report_type` = "' . pSQL($filters['report_type']) . '"';
        }

        if (!empty($filters['id_product'])) {
            $where[] = 'r.`id_product` = ' . (int) $filters['id_product'];
        }

        if (!empty($filters['id_category'])) {
            $where[] = 'EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'category_product` cp
                WHERE cp.`id_product` = r.`id_product` AND cp.`id_category` = ' . (int) $filters['id_category'] . ')';
        }

        // The date columns are DATETIME, so a bare date as the upper bound would exclude
        // everything reported after midnight on that day. Widen both ends to cover the day.
        if (!empty($filters['date_from']) && self::isDate($filters['date_from'])) {
            $where[] = 'r.`created_at` >= "' . pSQL($filters['date_from']) . ' 00:00:00"';
        }

        if (!empty($filters['date_to']) && self::isDate($filters['date_to'])) {
            $where[] = 'r.`created_at` <= "' . pSQL($filters['date_to']) . ' 23:59:59"';
        }

        if (!empty($filters['search'])) {
            $needle = pSQL($filters['search']);
            $where[] = '(r.`message` LIKE "%' . $needle . '%"
                OR r.`customer_email` LIKE "%' . $needle . '%"
                OR r.`customer_name` LIKE "%' . $needle . '%")';
        }

        return implode(' AND ', $where);
    }

    /**
     * @param string $value
     *
     * @return bool true for a real Y-m-d date, rejecting both nonsense and 2026-02-31
     */
    private static function isDate($value)
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * @param array $ids
     *
     * @return int[] positive integers only, so IN () can never receive a crafted string
     */
    private static function sanitizeIdList(array $ids)
    {
        $clean = array();
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $clean[] = $id;
            }
        }

        return array_unique($clean);
    }
}
