<?php

namespace App\Support;

/** Pure parsing only: no database, network, or thread-control operations. */
final class WhatsAppInboxProtocol
{
    public const MAX_CART_ITEMS = 30;

    /** Catalog IDs/SKUs and quoted prices remain evidence, never ERP product IDs or prices. */
    public static function cart($order): ?array
    {
        if (!is_array($order) || !is_string($order['catalog_id'] ?? null)
            || !preg_match('/\A[0-9]{1,30}\z/', $order['catalog_id'])
            || !self::isList($order['product_items'] ?? null) || $order['product_items'] === []
            || count($order['product_items']) > self::MAX_CART_ITEMS) return null;
        $text = $order['text'] ?? null;
        if ($text !== null && (!is_string($text) || strlen($text) > 4000 || preg_match('//u', $text) !== 1)) return null;
        $items = []; $seen = []; $currency = null; $total = 0;
        foreach ($order['product_items'] as $item) {
            if (!is_array($item)) return null;
            $retailer = $item['product_retailer_id'] ?? null;
            $quantity = $item['quantity'] ?? null;
            $price = self::cartPrice($item['item_price'] ?? null);
            $unitCurrency = $item['currency'] ?? null;
            if (!is_string($retailer) || trim($retailer) !== $retailer || $retailer === ''
                || strlen($retailer) > 200 || preg_match('//u', $retailer) !== 1
                || preg_match('/[\x00-\x1f\x7f]/', $retailer) || isset($seen[$retailer])
                || (!is_int($quantity) && !is_string($quantity))
                || !preg_match('/\A[1-9][0-9]{0,5}\z/', (string) $quantity)
                || $price === null || !is_string($unitCurrency) || !preg_match('/\A[A-Z]{3}\z/', $unitCurrency)
                || ($currency !== null && $currency !== $unitCurrency)) return null;
            $seen[$retailer] = true; $currency = $unitCurrency;
            $minor = self::cartMinor($price); $total += $minor * (int) $quantity;
            if ($total > 9999999999) return null;
            $items[] = ['product_retailer_id' => $retailer, 'quantity' => (string) $quantity,
                'item_price' => $price, 'currency' => $unitCurrency];
        }
        return ['catalog_id' => $order['catalog_id'], 'text' => $text, 'product_items' => $items,
            'total_price' => self::cartMoney($total), 'currency' => $currency];
    }

    /** Revalidate coordinator-supplied cart DTOs, including derived quoted totals. */
    public static function normalizedCart($cart): ?array
    {
        if (!is_array($cart) || count($cart) !== 5 || array_diff(array_keys($cart),
            ['catalog_id', 'text', 'product_items', 'total_price', 'currency'])
            || !is_string($cart['total_price'] ?? null)
            || !preg_match('/\A(?:0|[1-9][0-9]{0,7})\.[0-9]{2}\z/', $cart['total_price'])
            || !is_string($cart['currency'] ?? null) || !preg_match('/\A[A-Z]{3}\z/', $cart['currency'])
            || !self::isList($cart['product_items'] ?? null) || count($cart['product_items']) > self::MAX_CART_ITEMS) return null;
        foreach ($cart['product_items'] as $item) {
            if (!is_array($item) || count($item) !== 4 || array_diff(array_keys($item),
                ['product_retailer_id', 'quantity', 'item_price', 'currency'])) return null;
        }
        $normalized = self::cart($cart);
        if ($normalized === null || self::canonical($normalized) !== self::canonical($cart)) return null;
        return $normalized;
    }

