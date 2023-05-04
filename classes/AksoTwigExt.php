<?php

namespace Grav\Plugin\AksoBridge;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class AksoTwigExt extends AbstractExtension {
    public function getFilters() {
        return [
            new TwigFilter('akso_date', [$this, 'akso_date']),
            new TwigFilter('akso_datetime', [$this, 'akso_datetime']),
            new TwigFilter('akso_currency', [$this, 'akso_currency']),
        ];
    }

    public function akso_date($input): string {
        try {
            if (preg_match('/^\d+-\d+-\d+$/', $input)) {
                // it's YMD. nothing to do
            } else if (preg_match('/^\d+$/', $input)) {
                $dateTime = new \DateTime("@$input");
                $input = $dateTime->format('Y-m-d');
            }

            $res = Utils::formatDate($input);
            if ($res) return $res;
        } catch (\Exception $e) {}
        return '<' . $input . '>';
    }

    public function akso_datetime($input): string {
        try {
            if (gettype($input) === 'integer' || preg_match('^\d+$', $input)) {
                $dateTime = new \DateTime("@$input");
            } else {
                $dateTime = new \DateTime($input);
            }
            return Utils::formatDateTimeUtc($dateTime);
        } catch (\Exception $e) {
            return '<' . $input . '>';
        }
    }

    public function akso_currency($input, $currency): string {
        if ($input === null) return '?';
        return Utils::formatCurrency($input, $currency);
    }
}
