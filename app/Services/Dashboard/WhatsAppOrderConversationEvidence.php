<?php

namespace App\Services\Dashboard;

/**
 * Bounded, side effect free proof of a selected basket across short chat turns.
 * References choose supplied rows; they never create text, products or ERP IDs.
 */
final class WhatsAppOrderConversationEvidence
{
    private const REFERENCE_KEYS = ['name_anchor_id', 'business_offer_id', 'customer_quantity_id',
        'business_summary_id', 'summary_clause_index', 'customer_option_id'];
    private const MAX_ROWS = 60;
    private const MAX_ITEMS = 30;

    /**
     * Prompt-only proof of the selected amounts before our factual recap exists.
     * It cannot prove CONFIRMED, customer approval, live catalog identity or dispatch.
     */
    public static function draftItemQuantities(array $data, array $transcript, ?array &$sources = null, ?int &$unresolvedIndex = null): bool
    {
        $sources = []; $unresolvedIndex = null;
        if (($data['decision'] ?? null) !== 'DRAFT') return false;
        $checked = \App\Support\WhatsAppOrderExtraction::validateDetailed($data, $transcript);
        if (($checked['ok'] ?? false) !== true || ($checked['data']['decision'] ?? null) !== 'DRAFT'
            || !self::evidenceAfterTest($data, $transcript) || empty($data['items'])) return false;
        $ledger = self::ledger(self::episodeRows($transcript));
        if ($ledger === null) return false;
        [$rows, $positions] = $ledger;
        $items = $data['items']; $requests = []; $selections = []; $resolved = []; $deferredDirect=[];
        foreach ($rows as $row) if ($row['speaker'] === 'customer' && preg_match('/(?:مش|لا)\s+(?:عايز|عاوز|موافق|أريد|اريد)|'
            . '(?<![\p{L}\p{N}])(?:و)?(?:إلغاء|الغاء|إلغي|إلغى|الغي|الغى|الغيه|إلغيه|ألغي|ألغى|لغي|لغيه|إلغ|الغ|الغوا|إلغوا|الغيت|ألغيت|هلغي)(?![\p{L}\p{N}])|'
            . '(?:بدل|بدّل|غيّر|غير)\s+(?:الطلب|الأوردر|الاوردر)|\b(?:cancel|not\s+want|change\s+the\s+order)\b/iu',$row['text'] ?? '')) return false;
        foreach ($data['evidence']['request_ids'] as $id) {
            if (!isset($rows[$id]) || $rows[$id]['speaker'] !== 'customer') return false;
            $text=$rows[$id]['text']??'';
            // Preserve each whole named message: an orphan second amount in the
            // same message cannot disappear when another item answers later.
            foreach ($items as $candidate) if (self::literal($candidate['name'],$text)) { $requests[]=$text; break; }
        }
        foreach ($items as $index => $item) {
            $unresolvedIndex = $index;
            $amount = self::declaredAmount($item);
            if ($amount === null || ($item['cart_reference'] ?? null) !== null) return false;
            $ref = $item['conversation_reference'] ?? null;
            if ($ref === null) {
                $option = self::pendingSelectedOption($item,$data,$transcript);
                if ($option !== null) {
                    $resolved[$index]=$option['reference'];
                    continue;
                }
                if (\App\Support\WhatsAppOrderExtraction::itemQuantityBacked($item, $items, $requests)) { $resolved[$index]=null; continue; }
                // Link only supplied labels for a prompt. These temporary links are
                // never persisted into extraction and cannot authorize final proof.
                $candidates = []; $ordered = array_values($rows);
                foreach ($ordered as $p=>$quantityRow) {
                    if ($p < 2 || $quantityRow['speaker'] !== 'customer'
                        || !self::cited($data,'request_ids',$quantityRow['id'])
                        || !self::shortText($quantityRow['text'] ?? null,16000)
                        || !self::bareSelection($quantityRow['text'],$item['name'])
                        || !self::oneAmount($quantityRow['text'],$amount)) continue;
                    $offerRow = $ordered[$p - 1];
                    if ($offerRow['speaker'] !== 'business' || !self::shortText($offerRow['text'] ?? null,16000)
                        || !self::literal($item['name'],$offerRow['text']) || !self::quantityQuestion($offerRow['text'])
                        || self::topics($offerRow['text'],$items) !== [$index]) continue;
                    $anchorRow = null;
                    for ($a=$p - 2;$a >= max(0,$p - 8);$a--) {
                        if (($ordered[$a]['text']??null)===null&&($ordered[$a]['location']??null)!==null) continue;
                        if (!self::shortText($ordered[$a]['text'] ?? null,16000)) break;
                        if ($ordered[$a]['speaker'] === 'customer' && self::cited($data,'request_ids',$ordered[$a]['id'])
                            && self::literal($item['name'],$ordered[$a]['text'])) { $anchorRow=$ordered[$a]; break; }
                    }
                    if ($anchorRow !== null) $candidates[]=['name_anchor_id'=>$anchorRow['id'],'business_offer_id'=>$offerRow['id'],
                        'customer_quantity_id'=>$quantityRow['id'],'business_summary_id'=>$offerRow['id'],
                        'summary_clause_index'=>0,'customer_option_id'=>null];
                }
                if (count($candidates) !== 1) {
                    // A shared original row is considered only after every
                    // other mentioned item has its own actual named question/answer.
                    $deferredDirect[$index]=true; $resolved[$index]=null; continue;
                }
                $ref = $candidates[0];
            }
            if (!is_array($ref) || !self::exactKeys($ref, self::REFERENCE_KEYS)) return false;
            foreach (['name_anchor_id'=>'customer','business_offer_id'=>'business','customer_quantity_id'=>'customer',
                'customer_option_id'=>'customer'] as $key=>$speaker) {
                $id = $ref[$key];
                if ($key === 'customer_option_id' && $id === null) continue;
                if (!is_string($id) || !isset($rows[$id]) || $rows[$id]['speaker'] !== $speaker
                    || !self::shortText($rows[$id]['text'] ?? null,16000)
                    || ($speaker === 'customer' && !self::cited($data,'request_ids',$id))) return false;
            }
            $anchor = $positions[$ref['name_anchor_id']]; $offer = $positions[$ref['business_offer_id']];
            $quantity = $positions[$ref['customer_quantity_id']];
            if ($quantity < $offer) {
                $selected=self::pendingSelectedOption($item,$data,$transcript);
                if ($selected===null) return false;
                foreach (['name_anchor_id','business_offer_id','customer_quantity_id','customer_option_id'] as $key)
                    if ($selected['reference'][$key]!==$ref[$key]) return false;
                $resolved[$index]=$ref;
                continue;
            }
            if (!($anchor < $offer && $offer < $quantity) || $quantity - $anchor > 8) return false;
            $anchorText = $rows[$ref['name_anchor_id']]['text'];
            $offerText = $rows[$ref['business_offer_id']]['text'];
            $quantityText = $rows[$ref['customer_quantity_id']]['text'];
            $directAnswer = $quantity === $offer + 1 && self::literal($item['name'],$offerText)
                && self::quantityQuestion($offerText)
                && (self::bareSelection($quantityText,$item['name'])
                    || \App\Support\WhatsAppOrderExtraction::itemQuantityBacked($item,$items,[$quantityText]));
            if (!self::literal($item['name'],$anchorText) || self::unsafe($anchorText) || self::unsafe($offerText)
                || self::unsafe($quantityText) || self::amendment($quantityText) || self::alternatives($quantityText)
                || preg_match('/[?؟"«»“”]/u',$quantityText) || (!self::selection($quantityText) && !$directAnswer)
                || !self::oneAmount($quantityText,$amount)
                || !self::offerFor($item,$index,$items,$rows,$positions,$ref)
                || (!self::topicIs($anchorText,$index,$items)
                    &&!self::namedQuestionDisambiguates($item,$index,$items,$rows,$positions,$ref))) return false;
            $topicStart = self::literal($item['name'],$offerText) ? $offer : $anchor;
            foreach ($rows as $id=>$row) {
                $p = $positions[$id]; if ($p <= $topicStart || $p > $quantity) continue;
                $topics = self::topics($row['text'] ?? '',$items);
                if ($topics && $topics !== [$index]) return false;
                if ($row['speaker'] === 'customer' && $p < $quantity && !$topics
                    && preg_match('/\A\s*(?:في|عندكم|متاح)\s+\S+/u',$row['text'] ?? '')) return false;
            }
            $variants = self::variants($quantityText); $offered = self::variants($offerText);
            if (count($variants) > 1 || (!$variants && count($offered) > 1)) return false;
            $size = $variants[0] ?? ($offered[0] ?? null);
            if ($size !== null && !in_array($size,$offered,true)) return false;
            if (($item['option_hint'] ?? null) !== null) {
                $optionId = $ref['customer_option_id'] ?? $ref['customer_quantity_id'];
                $optionText = $rows[$optionId]['text'];
                if ($positions[$optionId] < $quantity || !self::positiveOption($item['option_hint'],$optionText)
                    || !self::oneAmount($optionText,$amount)) return false;
                $topics = self::topics($optionText,$items);
                if ($topics && $topics !== [$index]) return false;
            }
            $selections[$index] = ['id'=>$ref['customer_quantity_id'],'position'=>$quantity,'amount'=>$amount,'size'=>$size];
            $resolved[$index] = $ref;
        }
        foreach ($deferredDirect as $index=>$unused) {
            $unresolvedIndex=$index;
            if (!self::sharedNamedQuantityFromRows($items[$index],$index,$data,$rows,$positions,$resolved)) return false;
        }
        $start=null;
        foreach ($rows as $id=>$row) if ($row['speaker']==='customer'&&self::cited($data,'request_ids',$id)
            &&self::selection($row['text']??'')&&!preg_match('/["«»“”]/u',$row['text']??'')) {
            $named=preg_match('/(?<![\p{L}\p{N}])(?:ال)?(?:طلب|أوردر|اوردر|order)(?![\p{L}\p{N}])/iu',$row['text']??'')===1;
            foreach ($items as $item) if (self::literal($item['name'],$row['text']??'')) $named=true;
            if ($named) { $start=$positions[$id]; break; }
        }
        foreach ($resolved as $ref) if (is_array($ref)) $start=$start===null?$positions[$ref['customer_quantity_id']]
            :min($start,$positions[$ref['customer_quantity_id']]);
        $start=$start??0;
        // Account for actual native sale amounts, including ones the model did
        // not cite. Earlier price inquiries do not belong to this purchase.
        foreach ($rows as $id=>$row) {
            if ($row['speaker']!=='customer'||$positions[$id]<$start) continue;
            $actual=[];
            foreach (self::clauses($row['text']??'') as $clause) $actual=array_merge($actual,self::quantities($clause));
            if (!$actual) continue;
            $named=false;
            foreach ($items as $item) if (self::literal($item['name'],$row['text']??'')) $named=true;
            $covered=[];
            if ($named) {
                foreach ($items as $index=>$item) {
                    if (\App\Support\WhatsAppOrderExtraction::itemQuantityBacked($item,$items,[$row['text']]))
                        $covered[]=self::declaredAmount($item);
                    elseif (isset($deferredDirect[$index])&&self::sharedNamedQuantityFromRows($item,$index,$data,$rows,$positions,$resolved,$id))
                        $covered[]=self::declaredAmount($item);
                }
            } else {
                $owners=[];
                foreach ($resolved as $index=>$ref) if (is_array($ref)&&($ref['customer_quantity_id']===$id
                    ||($ref['customer_option_id']??null)===$id)) $owners[]=$index;
                if (count($owners)!==1||!self::cited($data,'request_ids',$id)) {
                    $unresolvedIndex=$owners[0]??0; return false;
                }
                $covered[]=self::declaredAmount($items[$owners[0]]);
            }
            $encode=static fn($amount)=>json_encode($amount,JSON_THROW_ON_ERROR);
            $actual=array_map($encode,$actual); $covered=array_map($encode,$covered); sort($actual); sort($covered);
            if ($actual!==$covered) { $unresolvedIndex=0; return false; }
        }
        $unresolvedIndex = null;
        foreach ($selections as $index=>$selection) foreach ($rows as $id=>$row) {
            if ($positions[$id] <= $selection['position'] || $row['speaker'] !== 'customer') continue;
            $text = $row['text'] ?? '';
            if (self::unsafe($text) || self::amendment($text) || self::newOrder($text)) return false;
            $amounts = self::quantities($text); if (!$amounts) continue;
            $otherSelection = false;
            foreach ($selections as $otherIndex=>$other)
                if ($otherIndex !== $index && $id === $other['id']) $otherSelection = true;
            if ($otherSelection) continue;
            $topics = self::topics($text,$items);
            if ($topics && !in_array($index,$topics,true)) {
                foreach ($topics as $topic) if (!is_int($topic) || !isset($items[$topic])) return false;
                continue;
            }
            if ($topics !== [$index] || !self::oneAmount($text,$selection['amount'])) return false;
            $sizes = self::variants($text);
            if (count($sizes) > 1 || ($sizes && $sizes !== [$selection['size']])) return false;
        }
        $sources = $resolved;
        return true;
    }

