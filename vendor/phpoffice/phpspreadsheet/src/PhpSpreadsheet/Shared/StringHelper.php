<?php

namespace PhpOffice\PhpSpreadsheet\Shared;

use Composer\Pcre\Preg;
use IntlCalendar;
use NumberFormatter;
use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use Stringable;

class StringHelper
{
    private const CONTROL_CHARACTERS_KEYS = [
        "\x00",
        "\x01",
        "\x02",
        "\x03",
        "\x04",
        "\x05",
        "\x06",
        "\x07",
        "\x08",
        "\x0b",
        "\x0c",
        "\x0e",
        "\x0f",
        "\x10",
        "\x11",
        "\x12",
        "\x13",
        "\x14",
        "\x15",
        "\x16",
        "\x17",
        "\x18",
        "\x19",
        "\x1a",
        "\x1b",
        "\x1c",
        "\x1d",
        "\x1e",
        "\x1f",
    ];
    private const CONTROL_CHARACTERS_VALUES = [
        '_x0000_',
        '_x0001_',
        '_x0002_',
        '_x0003_',
        '_x0004_',
        '_x0005_',
        '_x0006_',
        '_x0007_',
        '_x0008_',
        '_x000B_',
        '_x000C_',
        '_x000E_',
        '_x000F_',
        '_x0010_',
        '_x0011_',
        '_x0012_',
        '_x0013_',
        '_x0014_',
        '_x0015_',
        '_x0016_',
        '_x0017_',
        '_x0018_',
        '_x0019_',
        '_x001A_',
        '_x001B_',
        '_x001C_',
        '_x001D_',
        '_x001E_',
        '_x001F_',
    ];

