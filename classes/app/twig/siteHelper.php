<?php

namespace app\twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class siteHelper extends AbstractExtension
{
    public function getFilters()
    {
        return [
            // 1234 -> 1.2k, 2500000 -> 2.5m
            new TwigFilter('compact', function ($number) {
                $number = (int) $number;

                if ($number < 1000) {
                    return (string) $number;
                }

                foreach (["b"=>1000000000, "m"=>1000000, "k"=>1000] as $suffix => $size) {
                    if ($number >= $size) {
                        $value = $number / $size;
                        $formatted = $value >= 100 ? floor($value) : floor($value * 10) / 10;
                        return $formatted . $suffix;
                    }
                }
            }),

            // 11520 -> 3h 12m
            new TwigFilter('playtime', fn($seconds) => \watrlabs\games\playtime::format((int) $seconds)),

            // a unix time -> "5m ago", "3d ago", then a date
            new TwigFilter('ago', function ($time) {
                $diff = time() - (int) $time;
                if ($diff < 60) { return "just now"; }
                if ($diff < 3600) { return intdiv($diff, 60) . "m ago"; }
                if ($diff < 86400) { return intdiv($diff, 3600) . "h ago"; }
                if ($diff < 86400 * 7) { return intdiv($diff, 86400) . "d ago"; }
                return date("M j", (int) $time);
            }),
        ];
    }
}