    /** Native source labels for prompt-only inherited choices; never changes extraction. */
    public static function draftItemQuantitySources(array $data,array $transcript): ?array
    {
        $sources=[];
        return self::draftItemQuantities($data,$transcript,$sources) ? $sources : null;
    }

    /** Final callers must independently prove the contextual item, recap and approval first. */
    public static function sharedNamedQuantityBacked(array $item,array $data,array $transcript): bool
    {
        $copy=$data; $copy['decision']='DRAFT'; $sources=[];
        if (!self::draftItemQuantities($copy,array_values($transcript),$sources)) return false;
        $index=array_search($item,$data['items']??[],true); if ($index===false) return false;
        $ledger=self::ledger(self::episodeRows(array_values($transcript))); if ($ledger===null) return false;
        [$rows,$positions]=$ledger;
        return self::sharedNamedQuantityFromRows($item,$index,$copy,$rows,$positions,$sources);
    }

    /** A later uniquely named question can resolve names originally mentioned together. */
    private static function namedQuestionDisambiguates(array $item,int $index,array $items,array $rows,array $positions,array $ref): bool
    {
        $anchor=$rows[$ref['name_anchor_id']]['text']??''; $offer=$rows[$ref['business_offer_id']]['text']??'';
        $answer=$rows[$ref['customer_quantity_id']]['text']??'';
        $topics=self::topics($anchor,$items);
        foreach ($topics as $topic) if (!is_int($topic)) return false;
        return count($topics)>1&&in_array($index,$topics,true)&&self::literal($item['name'],$anchor)
            &&self::topics($offer,$items)===[$index]&&self::literal($item['name'],$offer)&&self::quantityQuestion($offer)
            &&$positions[$ref['customer_quantity_id']]===$positions[$ref['business_offer_id']]+1
            &&self::bareSelection($answer,$item['name'])&&self::oneAmount($answer,self::declaredAmount($item));
    }

    /** Exact AMOUNT FIRST-PRODUCT + bare other names, each later resolved independently. */
    private static function sharedNamedQuantityFromRows(array $item,int $index,array $data,array $rows,array $positions,array $sources,?string $onlyId=null): bool
    {
        $others=[];
        foreach ($data['items'] as $other=>$candidate) if ($other!==$index) $others[$candidate['name']]=$other;
        if (!$others||count($others)!==count($data['items'])-1) return false;
        $names=array_keys($others); usort($names,static fn($a,$b)=>strlen($b)<=>strlen($a));
        $alternation=implode('|',array_map(static fn($name)=>preg_quote($name,'/'),$names));
        $pattern='/\A\s*(?:(?:(?:أنا|انا)\s+)?(?:عايز|عاوز|أريد|اريد|اطلب|أطلب|هات|ابعت|اعملي|اعمللي|اعمل\s+لي)\s+)?'
            .'(?<amount>.+?)\s+(?:ال)?'.preg_quote($item['name'],'/').'(?<others>(?:\s+و\s*(?:ال)?(?:'.$alternation.'))+)\s*\z/u';
        $found=false;
        foreach ($data['evidence']['request_ids']??[] as $id) {
            if ($onlyId!==null&&$onlyId!==$id) continue;
            $row=$rows[$id]??null; if (!$row||$row['speaker']!=='customer') continue;
            foreach (self::clauses($row['text']??'') as $clause) {
                if (!preg_match($pattern,$clause,$match)||self::unsafe($clause)||self::amendment($clause)||self::alternatives($clause)
                    ||!self::bareSelection($match['amount'],$item['name'])
                    ||!self::oneAmount($match['amount'],self::declaredAmount($item))) continue;
                $mentioned=[];
                preg_match_all('/(?:\A|\s+)و\s*(?:ال)?('.$alternation.')(?![\p{L}\p{N}])/u',$match['others'],$matches);
                foreach ($matches[1] as $name) {
                    $other=$others[$name]; $ref=$sources[$other]??null;
                    if (isset($mentioned[$other])||!is_array($ref)||$ref['name_anchor_id']!==$id
                        ||!isset($positions[$ref['business_offer_id']],$positions[$ref['customer_quantity_id']])
                        ||$positions[$id]>=$positions[$ref['business_offer_id']]
                        ||!self::namedQuestionDisambiguates($data['items'][$other],$other,$data['items'],$rows,$positions,$ref)) continue 3;
                    $mentioned[$other]=true;
                }
                if ($mentioned) $found=true;
            }
        }
        return $found;
    }

    /** Exact answer to our named menu question, after an independently named sale amount.
     * These are source labels for a question/recap only; no customer fact is invented.
     */
    public static function pendingSelectedOption(array $item,array $data,array $transcript): ?array
    {
        try {
            if (!\App\Support\WhatsAppOrderExtraction::validateTranscript(array_values($transcript))['ok']
                ||\App\Support\WhatsAppOrderExtraction::hasProbe(array_values($transcript))
                ||!self::evidenceAfterTest($data,$transcript)||!self::shortText($item['name']??null,200)
                ||!self::shortText($item['option_hint']??null,200)||self::declaredAmount($item)===null
                ||($item['cart_reference']??null)!==null||!is_array($data['items']??null)
                ||count($data['items'])>self::MAX_ITEMS) return null;
            $index=array_search($item,$data['items'],true);
            if ($index===false||count(array_keys($data['items'],$item,true))!==1) return null;
            $ledger=self::ledger(self::episodeRows(array_values($transcript))); if ($ledger===null) return null;
            [$rows,$positions]=$ledger; $ordered=array_values($rows); $found=[];
            foreach ($ordered as $p=>$answer) {
                if ($p<2||$answer['speaker']!=='customer'||!self::cited($data,'request_ids',$answer['id'])
                    ||!self::shortText($answer['text']??null,1000)) continue;
                $question=$ordered[$p-1];
                if ($question['speaker']!=='business'||!self::shortText($question['text']??null,4096)
                    ||self::topics($item['name'],$data['items'])!==[$index]) continue;
                $pattern='/\A(?:لو سمحت، )?بالنسبة لـ «'.preg_quote($item['name'],'/').'»، من الاختيارات المتاحة عندنا:\n'
                    .'((?:• [^\n]+\n){1,6})اكتب اسم النوع اللي تحبه\.\z/u';
                if (!preg_match($pattern,$question['text'],$match)) continue;
                $labels=array_map(static fn($line)=>substr($line,strlen('• ')),explode("\n",rtrim($match[1],"\n")));
                if (count(array_unique($labels))!==count($labels)||!in_array($item['option_hint'],$labels,true)) continue;
                foreach ($labels as $label) if (!self::shortText($label,200)||self::unsafe($label)||self::alternatives($label)) continue 2;
                $answerPattern='/\A\s*(?:تمام\s+)?(?:(?:عايز|عاوز|أختار|اختار|أريد|اريد)\s+)?'
                    .preg_quote($item['option_hint'],'/').'(?:\s+(?:بس|فقط|يفندم|من\s+فضلك))*\s*[.!]*\s*\z/u';
                if (!preg_match($answerPattern,$answer['text'])||!self::positiveOption($item['option_hint'],$answer['text'])
                    ||self::amendment($answer['text'])||self::quantities($answer['text'])) continue;
                $quantity=null;
                for ($q=$p-2;$q>=max(0,$p-9);$q--) {
                    $row=$ordered[$q];
                    if ($row['speaker']!=='customer'||!self::cited($data,'request_ids',$row['id'])
                        ||!self::shortText($row['text']??null,16000)||self::unsafe($row['text'])||self::amendment($row['text'])) continue;
                    if (\App\Support\WhatsAppOrderExtraction::itemQuantityBacked($item,$data['items'],[$row['text']])) { $quantity=$row; break; }
                }
                if ($quantity===null) continue;
                $stable=true;
                foreach ($ordered as $later) {
                    if ($positions[$later['id']]<=$positions[$quantity['id']]||$later['id']===$answer['id']) continue;
                    $text=$later['text']??'';
                    if (self::unsafe($text)||self::amendment($text)) { $stable=false; break; }
                    if ($later['speaker']!=='customer') continue;
                    if ($text===''&&($later['location']??null)!==null) continue;
                    if (!self::shortText($text,16000)) { $stable=false; break; }
                    if (self::acceptance($text)||self::customerDataRow($data,$text)) continue;
                    // A second option/amount is not silently substituted for this answer.
                    $stable=false; break;
                }
                if (!$stable) continue;
                $found[]=['label'=>$item['option_hint'],'reference'=>[
                    'name_anchor_id'=>$quantity['id'],'business_offer_id'=>$question['id'],
                    'customer_quantity_id'=>$quantity['id'],'business_summary_id'=>$question['id'],
                    'summary_clause_index'=>0,'customer_option_id'=>$answer['id']]];
            }
            return count($found)===1?$found[0]:null;
        } catch (\Throwable $ignore) { return null; }
    }

