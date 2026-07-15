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

/**
 * Input sanitising and validation for report submissions and admin edits.
 *
 * Deliberately free of any PrestaShop dependency: every method is a pure function of its
 * arguments, so the rules can be unit tested with plain PHP and reasoned about in one place
 * rather than being spread across the front controller and the admin page.
 *
 * Validation methods return an error *code* (a short string) or null when the value is
 * acceptable. Translating a code into a message is the caller's job, because the front office
 * and the back office word the same failure differently.
 */
class ReportBrokenLinkValidator
{
    /** @var int Shortest message we accept. Anything below this is noise, not a report. */
    const MESSAGE_MIN = 10;

    /** @var int Longest message we store. Matches the TEXT column and the front-end counter. */
    const MESSAGE_MAX = 1000;

    /** @var int Longest admin response we store. */
    const RESPONSE_MAX = 2000;

    /** @var int Column width for customer_name. */
    const NAME_MAX = 255;

    /** @var int Column width for customer_email. */
    const EMAIL_MAX = 255;

    /**
     * Strips every tag and control character from a visitor-submitted message.
     *
     * Sanitising on the way *in* means the stored value can never contain markup, so a later
     * bug in an escaping call cannot turn a stored report into an XSS or an HTML-injected
     * e-mail. Output escaping is still applied on top of this — this is the inner of two layers,
     * not a replacement for the outer one.
     *
     * @param mixed $raw
     *
     * @return string
     */
    public static function sanitizeMessage($raw)
    {
        $value = self::stripControlCharacters((string) $raw);
        $value = strip_tags($value);

        // Normalise line endings, then collapse runs of blank lines so a wall of newlines
        // cannot be used to pad a message past the minimum length.
        $value = str_replace(array("\r\n", "\r"), "\n", $value);
        $value = preg_replace("/\n{3,}/", "\n\n", $value);

        $value = trim($value);

        return self::truncate($value, self::MESSAGE_MAX);
    }

    /**
     * @param string $message already passed through sanitizeMessage()
     *
     * @return string|null 'required' | 'too_short' | 'too_long' | null
     */
    public static function validateMessage($message)
    {
        $length = self::length($message);

        if ($length === 0) {
            return 'required';
        }
        if ($length < self::MESSAGE_MIN) {
            return 'too_short';
        }
        if ($length > self::MESSAGE_MAX) {
            return 'too_long';
        }

        return null;
    }

    /**
     * @param mixed $raw
     *
     * @return string
     */
    public static function sanitizeName($raw)
    {
        $value = self::stripControlCharacters((string) $raw);
        $value = strip_tags($value);
        $value = preg_replace('/\s+/u', ' ', $value);

        return self::truncate(trim($value), self::NAME_MAX);
    }

    /**
     * @param mixed $raw
     *
     * @return string lowercased, trimmed; never longer than the column
     */
    public static function sanitizeEmail($raw)
    {
        $value = self::stripControlCharacters((string) $raw);

        // stripControlCharacters deliberately keeps tab and newline, because a *message* is
        // allowed to contain them. An address is not: CR and LF are exactly what mail-header
        // injection needs, so strip every whitespace character here. validateEmail() would
        // reject the result anyway, but a sanitiser must not hand a newline on in the first
        // place — the next caller may not validate.
        $value = preg_replace('/\s+/u', '', $value);
        if ($value === null) {
            return '';
        }

        // Separators that could turn one recipient into several further down the stack.
        $value = str_replace(array(',', ';', '<', '>'), '', $value);

        return self::truncate($value, self::EMAIL_MAX);
    }

    /**
     * @param string $email already passed through sanitizeEmail()
     * @param bool $required
     *
     * @return string|null 'required' | 'invalid' | null
     */
    public static function validateEmail($email, $required)
    {
        if ($email === '') {
            return $required ? 'required' : null;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'invalid';
        }

        return null;
    }

    /**
     * @param mixed $raw
     * @param array $allowed list of enabled report types
     *
     * @return string|null the type when allowed, null otherwise
     */
    public static function normalizeType($raw, array $allowed)
    {
        $value = strtolower(trim((string) $raw));

        return in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * @param mixed $raw
     * @param array $allowed list of known statuses
     *
     * @return string|null the status when known, null otherwise
     */
    public static function normalizeStatus($raw, array $allowed)
    {
        $value = strtolower(trim((string) $raw));

        return in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * Admin responses are written by staff, not visitors, but they are echoed back into a
     * customer e-mail, so they get the same treatment as visitor input.
     *
     * @param mixed $raw
     *
     * @return string
     */
    public static function sanitizeAdminResponse($raw)
    {
        $value = self::stripControlCharacters((string) $raw);
        $value = strip_tags($value);
        $value = str_replace(array("\r\n", "\r"), "\n", $value);

        return self::truncate(trim($value), self::RESPONSE_MAX);
    }

    /**
     * Keyed hash of an IP address, used by the rate limiter.
     *
     * The raw address is never stored. The shop's _COOKIE_KEY_ is the HMAC key, so the hashes
     * are useless outside this shop and cannot be reversed with a rainbow table of the ~4
     * billion IPv4 addresses (which is exactly what an unkeyed sha256 of an IP would allow).
     *
     * @param string $ip
     * @param string $key
     *
     * @return string 64 hex characters, matching the CHAR(64) column
     */
    public static function hashIp($ip, $key)
    {
        return hash_hmac('sha256', (string) $ip, (string) $key);
    }

    /**
     * Length in characters, not bytes, so a message in Turkish or Greek is measured the same
     * way the visitor's browser measures it.
     *
     * @param string $value
     *
     * @return int
     */
    public static function length($value)
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($value, 'UTF-8');
        }

        return (int) strlen($value);
    }

    /**
     * @param string $value
     * @param int $max
     *
     * @return string
     */
    private static function truncate($value, $max)
    {
        if (self::length($value) <= $max) {
            return $value;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max, 'UTF-8');
        }

        return substr($value, 0, $max);
    }

    /**
     * Removes C0/C1 control characters but keeps tab and newline, which are legitimate in a
     * message body. CR and LF are what mail-header injection needs, so they are removed from
     * single-line fields by the callers above before this is relevant.
     *
     * @param string $value
     *
     * @return string
     */
    private static function stripControlCharacters($value)
    {
        // Valid UTF-8 is required before any preg_* call with the /u modifier; invalid bytes
        // would make the whole pattern fail and silently return null.
        if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
            $value = function_exists('mb_convert_encoding')
                ? mb_convert_encoding($value, 'UTF-8', 'UTF-8')
                : filter_var($value, FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_HIGH);
        }

        $cleaned = preg_replace('/[^\P{C}\t\n]/u', '', $value);

        // preg_replace returns null on failure; never hand the caller a null "string".
        return $cleaned === null ? '' : $cleaned;
    }
}