    /**
     * SYLK Characters array.
     */
    private const SYLK_CHARACTERS = [
        "\x1B 0" => "\x00",
        "\x1B 1" => "\x01",
        "\x1B 2" => "\x02",
        "\x1B 3" => "\x03",
        "\x1B 4" => "\x04",
        "\x1B 5" => "\x05",
        "\x1B 6" => "\x06",
        "\x1B 7" => "\x07",
        "\x1B 8" => "\x08",
        "\x1B 9" => "\x09",
        "\x1B :" => "\x0a",
        "\x1B ;" => "\x0b",
        "\x1B <" => "\x0c",
        "\x1B =" => "\x0d",
        "\x1B >" => "\x0e",
        "\x1B ?" => "\x0f",
        "\x1B!0" => "\x10",
        "\x1B!1" => "\x11",
        "\x1B!2" => "\x12",
        "\x1B!3" => "\x13",
        "\x1B!4" => "\x14",
        "\x1B!5" => "\x15",
        "\x1B!6" => "\x16",
        "\x1B!7" => "\x17",
        "\x1B!8" => "\x18",
        "\x1B!9" => "\x19",
        "\x1B!:" => "\x1a",
        "\x1B!;" => "\x1b",
        "\x1B!<" => "\x1c",
        "\x1B!=" => "\x1d",
        "\x1B!>" => "\x1e",
        "\x1B!?" => "\x1f",
        "\x1B'?" => "\x7f",
        "\x1B(0" => '?', // 128 in CP1252
        "\x1B(2" => ',', // 130 in CP1252
        "\x1B(3" => 'f', // 131 in CP1252
        "\x1B(4" => '"', // 132 in CP1252
        "\x1B(5" => '.', // 133 in CP1252
        "\x1B(6" => '?', // 134 in CP1252
        "\x1B(7" => '?', // 135 in CP1252
        "\x1B(8" => '^', // 136 in CP1252
        "\x1B(9" => '?', // 137 in CP1252
        "\x1B(:" => 'S', // 138 in CP1252
        "\x1B(;" => '<', // 139 in CP1252
        "\x1BNj" => 'O', // 140 in CP1252
        "\x1B(>" => 'Z', // 142 in CP1252
        "\x1B)1" => ''', // 145 in CP1252
        "\x1B)2" => ''', // 146 in CP1252
        "\x1B)3" => '"', // 147 in CP1252
        "\x1B)4" => '"', // 148 in CP1252
        "\x1B)5" => '.', // 149 in CP1252
        "\x1B)6" => '-', // 150 in CP1252
        "\x1B)7" => '-', // 151 in CP1252
        "\x1B)8" => '~', // 152 in CP1252
        "\x1B)9" => 'T', // 153 in CP1252
        "\x1B):" => 's', // 154 in CP1252
        "\x1B);" => '>', // 155 in CP1252
        "\x1BNz" => 'o', // 156 in CP1252
        "\x1B)>" => 'z', // 158 in CP1252
        "\x1B)?" => 'Y', // 159 in CP1252
        "\x1B*0" => ' ', // 160 in CP1252
        "\x1BN!" => '�', // 161 in CP1252
        "\x1BN\"" => '�', // 162 in CP1252
        "\x1BN#" => '�', // 163 in CP1252
        "\x1BN(" => '�', // 164 in CP1252
        "\x1BN%" => '�', // 165 in CP1252
        "\x1B*6" => '�', // 166 in CP1252
        "\x1BN'" => '�', // 167 in CP1252
        "\x1BNH " => '�', // 168 in CP1252
        "\x1BNS" => '�', // 169 in CP1252
        "\x1BNc" => '�', // 170 in CP1252
        "\x1BN+" => '�', // 171 in CP1252
        "\x1B*<" => '�', // 172 in CP1252
        "\x1B*=" => '�', // 173 in CP1252
        "\x1BNR" => '�', // 174 in CP1252
        "\x1B*?" => '�', // 175 in CP1252
        "\x1BN0" => '�', // 176 in CP1252
        "\x1BN1" => '�', // 177 in CP1252
        "\x1BN2" => '�', // 178 in CP1252
        "\x1BN3" => '�', // 179 in CP1252
        "\x1BNB " => '�', // 180 in CP1252
        "\x1BN5" => '�', // 181 in CP1252
        "\x1BN6" => '�', // 182 in CP1252
        "\x1BN7" => '�', // 183 in CP1252
        "\x1B+8" => '�', // 184 in CP1252
        "\x1BNQ" => '�', // 185 in CP1252
        "\x1BNk" => '�', // 186 in CP1252
        "\x1BN;" => '�', // 187 in CP1252
        "\x1BN<" => '�', // 188 in CP1252
        "\x1BN=" => '�', // 189 in CP1252
        "\x1BN>" => '�', // 190 in CP1252
        "\x1BN?" => '�', // 191 in CP1252
        "\x1BNAA" => '�', // 192 in CP1252
        "\x1BNBA" => '�', // 193 in CP1252
        "\x1BNCA" => '�', // 194 in CP1252
        "\x1BNDA" => '�', // 195 in CP1252
        "\x1BNHA" => '�', // 196 in CP1252
        "\x1BNJA" => '�', // 197 in CP1252
        "\x1BNa" => '�', // 198 in CP1252
        "\x1BNKC" => '�', // 199 in CP1252
        "\x1BNAE" => '�', // 200 in CP1252
        "\x1BNBE" => '�', // 201 in CP1252
        "\x1BNCE" => '�', // 202 in CP1252
        "\x1BNHE" => '�', // 203 in CP1252
        "\x1BNAI" => '�', // 204 in CP1252
        "\x1BNBI" => '�', // 205 in CP1252
        "\x1BNCI" => '�', // 206 in CP1252
        "\x1BNHI" => '�', // 207 in CP1252
        "\x1BNb" => '�', // 208 in CP1252
        "\x1BNDN" => '�', // 209 in CP1252
        "\x1BNAO" => '�', // 210 in CP1252
        "\x1BNBO" => '�', // 211 in CP1252
        "\x1BNCO" => '�', // 212 in CP1252
        "\x1BNDO" => '�', // 213 in CP1252
        "\x1BNHO" => '�', // 214 in CP1252
        "\x1B-7" => '�', // 215 in CP1252
        "\x1BNi" => '�', // 216 in CP1252
        "\x1BNAU" => '�', // 217 in CP1252
        "\x1BNBU" => '�', // 218 in CP1252
        "\x1BNCU" => '�', // 219 in CP1252
        "\x1BNHU" => '�', // 220 in CP1252
        "\x1B-=" => '�', // 221 in CP1252
        "\x1BNl" => '�', // 222 in CP1252
        "\x1BN{" => '�', // 223 in CP1252
        "\x1BNAa" => '�', // 224 in CP1252
        "\x1BNBa" => '�', // 225 in CP1252
        "\x1BNCa" => '�', // 226 in CP1252
        "\x1BNDa" => '�', // 227 in CP1252
        "\x1BNHa" => '�', // 228 in CP1252
        "\x1BNJa" => '�', // 229 in CP1252
        "\x1BNq" => '�', // 230 in CP1252
        "\x1BNKc" => '�', // 231 in CP1252
        "\x1BNAe" => '�', // 232 in CP1252
        "\x1BNBe" => '�', // 233 in CP1252
        "\x1BNCe" => '�', // 234 in CP1252
        "\x1BNHe" => '�', // 235 in CP1252
        "\x1BNAi" => '�', // 236 in CP1252
        "\x1BNBi" => '�', // 237 in CP1252
        "\x1BNCi" => '�', // 238 in CP1252
        "\x1BNHi" => '�', // 239 in CP1252
        "\x1BNs" => '�', // 240 in CP1252
        "\x1BNDn" => '�', // 241 in CP1252
        "\x1BNAo" => '�', // 242 in CP1252
        "\x1BNBo" => '�', // 243 in CP1252
        "\x1BNCo" => '�', // 244 in CP1252
        "\x1BNDo" => '�', // 245 in CP1252
        "\x1BNHo" => '�', // 246 in CP1252
        "\x1B/7" => '�', // 247 in CP1252
        "\x1BNy" => '�', // 248 in CP1252
        "\x1BNAu" => '�', // 249 in CP1252
        "\x1BNBu" => '�', // 250 in CP1252
        "\x1BNCu" => '�', // 251 in CP1252
        "\x1BNHu" => '�', // 252 in CP1252
        "\x1B/=" => '�', // 253 in CP1252
        "\x1BN|" => '�', // 254 in CP1252
        "\x1BNHy" => '�', // 255 in CP1252
    ];