    public static function prove(array $data, array $rows): array
    {
        $ledger = self::ledger($rows);
        if ($ledger === null || !self::evidenceAfterTest($data, $rows)
            || !isset($data['items']) || !is_array($data['items'])
            || count($data['items']) > self::MAX_ITEMS) return self::failed('CONVERSATION_REFERENCE_INVALID');
        $ledger = self::ledger(self::episodeRows($rows));
        if ($ledger === null) return self::failed('CONVERSATION_REFERENCE_INVALID');
        [$rows, $positions] = $ledger;
        $items = $data['items']; $proofs = []; $contexts = []; $postOptions=[]; $first = null; $lastSummary = null;
        foreach ($items as $index => $item) {
            if (!is_array($item) || !self::shortText($item['name'] ?? null, 200)
                || ($amount = self::declaredAmount($item)) === null) return self::failed('CONVERSATION_ITEM_UNGROUNDED');
            $ref = $item['conversation_reference'] ?? null;
            $proofs[$index] = ['amount' => $amount, 'variant' => null, 'quantity_position' => null];
            if ($ref === null) continue;
            if (!is_array($ref) || !self::exactKeys($ref, self::REFERENCE_KEYS)
                || !is_int($ref['summary_clause_index']) || $ref['summary_clause_index'] < 0
                || $ref['summary_clause_index'] >= self::MAX_ITEMS || ($item['cart_reference'] ?? null) !== null)
                return self::failed('CONVERSATION_REFERENCE_INVALID');
            foreach (['name_anchor_id' => 'customer', 'business_offer_id' => 'business',
                'customer_quantity_id' => 'customer', 'business_summary_id' => 'business',
                'customer_option_id' => 'customer'] as $key => $speaker) {
                $id = $ref[$key];
                if ($key === 'customer_option_id' && $id === null) continue;
                if (!is_string($id) || !isset($rows[$id]) || $rows[$id]['speaker'] !== $speaker
                    || !self::shortText($rows[$id]['text'] ?? null, 16000)) return self::failed('CONVERSATION_REFERENCE_INVALID');
                if ($speaker === 'customer' && !self::cited($data, 'request_ids', $id)) return self::failed('CONVERSATION_REFERENCE_INVALID');
                if ($key === 'business_summary_id' && !self::cited($data, 'confirmation_ids', $id)) return self::failed('CONVERSATION_REFERENCE_INVALID');
            }
            $anchor = $positions[$ref['name_anchor_id']]; $offer = $positions[$ref['business_offer_id']];
            $quantity = $positions[$ref['customer_quantity_id']]; $summary = $positions[$ref['business_summary_id']];
            if ($quantity < $offer) {
                $selected=self::pendingSelectedOption($item,$data,array_values($rows));
                if ($selected===null||!isset($positions[$ref['customer_option_id']??''])
                    ||$positions[$ref['customer_option_id']]>=$summary) return self::failed('CONVERSATION_ITEM_UNGROUNDED');
                foreach (['name_anchor_id','business_offer_id','customer_quantity_id','customer_option_id'] as $key)
                    if ($selected['reference'][$key]!==$ref[$key]) return self::failed('CONVERSATION_REFERENCE_INVALID');
                $sizes=self::variants($rows[$ref['customer_quantity_id']]['text']);
                if (count($sizes)>1) return self::failed('CONVERSATION_AMBIGUOUS_ITEM');
                $proofs[$index]=['amount'=>$amount,'variant'=>$sizes[0]??null,'quantity_position'=>$quantity];
                $contexts[$index]=$ref; $postOptions[$index]=true;
                $first=$first===null?$anchor:min($first,$anchor);
                $lastSummary=$lastSummary===null?$summary:max($lastSummary,$summary);
                continue;
            }
            if (!($anchor < $offer && $offer < $quantity && $quantity < $summary)
                || $quantity - $anchor > 8) return self::failed('CONVERSATION_REFERENCE_INVALID');
            $anchorText = $rows[$ref['name_anchor_id']]['text']; $offerText = $rows[$ref['business_offer_id']]['text'];
            $quantityText = $rows[$ref['customer_quantity_id']]['text'];
            if (!self::literal($item['name'], $anchorText) || self::unsafe($anchorText)
                || self::unsafe($offerText) || (!self::selection($quantityText)
                    && !(self::bareSelection($quantityText, $item['name']) && $quantity === $offer + 1
                        && self::literal($item['name'], $offerText) && self::quantityQuestion($offerText)))
                || self::alternatives($quantityText) || !self::oneAmount($quantityText, $amount))
                return self::failed('CONVERSATION_ITEM_UNGROUNDED');
            // A name may be established by a price question. Only the later positive
            // customer quantity selection commits an item; an offer cannot commit it.
            if (!self::offerFor($item, $index, $items, $rows, $positions, $ref)
                || (!self::topicIs($anchorText, $index, $items)
                    &&!self::namedQuestionDisambiguates($item,$index,$items,$rows,$positions,$ref))) return self::failed('CONVERSATION_AMBIGUOUS_ITEM');
            foreach ($rows as $id => $row) {
                $p = $positions[$id];
                // A later explicitly named offer re-establishes the product topic.
                $topicStart = self::literal($item['name'], $offerText) ? $offer : $anchor;
                if ($p <= $topicStart || $p > $quantity) continue;
                $topics = self::topics($row['text'] ?? '', $items);
                if ($topics && $topics !== [$index]) return self::failed('CONVERSATION_AMBIGUOUS_ITEM');
                if ($row['speaker'] === 'customer' && $p < $quantity && !$topics
                    && preg_match('/\A\s*(?:في|عندكم|متاح)\s+\S+/u', $row['text'] ?? ''))
                    return self::failed('CONVERSATION_AMBIGUOUS_ITEM');
            }
            $variants = self::variants($quantityText);
            if (count($variants) > 1) return self::failed('CONVERSATION_AMBIGUOUS_ITEM');
            $variant = $variants[0] ?? null;
            $offeredVariants = self::variants($offerText);
            if ($variant === null && count($offeredVariants) > 1) return self::failed('CONVERSATION_AMBIGUOUS_ITEM');
            if ($variant === null && count($offeredVariants) === 1) $variant = $offeredVariants[0];
            if ($variant !== null && !in_array($variant, $offeredVariants, true))
                return self::failed('CONVERSATION_ITEM_UNGROUNDED');
            $proofs[$index] = ['amount' => $amount, 'variant' => $variant, 'quantity_position' => $quantity];
            $contexts[$index] = $ref;
            $first = $first === null ? $anchor : min($first, $anchor);
            $lastSummary = $lastSummary === null ? $summary : max($lastSummary, $summary);
        }
        if (!$contexts) return self::ready();
        $episode = self::acknowledgment($data, $rows, $positions, $lastSummary);
        if ($episode===null) $episode=self::completeDialogueRecap($data,$rows,$positions,$contexts,$lastSummary);
        if ($episode === null) return self::failed('CONVERSATION_DATA_UNACKNOWLEDGED');
        $acceptance = self::acceptedBasket($data, $rows, $positions, $lastSummary, $episode['data_request']);
        if ($acceptance === null) return self::failed('CONVERSATION_EPISODE_UNCONFIRMED');
        $claimedClauses = [];
        foreach ($contexts as $index => $ref) {
            $item = $items[$index]; $summaryText = $rows[$ref['business_summary_id']]['text'];
            $clauses = self::clauses($summaryText); $clause = $clauses[$ref['summary_clause_index']] ?? null;
            $key = $ref['business_summary_id'] . ':' . $ref['summary_clause_index'];
            if ($clause === null || isset($claimedClauses[$key]) || self::unsafe($clause)
                || self::alternatives($clause) || !self::oneAmount($clause, $proofs[$index]['amount']))
                return self::failed('CONVERSATION_ITEM_UNGROUNDED');
            $claimedClauses[$key] = true;
            $variants = self::variants($clause);
            if (count($variants) > 1 || ($variants[0] ?? null) !== $proofs[$index]['variant'])
                return self::failed('CONVERSATION_ITEM_UNGROUNDED');
            $topicClause=isset($postOptions[$index])&&is_string($item['option_hint']??null)
                ?str_replace($item['option_hint'],' ',$clause):$clause;
            $topics = self::topics($topicClause, $items);
            if ($topics) {
                if ($topics !== [$index]) return self::failed('CONVERSATION_AMBIGUOUS_ITEM');
            } else {
                $candidates = [];
                foreach ($proofs as $candidate => $proof) {
                    if ($proof['amount'] === $proofs[$index]['amount'] && $proof['variant'] === $proofs[$index]['variant'])
                        $candidates[] = $candidate;
                }
                if ($candidates !== [$index]) return self::failed('CONVERSATION_AMBIGUOUS_ITEM');
            }
            $hint = $item['option_hint'] ?? null;
            if ($hint !== null) {
                if (!self::shortText($hint, 200) || !self::positiveOption($hint, $clause))
                    return self::failed('CONVERSATION_ITEM_UNGROUNDED');
                $optionId = $ref['customer_option_id'] ?? $ref['customer_quantity_id'];
                $optionText = $rows[$optionId]['text']; $optionPosition = $positions[$optionId];
                if ($optionPosition < $proofs[$index]['quantity_position'] || $optionPosition >= $positions[$ref['business_summary_id']]
                    || !self::positiveOption($hint, $optionText) || (!isset($postOptions[$index])&&!self::oneAmount($optionText, $proofs[$index]['amount'])))
                    return self::failed('CONVERSATION_ITEM_UNGROUNDED');
                $possible = [];
                foreach ($proofs as $candidate => $proof) {
                    if ($proof['quantity_position'] !== null && $proof['quantity_position'] <= $optionPosition
                        && $proof['amount'] === $proofs[$index]['amount']) $possible[] = $candidate;
                }
                $optionTopics = self::topics($optionText, $items);
                if (!isset($postOptions[$index])&&(($optionTopics && $optionTopics !== [$index]) || (!$optionTopics && $possible !== [$index])))
                    return self::failed('CONVERSATION_AMBIGUOUS_ITEM');
                // An intervening product inquiry can make a generic option answer
                // ambiguous. The uniquely mapped recap plus its later explicit
                // customer acceptance independently proves the final chosen option.
                if ($acceptance <= $positions[$ref['business_summary_id']]) return self::failed('CONVERSATION_EPISODE_UNCONFIRMED');
            }
        }
        if (!self::summaryCovered($rows, $items, $proofs, $contexts, $claimedClauses))
            return self::failed('CONVERSATION_AMBIGUOUS_ITEM');
        if (!self::episodeStable($data, $rows, $positions, $items, $proofs, $contexts, $first, $lastSummary, $episode['final']))
            return self::failed('CONVERSATION_EPISODE_UNCONFIRMED');
        return self::ready();
    }

    /** A final generic acknowledgment can acknowledge data supplied after a recap. */
    public static function addressAcknowledged(array $data, array $rows): bool
    {
        $ledger = self::ledger($rows);
        if ($ledger === null || !self::evidenceAfterTest($data, $rows)) return false;
        $ledger = self::ledger(self::episodeRows($rows));
        if ($ledger === null) return false;
        [$rows, $positions] = $ledger;
        $summary = null; $contextual = false;
        foreach ($data['items'] ?? [] as $item) {
            $ref = $item['conversation_reference'] ?? null;
            if ($ref === null) continue;
            $contextual = true;
            if (!is_array($ref) || !isset($positions[$ref['business_summary_id'] ?? ''])) return false;
            $summary = $summary === null ? $positions[$ref['business_summary_id']] : max($summary, $positions[$ref['business_summary_id']]);
        }
        if ($contextual && !self::prove($data, $rows)['ok']) return false;
        if (!$contextual) $summary = self::literalRecap($data, $rows, $positions);
        if ($summary === null) return false;
        $ack = self::acknowledgment($data, $rows, $positions, $summary);
        return $ack !== null && self::acceptedBasket($data, $rows, $positions, $summary, $ack['data_request']) !== null;
    }

    /** Only an explicit test ORDER blocks a sale; a connection check does not. */
    public static function operationalTest(array $transcript): bool
    {
        $ledger = self::ledger($transcript);
        if ($ledger === null) return false;
        [$rows, $positions] = $ledger; $test = self::lastTest($rows, $positions);
        return $test !== null && self::newOrderAfter($rows, $positions, $test) === null;
    }