    private static function cartPrice($value): ?string
    {
        if (is_int($value)) $value = (string) $value;
        elseif (is_float($value)) {
            if (!is_finite($value) || $value < 0 || $value > 99999999.99
                || abs($value * 100 - round($value * 100)) > 0.000001) return null;
            $value = number_format($value, 2, '.', '');
        }
        if (!is_string($value) || !preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/', $value)) return null;
        return self::cartMoney(self::cartMinor($value));
    }

    private static function cartMinor(string $value): int
    {
        $parts = explode('.', $value, 2);
        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    private static function cartMoney(int $minor): string
    {
        return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function messages(array $payload, string $wabaId = '468336579702269', string $phoneId = '515388018324075'): array
    {
        return self::report($payload, $wabaId, $phoneId)['messages'];
    }

    /** Malformed supported envelopes remain quarantined in the encrypted raw event. */
    public static function report(array $payload, string $wabaId = '468336579702269', string $phoneId = '515388018324075'): array
    {
        $report = ['messages' => [], 'quarantined_count' => 0, 'ignored_count' => 0, 'status_count' => 0];
        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            $report['ignored_count']++;
            return $report;
        }
        if (!self::isList($payload['entry'] ?? null)) {
            $report['quarantined_count']++;
            return $report;
        }
        foreach ($payload['entry'] as $entry) {
            if (!is_array($entry)) {
                $report['quarantined_count']++;
                continue;
            }
            if (!is_string($entry['id'] ?? null) || !preg_match('/\A[0-9]+\z/', $entry['id'])) {
                $report['quarantined_count']++;
                continue;
            }
            if ($entry['id'] !== $wabaId) {
                $report['ignored_count']++;
                continue;
            }
            // Messenger-style entry.standby is not a scoped WhatsApp change.
            // Retain it for review instead of recursively guessing identities.
            if (array_key_exists('standby', $entry)) {
                $report['quarantined_count']++;
            }
            if (!self::isList($entry['changes'] ?? null)) {
                $report['quarantined_count']++;
                continue;
            }
            foreach ($entry['changes'] as $change) {
                if (!is_array($change) || !is_string($change['field'] ?? null)) {
                    $report['quarantined_count']++;
                    continue;
                }
                $field = $change['field'];
                if (!in_array($field, ['messages', 'smb_message_echoes', 'standby'], true)) {
                    $report['ignored_count']++;
                    continue;
                }
                $value = $change['value'] ?? null;
                if (!is_array($value) || ($value['messaging_product'] ?? null) !== 'whatsapp' || !is_array($value['metadata'] ?? null)) {
                    $report['quarantined_count']++;
                    continue;
                }
                if (!is_string($value['metadata']['phone_number_id'] ?? null) || !preg_match('/\A[0-9]+\z/', $value['metadata']['phone_number_id'])) {
                    $report['quarantined_count']++;
                    continue;
                }
                if ($value['metadata']['phone_number_id'] !== $phoneId) {
                    $report['ignored_count']++;
                    continue;
                }
                self::value($value, $field, $report);
            }
        }
        self::quarantineConflicts($report);
        return $report;
    }

    private static function value(array $value, string $source, array &$report): void
    {
        $channel = $value;
        if ($source === 'standby' && array_key_exists('standby', $value)) {
            if (!is_array($value['standby'])) {
                $report['quarantined_count']++;
                return;
            }
            foreach (['messages', 'message_echoes', 'statuses'] as $key) {
                if (array_key_exists($key, $value)) {
                    $report['quarantined_count']++;
                    return;
                }
            }
            $channel = $value['standby'];
            if ((array_key_exists('metadata', $channel) && $channel['metadata'] !== $value['metadata']) || (array_key_exists('messaging_product', $channel) && $channel['messaging_product'] !== 'whatsapp')) {
                $report['quarantined_count']++;
                return;
            }
        }
        if ($source !== 'standby' && array_key_exists('standby', $value)) {
            $report['quarantined_count']++;
            return;
        }
        $businessPhone = self::phone($value['metadata']['display_phone_number'] ?? null);
        $contacts = array_key_exists('contacts', $channel) ? $channel['contacts'] : (array_key_exists('contacts', $value) ? $value['contacts'] : []);
        if (!self::isList($contacts)) {
            $report['quarantined_count']++;
            return;
        }
        $normalizedContacts = [];
        foreach ($contacts as $contact) {
            if (!is_array($contact)) {
                $report['quarantined_count']++;
                return;
            }
            $phone = self::phone($contact['wa_id'] ?? null);
            $user = self::user($contact['user_id'] ?? null);
            if ((array_key_exists('wa_id', $contact) && $phone === null) || (array_key_exists('user_id', $contact) && $user === null)) {
                $report['quarantined_count']++;
                return;
            }
            if (array_key_exists('profile', $contact) && !is_array($contact['profile'])) {
                $report['quarantined_count']++;
                return;
            }
            $name = $contact['profile']['name'] ?? null;
            if ($name !== null && (!is_string($name) || strlen($name) > 512)) {
                $report['quarantined_count']++;
                return;
            }
            $normalizedContacts[] = ['phone' => $phone, 'user' => $user, 'name' => $name];
        }
        $seen = false;
        foreach (['messages', 'message_echoes', 'statuses'] as $key) {
            if (!array_key_exists($key, $channel)) {
                continue;
            }
            $seen = true;
            if (!self::isList($channel[$key])) {
                $report['quarantined_count']++;
                continue;
            }
            if ($key === 'statuses') {
                if ($source === 'smb_message_echoes') {
                    $report['quarantined_count']++;
                    continue;
                }
                foreach ($channel[$key] as $status) {
                    if (is_array($status) && self::identifier($status['id'] ?? null, 512) && in_array($status['status'] ?? null, ['sent', 'delivered', 'read', 'failed', 'deleted'], true)) {
                        $report['status_count']++;
                    } else {
                        $report['quarantined_count']++;
                    }
                }
                continue;
            }
            if (($source === 'messages' && $key !== 'messages') || ($source === 'smb_message_echoes' && $key !== 'message_echoes')) {
                $report['quarantined_count'] += max(1, count($channel[$key]));
                continue;
            }
            if ($channel[$key] === []) {
                $report['quarantined_count']++;
                continue;
            }
            $direction = $key === 'message_echoes' ? 'outbound' : 'inbound';
            foreach ($channel[$key] as $message) {
                $parsed = is_array($message) ? self::message($message, $direction, $source, $normalizedContacts, $businessPhone) : null;
                if ($parsed === null) {
                    $report['quarantined_count']++;
                } else {
                    $report['messages'][] = $parsed;
                }
            }
        }
        if (!$seen) {
            $report['quarantined_count']++;
        }
    }

    private static function message(array $message, string $direction, string $source, array $contacts, ?string $businessPhone): ?array
    {
        $id = $message['id'] ?? null;
        $type = $message['type'] ?? null;
        $timestamp = $message['timestamp'] ?? null;
        if (!self::identifier($id, 512) || !is_string($type) || !preg_match('/\A[a-z_]{1,40}\z/', $type)) {
            return null;
        }
        if ((!is_string($timestamp) && !is_int($timestamp)) || !preg_match('/\A[0-9]{1,10}\z/', (string) $timestamp) || (int) $timestamp < 1 || (int) $timestamp > min(2147483647, time() + 86400)) {
            return null;
        }
        $encoded = json_encode($message);
        if (!is_string($encoded) || strlen($encoded) > 262144) {
            return null;
        }
        $text = null;
        if ($type === 'text') {
            if (!is_array($message['text'] ?? null) || !is_string($message['text']['body'] ?? null)) {
                return null;
            }
            $text = $message['text']['body'];
            if (strlen($text) > 65536) return null;
        } elseif (!self::validContent($message, $type)) {
            return null;
        }
        $phoneKey = $direction === 'inbound' ? 'from' : 'to';
        $userKey = $direction === 'inbound' ? 'from_user_id' : 'to_user_id';
        $phone = self::phone($message[$phoneKey] ?? null);
        $user = self::user($message[$userKey] ?? null);
        if ((array_key_exists($phoneKey, $message) && $phone === null) || (array_key_exists($userKey, $message) && $user === null) || ($phone === null && $user === null)) {
            return null;
        }
        if ($businessPhone !== null && $phone === $businessPhone) {
            return null;
        }
        if ($direction === 'outbound' && array_key_exists('from', $message)) {
            $sender = self::phone($message['from']);
            if ($sender === null || ($businessPhone !== null && $sender !== $businessPhone)) {
                return null;
            }
        }
        if ($direction === 'inbound' && array_key_exists('to', $message)) {
            $recipient = self::phone($message['to']);
            if ($recipient === null || ($businessPhone !== null && $recipient !== $businessPhone)) {
                return null;
            }
        }
        $name = null;
        foreach ($contacts as $contact) {
            $matches = ($phone !== null && $contact['phone'] === $phone) || ($user !== null && $contact['user'] === $user);
            if (!$matches) continue;
            if (($phone !== null && $contact['phone'] !== null && $phone !== $contact['phone']) || ($user !== null && $contact['user'] !== null && $user !== $contact['user'])) {
                return null;
            }
            $phone = $phone ?? $contact['phone'];
            $user = $user ?? $contact['user'];
            $name = $name ?? $contact['name'];
        }
        if ($businessPhone !== null && $phone === $businessPhone) {
            return null;
        }
        return [
            'message_id' => $id,
            'peer_identity' => $user !== null ? 'user:' . $user : 'phone:' . $phone,
            'peer_user_id' => $user,
            'peer_phone' => $phone,
            'customer_name' => $name,
            'direction' => $direction,
            'type' => $type,
            'text' => $text,
            'sent_at' => gmdate('Y-m-d H:i:s', (int) $timestamp),
            'source' => $source,
            'content' => ['sources' => [$source], 'message' => $message],
        ];
    }

    private static function validContent(array $message, string $type): bool
    {
        if (in_array($type, ['unsupported', 'unknown'], true) && array_key_exists('errors', $message)) {
            if (!self::isList($message['errors']) || $message['errors'] === []) return false;
            foreach ($message['errors'] as $error) if (!is_array($error) || $error === []) return false;
            return true;
        }
        $body = $message[$type] ?? null;
        if (!is_array($body) || $body === []) return false;
        if (in_array($type, ['image', 'audio', 'video', 'document', 'sticker'], true)) {
            if (!self::identifier($body['id'] ?? null, 512)) return false;
            if (array_key_exists('caption', $body) && !is_string($body['caption'])) return false;
        } elseif ($type === 'location') {
            foreach (['latitude' => 90, 'longitude' => 180] as $key => $limit) {
                $number = $body[$key] ?? null;
                if ((!is_float($number) && !is_int($number)) || !is_finite((float) $number) || abs($number) > $limit) return false;
            }
        } elseif ($type === 'contacts') {
            if (!self::isList($body)) return false;
            foreach ($body as $contact) if (!is_array($contact) || $contact === []) return false;
        } elseif ($type === 'interactive') {
            $kind = $body['type'] ?? null;
            if (!in_array($kind, ['button_reply', 'list_reply', 'nfm_reply'], true) || !is_array($body[$kind] ?? null) || $body[$kind] === []) return false;
        } elseif ($type === 'button') {
            if (!is_string($body['text'] ?? null)) return false;
        } elseif ($type === 'reaction') {
            if (!self::identifier($body['message_id'] ?? null, 512) || !is_string($body['emoji'] ?? null)) return false;
        } elseif ($type === 'order') {
            return self::cart($body) !== null;
        } elseif (in_array($type, ['edit', 'revoke'], true)) {
            if (!self::identifier($body['original_message_id'] ?? null, 512)) return false;
        } elseif (!in_array($type, ['order', 'system', 'referral', 'unsupported', 'unknown'], true)) {
            // Future message types are held with their raw event for an explicit parser update.
            return false;
        }
        return true;
    }

    private static function identifier($value, int $limit): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $limit && !preg_match('/[\x00-\x20\x7f]/', $value);
    }

    /** Never silently merge contradictory identities or contradictory copies. */
    private static function quarantineConflicts(array &$report): void
    {
        $phoneUsers = [];
        $userPhones = [];
        $ids = [];
        foreach ($report['messages'] as $index => $message) {
            $phone = $message['peer_phone'];
            $user = $message['peer_user_id'];
            if ($phone !== null && $user !== null) {
                $phoneUsers[$phone][$user] = true;
                $userPhones[$user][$phone] = true;
            }
            $ids[$message['message_id']][] = $index;
        }
        $conflicts = [];
        foreach ($report['messages'] as $index => $message) {
            $phone = $message['peer_phone'];
            $user = $message['peer_user_id'];
            if (($phone !== null && count($phoneUsers[$phone] ?? []) > 1) || ($user !== null && count($userPhones[$user] ?? []) > 1)) {
                $conflicts[$index] = true;
            }
        }
        foreach ($ids as $indexes) {
            $first = $report['messages'][$indexes[0]];
            $phones = [];
            $users = [];
            foreach ($indexes as $index) {
                $item = $report['messages'][$index];
                if ($item['peer_phone'] !== null) $phones[$item['peer_phone']] = true;
                if ($item['peer_user_id'] !== null) $users[$item['peer_user_id']] = true;
            }
            if (count($phones) > 1 || count($users) > 1) {
                foreach ($indexes as $bad) $conflicts[$bad] = true;
            }
            foreach ($indexes as $index) {
                $other = $report['messages'][$index];
                foreach (['direction', 'type', 'text', 'sent_at'] as $key) {
                    if ($first[$key] !== $other[$key]) {
                        foreach ($indexes as $bad) $conflicts[$bad] = true;
                    }
                }
                foreach (['peer_phone', 'peer_user_id'] as $key) {
                    if ($first[$key] !== null && $other[$key] !== null && $first[$key] !== $other[$key]) {
                        foreach ($indexes as $bad) $conflicts[$bad] = true;
                    }
                }
                $type = $first['type'];
                $firstBody = $first['content']['message'][$type] ?? ($first['content']['message']['errors'] ?? null);
                $otherBody = $other['content']['message'][$type] ?? ($other['content']['message']['errors'] ?? null);
                if (self::canonical($firstBody) !== self::canonical($otherBody)) {
                    foreach ($indexes as $bad) $conflicts[$bad] = true;
                }
            }
        }
        $normalized = [];
        foreach ($ids as $indexes) {
            $phones = [];
            $users = [];
            $paired = false;
            foreach ($indexes as $index) {
                $item = $report['messages'][$index];
                if ($item['peer_phone'] !== null) $phones[$item['peer_phone']] = true;
                if ($item['peer_user_id'] !== null) $users[$item['peer_user_id']] = true;
                if ($item['peer_phone'] !== null && $item['peer_user_id'] !== null) $paired = true;
            }
            // A phone and a BUID from disjoint copies require an explicit pair.
            if (count($indexes) > 1 && $phones !== [] && $users !== [] && !$paired) {
                foreach ($indexes as $bad) $conflicts[$bad] = true;
            }
            foreach ($indexes as $index) {
                if (isset($conflicts[$index])) {
                    foreach ($indexes as $bad) $conflicts[$bad] = true;
                    break;
                }
            }
            if (isset($conflicts[$indexes[0]])) continue;

            $priority = ['messages' => 0, 'standby' => 1, 'smb_message_echoes' => 2];
            usort($indexes, function ($left, $right) use ($report, $priority) {
                return ($priority[$report['messages'][$left]['source']] <=> $priority[$report['messages'][$right]['source']]) ?: ($left <=> $right);
            });
            $message = $report['messages'][$indexes[0]];
            $sources = [];
            foreach ($indexes as $index) {
                $item = $report['messages'][$index];
                $sources[$item['source']] = true;
                $message['peer_phone'] = $message['peer_phone'] ?? $item['peer_phone'];
                $message['peer_user_id'] = $message['peer_user_id'] ?? $item['peer_user_id'];
                $message['customer_name'] = $message['customer_name'] ?? $item['customer_name'];
            }
            $message['peer_identity'] = $message['peer_user_id'] !== null ? 'user:' . $message['peer_user_id'] : 'phone:' . $message['peer_phone'];
            $message['content']['sources'] = array_keys($sources);
            $normalized[] = $message;
        }
        $report['quarantined_count'] += count($conflicts);
        $report['messages'] = $normalized;
    }

    private static function canonical($value)
    {
        if (!is_array($value)) return $value;
        if (!self::isList($value)) ksort($value, SORT_STRING);
        foreach ($value as &$item) $item = self::canonical($item);
        unset($item);
        return $value;
    }

    private static function phone($value): ?string
    {
        return is_string($value) && preg_match('/\A\+?[0-9]{6,20}\z/', $value) ? ltrim($value, '+') : null;
    }

    private static function user($value): ?string
    {
        return self::identifier($value, 191) ? $value : null;
    }

    private static function isList($value): bool
    {
        return is_array($value) && ($value === [] || array_keys($value) === range(0, count($value) - 1));
    }
}
