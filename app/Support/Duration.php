<?php

namespace App\Support;

class Duration
{
    public static function label(int $minutes): string
    {
        $hours = intdiv(max($minutes, 0), 60);
        $rest = max($minutes, 0) % 60;

        if ($hours === 0) {
            return $rest.'m';
        }

        if ($rest === 0) {
            return $hours.'h';
        }

        return $hours.'h '.$rest.'m';
    }
}