    /** A later genuine order must not borrow evidence from either kind of test episode. */
    public static function evidenceAfterTest(array $data, array $rows): bool
    {
        $ledger = self::ledger($rows);
        if ($ledger === null) return false;
        [$rows, $positions] = $ledger; $test = self::lastEpisodeTest($rows, $positions);
        if ($test === null) return true;
        $ids = [];
        foreach (['request_ids', 'confirmation_ids', 'customer_acceptance_ids'] as $field) {
            $values = $data['evidence'][$field] ?? [];
            if (!is_array($values)) return false;
            foreach ($values as $id) $ids[] = $id;
        }
        if (($data['evidence']['location_id'] ?? null) !== null) $ids[] = $data['evidence']['location_id'];
        foreach ($data['items'] ?? [] as $item) {
            if (!is_array($item)) return false;
            if (($item['cart_reference']['message_id'] ?? null) !== null) $ids[] = $item['cart_reference']['message_id'];
            if (is_array($item['conversation_reference'] ?? null)) foreach (self::REFERENCE_KEYS as $key) {
                if ($key !== 'summary_clause_index' && ($item['conversation_reference'][$key] ?? null) !== null)
                    $ids[] = $item['conversation_reference'][$key];
            }
        }
        if (!$ids) return true;
        $boundary = self::newOrderAfter($rows, $positions, $test);
        if ($boundary === null) return false;
        foreach ($ids as $id) if (!is_string($id) || !isset($positions[$id]) || $positions[$id] < $boundary) return false;
        return true;
    }

    /** Original label of a genuine new order after the latest coded or operational test. */
    public static function episodeBoundary(array $rows): ?string
    {
        if (!\App\Support\WhatsAppOrderExtraction::validateTranscript($rows)['ok']) return null;
        $ledger = self::ledger($rows);
        if ($ledger === null) return null;
        [$indexed, $positions] = $ledger; $test = self::lastEpisodeTest($indexed, $positions);
        $boundary = $test === null ? null : self::newOrderAfter($indexed, $positions, $test);
        return $boundary === null ? null : array_keys($indexed)[$boundary];
    }

    /** A genuine new order after the latest test marker starts an isolated native episode. */
    public static function episodeRows(array $rows): array
    {
        $ledger = self::ledger($rows);
        if ($ledger === null) return $rows;
        [$indexed, $positions] = $ledger; $test = self::lastEpisodeTest($indexed, $positions);
        $boundary = $test === null ? null : self::newOrderAfter($indexed, $positions, $test);
        if ($boundary === null) return $rows;
        return array_values(array_filter($indexed, static fn ($row) => $positions[$row['id']] >= $boundary));
    }

    public static function episodeTranscript(array $transcript): array
    {
        return array_values(self::episodeRows($transcript));
    }

    /** Existing live labels may be matched only in the independently proved item clause. */
    public static function catalogOptionLabels(array $item, array $data, array $rows, array $liveLabels): ?array
    {
        if (count($liveLabels) > 100 || !self::shortText($item['name'] ?? null, 200)
            || !is_array($item['conversation_reference'] ?? null)) return null;
        foreach ($liveLabels as $label) if (!self::shortText($label, 200)) return null;
        $member = false;
        foreach ($data['items'] ?? [] as $candidate) if ($candidate === $item) $member = true;
        if (!$member || !self::prove($data, $rows)['ok']) return null;
        $ledger = self::ledger($rows);
        if ($ledger === null) return null;
        [$indexed] = $ledger; $ref = $item['conversation_reference'];
        $text = self::clauses($indexed[$ref['business_summary_id']]['text'])[$ref['summary_clause_index']] ?? null;
        if ($text === null || self::alternatives($text)) return null;
        preg_match_all('/(?<![\p{L}\p{N}])(?:ال)?(' . preg_quote($item['name'], '/') . ')(?![\p{L}\p{N}])/iu',
            $text, $names, PREG_OFFSET_CAPTURE);
        $hits = [];
        foreach (array_unique($liveLabels) as $label) {
            preg_match_all('/(?<![\p{L}\p{N}])(?:ب)?(' . preg_quote($label, '/') . ')(?![\p{L}\p{N}])/iu',
                $text, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as [$matched, $offset]) {
                $overlap = false;
                foreach ($names[1] as [$name, $nameOffset]) if ($offset < $nameOffset + strlen($name)
                    && $offset + strlen($matched) > $nameOffset) $overlap = true;
                if ($overlap) continue;
                $before = substr($text, 0, $offset); $after = substr($text, $offset + strlen($matched));
                if ($label === 'تنظيف' && preg_match('/\A\s+(?:إضافي|اضافي)(?![\p{L}\p{N}])/u', $after)) continue;
                $negative = preg_match('/(?:بدون|دون|من\s+غير|غير|لا|مش|ليس|not|no|without)\s*(?:(?:أي|اي)\s+|ب)?\z/iu', $before) === 1;
                $hits[] = ['label'=>$label, 'offset'=>$offset, 'length'=>strlen($matched), 'negative'=>$negative];
            }
        }
        $positive = []; $negative = [];
        foreach ($hits as $hit) {
            $contained = false;
            foreach ($hits as $other) if ($other['length'] > $hit['length'] && $hit['offset'] >= $other['offset']
                && $hit['offset'] + $hit['length'] <= $other['offset'] + $other['length']) $contained = true;
            if ($contained) continue;
            if ($hit['negative']) $negative[$hit['label']] = true;
            else $positive[$hit['label']] = true;
        }
        if (array_intersect_key($positive, $negative) || count($positive) > 1) return null;
        $variant = self::variants($indexed[$ref['customer_quantity_id']]['text']);
        if (!$variant) $variant = self::variants($indexed[$ref['business_offer_id']]['text']);
        if ($variant) {
            // A selected fish-per-kilogram price choice cannot be dropped while
            // mapping only the preparation choice into the current one-option ERP.
            if (count($variant) !== 1 || count($positive) !== 1) return null;
            $label = array_key_first($positive);
            preg_match_all('/(?<![\p{L}\p{N}])([0-9٠-٩۰-۹]{1,2})\s*(?:حبات|حبة|حبه|سمكات|سمكة|سمكه)(?![\p{L}\p{N}])/u',
                self::normalize($label), $sizes);
            $labelSizes = array_values(array_unique($sizes[1] ?? []));
            if (count($labelSizes) !== 1 || (string) (int) $labelSizes[0] !== $variant[0]) return null;
        }
        return array_keys($positive);
    }

    /** Map only a proved contextual choice; pending mode is restricted to question planning. */
    public static function contextualOptionLabels(array $item,array $data,array $rows,array $liveLabels,
        bool $pending=false,bool $preservePreparation=true,bool $requireSize=true): ?array
    {
        if (count($liveLabels)>100||!self::shortText($item['name']??null,200)) return null;
        foreach ($liveLabels as $label) if (!self::shortText($label,200)) return null;
        $index=array_search($item,$data['items']??[],true); if ($index===false) return null;
        $ref=$item['conversation_reference']??null;
        if ($pending) {
            $sources=self::draftItemQuantitySources($data,array_values($rows));
            if ($sources===null) return null;
            $ref=$sources[$index]??null;
        } elseif (!self::prove($data,$rows)['ok']) return null;
        if (!is_array($ref)) return null;
        $ledger=self::ledger($rows); if ($ledger===null) return null;
        [$indexed,$positions]=$ledger;
        $quantity=$indexed[$ref['customer_quantity_id']]['text']??null;
        $offer=$indexed[$ref['business_offer_id']]['text']??null;
        if (!is_string($quantity)||!is_string($offer)) return null;
        $postOption=($positions[$ref['customer_quantity_id']]??PHP_INT_MAX)<($positions[$ref['business_offer_id']]??-1);
        // A later explicit answer to the reviewed option question resolves the
        // earlier offered alternatives; retain the quantity separately as size provenance.
        $texts=$postOption?[]:[$quantity];
        $option=$ref['customer_option_id']??null;
        if ($option!==null) $texts[]=$indexed[$option]['text'];
        if (!$pending) {
            $summary=self::clauses($indexed[$ref['business_summary_id']]['text'])[$ref['summary_clause_index']]??null;
            if (!is_string($summary)) return null;
            $texts[]=$summary;
        }
        $positive=[]; $negative=[];
        foreach ($texts as $text) {
            $hits=[];
            preg_match_all('/(?<![\p{L}\p{N}])(?:ال)?('.preg_quote($item['name'],'/').')(?![\p{L}\p{N}])/iu',$text,$names,PREG_OFFSET_CAPTURE);
            foreach (array_unique($liveLabels) as $label) {
                preg_match_all('/(?<![\p{L}\p{N}])(?:ب)?('.preg_quote($label,'/').')(?![\p{L}\p{N}])/iu',$text,$matches,PREG_OFFSET_CAPTURE);
                foreach ($matches[1] as [$matched,$offset]) {
                    $overlap=false;
                    foreach ($names[1] as [$name,$nameOffset]) if ($offset<$nameOffset+strlen($name)&&$offset+strlen($matched)>$nameOffset) $overlap=true;
                    if ($overlap) continue;
                    $after=substr($text,$offset+strlen($matched));
                    if ($label==='تنظيف'&&preg_match('/\A\s+(?:إضافي|اضافي)(?![\p{L}\p{N}])/u',$after)) continue;
                    $declined=preg_match('/(?:بدون|دون|من\s+غير|غير|لا|مش|ليس|not|no|without)\s*(?:(?:أي|اي)\s+|ب)?\z/iu',substr($text,0,$offset))===1;
                    $hits[]=['label'=>$label,'offset'=>$offset,'length'=>strlen($matched),'negative'=>$declined];
                }
            }
            foreach ($hits as $hit) {
                $contained=false;
                foreach ($hits as $other) if ($other['length']>$hit['length']&&$hit['offset']>=$other['offset']
                    &&$hit['offset']+$hit['length']<=$other['offset']+$other['length']) $contained=true;
                if ($contained) continue;
                if ($hit['negative']) $negative[$hit['label']]=true; else $positive[$hit['label']]=true;
            }
        }
        if (array_intersect_key($positive,$negative)) return null;
        $source=self::variants($quantity)?$quantity:$offer;
        if ($requireSize&&self::variants($source)) {
            $size=self::fishSizeOptions($source,$liveLabels,$texts,$preservePreparation);
            if ($size===null||count($size)!==1) return null;
            foreach (array_keys($positive) as $label) if (!self::literal($label,$size[0])
                &&self::fishSizeOptions($source,[$label],$texts,$preservePreparation)!==[$label]) return null;
            return $size;
        }
        return count($positive)<=1?array_keys($positive):null;
    }

    /** Split item recaps after composing fractions; preserve original variants as text. */
    public static function clauses(string $text): array
    {
        if (strlen($text) > 65536 || preg_match('//u', $text) !== 1) return [];
        $text = self::normalize($text);
        if (self::unsupportedAmount($text)) return [];
        $parts = preg_split('/[\n;؛،,!؟?]+|(?<![0-9])\.(?![0-9])|\s+(?:and|&)\s+|'
            . '\s+و(?=\s*(?:[0-9]|كيلو|نص|نصف|ربع|جرام|قطعة|علبة))/iu', $text);
        $parts = array_values(array_filter(array_map(static fn ($part) => trim($part), $parts), static fn ($part) => $part !== ''));
        return count($parts) <= self::MAX_ITEMS ? $parts : [];
    }

    /** Exact sale amounts in thousandths. Fish per kilogram is a size, not an amount. */
    public static function quantities(string $text): array
    {
        $amounts = self::rawQuantities($text);
        $fish = self::fishWeightSize($text);
        return $fish === null ? $amounts : [$fish['amount']];
    }

