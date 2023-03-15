<?php
namespace Grav\Plugin\AksoBridge;
use Cocur\Slugify\Slugify;
use \DiDom\Document;
use \DiDom\Element;
use Grav\Common\Markdown\Parsedown;

/**
 * We have a couple of renderers written using Parsedown's HTML syntax, but Parsedown does not let you access
 * its element renderer directly. it *is* protected though, so we can subclass it to access it from here...
 */
class Parsedown2 extends Parsedown {
    public function pd2Elements($els) {
        return $this->elements($els);
    }
}

class Utils {
    static function parsedownElementsToHTML($elements) {
        return Parsedown2::instance()->pd2Elements($elements);
    }

    static function setInnerHTML($node, $html) {
        $fragment = $node->ownerDocument->createDocumentFragment();
        $fragment->appendXML($html);
        $node->appendChild($fragment);
    }

    static function formatDateTimeUtc($dateTime) {
        $time = $dateTime->getTimestamp();
        $dateTime = new \DateTime("@$time");
        $dateTime->setTimezone(new \DateTimeZone('+0000'));
        $date = $dateTime->format('Y-m-d');
        $time = $dateTime->format('H:i');

        return Utils::formatDate($date) . ' ' . $time . ' UTC';
    }

    static function formatDate($dateString) {
        $date = \DateTime::createFromFormat('Y-m-d', $dateString);
        if (!$date) return null;
        $formatted = $date->format('j') . '-a de ' . Utils::formatMonth($date->format('m')) . ' ' . $date->format('Y');
        return $formatted;
    }

    static function formatDayMonth($dateString) {
        $date = \DateTime::createFromFormat('Y-m-d', $dateString);
        if (!$date) return null;
        $formatted = $date->format('d') . '-a de ' . Utils::formatMonth($date->format('m'));
        return $formatted;
    }

    static function formatDuration($interval) {
        $prefix = $interval->invert ? 'antaŭ ' : 'post ';

        $years = $interval->y;
        $months = $interval->m;
        $days = $interval->d;
        $hours = $interval->h;
        $minutes = $interval->i;
        $seconds = $interval->s;

        $out = '';
        $space = "⁠"; // u+2060 word joiner
        $zspace = " "; // figure space

        if ($years > 0) {
            return $prefix . $years . ' jaro' . (($years > 1) ? 'j' : '');
        }
        if ($months > 0) {
            return $prefix . $months . ' monato' . (($months > 1) ? 'j' : '');
        }

        if ($days >= 7) {
            return $prefix . $days . ' tagoj';
        } else if ($days > 0) {
            $out .= $days . $space . 't' . $zspace;
        }
        if ($days > 0 || $hours > 0) $out .= $hours . $space . 'h' . $zspace;
        $out .= $minutes . $space . 'm';
        return $prefix . $out;
    }

    static function formatMonth($number) {
        $months = [
            '???',
            'januaro',
            'februaro',
            'marto',
            'aprilo',
            'majo',
            'junio',
            'julio',
            'aŭgusto',
            'septembro',
            'oktobro',
            'novembro',
            'decembro',
        ];
        return $months[(int) $number];
    }

    // src: https://www.php.net/manual/en/function.base-convert.php#102232 by Bryan Ruiz
    private static $base32Map = array(
        'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', //  7
        'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', // 15
        'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', // 23
        'Y', 'Z', '2', '3', '4', '5', '6', '7', // 31
        '='  // padding char
    );
    private static $base32FlippedMap = array(
        'A'=>'0', 'B'=>'1', 'C'=>'2', 'D'=>'3', 'E'=>'4', 'F'=>'5', 'G'=>'6', 'H'=>'7',
        'I'=>'8', 'J'=>'9', 'K'=>'10', 'L'=>'11', 'M'=>'12', 'N'=>'13', 'O'=>'14', 'P'=>'15',
        'Q'=>'16', 'R'=>'17', 'S'=>'18', 'T'=>'19', 'U'=>'20', 'V'=>'21', 'W'=>'22', 'X'=>'23',
        'Y'=>'24', 'Z'=>'25', '2'=>'26', '3'=>'27', '4'=>'28', '5'=>'29', '6'=>'30', '7'=>'31'
    );
    static function base32_decode($input) {
        if(empty($input)) return;
        $paddingCharCount = substr_count($input, self::$base32Map[32]);
        $allowedValues = array(6,4,3,1,0);
        if(!in_array($paddingCharCount, $allowedValues)) return false;
        for($i=0; $i<4; $i++){
            if($paddingCharCount == $allowedValues[$i] &&
                substr($input, -($allowedValues[$i])) != str_repeat(self::$base32Map[32], $allowedValues[$i])) return false;
        }
        $input = str_replace('=','', $input);
        $input = str_split($input);
        $binaryString = "";
        for($i=0; $i < count($input); $i = $i+8) {
            $x = "";
            if(!in_array($input[$i], self::$base32Map)) return false;
            for($j=0; $j < 8; $j++) {
                $x .= str_pad(base_convert(@self::$base32FlippedMap[@$input[$i + $j]], 10, 2), 5, '0', STR_PAD_LEFT);
            }
            $eightBits = str_split($x, 8);
            for($z = 0; $z < count($eightBits); $z++) {
                $binaryString .= ( ($y = chr(base_convert($eightBits[$z], 2, 10))) || ord($y) == 48 ) ? $y:"";
            }
        }
        return $binaryString;
    }
    public static function base32_encode($input, $padding = true) {
        if(empty($input)) return "";
        $input = str_split($input);
        $binaryString = "";
        for($i = 0; $i < count($input); $i++) {
            $binaryString .= str_pad(base_convert(ord($input[$i]), 10, 2), 8, '0', STR_PAD_LEFT);
        }
        $fiveBitBinaryArray = str_split($binaryString, 5);
        $base32 = "";
        $i=0;
        while($i < count($fiveBitBinaryArray)) {
            $base32 .= self::$base32Map[base_convert(str_pad($fiveBitBinaryArray[$i], 5,'0'), 2, 10)];
            $i++;
        }
        if($padding && ($x = strlen($binaryString) % 40) != 0) {
            if($x == 8) $base32 .= str_repeat(self::$base32Map[32], 6);
            else if($x == 16) $base32 .= str_repeat(self::$base32Map[32], 4);
            else if($x == 24) $base32 .= str_repeat(self::$base32Map[32], 3);
            else if($x == 32) $base32 .= self::$base32Map[32];
        }
        return $base32;
    }

