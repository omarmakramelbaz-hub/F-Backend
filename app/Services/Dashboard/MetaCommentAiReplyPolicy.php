<?php

namespace App\Services\Dashboard;

/** Preserve legacy behavior if the separately installed owner reference is unavailable. */
class MetaCommentAiReplyPolicy
{
    public const MAX_MESSAGE_CHARACTERS = 600;

    private static function policy(): string
    {
        return FasakhanstaCommentTrainingPolicy::enabled()
            ? FasakhanstaCommentTrainingPolicy::class : LegacyMetaCommentAiReplyPolicy::class;
    }

    public static function priceRedirect(string $commentText = ''): string
    { $policy = self::policy(); return $policy::priceRedirect($commentText); }

    public static function isPriceInquiry(string $text): bool
    { $policy = self::policy(); return $policy::isPriceInquiry($text); }

    public static function priceResult(string $commentText = ''): array
    { $policy = self::policy(); return $policy::priceResult($commentText); }

    public static function validateReply(string $message, array $productNames = [], string $commentText = ''): array
    { $policy = self::policy(); return $policy::validateReply($message, $productNames, $commentText); }

    public static function skip(string $reason): array
    { $policy = self::policy(); return $policy::skip($reason); }
}
