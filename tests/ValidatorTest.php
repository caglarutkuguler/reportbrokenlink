<?php
/**
 * @author    MEG Venture <info@megventure.com>
 * @copyright 2019-2026 MEG Venture & Consulting Ltd.
 * @license   https://opensource.org/licenses/MIT MIT License
 */

/**
 * Tests for ReportBrokenLinkValidator, the module's input sanitising and validation rules.
 *
 * ReportBrokenLinkValidator has no PrestaShop dependency by design, so these run without a shop
 * and without Composer:
 *
 *     php tests/ValidatorTest.php
 *
 * Everything else in the module needs Db/Configuration/Mail and is covered by the manual test
 * plan in Readme.md instead.
 */

// The class guards itself against direct access with this constant, like every module file.
if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.0.0');
}

require_once dirname(__FILE__) . '/../classes/ReportBrokenLinkValidator.php';

class ValidatorTest
{
    private $passed = 0;
    private $failed = 0;

    public function run()
    {
        $this->testMessageSanitisingStripsMarkup();
        $this->testMessageSanitisingRemovesControlCharacters();
        $this->testMessageSanitisingCollapsesBlankLinePadding();
        $this->testMessageSanitisingKeepsRealLineBreaks();
        $this->testMessageSanitisingTruncatesToTheColumnWidth();
        $this->testMessageSanitisingSurvivesInvalidUtf8();
        $this->testMessageValidation();
        $this->testMessageLengthIsCountedInCharactersNotBytes();
        $this->testEmailSanitisingBlocksHeaderInjection();
        $this->testEmailValidation();
        $this->testNameSanitising();
        $this->testTypeNormalisation();
        $this->testStatusNormalisation();
        $this->testAdminResponseSanitising();
        $this->testIpHashing();

        echo "\n" . str_repeat('-', 56) . "\n";
        echo $this->passed . ' passed, ' . $this->failed . " failed\n";

        return $this->failed === 0 ? 0 : 1;
    }

    /* ---------------------------------------------------------------- */
    /* Message                                                           */
    /* ---------------------------------------------------------------- */

    private function testMessageSanitisingStripsMarkup()
    {
        // The stored value must never be able to carry markup: this is the inner of the two
        // layers protecting the admin list and the notification e-mail.
        $this->assertSame(
            'alert(1)',
            ReportBrokenLinkValidator::sanitizeMessage('<script>alert(1)</script>'),
            'strips script tags'
        );

        $this->assertSame(
            'Click here',
            ReportBrokenLinkValidator::sanitizeMessage('<a href="https://evil.example/">Click here</a>'),
            'strips anchor tags but keeps the text'
        );

        $this->assertSame(
            'Bold and italic',
            ReportBrokenLinkValidator::sanitizeMessage('<b>Bold</b> and <i>italic</i>'),
            'strips inline formatting'
        );

        $this->assertSame(
            'img',
            ReportBrokenLinkValidator::sanitizeMessage('<img src=x onerror=alert(1)>img'),
            'strips an unclosed img tag with an event handler'
        );
    }

    private function testMessageSanitisingRemovesControlCharacters()
    {
        $this->assertSame(
            'abcdef',
            ReportBrokenLinkValidator::sanitizeMessage("abc\x00\x07\x1bdef"),
            'removes NUL, BEL and ESC'
        );
    }

    private function testMessageSanitisingCollapsesBlankLinePadding()
    {
        // A wall of newlines must not be usable to pad a two-word message past the minimum.
        $this->assertSame(
            "a\n\nb",
            ReportBrokenLinkValidator::sanitizeMessage("a\n\n\n\n\n\n\n\nb"),
            'collapses runs of blank lines'
        );

        $padded = ReportBrokenLinkValidator::sanitizeMessage("hi" . str_repeat("\n", 40));
        $this->assertSame('hi', $padded, 'trailing newlines are trimmed away');
        $this->assertSame(
            'too_short',
            ReportBrokenLinkValidator::validateMessage($padded),
            'newline-padded short message is still rejected as too short'
        );
    }

    private function testMessageSanitisingKeepsRealLineBreaks()
    {
        $this->assertSame(
            "line one\nline two",
            ReportBrokenLinkValidator::sanitizeMessage("line one\r\nline two"),
            'normalises CRLF to LF and keeps the break'
        );
    }