    /**
     * Decimal separator.
     */
    protected static ?string $decimalSeparator = null;

    /**
     * Thousands separator.
     */
    protected static ?string $thousandsSeparator = null;

    /**
     * Currency code.
     */
    protected static ?string $currencyCode = null;

    /**
     * Is iconv extension available?
     */
    protected static ?bool $isIconvEnabled = null;

    /**
     * iconv options.
     */
    protected static string $iconvOptions = '//IGNORE//TRANSLIT';

    /** @var string[] */
    protected static array $iconvOptionsArray = ['//IGNORE//TRANSLIT', '//IGNORE'];

    /** @internal */
    protected static string $iconvName = 'iconv';

    /** @internal */
    protected static bool $iconvTest2 = false;

    /** @internal */
    protected static bool $iconvTest3 = false;

    /**
     * Get whether iconv extension is available.
     */
    public static function getIsIconvEnabled(): bool
    {
        if (isset(static::$isIconvEnabled)) {
            return static::$isIconvEnabled;
        }

        // Assume no problems with iconv
        static::$isIconvEnabled = true;

        // Fail if iconv doesn't exist
        if (!function_exists(static::$iconvName)) {
            static::$isIconvEnabled = false;
        } elseif (static::$iconvTest2 || !@iconv('UTF-8', 'UTF-16LE', 'x')) {
            // Sometimes iconv is not working, and e.g. iconv('UTF-8', 'UTF-16LE', 'x') just returns false,
            static::$isIconvEnabled = false;
        } elseif (static::$iconvTest3 || (defined('PHP_OS') && @stristr(PHP_OS, 'AIX') && defined('ICONV_IMPL') && (@strcasecmp(ICONV_IMPL, 'unknown') == 0) && defined('ICONV_VERSION') && (@strcasecmp(ICONV_VERSION, 'unknown') == 0))) {
            // CUSTOM: IBM AIX iconv() does not work
            static::$isIconvEnabled = false;
        }

        // Deactivate iconv default options if they fail (as seen on IBM i-series)
        if (static::$isIconvEnabled) {
            static::$iconvOptions = '';
            foreach (static::$iconvOptionsArray as $option) {
                if (@iconv('UTF-8', 'UTF-16LE' . $option, 'x') !== false) {
                    static::$iconvOptions = $option;

                    break;
                }
            }
        }

        return static::$isIconvEnabled;
    }