    static function latinizeEsperanto($s, $useH) {
        $latinizeEsperanto = function ($k) {
            switch ($k) {
                case 'Ĥ': return 'H';
                case 'Ŝ': return 'S';
                case 'Ĝ': return 'G';
                case 'Ĉ': return 'C';
                case 'Ĵ': return 'J';
                case 'Ŭ': return 'U';
                case 'ĥ': return 'h';
                case 'ŝ': return 's';
                case 'ĝ': return 'g';
                case 'ĉ': return 'c';
                case 'ĵ': return 'j';
                case 'ŭ': return 'u';
            }
            return $k;
        };

        $replaceHUpper = function (array $matches) use ($latinizeEsperanto, $useH) {
            return $latinizeEsperanto($matches[1]) . ($useH ? 'H' : '');
        };
        $replaceH = function (array $matches) use ($latinizeEsperanto, $useH) {
            return $latinizeEsperanto($matches[1]) . ($useH ? 'h' : '');
        };

        $s = preg_replace_callback('/([ĤŜĜĈĴŬ])(?=[A-ZĤŜĜĈĴŬ])/u', $replaceHUpper, $s);
        $s = preg_replace_callback('/([ĥŝĝĉĵŭ])/ui', $replaceH, $s);

        return $s;
    }

    static function escapeFileNameLossy($name) {
        $s = \Normalizer::normalize($name);
        $s = self::latinizeEsperanto($s, true);
        $slugify = new Slugify(['lowercase' => false]);
        return $slugify->slugify($s);
    }

    static function formatCurrency(int $value, string $currency, $withUnit = true): string {
        if ($currency === 'JPY') {
            $decimals = 0;
        } else {
            $decimals = 2;
        }

        $number = number_format($value / pow(10, $decimals), $decimals, ',', "\u{202f}");
        if ($withUnit) {
            return $number . ' ' . $currency;
        }
        return $number;
    }

    static function obfuscateEmail($email) {
        $emailLink = new Element('a');
        $emailLink->class = 'non-interactive-address';
        $emailLink->href = 'javascript:void(0)';

        // obfuscated email
        $parts = preg_split('/(?=[@\.])/', $email);
        for ($i = 0; $i < count($parts); $i++) {
            $text = $parts[$i];
            $after = '';
            $delim = '';
            if ($i !== 0) {
                // split off delimiter
                $delim = substr($text, 0, 1);
                $mid = ceil(strlen($text) * 2 / 3);
                $after = substr($text, $mid);
                $text = substr($text, 1, $mid - 1);
            } else {
                $mid = ceil(strlen($text) * 2 / 3);
                $after = substr($text, $mid);
                $text = substr($text, 0, $mid);
            }
            $emailPart = new Element('span', $text);
            $emailPart->class = 'epart';
            $emailPart->setAttribute('data-at-sign', '@');
            $emailPart->setAttribute('data-after', $after);

            if ($delim === '@') {
                $emailPart->setAttribute('data-show-at', 'true');
            } else if ($delim === '.') {
                $emailPart->setAttribute('data-show-dot', 'true');
            }

            $emailLink->appendChild($emailPart);
        }

        $invisible = new Element('span', ' (uzu retumilon kun CSS por vidi retpoŝtadreson)');
        $invisible->class = 'fpart';
        $emailLink->appendChild($invisible);

        return $emailLink;
    }

    private static $cached_countries = null;
    public static function getCountries($bridge) {
        if (self::$cached_countries) {
            return self::$cached_countries;
        }
        $res = $bridge->get('/countries', array(
            'limit' => 300,
            'fields' => ['code', 'name_eo'],
            'order' => [['name_eo', 'asc']],
        ), 600);
        if (!$res['k']) return null;
        self::$cached_countries = $res['b'];
        return $res['b'];
    }

    // Formats a country code
    public static function formatCountry($bridge, $code) {
        foreach (self::getCountries($bridge) as $country) {
            if ($country['code'] === $code) return $country['name_eo'];
        }
        return null;
    }

    public static function getEmojiForFlag($code) {
        $altText = '';
        $emojiName = '';
        if (strlen($code) === 2) {
            $ri1 = 0x1f1e6 - 0x61 + ord($code[0]);
            $ri2 = 0x1f1e6 - 0x61 + ord($code[1]);
            $altText = mb_chr($ri1) . mb_chr($ri2);
            $emojiName = 'twemoji/' . dechex($ri1) . '-' . dechex($ri2);
        } else {
            $altText = $code;
            $emojiName = 'extra/' . $code;
        }
        return array(
            'src' => "/user/plugins/akso-bridge/emoji/$emojiName.png",
            'alt' => $altText,
        );
    }


}