    private function testMessageSanitisingTruncatesToTheColumnWidth()
    {
        $long = str_repeat('a', 5000);
        $result = ReportBrokenLinkValidator::sanitizeMessage($long);

        $this->assertSame(
            ReportBrokenLinkValidator::MESSAGE_MAX,
            ReportBrokenLinkValidator::length($result),
            'truncates to MESSAGE_MAX characters'
        );

        $this->assertSame(
            null,
            ReportBrokenLinkValidator::validateMessage($result),
            'the truncated result then validates, rather than tripping too_long'
        );
    }

    private function testMessageSanitisingSurvivesInvalidUtf8()
    {
        // A /u regex against invalid UTF-8 returns null; the method must not hand back a null
        // pretending to be a string.
        $result = ReportBrokenLinkValidator::sanitizeMessage("valid\xB1\x31text");

        $this->assertSame(true, is_string($result), 'always returns a string for invalid UTF-8');
    }

    private function testMessageValidation()
    {
        $this->assertSame('required', ReportBrokenLinkValidator::validateMessage(''), 'empty is required');
        $this->assertSame('too_short', ReportBrokenLinkValidator::validateMessage('broken'), '6 chars is too short');
        $this->assertSame(null, ReportBrokenLinkValidator::validateMessage('0123456789'), 'exactly 10 chars is accepted');
        $this->assertSame(
            'too_long',
            ReportBrokenLinkValidator::validateMessage(str_repeat('a', 1001)),
            '1001 chars is too long'
        );
    }

    private function testMessageLengthIsCountedInCharactersNotBytes()
    {
        // "şğüöçİ" is 6 characters but 11 bytes in UTF-8. Measuring bytes would let a Turkish
        // message through that the browser counted as under the minimum, and vice versa.
        $this->assertSame(6, ReportBrokenLinkValidator::length('şğüöçİ'), 'counts characters, not bytes');

        $this->assertSame(
            'too_short',
            ReportBrokenLinkValidator::validateMessage('şğüöçİ'),
            'a 6-character Turkish message is short even though it is 11 bytes'
        );
    }

    /* ---------------------------------------------------------------- */
    /* E-mail                                                            */
    /* ---------------------------------------------------------------- */

    private function testEmailSanitisingBlocksHeaderInjection()
    {
        $this->assertSame(
            'victim@example.comBcc:attacker@evil.example',
            ReportBrokenLinkValidator::sanitizeEmail("victim@example.com\r\nBcc: attacker@evil.example"),
            'CR/LF and the space are gone, so the value cannot become a second header'
        );

        $this->assertSame(
            'a@example.comb@evil.example',
            ReportBrokenLinkValidator::sanitizeEmail('a@example.com,b@evil.example'),
            'a comma cannot turn one recipient into two'
        );

        $this->assertSame(
            'realname@example.com',
            ReportBrokenLinkValidator::sanitizeEmail('real<name@example.com>'),
            'angle brackets are stripped'
        );

        // Each of the above is then rejected by validateEmail() as malformed, which is the point:
        // the injection attempt cannot even be stored.
        $this->assertSame(
            'invalid',
            ReportBrokenLinkValidator::validateEmail(
                ReportBrokenLinkValidator::sanitizeEmail("victim@example.com\r\nBcc: attacker@evil.example"),
                false
            ),
            'the sanitised injection attempt fails validation'
        );
    }

    private function testEmailValidation()
    {
        $this->assertSame(null, ReportBrokenLinkValidator::validateEmail('', false), 'empty is fine when optional');
        $this->assertSame('required', ReportBrokenLinkValidator::validateEmail('', true), 'empty fails when required');
        $this->assertSame(null, ReportBrokenLinkValidator::validateEmail('user@example.com', false), 'a normal address passes');
        $this->assertSame(null, ReportBrokenLinkValidator::validateEmail('user+tag@sub.example.co.uk', false), 'plus addressing passes');
        $this->assertSame('invalid', ReportBrokenLinkValidator::validateEmail('not-an-email', false), 'garbage fails');
        $this->assertSame('invalid', ReportBrokenLinkValidator::validateEmail('user@', false), 'a missing domain fails');
    }

    /* ---------------------------------------------------------------- */
    /* Other fields                                                      */
    /* ---------------------------------------------------------------- */