    /**
     * Convert from OpenXML escaped control character to PHP control character.
     *
     * Excel 2007 team:
     * ----------------
     * That's correct, control characters are stored directly in the shared-strings table.
     * We do encode characters that cannot be represented in XML using the following escape sequence:
     * _xHHHH_ where H represents a hexadecimal character in the character's value...
     * So you could end up with something like _x0008_ in a string (either in a cell value (<v>)
     * element or in the shared string <t> element.
     *
     * @param string $textValue Value to unescape
     */
    public static function controlCharacterOOXML2PHP(string $textValue): string
    {
        return Preg::replaceCallback('/_x[0-9A-F]{4}_(_xD[CDEF][0-9A-F]{2}_)?/', self::toOutChar(...), $textValue);
    }

    private static function toHexVal(string $char): int
    {
        if ($char >= '0' && $char <= '9') {
            return ord($char) - ord('0');
        }

        return ord($char) - ord('A') + 10;
    }

    /** @param array<?string> $match */
    private static function toOutChar(array $match): string
    {
        /** @var string */
        $chars = $match[0];
        $h = ((self::toHexVal($chars[2]) << 12)
            | (self::toHexVal($chars[3]) << 8)
            | (self::toHexVal($chars[4]) << 4)
            | (self::toHexVal($chars[5])));
        if (strlen($chars) === 7) { // no low surrogate
            if ($chars[2] === 'D' && in_array($chars[3], ['8', '9', 'A', 'B', 'C', 'D', 'E', 'F'], true)) {
                return '?';
            }

            return mb_chr($h, 'UTF-8');
        }
        if ($chars[2] === 'D' && in_array($chars[3], ['C', 'D', 'D', 'F'], true)) {
            return '?'; // Excel interprets as one substitute, not 2
        }
        if ($chars[2] !== 'D' || !in_array($chars[3], ['8', '9', 'A', 'B'], true)) {
            return mb_chr($h, 'UTF-8') . '?';
        }
        $l = ((self::toHexVal($chars[9]) << 12)
            | (self::toHexVal($chars[10]) << 8)
            | (self::toHexVal($chars[11]) << 4)
            | (self::toHexVal($chars[12])));
        $result = 0x10000 + ($h - 0xD800) * 0x400 + ($l - 0xDC00);

        return mb_chr($result, 'UTF-8');
    }

    /**
     * Convert from PHP control character to OpenXML escaped control character.
     *
     * Excel 2007 team:
     * ----------------
     * That's correct, control characters are stored directly in the shared-strings table.
     * We do encode characters that cannot be represented in XML using the following escape sequence:
     * _xHHHH_ where H represents a hexadecimal character in the character's value...
     * So you could end up with something like _x0008_ in a string (either in a cell value (<v>)
     * element or in the shared string <t> element.
     *
     * @param string $textValue Value to escape
     */
    public static function controlCharacterPHP2OOXML(string $textValue): string
    {
        $textValue = Preg::replace('/_(x[0-9A-F]{4}_)/', '_x005F_$1', $textValue);

        return str_replace(self::CONTROL_CHARACTERS_KEYS, self::CONTROL_CHARACTERS_VALUES, $textValue);
    }

