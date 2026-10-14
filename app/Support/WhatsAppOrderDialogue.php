<?php

namespace App\Support;

/** A question selector can choose only supplied topics and menu labels; it cannot supply order facts. */
final class WhatsAppOrderDialogue
{
    public const FIELDS = ['NAME', 'PHONE', 'ADDRESS', 'ITEMS', 'QUANTITY', 'QUANTITY_MODE',
        'FULFILLMENT', 'BRANCH', 'LOCATION'];
    public const KINDS = ['MISSING', 'QUANTITY_ASSOCIATION', 'PRODUCT_CHOICE', 'OPTION_CHOICE',
        'UNREADABLE', 'CONFLICT', 'GREETING', 'CATALOG_INFORMATION'];

    public static function validContext(array $context): bool
    {
        try {
            if (!self::keys($context, ['field', 'kind', 'item_name', 'candidates', 'transcript'])
                || !in_array($context['field'], self::FIELDS, true)
                || !in_array($context['kind'], self::KINDS, true)
                || ($context['item_name'] !== null && !self::literal($context['item_name'], 200))
                || !is_array($context['candidates']) || !array_is_list($context['candidates'])
                || count($context['candidates']) > 12 || !is_array($context['transcript'])
                || !WhatsAppOrderExtraction::validateTranscript($context['transcript'])['ok']
                || WhatsAppOrderExtraction::hasProbe($context['transcript'])) return false;
            $menu = in_array($context['kind'], ['PRODUCT_CHOICE', 'OPTION_CHOICE', 'CATALOG_INFORMATION'], true);
            if ($menu !== ($context['candidates'] !== []) || ($menu && $context['field'] !== 'ITEMS')) return false;
            if ($context['kind'] === 'QUANTITY_ASSOCIATION'
                && (!in_array($context['field'], ['QUANTITY', 'QUANTITY_MODE'], true)
                    || $context['item_name'] === null)) return false;
            if ($context['kind'] === 'GREETING' && ($context['field'] !== 'ITEMS'
                || $context['item_name'] !== null || $context['candidates'] !== [])) return false;
            if ($context['kind'] === 'CATALOG_INFORMATION' && $context['item_name'] !== null) return false;
            $ids = []; $labels = [];
            foreach ($context['candidates'] as $candidate) {
                if (!is_array($candidate) || !self::keys($candidate, ['id', 'label'])
                    || !is_string($candidate['id']) || !preg_match('/\Ac[1-9][0-9]{0,3}\z/', $candidate['id'])
                    || isset($ids[$candidate['id']]) || !self::literal($candidate['label'], 240)
                    || isset($labels[$candidate['label']])) return false;
                $ids[$candidate['id']] = true; $labels[$candidate['label']] = true;
            }
            if ($context['item_name'] !== null) {
                $found = false;
                foreach ($context['transcript'] as $row) {
                    foreach ([$row['text'], $row['cart']['text'] ?? null] as $text)
                        if (is_string($text) && strpos($text, $context['item_name']) !== false) $found = true;
                }
                if (!$found) return false;
            }
            return true;
        } catch (\Throwable $ignore) { return false; }
    }

    /** Return a canonical choice, or null; a valid choice is still no authority to send or dispatch. */
    public static function validateChoice(array $context, array $choice): ?array
    {
        if (!self::validContext($context) || !self::keys($choice, ['action', 'candidate_ids', 'variant'])
            || !in_array($choice['action'], ['ASK', 'WAIT'], true)
            || !in_array($choice['variant'], ['SHORT', 'POLITE'], true)
            || !is_array($choice['candidate_ids']) || !array_is_list($choice['candidate_ids'])
            || count($choice['candidate_ids']) > 6) return null;
        $allowed = array_column($context['candidates'], 'label', 'id'); $seen = [];
        foreach ($choice['candidate_ids'] as $id) {
            if (!is_string($id) || !isset($allowed[$id]) || isset($seen[$id])) return null;
            $seen[$id] = true;
        }
        if ($choice['action'] === 'WAIT' && $choice['candidate_ids'] !== []) return null;
        if ($choice['action'] === 'ASK'
            && (($context['candidates'] !== []) !== ($choice['candidate_ids'] !== []))) return null;
        return ['action' => $choice['action'], 'candidate_ids' => $choice['candidate_ids'], 'variant' => $choice['variant']];
    }

