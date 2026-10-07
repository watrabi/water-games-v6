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
        ];
    }
}