    /**
     * Try to sanitize UTF8, replacing invalid sequences with Unicode substitution characters.
     */
    public static function sanitizeUTF8(string $textValue): string
    {
        $textValue = str_replace(["\xef\xbf\xbe", "\xef\xbf\xbf"], "\xef\xbf\xbd", $textValue);
        $subst = mb_substitute_character(); // default is question mark
        mb_substitute_character(65533); // Unicode substitution character
        $returnValue = (string) mb_convert_encoding($textValue, 'UTF-8', 'UTF-8');
        mb_substitute_character($subst);

        return $returnValue;
    }

    /**
     * Check if a string contains UTF8 data.
     */
    public static function isUTF8(string $textValue): bool
    {
        return $textValue === self::sanitizeUTF8($textValue);
    }

    /**
     * Formats a numeric value as a string for output in various output writers forcing
     * point as decimal separator in case locale is other than English.
     */
    public static function formatNumber(float|int|string|null $numericValue): string
    {
        if (is_float($numericValue)) {
            return str_replace(',', '.', (string) $numericValue);
        }

        return (string) $numericValue;
    }

    /**
     * Converts a UTF-8 string into BIFF8 Unicode string data (8-bit string length)
     * Writes the string using uncompressed notation, no rich text, no Asian phonetics
     * If mbstring extension is not available, ASCII is assumed, and compressed notation is used
     * although this will give wrong results for non-ASCII strings
     * see OpenOffice.org's Documentation of the Microsoft Excel File Format, sect. 2.5.3.
     *
     * @param string $textValue UTF-8 encoded string
     * @param array<int, array{strlen: int, fontidx: int}> $arrcRuns Details of rich text runs in $value
     */
    public static function UTF8toBIFF8UnicodeShort(string $textValue, array $arrcRuns = []): string
    {
        // character count
        $ln = self::countCharacters($textValue, 'UTF-8');
        // option flags
        if (empty($arrcRuns)) {
            $data = pack('CC', $ln, 0x0001);
            // characters
            $data .= self::convertEncoding($textValue, 'UTF-16LE', 'UTF-8');
        } else {
            $data = pack('vC', $ln, 0x09);
            $data .= pack('v', count($arrcRuns));
            // characters
            $data .= self::convertEncoding($textValue, 'UTF-16LE', 'UTF-8');
            foreach ($arrcRuns as $cRun) {
                $data .= pack('v', $cRun['strlen']);
                $data .= pack('v', $cRun['fontidx']);
            }
        }

        return $data;
    }

    /**
     * Converts a UTF-8 string into BIFF8 Unicode string data (16-bit string length)
     * Writes the string using uncompressed notation, no rich text, no Asian phonetics
     * If mbstring extension is not available, ASCII is assumed, and compressed notation is used
     * although this will give wrong results for non-ASCII strings
     * see OpenOffice.org's Documentation of the Microsoft Excel File Format, sect. 2.5.3.
     *
     * @param string $textValue UTF-8 encoded string
     */
    public static function UTF8toBIFF8UnicodeLong(string $textValue): string
    {
        // characters
        $chars = self::convertEncoding($textValue, 'UTF-16LE', 'UTF-8');
        $ln = (int) (strlen($chars) / 2);  // N.B. - strlen, not mb_strlen issue #642

        return pack('vC', $ln, 0x0001) . $chars;
    }

