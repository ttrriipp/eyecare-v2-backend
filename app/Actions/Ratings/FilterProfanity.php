<?php

namespace App\Actions\Ratings;

final class FilterProfanity
{
    /**
     * Common English and Filipino profanity terms used for feedback filtering.
     *
     * @var array<int, string>
     */
    private const array BAD_WORDS = [
        'asshole',
        'bastard',
        'bitch',
        'blow job',
        'blowjob',
        'bullshit',
        'cock',
        'cocksucker',
        'cum',
        'cunt',
        'dick',
        'fuck',
        'fucker',
        'fucking',
        'hand job',
        'handjob',
        'jack off',
        'jackoff',
        'jerk off',
        'jerkoff',
        'jizz',
        'motherfucker',
        'piss',
        'porn',
        'porno',
        'pussy',
        'shit',
        'slut',
        'tit',
        'tits',
        'twat',
        'wank',
        'whore',
        'bayag',
        'bobo',
        'gaga',
        'gago',
        'hayop',
        'inutil',
        'iyot',
        'kantot',
        'kantutan',
        'jakol',
        'jakulin',
        'leche',
        'letse',
        'libog',
        'malibog',
        'pakyu',
        'punyeta',
        'puta',
        'putang ina',
        'putangina',
        'puke',
        'sira ulo',
        'salsal',
        'tanga',
        'tang ina',
        'tangina',
        'tamod',
        'tarantado',
        'titi',
        'ulol',
    ];

    public function handle(?string $comment): ?string
    {
        if ($comment === null || $comment === '') {
            return $comment;
        }

        $words = array_map(
            fn (string $word): string => preg_quote($word, '/'),
            self::BAD_WORDS,
        );

        usort($words, fn (string $first, string $second): int => strlen($second) <=> strlen($first));

        $pattern = '/(?<![\p{L}\p{N}])(?:'.implode('|', $words).')(?![\p{L}\p{N}])/iu';

        return preg_replace_callback(
            $pattern,
            fn (array $matches): string => str_repeat('*', mb_strlen($matches[0], 'UTF-8')),
            $comment,
        ) ?? $comment;
    }
}