    /** Deterministic, grounded fallback after a reserved attempt; never model-written prose or guessed products. */
    public static function fallback(array $context): array
    {
        if (!self::validContext($context)) throw new \RuntimeException('INVALID_DIALOGUE_CONTEXT');
        return ['action' => 'ASK', 'candidate_ids' => array_slice(array_column($context['candidates'], 'id'), 0, 6),
            'variant' => 'POLITE'];
    }

    /** WAIT renders empty: the coordinator must not send an empty message. */
    public static function render(array $context, array $choice): string
    {
        $choice = self::validateChoice($context, $choice);
        if ($choice === null) throw new \RuntimeException('INVALID_DIALOGUE_CHOICE');
        if ($choice['action'] === 'WAIT') return '';
        if ($context['kind'] === 'GREETING')
            return 'أهلاً بحضرتك في فسخانستا. تحت أمرك، تحب نساعدك بإيه أو تطلب إيه؟';
        $polite = $choice['variant'] === 'POLITE' ? 'لو سمحت، ' : '';
        $item = $context['item_name'] === null ? '' : 'بالنسبة لـ «'.$context['item_name'].'»، ';
        if ($context['candidates'] !== []) {
            $labels = array_column($context['candidates'], 'label', 'id'); $lines = [];
            foreach ($choice['candidate_ids'] as $id) $lines[] = '• '.$labels[$id];
            $text = $context['kind'] === 'CATALOG_INFORMATION'
                ? "دي بعض الأصناف المتاحة في الفرع:\n".implode("\n", $lines)."\nتحب تعرف تفاصيل عن أنهي صنف؟"
                : $polite.$item."من الاختيارات المتاحة عندنا:\n".implode("\n", $lines)
                    ."\nاكتب اسم ".($context['kind'] === 'OPTION_CHOICE' ? 'النوع' : 'الصنف').' اللي تحبه.';
        } else {
            $questions = ['NAME' => 'ممكن اسم حضرتك علشان نسجّل الطلب؟',
                'PHONE' => 'ممكن رقم هاتف للتواصل بخصوص الطلب؟',
                'ADDRESS' => 'عنوان التوصيل بالتفصيل إيه؟',
                'ITEMS' => 'تحب تطلب إيه؟ اكتب لنا اسم الصنف المقصود.',
                'QUANTITY' => 'تحب قد إيه؟',
                'QUANTITY_MODE' => 'الكمية المطلوبة بالكيلو ولا بالقطعة؟',
                'FULFILLMENT' => 'تحب الطلب دليفري ولا في الصالة أو تيك أواي؟',
                'BRANCH' => 'تقصد أنهي فرع أو مدينة للطلب؟',
                'LOCATION' => 'ممكن تبعت لوكيشن عنوان التوصيل من زر الموقع في واتساب؟'];
            $lead = $context['kind'] === 'UNREADABLE' ? 'معلش، محتاجين توضيح الجزئية دي. '
                : ($context['kind'] === 'CONFLICT' ? 'علشان نتأكد من البيانات الحالية، ' : '');
            $text = $polite.$item.$lead.$questions[$context['field']];
            if ($context['kind'] === 'QUANTITY_ASSOCIATION' && $context['field'] === 'QUANTITY')
                $text .= ' عدد السمك في الكيلو بيحدد الحجم؛ محتاجين كمية الطلب نفسها.';
        }
        if (!self::literal($text, 4096, true)) throw new \RuntimeException('INVALID_DIALOGUE_RENDER');
        return $text;
    }

    private static function keys(array $value, array $keys): bool
    { return count($value) === count($keys) && array_diff($keys, array_keys($value)) === []; }

    private static function literal($value, int $maximum, bool $newlines = false): bool
    {
        return is_string($value) && trim($value) !== '' && preg_match('//u', $value) === 1
            && preg_match_all('/./us', $value) <= $maximum
            && !preg_match($newlines ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/', $value);
    }
}