    /**
     * Convert string from one encoding to another.
     *
     * @param string $to Encoding to convert to, e.g. 'UTF-8'
     * @param string $from Encoding to convert from, e.g. 'UTF-16LE'
     */
    public static function convertEncoding(string $textValue, string $to, string $from, ?string $options = null): string
    {
        if (static::getIsIconvEnabled()) {
            $result = iconv($from, $to . ($options ?? static::$iconvOptions), $textValue);
            if (false !== $result) {
                return $result;
            }
        }

        return (string) mb_convert_encoding($textValue, $to, $from);
    }

    /**
     * Get character count.
     *
     * @param string $encoding Encoding
     *
     * @return int Character count
     */
    public static function countCharacters(string $textValue, string $encoding = 'UTF-8'): int
    {
        return mb_strlen($textValue, $encoding);
    }

    /**
     * Get character count using mb_strwidth rather than mb_strlen.
     *
     * @param string $encoding Encoding
     *
     * @return int Character count
     */
    public static function countCharactersDbcs(string $textValue, string $encoding = 'UTF-8'): int
    {
        return mb_strwidth($textValue, $encoding);
    }

    /**
     * Get a substring of a UTF-8 encoded string.
     *
     * @param string $textValue UTF-8 encoded string
     * @param int $offset Start offset
     * @param ?int $length Maximum number of characters in substring
     */
    public static function substring(string $textValue, int $offset, ?int $length = 0): string
    {
        return mb_substr($textValue, $offset, $length, 'UTF-8');
    }

    /**
     * Convert a UTF-8 encoded string to upper case.
     *
     * @param string $textValue UTF-8 encoded string
     */
    public static function strToUpper(string $textValue): string
    {
        return mb_convert_case($textValue, MB_CASE_UPPER, 'UTF-8');
    }

    /**
     * Convert a UTF-8 encoded string to lower case.
     *
     * @param string $textValue UTF-8 encoded string
     */
    public static function strToLower(string $textValue): string
    {
        return mb_convert_case($textValue, MB_CASE_LOWER, 'UTF-8');
    }

    /**
     * Convert a UTF-8 encoded string to title/proper case
     * (uppercase every first character in each word, lower case all other characters).
     *
     * @param string $textValue UTF-8 encoded string
     */
    public static function strToTitle(string $textValue): string
    {
        return mb_convert_case($textValue, MB_CASE_TITLE, 'UTF-8');
    }

    public static function mbIsUpper(string $character): bool
    {
        return mb_strtolower($character, 'UTF-8') !== $character;
    }

    /**
     * Splits a UTF-8 string into an array of individual characters.
     *
     * @return string[]
     */
    public static function mbStrSplit(string $string): array
    {
        // Split at all position not after the start: ^
        // and not before the end: $
        $split = Preg::split('/(?<!^)(?!$)/u', $string);

        return $split;
    }

    /**
     * Reverse the case of a string, so that all uppercase characters become lowercase
     * and all lowercase characters become uppercase.
     *
     * @param string $textValue UTF-8 encoded string
     */
    public static function strCaseReverse(string $textValue): string
    {
        $characters = self::mbStrSplit($textValue);
        foreach ($characters as &$character) {
            if (self::mbIsUpper($character)) {
                $character = mb_strtolower($character, 'UTF-8');
            } else {
                $character = mb_strtoupper($character, 'UTF-8');
            }
        }

        return implode('', $characters);
    }

    private static function useAlt(string $altValue, string $default, bool $trimAlt): string
    {
        return ($trimAlt ? trim($altValue) : $altValue) ?: $default;
    }

    private static function getLocaleValue(string $key, string $altKey, string $default, bool $trimAlt = false): string
    {
        /** @var string[] */
        $localeconv = localeconv();
        $rslt = $localeconv[$key];
        // win-1252 implements Euro as 0x80 plus other symbols
        // Not suitable for Composer\Pcre\Preg
        if (preg_match('//u', $rslt) !== 1) {
            $rslt = '';
        }

        return $rslt ?: self::useAlt($localeconv[$altKey], $default, $trimAlt);
    }