    private function testNameSanitising()
    {
        $this->assertSame(
            'Ada Lovelace',
            ReportBrokenLinkValidator::sanitizeName("  Ada\t\tLovelace  "),
            'collapses whitespace and trims'
        );

        $this->assertSame(
            'evil',
            ReportBrokenLinkValidator::sanitizeName('<script>evil</script>'),
            'strips markup from the name'
        );

        $this->assertSame(
            ReportBrokenLinkValidator::NAME_MAX,
            ReportBrokenLinkValidator::length(ReportBrokenLinkValidator::sanitizeName(str_repeat('x', 400))),
            'truncates to the column width'
        );
    }

    private function testTypeNormalisation()
    {
        $allowed = array('broken_link', 'wrong_price');

        $this->assertSame('broken_link', ReportBrokenLinkValidator::normalizeType('broken_link', $allowed), 'an enabled type passes');
        $this->assertSame('broken_link', ReportBrokenLinkValidator::normalizeType('  BROKEN_LINK  ', $allowed), 'case and padding are normalised');
        $this->assertSame(null, ReportBrokenLinkValidator::normalizeType('missing_info', $allowed), 'a disabled type is rejected');
        $this->assertSame(null, ReportBrokenLinkValidator::normalizeType('nonsense', $allowed), 'an unknown type is rejected');
        $this->assertSame(null, ReportBrokenLinkValidator::normalizeType('', $allowed), 'empty is rejected');
    }

    private function testStatusNormalisation()
    {
        $allowed = array('open', 'resolved', 'spam');

        $this->assertSame('resolved', ReportBrokenLinkValidator::normalizeStatus('resolved', $allowed), 'a known status passes');
        $this->assertSame(null, ReportBrokenLinkValidator::normalizeStatus('deleted', $allowed), 'an unknown status is rejected');

        // A rejected status is what stops a hand-edited form from writing arbitrary text into
        // the status column.
        $this->assertSame(
            null,
            ReportBrokenLinkValidator::normalizeStatus('open"; DROP TABLE x; --', $allowed),
            'an injection attempt is rejected rather than escaped through'
        );
    }

    private function testAdminResponseSanitising()
    {
        $this->assertSame(
            'Fixed the link.',
            ReportBrokenLinkValidator::sanitizeAdminResponse('<p>Fixed the link.</p>'),
            'strips markup from staff input too, since it is echoed into a customer e-mail'
        );

        $this->assertSame(
            ReportBrokenLinkValidator::RESPONSE_MAX,
            ReportBrokenLinkValidator::length(ReportBrokenLinkValidator::sanitizeAdminResponse(str_repeat('x', 3000))),
            'truncates to RESPONSE_MAX'
        );
    }

    private function testIpHashing()
    {
        $hash = ReportBrokenLinkValidator::hashIp('203.0.113.7', 'shop-cookie-key');

        $this->assertSame(64, strlen($hash), 'produces exactly the CHAR(64) the column expects');
        $this->assertSame(true, ctype_xdigit($hash), 'is hex');

        $this->assertSame(
            $hash,
            ReportBrokenLinkValidator::hashIp('203.0.113.7', 'shop-cookie-key'),
            'is stable, so the rate limiter can count across requests'
        );

        $this->assertSame(
            false,
            $hash === ReportBrokenLinkValidator::hashIp('203.0.113.8', 'shop-cookie-key'),
            'a different IP gives a different hash'
        );

        // Keyed with the shop's _COOKIE_KEY_: an unkeyed sha256 of an IP is trivially reversed
        // by hashing all ~4 billion IPv4 addresses.
        $this->assertSame(
            false,
            $hash === ReportBrokenLinkValidator::hashIp('203.0.113.7', 'another-shop-key'),
            'the same IP under a different shop key gives a different hash'
        );

        $this->assertSame(
            false,
            $hash === hash('sha256', '203.0.113.7'),
            'is not a plain unkeyed sha256 of the address'
        );
    }

    /* ---------------------------------------------------------------- */
    /* Tiny assertion helper                                             */
    /* ---------------------------------------------------------------- */

    private function assertSame($expected, $actual, $label)
    {
        if ($expected === $actual) {
            $this->passed++;
            echo "  ok    " . $label . "\n";

            return;
        }

        $this->failed++;
        echo "  FAIL  " . $label . "\n";
        echo "        expected: " . var_export($expected, true) . "\n";
        echo "        actual:   " . var_export($actual, true) . "\n";
    }
}

$test = new ValidatorTest();
exit($test->run());