    /**
     * One kilogram containing a named fish count establishes its size. Other
     * weights require an explicit per-kilogram relationship. No native text or
     * product facts are rewritten, and an additional ordered piece stays an amount.
     */
    public static function fishWeightSize(string $text): ?array
    {
        if (strlen($text) > 65536 || preg_match('//u', $text) !== 1) return null;
        $text = self::normalize($text);
        if (self::signedAmount($text) || self::unsupportedAmount($text) || self::unsafe($text)
            || self::alternatives($text) || self::amendment($text) || preg_match('/[?؟"«»“”]/u', $text)) return null;
        $fish = '(?:فسيخ|رنجة|رنجه|سردين|ماكريل|بوري|بورى|بلطي|بلطى)';
        preg_match_all('/(?<![\p{L}\p{N}])(?:و)?(?:ال)?' . $fish . '(?![\p{L}\p{N}])/u', $text, $topics);
        if (count($topics[0]) !== 1) return null;
        $amounts = self::rawQuantities($text);
        $explicit = self::explicitVariants($text);
        if (count($explicit) === 1 && count($amounts) === 1 && $amounts[0]['mode'] === 'weight') {
            if (preg_match('/(?:بدون|دون|غير|مش|لا|ليس|not|no|without)\s+(?:(?:من|في|لكل)\s+)?(?:ال)?كيلو\s*[0-9]/iu', $text)) return null;
            return ['amount' => $amounts[0], 'fish_per_kg' => $explicit[0]];
        }
        if ($explicit || count($amounts) !== 2) return null;
        $qualifiers = '(?:نبروه|دسوق|بلدي|بلدى|هيرنج|بطارخ|ناعم|ناعمة|ناعمه|سمين|سمينة|سمينه|كبير|صغير|كامل|مخلي)';
        $count = '(?:([0-9]{1,2})\s*(?:سمكات|سمكة|سمكه|حبات|حبة|حبه)|(سمكتين|سمكتان))';
        $pattern = '/(?<![\p{L}\p{N}.])(?:(?:1(?:\.0{1,3})?)\s*)?كيلو(?:جرام|غرام)?\s+'
            . '(?:ال)?' . $fish . '(?:\s+' . $qualifiers . '){0,3}\s*[:：]?\s+'
            . $count . '(?![\p{L}\p{N}])/u';
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if (count($matches) !== 1) return null;
        $match = $matches[0];
        $before = substr($text, 0, $match[0][1]);
        if (preg_match('/(?:بدون|دون|غير|مش|لا|ليس|not|no|without)\s*\z/iu', $before)) return null;
        $size = ($match[1][0] ?? '') !== '' ? (int) $match[1][0] : 2;
        if ($size < 1 || $size > 99) return null;
        $wanted = [['quantity'=>1000, 'mode'=>'weight'], ['quantity'=>$size * 1000, 'mode'=>'piece']];
        foreach ($amounts as $amount) {
            $index = array_search($amount, $wanted, true);
            if ($index === false) return null;
            unset($wanted[$index]);
        }
        return !$wanted ? ['amount'=>['quantity'=>1000, 'mode'=>'weight'], 'fish_per_kg'=>(string) $size] : null;
    }