    /**
     * Get the decimal separator. If it has not yet been set explicitly, try to obtain number
     * formatting information from locale.
     */
    public static function getDecimalSeparator(): string
    {
        if (!isset(static::$decimalSeparator)) {
            static::$decimalSeparator = self::getLocaleValue('decimal_point', 'mon_decimal_point', '.');
        }

        return static::$decimalSeparator;
    }

    /**
     * Set the decimal separator. Only used by NumberFormat::toFormattedString()
     * to format output by \PhpOffice\PhpSpreadsheet\Writer\Html and \PhpOffice\PhpSpreadsheet\Writer\Pdf.
     *
     * @param ?string $separator Character for decimal separator
     */
    public static function setDecimalSeparator(?string $separator): void
    {
        static::$decimalSeparator = $separator;
    }

    /**
     * Get the thousands separator. If it has not yet been set explicitly, try to obtain number
     * formatting information from locale.
     */
    public static function getThousandsSeparator(): string
    {
        if (!isset(static::$thousandsSeparator)) {
            static::$thousandsSeparator = self::getLocaleValue('thousands_sep', 'mon_thousands_sep', ',');
        }

        return static::$thousandsSeparator;
    }

    /**
     * Set the thousands separator. Only used by NumberFormat::toFormattedString()
     * to format output by \PhpOffice\PhpSpreadsheet\Writer\Html and \PhpOffice\PhpSpreadsheet\Writer\Pdf.
     *
     * @param ?string $separator Character for thousands separator
     */
    public static function setThousandsSeparator(?string $separator): void
    {
        static::$thousandsSeparator = $separator;
    }

    /**
     *    Get the currency code. If it has not yet been set explicitly, try to obtain the
     *        symbol information from locale.
     */
    public static function getCurrencyCode(bool $trimAlt = false): string
    {
        if (!isset(static::$currencyCode)) {
            static::$currencyCode = self::getLocaleValue('currency_symbol', 'int_curr_symbol', '$', $trimAlt);
        }

        return static::$currencyCode;
    }

    /**
     * Set the currency code. Only used by NumberFormat::toFormattedString()
     *        to format output by \PhpOffice\PhpSpreadsheet\Writer\Html and \PhpOffice\PhpSpreadsheet\Writer\Pdf.
     *
     * @param ?string $currencyCode Character for currency code
     */
    public static function setCurrencyCode(?string $currencyCode): void
    {
        static::$currencyCode = $currencyCode;
    }

    /**
     * Convert SYLK encoded string to UTF-8.
     *
     * @param string $textValue SYLK encoded string
     *
     * @return string UTF-8 encoded string
     */
    public static function SYLKtoUTF8(string $textValue): string
    {
        // If there is no escape character in the string there is nothing to do
        if (!str_contains($textValue, "\x1b")) {
            return $textValue;
        }

        foreach (self::SYLK_CHARACTERS as $k => $v) {
            $textValue = str_replace($k, $v, $textValue);
        }

        return $textValue;
    }

    /**
     * Retrieve any leading numeric part of a string, or return the full string if no leading numeric
     * (handles basic integer or float, but not exponent or non decimal).
     *
     * @return float|string string or only the leading numeric part of the string
     */
    public static function testStringAsNumeric(string $textValue): float|string
    {
        if (is_numeric($textValue)) {
            return $textValue;
        }
        $v = (float) $textValue;

        return (is_numeric(substr($textValue, 0, strlen((string) $v)))) ? $v : $textValue;
    }

    public static function strlenAllowNull(?string $string): int
    {
        return strlen("$string");
    }

    /**
     * @param bool $convertBool If true, convert bool to locale-aware TRUE/FALSE rather than 1/null-string
     * @param bool $lessFloatPrecision If true, floats will be converted to a more human-friendly but less computationally accurate value
     */
    public static function convertToString(mixed $value, bool $throw = true, string $default = '', bool $convertBool = false, bool $lessFloatPrecision = false): string
    {
        if ($convertBool && is_bool($value)) {
            return $value ? Calculation::getTRUE() : Calculation::getFALSE();
        }
        if (is_float($value) && !$lessFloatPrecision) {
            $string = (string) $value;
            // look out for scientific notation
            if (!Preg::isMatch('/[^-+0-9.]/', $string)) {
                $minus = $value < 0 ? '-' : '';
                $positive = abs($value);
                $floor = floor($positive);
                $oldFrac = (string) ($positive - $floor);
                $frac = Preg::replace('/^0[.](\d+)$/', '$1', $oldFrac);
                if ($frac !== $oldFrac) {
                    return "$minus$floor.$frac";
                }
            }

            return $string;
        }
        if ($value === null || is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        if ($throw) {
            throw new SpreadsheetException('Unable to convert to string');
        }

        return $default;
    }

    /**
     * Assist with POST items when samples are run in browser.
     * Never run as part of unit tests, which are command line.
     *
     * @codeCoverageIgnore
     */
    public static function convertPostToString(string $index, string $default = ''): string
    {
        if (isset($_POST[$index])) {
            return htmlentities(self::convertToString($_POST[$index], false, $default));
        }

        return $default;
    }

    /**
     * Php introduced str_increment with Php8.3,
     * but didn't issue deprecation notices till 8.5.
     *
     * @codeCoverageIgnore
     */
    public static function stringIncrement(string &$str): string
    {
        if (function_exists('str_increment')) {
            $str = str_increment($str); // @phpstan-ignore-line
        } else {
            ++$str; // @phpstan-ignore-line
        }

        return $str; // @phpstan-ignore-line
    }

    /** @internal */
    protected static string $testClass = IntlCalendar::class;

    /**
     * Set all of currencyCode, thousandsSeparator, decimalSeparator,
     * and Calculation locale with a single call.
     * The main point here is avoid the use of Php setlocale,
     * which is not threadsafe. It uses the Intl extension instead,
     * which is not a requirement for PhpSpreadsheet.
     * Because of that, the function returns a bool which will
     * be false if Intl is not available, or the supplied locale
     * is not valid according to Intl.
     */
    public static function setLocale(?string $locale): bool
    {
        if ($locale === null) {
            self::$currencyCode = null;
            self::$thousandsSeparator = null;
            self::$decimalSeparator = null;
            Calculation::getInstance()->setLocale('en_us');

            return true;
        }
        $localeCalc = $locale;
        if (Preg::isMatch('/^([a-z][a-z])_([a-z][a-z])(?:[.]utf-8)?$/i', $locale, $matches)) {
            $locale = strtolower($matches[1]) . '_' . strtoupper($matches[2]);
            $localeCalc = strtolower($matches[1]) . '_' . strtolower($matches[2]);
        }
        if (!class_exists(static::$testClass)) {
            return false;
        }
        // NumberFormatter constructor succeeds even with
        // bad locale before Php8.4, so try to validate
        // the locale beforehand.
        $locales = IntlCalendar::getAvailableLocales();
        if (!in_array($locale, $locales, true)) {
            return false;
        }
        $formatter = new NumberFormatter(
            $locale,
            NumberFormatter::CURRENCY
        );
        $currency = $formatter->getSymbol(
            NumberFormatter::CURRENCY_SYMBOL
        );
        $formatter = new NumberFormatter(
            $locale,
            NumberFormatter::DECIMAL
        );
        $thousands = $formatter->getSymbol(
            NumberFormatter::GROUPING_SEPARATOR_SYMBOL
        );
        $decimal = $formatter->getSymbol(
            NumberFormatter::DECIMAL_SEPARATOR_SYMBOL
        );
        self::$currencyCode = $currency;
        self::$thousandsSeparator = $thousands;
        self::$decimalSeparator = $decimal;
        Calculation::getInstance()->setLocale($localeCalc);

        return true;
    }
}