    /** An explicit size must survive as one real catalog choice, including its preparation. */
    public static function fishSizeOptions(string $clause, array $liveLabels, array $qualifierTexts = [], bool $preservePreparation = true): ?array
    {
        if (strlen($clause) > 65536 || preg_match('//u', $clause) !== 1 || count($liveLabels) > 100 || count($qualifierTexts)>60) return null;
        foreach ($liveLabels as $label) if (!self::shortText($label, 200)) return null;
        foreach ($qualifierTexts as $text) if (!is_string($text)||strlen($text)>65536||preg_match('//u',$text)!==1) return null;
        $texts=[$clause,...$qualifierTexts];
        $sizes = self::variants($clause);
        if (!$sizes) return [];
        if (count($sizes) !== 1 || self::unsafe($clause) || self::alternatives($clause)) return null;
        $selected = [];
        foreach (array_unique($liveLabels) as $label) {
            if (!self::shortText($label, 200)) return null;
            $normalized = self::normalize($label);
            $normalized = preg_replace('/(?<![\p{L}\p{N}])(?:(?:من|في|لكل)\s+)?(?:ال)?كيلو(?=\s*(?:[0-9]|سمكت))/u', ' ', $normalized);
            $pattern = '/(?<![\p{L}\p{N}])(?:([0-9]{1,2})\s*(?:سمكات|سمكة|سمكه|حبات|حبة|حبه)|(سمكتين|سمكتان))(?![\p{L}\p{N}])/u';
            preg_match_all($pattern, $normalized, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            if (count($matches) !== 1) continue;
            $match = $matches[0];
            if (preg_match('/(?:بدون|دون|غير|مش|لا|ليس|not|no|without)\s*\z/iu', substr($normalized,0,$match[0][1]))) continue;
            $count = ($match[1][0] ?? '') !== '' ? (string) (int) $match[1][0] : '2';
            if ($count !== $sizes[0]) continue;
            $rest = substr_replace($normalized, ' ', $match[0][1], strlen($match[0][0]));
            $rest = preg_replace('/(?<![\p{L}\p{N}])(?:(?:من|في|لكل)\s+)?(?:ال)?كيلو(?![\p{L}\p{N}])/u', ' ', $rest);
            $rest = trim(preg_replace('/[\s:：\/()\-]+/u', ' ', $rest));
            $restSeen=$rest==='';
            foreach ($texts as $text) if (self::positiveOption($rest,self::normalize($text))||self::positiveOption($label,$text)) $restSeen=true;
            if (!$restSeen) continue;
            $missingPreparation = false;
            if ($preservePreparation) foreach (['كامل', 'مخلي'] as $preparation) foreach ($texts as $text)
                if (self::positiveOption($preparation, $text) && !self::literal($preparation, $normalized)) $missingPreparation = true;
            if (!$missingPreparation) $selected[$label] = true;
        }
        return count($selected) === 1 ? array_keys($selected) : null;
    }

    public static function fishSizes(string $clause): array
    {
        return strlen($clause) <= 65536 && preg_match('//u', $clause) === 1 ? self::variants($clause) : [];
    }

    private static function rawQuantities(string $text): array
    {
        if (strlen($text) > 65536 || preg_match('//u', $text) !== 1 || self::signedAmount($text)) return [];
        $text = self::normalize($text);
        if (self::unsupportedAmount($text)) return [];
        $text = preg_replace(self::variantPattern(), ' ', $text);
        $units = '(?:كيلو(?:جرام|غرام)?|كجم|كغ|kilograms?|kilos?|kg|جرام|غرام|جم|grams?|g|'
            . 'قطعة|قطع|حبة|حبه|حبات|سمكة|سمكه|سمكات|علبة|علب|عدد|وجبة|وجبات|ساندوتش|سندوتش|'
            . 'ساندوتشات|سندوتشات|pieces?|pcs|units?|meals?|sandwich(?:es)?)';
        $numbers = '(?:[0-9]{1,6}(?:\.[0-9]{1,3})?|نصف|نص|ربع|half|quarter)';
        preg_match_all('/(?<![\p{L}\p{N}.])(?:و)?(' . $numbers . ')\s*(' . $units . ')(?![\p{L}\p{N}])/iu',
            $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $result = []; $covered = [];
        foreach ($matches as $match) {
            $covered[] = [$match[0][1], $match[0][1] + strlen($match[0][0])];
            $amount = self::unitAmount($match[1][0], $match[2][0]);
            if ($amount !== null) $result[] = $amount;
        }
        preg_match_all('/(?<![\p{L}\p{N}])(?:و)?(' . $units . ')(?![\p{L}\p{N}])/iu', $text,
            $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            $inside = false;
            foreach ($covered as $range) if ($match[0][1] >= $range[0] && $match[0][1] < $range[1]) $inside = true;
            if (!$inside && preg_match('/\A(?:كيلو(?:جرام|غرام)?|قطعة|حبة|حبه|سمكة|سمكه|علبة|وجبة|ساندوتش|سندوتش|kilogram|kilo|piece|unit|meal|sandwich)\z/iu', $match[1][0])) {
                $amount = self::unitAmount('1', $match[1][0]);
                if ($amount !== null) $result[] = $amount;
            }
        }
        preg_match_all('/(?<![\p{L}\p{N}])(?:و)?(?:سمكتين|سمكتان)(?![\p{L}\p{N}])/u', $text, $dual);
        foreach ($dual[0] as $ignored) $result[] = ['quantity'=>2000, 'mode'=>'piece'];
        return count($result) <= self::MAX_ITEMS ? $result : [];
    }

    /** The application's complete factual recap can replace the historical details-after-basket sequence.
     * Receipt ownership/proposal authentication is a separate mandatory coordinator gate.
     */
    private static function completeDialogueRecap(array $data,array $rows,array $positions,array $contexts,?int $summary): ?array
    {
        if ($summary===null||!$contexts) return null;
        $summaries=[];
        foreach ($contexts as $index=>$ref) {
            $item=$data['items'][$index]; $offer=$rows[$ref['business_offer_id']]['text']??null;
            $prefix='بالنسبة لـ «'.$item['name'].'»، ';
            if (!is_string($offer)||(!str_starts_with($offer,$prefix)&&!str_starts_with($offer,'لو سمحت، '.$prefix))) return null;
            if (!self::quantityQuestion($offer)&&!str_contains($offer,"من الاختيارات المتاحة عندنا:\n")) return null;
            $summaries[$ref['business_summary_id']]=true;
        }
        if (count($summaries)!==1) return null;
        $id=array_key_first($summaries); $row=$rows[$id]??null;
        if (!$row||$row['speaker']!=='business'||$positions[$id]!==$summary
            ||!self::shortText($row['text']??null,4096)||self::unsafe($row['text'])||self::alternatives($row['text'])) return null;
        $lines=explode("\n",$row['text']);
        if (($lines[0]??null)!=='تأكيد الطلب:'||!isset($lines[1])||trim($lines[1])==='') return null;
        $expected=[];
        foreach (['name'=>'الاسم: ','phone'=>'رقم التواصل: ','address'=>'العنوان: '] as $field=>$label) {
            $value=$data['customer'][$field]??null;
            if (!self::shortText($value,$field==='address'?1000:200)) return null;
            $expected[]=$label.$value; $backed=false;
            foreach ($data['evidence']['request_ids']??[] as $request) {
                $source=$rows[$request]??null;
                if (!$source||$source['speaker']!=='customer'||$positions[$request]>=$summary) continue;
                $text=$source['text']??'';
                if ($field==='phone'?self::phoneLiteral($value,$text):self::literal($value,$text)) $backed=true;
            }
            if (!$backed) return null;
        }
        if (($data['customer']['area']??null)!==null) $expected[]='المنطقة: '.$data['customer']['area'];
        if (($data['branch_hint']??null)!==null) $expected[]='الفرع: '.$data['branch_hint'];
        if (($data['customer']['notes']??null)!==null) $expected[]='ملاحظات: '.$data['customer']['notes'];
        if (array_slice($lines,2,count($expected))!==$expected||count($lines)!==count($expected)+4) return null;
        $mode=$lines[count($lines)-2];
        if (!in_array($mode,['طريقة الاستلام: دليفري.','طريقة الاستلام: من الفرع.'],true)
            ||(($data['fulfillment']??'UNKNOWN')==='DELIVERY'&&$mode!=='طريقة الاستلام: دليفري.')
            ||(($data['fulfillment']??'UNKNOWN')==='PICKUP'&&$mode!=='طريقة الاستلام: من الفرع.')) return null;
        if (!in_array($lines[count($lines)-1],['اكتب موافق للموافقة على البيانات والطلب المذكور.',
            'من فضلك اكتب موافق للموافقة على البيانات والطلب المذكور.'],true)) return null;
        $acceptance=null;
        foreach ($rows as $label=>$after) {
            if ($positions[$label]<=$summary) continue;
            if ($after['speaker']!=='customer'||!self::acceptance($after['text']??'')) return null;
            if (self::cited($data,'customer_acceptance_ids',$label)) $acceptance=$positions[$label];
        }
        if ($acceptance===null||self::laterChanges($data,$rows,$positions,$summary)) return null;
        return ['data_request'=>$acceptance+1,'ack'=>$summary,'final'=>$summary];
    }

    private static function acknowledgment(array $data, array $rows, array $positions, ?int $afterSummary): ?array
    {
        foreach (['name', 'phone', 'address'] as $field) if (!self::shortText($data['customer'][$field] ?? null, $field === 'address' ? 1000 : 200)) return null;
        $requests = $data['evidence']['request_ids'] ?? []; $confirmations = $data['evidence']['confirmation_ids'] ?? [];
        if (!is_array($requests) || !is_array($confirmations)) return null;
        $acks = [];
        foreach ($confirmations as $id) if (isset($rows[$id]) && $rows[$id]['speaker'] === 'business'
            && self::finalConfirm($rows[$id]['text'] ?? '') && !self::unsafe($rows[$id]['text'] ?? '')) $acks[] = $positions[$id];
        rsort($acks);
        foreach ($acks as $ack) {
            if ($afterSummary !== null && $ack <= $afterSummary) continue;
            $addressAck = false; $final = $ack;
            foreach ($confirmations as $id) {
                if (!isset($rows[$id]) || $rows[$id]['speaker'] !== 'business') continue;
                $p = $positions[$id]; $text = $rows[$id]['text'] ?? '';
                if ($p >= $ack && $p <= $ack + 2 && self::dataAcknowledgment($text) && !self::unsafe($text)) {
                    $addressAck = true; $final = max($final, $p);
                }
            }
            if (!$addressAck) continue;
            $dataRequest = null;
            foreach ($rows as $id => $row) {
                $p = $positions[$id];
                if ($row['speaker'] === 'business' && $p < $ack && ($afterSummary === null || $p > $afterSummary)
                    && self::dataRequest($row['text'] ?? '')) $dataRequest = $p;
            }
            if ($dataRequest === null) continue;
            $fields = [];
            foreach (['name', 'phone', 'address'] as $field) {
                foreach ($requests as $id) {
                    if (!isset($rows[$id]) || $rows[$id]['speaker'] !== 'customer') continue;
                    $p = $positions[$id]; $text = $rows[$id]['text'] ?? '';
                    if ($p > $dataRequest && $p < $ack && !self::unsafe($text)
                        && ($field === 'phone' ? self::phoneLiteral($data['customer'][$field], $text)
                            : self::literal($data['customer'][$field], $text))) $fields[$field] = $p;
                }
            }
            if (count($fields) !== 3) continue;
            $stable = true;
            foreach ($rows as $id => $row) {
                $p = $positions[$id];
                if ($p <= $dataRequest || $p > $final) continue;
                $text = $row['text'] ?? '';
                if (self::unsafe($text) || self::amendment($text) || ($row['speaker'] === 'customer' && self::newOrder($text))) $stable = false;
                if ($row['speaker'] === 'customer' && $p < $ack && !self::customerDataRow($data, $text)) $stable = false;
                if ($p >= $ack && $row['speaker'] !== 'business') $stable = false;
            }
            if ($stable && !self::laterChanges($data, $rows, $positions, $final))
                return ['data_request' => $dataRequest, 'ack' => $ack, 'final' => $final];
        }
        return null;
    }

    private static function acceptedBasket(array $data, array $rows, array $positions, int $summary, int $dataRequest): ?int
    {
        $acceptance = null;
        foreach ($data['evidence']['customer_acceptance_ids'] ?? [] as $id) {
            if (!isset($rows[$id]) || $rows[$id]['speaker'] !== 'customer') continue;
            $p = $positions[$id]; $text = $rows[$id]['text'] ?? '';
            if ($p > $summary && $p < $dataRequest && self::acceptance($text)) $acceptance = $p;
        }
        if ($acceptance === null) return null;
        foreach ($rows as $id => $row) {
            $p = $positions[$id];
            if ($p > $summary && $p < $dataRequest) {
                $text = $row['text'] ?? '';
                if (self::unsafe($text) || self::amendment($text) || self::quantities($text)) return null;
                if ($row['speaker'] === 'business' && !self::dataRequest($text)) return null;
            }
        }
        return $acceptance;
    }

    private static function episodeStable(array $data, array $rows, array $positions, array $items,
        array $proofs, array $contexts, int $first, int $lastSummary, int $final): bool
    {
        $primary = []; $options = []; $sharedAnchors=[];
        foreach ($contexts as $index=>$ref) {
            $primary[$ref['customer_quantity_id']] = true;
            if ($ref['customer_option_id'] !== null) $options[$ref['customer_option_id']] = true;
            if (self::namedQuestionDisambiguates($items[$index],$index,$items,$rows,$positions,$ref))
                $sharedAnchors[$ref['name_anchor_id']]=true;
        }
        foreach ($rows as $id => $row) {
            $p = $positions[$id]; if ($p < $first || $p > $final) continue;
            $text = $row['text'] ?? '';
            if (self::unsafe($text) || self::amendment($text)) return false;
            if ($row['speaker'] === 'customer' && $p > $first && self::newOrder($text)) return false;
            if ($row['speaker'] === 'business' && $p < $lastSummary && self::finalConfirm($text)) return false;
            if ($row['speaker']==='customer'&&isset($sharedAnchors[$id])) {
                $actual=[]; $covered=[];
                foreach (self::clauses($text) as $clause) $actual=array_merge($actual,self::quantities($clause));
                foreach ($items as $index=>$item) if (($proofs[$index]['quantity_position']??null)===null
                    &&self::sharedNamedQuantityFromRows($item,$index,$data,$rows,$positions,$contexts,$id))
                    $covered[]=self::declaredAmount($item);
                $encode=static fn($amount)=>json_encode($amount,JSON_THROW_ON_ERROR);
                $actual=array_map($encode,$actual); $covered=array_map($encode,$covered); sort($actual); sort($covered);
                if ($covered&&$actual===$covered) continue;
            }
            if ($row['speaker'] !== 'customer' || $p >= $lastSummary || isset($primary[$id]) || isset($options[$id])) continue;
            $amounts = self::quantities($text); if (!$amounts) continue;
            $topics = self::topics($text, $items); $possible = [];
            foreach ($proofs as $index => $proof) if ($proof['quantity_position'] !== null && $proof['quantity_position'] <= $p
                && count($amounts) === 1 && $amounts[0] === $proof['amount'] && (!$topics || $topics === [$index])) $possible[] = $index;
            if (count($possible) !== 1) return false;
        }
        return true;
    }

    private static function summaryCovered(array $rows, array $items, array $proofs, array $contexts, array $claimed): bool
    {
        $recaps = [];
        foreach ($contexts as $ref) $recaps[$ref['business_summary_id']] = true;
        $seen = [];
        foreach ($contexts as $index => $ref) $seen[$index] = true;
        foreach (array_keys($recaps) as $id) foreach (self::clauses($rows[$id]['text']) as $index => $clause) {
            if (isset($claimed[$id . ':' . $index])) continue;
            $amounts = self::quantities($clause); $topics = self::topics($clause, $items);
            if (!$amounts && !$topics) continue;
            if (count($amounts) !== 1 || count($topics) !== 1 || !is_int($topics[0])) return false;
            $itemIndex = $topics[0];
            if (isset($seen[$itemIndex]) || !isset($proofs[$itemIndex]) || $amounts[0] !== $proofs[$itemIndex]['amount']) return false;
            $variants = self::variants($clause);
            if (count($variants) > 1 || ($variants[0] ?? null) !== $proofs[$itemIndex]['variant']) return false;
            $seen[$itemIndex] = true;
        }
        return count($seen) === count($items);
    }

    private static function literalRecap(array $data, array $rows, array $positions): ?int
    {
        if (!isset($data['items']) || !is_array($data['items']) || !$data['items']) return null;
        $seen = []; $last = null;
        foreach ($data['evidence']['confirmation_ids'] ?? [] as $id) {
            if (!isset($rows[$id]) || $rows[$id]['speaker'] !== 'business') continue;
            foreach (self::clauses($rows[$id]['text'] ?? '') as $clause) foreach ($data['items'] as $index => $item) {
                if (!self::shortText($item['name'] ?? null, 200) || ($amount = self::declaredAmount($item)) === null) return null;
                if (self::literal($item['name'], $clause) && self::oneAmount($clause, $amount)
                    && !self::unsafe($clause) && !self::alternatives($clause)) {
                    $seen[$index] = true; $last = $last === null ? $positions[$id] : max($last, $positions[$id]);
                }
            }
        }
        return count($seen) === count($data['items']) ? $last : null;
    }

    private static function customerDataRow(array $data, string $text): bool
    {
        if (self::acceptance($text)) return true;
        // A short order label/typing fragment is not an alternative customer name.
        if (preg_match('/\A\s*(?:الطلب|طلب|الأوردر|الاوردر|الاورد|الاور|البيانات|بيانات)\s*[:：.!]*\s*\z/u', $text)) return true;
        $hasPhone = preg_match('/(?<![0-9])\+?[0-9](?:[ .()\-]?[0-9]){7,14}(?![0-9])/', self::normalize($text)) === 1;
        if ($hasPhone && !self::phonesConsistent($data['customer']['phone'], $text)) return false;
        if (preg_match('/(?:اسمي|إسمي|الاسم)\s*[:：]?/u', $text) && !self::literal($data['customer']['name'], $text)) return false;
        if (preg_match('/(?:عنواني|العنوان|عنوان\s+التوصيل)\s*[:：]?/u', $text) && !self::literal($data['customer']['address'], $text)) return false;
        // A familiar phone/name must not hide a contradictory street in the
        // remainder of the same row. Strip exact supplied facts, then permit only
        // finite field labels/separators, never arbitrary residual customer text.
        $remaining = preg_replace('/\s+/u', ' ', trim($text)); $found = $hasPhone;
        $values = [];
        foreach (['name', 'address', 'area'] as $field) if (is_string($data['customer'][$field] ?? null))
            $values[] = preg_replace('/\s+/u', ' ', trim($data['customer'][$field]));
        if (is_string($data['customer']['notes'] ?? null) && preg_match('/(?:ملاحظة|ملاحظات|ملاحظاتي)\s*[:：]?/u', $remaining))
            $values[] = preg_replace('/\s+/u', ' ', trim($data['customer']['notes']));
        usort($values, static fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($values as $value) {
            $remaining = preg_replace('/(?<![\p{L}\p{N}])(?:ال)?' . preg_quote($value, '/') . '(?![\p{L}\p{N}])/iu',
                ' ', $remaining, -1, $count);
            if (!is_string($remaining)) return false;
            $found = $found || $count > 0;
        }
        // Canonical phone matching was checked above. Actual phone formatting
        // may differ from the normalized extracted callback.
        $remaining = preg_replace('/(?<![0-9٠-٩۰-۹])\+?[0-9٠-٩۰-۹](?:[ .()\-]?[0-9٠-٩۰-۹]){7,14}(?![0-9٠-٩۰-۹])/u', ' ', $remaining);
        if (!is_string($remaining)) return false;
        $remaining = preg_replace('/(?<![\p{L}\p{N}])(?:اسمي|اسمى|إسمي|أنا|انا|الاسم|رقمي|رقم|الهاتف|التليفون|'
            . 'تليفون|هاتف|الموبايل|موبايل|عنواني|العنوان|عنوان|التوصيل|المنطقة|المدينة|مدينة|محافظة|في|من|'
            . 'ملاحظات|ملاحظة|ملاحظاتي|ساكن|اسكن|و)(?![\p{L}\p{N}])/u', ' ', $remaining);
        return $found && is_string($remaining) && preg_match('/\A[\s:：،,.!؛;\-()]*\z/u', $remaining) === 1;
    }

    /** Confirmed evidence must remain current through the end of the supplied episode. */
    private static function laterChanges(array $data, array $rows, array $positions, int $after): bool
    {
        foreach ($rows as $id => $row) {
            if ($positions[$id] <= $after) continue;
            $text = $row['text'] ?? '';
            if (self::unsafe($text) || self::amendment($text) || self::finalConfirm($text)) return true;
            if ($row['speaker'] !== 'customer') continue;
            if (self::newOrder($text) || self::quantities($text) || self::signedAmount($text) || self::unsupportedAmount(self::normalize($text))
                || !self::phonesConsistent($data['customer']['phone'] ?? '', $text)) return true;
            if (preg_match('/(?<![0-9٠-٩۰-۹])\+?[0-9٠-٩۰-۹](?:[ .()\-]?[0-9٠-٩۰-۹]){7,14}(?![0-9٠-٩۰-۹])|'
                . '\A\s*(?:شارع|مدينة|عمارة|شقة|عنوان|العنوان|اسمي|إسمي|الاسم)(?![\p{L}\p{N}])/u', $text)
                && !self::customerDataRow($data, $text)) return true;
            if (preg_match('/(?:العنوان\s+(?:الجديد|الصح|الصحيح)|(?:غير|غيّر|بدل|عدّل|عدل)\s+(?:العنوان|البيانات|الاسم|الرقم))|'
                . '(?:عنواني|عنوان\s+التوصيل|اسمي|إسمي|رقمي|رقم\s+(?:الهاتف|التليفون|الموبايل))\s*[:：]?/u', $text)) return true;
            if (preg_match('/(?<![\p{L}\p{N}])(?:كامل|مخلي|تنظيف)(?![\p{L}\p{N}])/u', $text)) return true;
        }
        return false;
    }

    private static function offerFor(array $item, int $index, array $items, array $rows, array $positions, array $ref): bool
    {
        $text = $rows[$ref['business_offer_id']]['text'];
        $topics = self::topics($text, $items);
        if ($topics) return $topics === [$index] && !self::alternatives($text);
        if (!preg_match('/(?:ب\s*[0-9٠-٩۰-۹]|جنيه|يفندم|الكيلو|per\s+kg|price)/iu', $text)) return false;
        // Unnamed price replies may answer only the immediately preceding named
        // customer inquiry. Intervening business filler does not introduce a topic.
        $previousCustomer = null;
        foreach ($rows as $id => $row) if ($row['speaker'] === 'customer'
            && $positions[$id] < $positions[$ref['business_offer_id']]) $previousCustomer = $id;
        return $previousCustomer === $ref['name_anchor_id'];
    }

    private static function topics(string $text, array $items): array
    {
        $found = [];
        foreach ($items as $index => $item) if (self::literal($item['name'], $text)) $found[$index] = true;
        // Known fish topics catch an intervening unselected product too. A family
        // token inside a unique full item name does not create a second topic.
        $families = ['فسيخ', 'رنجة', 'رنجه', 'سردين', 'بطارخ', 'ملوحة', 'أنشوجه', 'انشوجه', 'تونة', 'ماكريل',
            'جمبري', 'جمبرى', 'بلطي', 'بوري', 'كابوريا', 'سبيط'];
        foreach ($families as $family) {
            if (!self::literal($family, $text)) continue;
            $mapped = [];
            foreach ($items as $index => $item) if (self::literal($family, $item['name'])
                || (in_array($family, ['رنجة', 'رنجه'], true) && preg_match('/رنج[ةه]/u', $item['name']))) $mapped[] = $index;
            if (count($mapped) === 1) $found[$mapped[0]] = true;
            else $found['unknown:' . $family] = true;
        }
        return array_keys($found);
    }

    private static function topicIs(string $text, int $index, array $items): bool
    {
        return self::topics($text, $items) === [$index];
    }

    private static function variants(string $text): array
    {
        $variants = self::explicitVariants($text);
        $fish = self::fishWeightSize($text);
        if ($fish !== null) $variants[] = $fish['fish_per_kg'];
        return array_values(array_unique($variants));
    }

    private static function explicitVariants(string $text): array
    {
        $text = self::normalize($text); preg_match_all(self::variantPattern(), $text, $matches, PREG_SET_ORDER);
        $variants = [];
        foreach ($matches as $match) {
            $number = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');
            if ($number === '' && (($match[3] ?? '') !== '' || ($match[4] ?? '') !== '')) $number = '2';
            if ($number !== '' && (int) $number > 0) $variants[(string) (int) $number] = true;
        }
        return array_map('strval', array_keys($variants));
    }

    private static function variantPattern(): string
    {
        // Arabic offers commonly join the next size with an attached conjunction
        // ("والكيلو 3 حبه"). Keep the token boundary and consume only that prefix.
        return '/(?<![\p{L}\p{N}])(?:و)?(?:من\s+)?(?:ال)?كيلو\s*([0-9]{1,2})\s*'
            . '(?:حبات|حبة|حبه|سمكات|سمكة|سمكه)(?![\p{L}\p{N}])|'
            . '(?<![\p{L}\p{N}])([0-9]{1,2})\s*(?:حبات|حبة|حبه|سمكات|سمكة|سمكه)\s*'
            . '(?:في|لكل|\/)\s*(?:ال)?كيلو(?![\p{L}\p{N}])|'
            . '(?<![\p{L}\p{N}])(?:و)?(?:من\s+)?(?:ال)?كيلو\s*(سمكتين|سمكتان)(?![\p{L}\p{N}])|'
            . '(?<![\p{L}\p{N}])(سمكتين|سمكتان)\s*(?:في|لكل|\/)\s*(?:ال)?كيلو(?![\p{L}\p{N}])/iu';
    }

    private static function normalize(string $text): string
    {
        $text = strtr($text, ['٠'=>'0', '١'=>'1', '٢'=>'2', '٣'=>'3', '٤'=>'4', '٥'=>'5', '٦'=>'6',
            '٧'=>'7', '٨'=>'8', '٩'=>'9', '۰'=>'0', '۱'=>'1', '۲'=>'2', '۳'=>'3', '۴'=>'4', '۵'=>'5',
            '۶'=>'6', '۷'=>'7', '۸'=>'8', '۹'=>'9', '٫'=>'.']);
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text);
        $text = preg_replace_callback('/(?<![\p{L}\p{N}.])(?:([0-9]{1,5})\s*)?كيلو\s+و\s*(نص|نصف|ربع)(?![\p{L}\p{N}])/u',
            static function ($match) {
                $whole = isset($match[1]) && $match[1] !== '' ? (int) $match[1] : 1;
                return $whole . ($match[2] === 'ربع' ? '.25' : '.5') . ' كيلو';
            }, $text);
        return preg_replace('/[\t\r ]+/u', ' ', trim($text));
    }

    private static function unitAmount(string $number, string $unit): ?array
    {
        if (preg_match('/\A(?:نصف|نص|half)\z/iu', $number)) $quantity = 500;
        elseif (preg_match('/\A(?:ربع|quarter)\z/iu', $number)) $quantity = 250;
        elseif (($quantity = self::scaled($number)) === null) return null;
        if ($quantity <= 0) return null;
        if (preg_match('/\A(?:كيلو(?:جرام|غرام)?|كجم|كغ|kilograms?|kilos?|kg)\z/iu', $unit)) return ['quantity'=>$quantity, 'mode'=>'weight'];
        if (preg_match('/\A(?:جرام|غرام|جم|grams?|g)\z/iu', $unit)) return $quantity % 1000 === 0
            ? ['quantity'=>intdiv($quantity, 1000), 'mode'=>'weight'] : null;
        return ['quantity'=>$quantity, 'mode'=>'piece'];
    }

    private static function declaredAmount(array $item): ?array
    {
        $scaled = self::scaled($item['quantity'] ?? null); $mode = $item['quantity_mode'] ?? null;
        return $scaled !== null && $scaled > 0 && in_array($mode, ['weight', 'piece'], true)
            && ($mode !== 'piece' || $scaled % 1000 === 0) ? ['quantity'=>$scaled, 'mode'=>$mode] : null;
    }

    private static function scaled($value): ?int
    {
        if (!is_string($value) || !preg_match('/\A(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,3})?\z/', $value)) return null;
        $parts = explode('.', $value, 2);
        return (int) $parts[0] * 1000 + (int) str_pad($parts[1] ?? '', 3, '0');
    }

    private static function oneAmount(string $text, array $amount): bool
    {
        $found = self::quantities($text);
        return count($found) === 1 && $found[0] === $amount;
    }

    private static function selection(string $text): bool
    {
        return !self::unsafe($text) && !self::signedAmount($text) && !preg_match('/[?؟]/u', $text)
            && preg_match('/(?:عايز|عاوز|أريد|اريد|اطلب|أطلب|هات|ابعت|اعملي|اعمللي|اعمل\s+لي|اعمل\s+لى|'
                . 'زود|ضيف|أضف|اضف|\b(?:want|order|send|add)\b)/iu', $text) === 1;
    }

    private static function quantityQuestion(string $text): bool
    {
        return preg_match('/(?:تحب|عايز|عاوز|تحتاج).{0,30}(?:قد\s+إيه|قد\s+ايه|قد\s+أيه|كام|كم)|'
            . '(?:how\s+(?:much|many)|what\s+quantity)/iu', $text) === 1;
    }

    private static function bareSelection(string $text, string $name): bool
    {
        if (self::unsafe($text) || self::signedAmount($text) || self::alternatives($text) || self::amendment($text) || preg_match('/[?؟]/u', $text)) return false;
        $text = self::normalize($text);
        $text = preg_replace(self::variantPattern(), ' ', $text);
        $text = preg_replace('/(?<![\p{L}\p{N}])(?:ال)?' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/iu', ' ', $text);
        return preg_match('/\A\s*(?:تمام\s+)?(?:(?:[0-9]{1,6}(?:\.[0-9]{1,3})?|نص|نصف|ربع)\s+)?'
            . '(?:كيلو(?:جرام|غرام)?|كجم|كغ|جرام|غرام|جم|قطعة|حبة|حبه|علبة|سمكة|سمكه|وجبة|kg|kilo|piece|unit)'
            . '(?:\s+(?:كامل|مخلي|يفندم|بس|فقط))*\s*[.!]*\s*\z/iu', $text) === 1;
    }

    private static function positiveOption(string $hint, string $text): bool
    {
        if (!self::literal($hint, $text) || self::unsafe($text) || self::alternatives($text)) return false;
        if (preg_match('/(?:بدون|دون|غير|مش|لا|not|no|without)\s*' . preg_quote($hint, '/') . '/iu', $text)) return false;
        if (in_array($hint, ['كامل', 'مخلي'], true)) {
            $other = $hint === 'كامل' ? 'مخلي' : 'كامل';
            if (self::literal($other, $text)
                && !preg_match('/(?:بدون|دون|غير|مش|لا)\s*' . $other . '/u', $text)) return false;
        }
        return true;
    }

    private static function acceptance(string $text): bool
    {
        return !self::unsafe($text) && !self::alternatives($text) && !self::amendment($text)
            && preg_match('/\A\s*(?:تمام|موافق|اتفقنا|أؤكد|اؤكد|أكد|اكد|yes|confirm|ok(?:ay)?)(?:\s+(?:تمام|موافق|يفندم|شكرا|شكراً|على\s+(?:الطلب|الأوردر|الاوردر)|الطلب|الأوردر|الاوردر))*\s*[.!،♥❤❤️👍]*\s*\z/iu', $text) === 1;
    }

    private static function dataRequest(string $text): bool
    {
        return !self::unsafe($text) && !self::finalConfirm($text)
            && preg_match('/(?:استأ?ذنك|استاذنك|استأذنك|ممكن|لو\s+سمحت|من\s+فضلك|هات|ابعت|أرسل|ارسل|اكتب|قول)'
                . '.{0,60}(?:البيانات|بياناتك|اسمك|إسمك|رقم|تليفون|عنوان)|'
                . '(?:اسمك|إسمك|الاسم|رقمك|عنوانك).{0,30}[?؟]/iu', $text) === 1;
    }

    private static function dataAcknowledgment(string $text): bool
    {
        if (preg_match('/[?؟]|(?<![\p{L}\p{N}])(?:لم|لن|لا|مش|لسه|غير).{0,60}(?:بيانات|عنوان)|'
            . '(?:بيانات|عنوان).{0,60}(?:غير|ناقص|مش|لم|لن|لا|لسه)|هنراجع|سنراجع|سوف|\bwill\b/iu', $text)) return false;
        return preg_match('/(?:البيانات|بياناتك).{0,40}(?:العنوان|عنوانك)|'
            . '(?:الاسم|اسمك).{0,50}(?:رقم|الهاتف|التليفون).{0,50}(?:العنوان|عنوانك)/iu', $text) === 1;
    }

    private static function finalConfirm(string $text): bool
    {
        $order = '(?<![\p{L}\p{N}])(?:الطلب|طلب|الأوردر|الاوردر|الأورد|الاورد|الاور)(?![\p{L}\p{N}])';
        return !preg_match('/[?؟]/u', $text) && preg_match('/(?:تم\s+(?:تأكيد|تاكيد|اعتماد).{0,40}'
            . $order . '|(?:تأكيد|تاكيد)\s+' . $order . '|'
            . 'order\s+(?:is\s+)?confirmed|order\s+confirmation)/iu', $text) === 1;
    }

    private static function alternatives(string $text): bool
    {
        return preg_match('/(?<![\p{L}\p{N}])(?:أو|او|أم|ام|or)(?![\p{L}\p{N}])/iu', $text) === 1;
    }

    private static function amendment(string $text): bool
    {
        return preg_match('/(?:بدل|بدّل|غيّر|غير\s+(?:الطلب|الأوردر|الاوردر)|خليه|خليها|طلب\s+(?:تاني|آخر|اخر|جديد)|'
            . '(?:أوردر|اوردر)\s+(?:تاني|آخر|اخر|جديد)|\b(?:change|instead|another\s+order|new\s+order)\b)/iu', $text) === 1;
    }

    private static function unsafe(string $text): bool
    {
        return preg_match('/(?:تجاهل\s+(?:كل|التعليمات)|تعليمات\s+(?:النظام|المطور)|ignore\s+(?:all|previous|above)|'
            . 'system\s+prompt|developer\s+message|(?:return|decision).{0,30}CONFIRMED|'
            . '(?:مش|لا|مت|ما)\s*(?:عايز|عاوز|موافق|اعملي|اعمللي|اعمل\s+لي|هات|ابعت|ضيف|زود)|'
            . 'لا\s+(?:أريد|اريد|تأكد|تاكد|تؤكد|ترسل)|(?<![\p{L}\p{N}])(?:و)?(?:إلغاء|الغاء|إلغي|إلغى|الغي|الغى|الغيه|إلغيه|ألغي|ألغى|لغي|لغيه|إلغ|الغ|الغوا|إلغوا|الغيت|ألغيت|هلغي)(?![\p{L}\p{N}])|'
            . '(?:لم|لن|مش|لسه)\s+(?:يتم\s+)?(?:تأكيد|تاكيد|اعتماد)|'
            . '(?:لو|إذا|اذا|لما).{0,40}(?:تأكيد|تاكيد|اكد|أكد)|'
            . '(?:فريق|موظف|زميل).{0,60}(?:لمراجعة|للمراجعة|للتحقق|لتأكيد|للتأكيد|للتاكيد)|'
            . '\b(?:do\s+not|don[’\x27]t|not\s+want|cancel|unconfirmed|pending|will\s+(?:verify|review|confirm))\b)/iu', $text) === 1;
    }

    private static function lastTest(array $rows, array $positions): ?int
    {
        $last = null;
        foreach ($rows as $id => $row) if ($row['speaker'] === 'customer' && self::testIntent($row['text'] ?? '')) $last = $positions[$id];
        return $last;
    }

    private static function lastEpisodeTest(array $rows, array $positions): ?int
    {
        $last = self::lastTest($rows, $positions);
        foreach ($rows as $id => $row) {
            $text = implode("\n", array_filter([$row['text'] ?? null, $row['cart']['text'] ?? null], 'is_string'));
            if (preg_match('/\bWA-[0-9]{4}\b/i', $text) === 1)
                $last = $last === null ? $positions[$id] : max($last, $positions[$id]);
        }
        return $last;
    }

    private static function testIntent(string $text): bool
    {
        $text = trim($text);
        return preg_match('/\A\s*(?:(?:دي|ده|هذه|هذا)\s+)?(?:الطلب|الأوردر|الاوردر|طلب|أوردر|اوردر)'
            . '\s+(?:(?:ده|دي|هذا|هذه)\s+)?(?:اختبار|تجريبي|تجريبى|للتجربة)'
            . '(?:\s+(?:بس|فقط|لا\s+ترسله|لا\s+ترسلي|متبعتوش|ما\s+تبعتوش|مش\s+حقيقي))*\s*[.!،]*\s*\z|'
            . '\A\s*(?:this\s+order\s+is\s+(?:a\s+)?test|test\s+order)'
            . '(?:\s*[,;]\s*do\s+not\s+send\s+(?:it|this\s+order))?\s*[.!]*\s*\z/iu', $text) === 1;
    }

    private static function newOrderAfter(array $rows, array $positions, int $after): ?int
    {
        foreach ($rows as $id => $row) if ($positions[$id] > $after && $row['speaker'] === 'customer'
            && self::newOrder($row['text'] ?? '')) return $positions[$id];
        return null;
    }

    private static function newOrder(string $text): bool
    {
        return !self::unsafe($text) && !preg_match('/[?؟"“”«»]|(?:اختبار|تجربة|\btest\b)/iu', $text)
            && preg_match('/\A\s*(?:(?:أنا|انا)\s+)?(?:عايز|عاوز|محتاج|أريد|اريد)\s+(?:(?:أطلب|اطلب|أعمل|اعمل)\s+)?'
                . '(?:طلب|أوردر|اوردر)(?:\s+(?:جديد|تاني|آخر|اخر))?(?![\p{L}\p{N}])|'
                . '\A\s*(?:ابدأ|ابدا|ابدئي)\s+(?:طلب|أوردر|اوردر)\s+جديد|\A\s*(?:start|place)\s+a\s+new\s+order\b/iu', $text) === 1;
    }

    private static function signedAmount(string $text): bool
    {
        return preg_match('/(?:^|\s)[+\-−]\s*[0-9٠-٩۰-۹]+(?:[.٫][0-9٠-٩۰-۹]+)?\s*'
            . '(?:كيلو|كجم|كغ|جرام|غرام|جم|قطعة|حبة|حبه|سمكة|سمكه|علبة|وجبة|kg|kilo|piece)/iu', $text) === 1;
    }

    private static function unsupportedAmount(string $text): bool
    {
        return preg_match('/(?:كيلو|كجم|كغ|جرام|غرام|جم)\s+(?:إلا|الا)(?![\p{L}\p{N}])|'
            . '\s+و\s*(?:نص|نصف|ربع)(?![\p{L}\p{N}])(?!\s*(?:كيلو|كجم|كغ|جرام|غرام|جم))|'
            . '(?<![\p{L}\p{N}])(?:اثنين|اتنين|ثلاثة|ثلاثه|تلاتة|تلاته|أربعة|أربعه|اربعة|اربعه|خمسة|خمسه|'
            . 'ستة|سته|سبعة|سبعه|ثمانية|ثمانيه|تسعة|تسعه|عشرة|عشره|two|three|four|five|six|seven|eight|nine|ten)'
            . '\s+(?:كيلو|كجم|كغ|جرام|غرام|جم|قطعة|حبة|حبه|علبة|وجبة|kg|kilos?|pieces?|units?)(?![\p{L}\p{N}])/iu', $text) === 1;
    }

    private static function ledger(array $values): ?array
    {
        if (!$values || count($values) > self::MAX_ROWS) return null;
        $rows = []; $positions = [];
        foreach ($values as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null) || isset($rows[$row['id']])
                || !in_array($row['speaker'] ?? null, ['customer', 'business'], true)
                || (($row['text'] ?? null) !== null && !self::shortText($row['text'], 16000))) return null;
            $positions[$row['id']] = count($rows); $rows[$row['id']] = $row;
        }
        return [$rows, $positions];
    }

    private static function literal(string $value, string $text): bool
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)); $text = preg_replace('/\s+/u', ' ', $text);
        return $value !== '' && preg_match('/(?<![\p{L}\p{N}])(?:ال)?' . preg_quote($value, '/') . '(?![\p{L}\p{N}])/iu', $text) === 1;
    }

    private static function phoneLiteral(string $phone, string $text): bool
    {
        $phone = preg_replace('/[^0-9]/', '', self::normalize($phone));
        if (preg_match('/\A01[0125][0-9]{8}\z/', $phone)) $phone = '20' . substr($phone, 1);
        if (preg_match('/\A00201[0125][0-9]{8}\z/', $phone)) $phone = substr($phone, 2);
        preg_match_all('/(?<![0-9])\+?[0-9](?:[ .()\-]?[0-9]){7,14}(?![0-9])/', self::normalize($text), $matches);
        foreach ($matches[0] as $candidate) {
            $candidate = preg_replace('/[^0-9]/', '', $candidate);
            if (preg_match('/\A01[0125][0-9]{8}\z/', $candidate)) $candidate = '20' . substr($candidate, 1);
            if (preg_match('/\A00201[0125][0-9]{8}\z/', $candidate)) $candidate = substr($candidate, 2);
            if ($candidate === $phone) return true;
        }
        return false;
    }

    private static function phonesConsistent(string $phone, string $text): bool
    {
        $wanted = self::canonicalPhone($phone);
        preg_match_all('/(?<![0-9])\+?[0-9](?:[ .()\-]?[0-9]){7,14}(?![0-9])/', self::normalize($text), $matches);
        foreach ($matches[0] as $candidate) if (self::canonicalPhone($candidate) !== $wanted) return false;
        return true;
    }

    private static function canonicalPhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', self::normalize($phone));
        if (preg_match('/\A01[0125][0-9]{8}\z/', $phone)) $phone = '20' . substr($phone, 1);
        if (preg_match('/\A00201[0125][0-9]{8}\z/', $phone)) $phone = substr($phone, 2);
        return $phone;
    }

    private static function shortText($value, int $limit): bool
    {
        return is_string($value) && trim($value) !== '' && preg_match('//u', $value) === 1
            && strlen($value) <= $limit * 4;
    }

    private static function cited(array $data, string $field, string $id): bool
    {
        return isset($data['evidence'][$field]) && is_array($data['evidence'][$field]) && in_array($id, $data['evidence'][$field], true);
    }

    private static function exactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value); sort($actual); sort($keys); return $actual === $keys;
    }

    private static function ready(): array { return ['ok'=>true, 'reason'=>null]; }
    private static function failed(string $reason): array { return ['ok'=>false, 'reason'=>$reason]; }
}
