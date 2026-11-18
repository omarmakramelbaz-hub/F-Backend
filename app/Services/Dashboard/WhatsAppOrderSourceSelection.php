<?php

namespace App\Services\Dashboard;

use App\Support\WhatsAppOrderExtraction;

/** Pure transport choices. All copied values still pass the unchanged order validator. */
final class WhatsAppOrderSourceSelection
{
    private const MAX_CLAUSES=200;
    private const MAX_ENUM_VALUES=1000;
    private const MAX_BYTES=131072;

    public static function build(array $transcript): array
    {
        try {
            $checked=WhatsAppOrderExtraction::validateTranscript($transcript);
            if (!$checked['ok']) return self::result(false,$checked['reason']);
            $sources=[]; $nameTokens=[]; $clauses=[]; $clauseInput=[];
            $customerText=[]; $customerSources=[]; $businessText=[]; $locations=[]; $carts=[]; $retailers=[];
            foreach ($transcript as $row) {
                $id=$row['id']; $speaker=$row['speaker'];
                if ($speaker==='customer' && $row['location']!==null) $locations[]=$id;
                if ($speaker==='customer' && ($row['cart']??null)!==null) {
                    $carts[]=$id;
                    foreach ($row['cart']['product_items'] as $line) $retailers[]=$line['product_retailer_id'];
                }
                // Context references need actual text rows, not a constructed cart/text join.
                $text=$row['text'];
                if (is_string($text) && trim($text)!=='') {
                    if ($speaker==='customer') $customerText[]=$id;
                    else $businessText[]=$id;
                }
                // Every original field is represented independently. A cart caption is
                // never appended to native text or silently dropped when both exist.
                foreach (['text'=>$row['text'],'cart_text'=>$row['cart']['text']??null] as $kind=>$original) {
                    if (!is_string($original) || trim($original)==='') continue;
                    $tokens=self::tokens($original);
                    if ($tokens===[]) continue;
                    $key=$id.':'.$kind;
                    $sources[$key]=['message_id'=>$id,'text'=>$original,'speaker'=>$speaker,'source'=>$kind,'tokens'=>$tokens];
                    $nameTokens[]=['message_id'=>$id,'speaker'=>$speaker,'source'=>$kind,
                        'tokens'=>array_map(static fn($token)=>['index'=>$token['index'],'text'=>$token['text']],$tokens)];
                    if ($speaker==='customer' && $kind==='text') $customerSources[$key]=$sources[$key];
                }
                if ($speaker!=='business' || !is_string($text) || trim($text)==='') continue;
                $parts=WhatsAppOrderConversationEvidence::clauses($text);
                if (count($parts)>30) return self::result(false,'INPUT_TOO_LARGE');
                foreach ($parts as $index=>$part) {
                    if (count($clauses)>=self::MAX_CLAUSES) return self::result(false,'INPUT_TOO_LARGE');
                    $choice='s'.(count($clauses)+1);
                    $clauses[$choice]=['message_id'=>$id,'clause_index'=>$index];
                    $clauseInput[]=['id'=>$choice,'message_id'=>$id,'clause_index'=>$index,'text'=>$part,'derived'=>true];
                }
            }
            $base=WhatsAppOrderExtraction::schema(); $properties=$base['properties']['items']['items']['properties'];
            $common=array_intersect_key($properties,array_flip(['quantity','quantity_mode']));
            $choices=[];
            if ($sources!==[]) $choices[]=self::object(['name_source'=>self::nameSource($sources)]+$common+
                ['option_source'=>self::optionalSource($sources)]+
                ['cart_reference'=>['type'=>'null'],'conversation_reference'=>['type'=>'null']]);
            if ($customerSources!==[] && $businessText!==[] && $clauses!==[]) {
                $context=self::object([
                    'business_offer_id'=>self::labels($businessText),
                    'customer_quantity_id'=>self::labels($customerText),'summary_clause_id'=>self::labels(array_keys($clauses)),
                ]);
                $choices[]=self::object(['name_source'=>self::nameSource($customerSources)]+$common+
                    ['option_source'=>self::optionalSource($customerSources)]+
                    ['cart_reference'=>['type'=>'null'],'conversation_reference'=>$context]);
            }
            if ($sources!==[] && $carts!==[] && $retailers!==[]) {
                $cart=self::object(['message_id'=>self::labels($carts),'product_retailer_id'=>self::labels(array_values(array_unique($retailers)))]);
                $choices[]=self::object(['name_source'=>self::nameSource($sources)]+$common+
                    ['option_source'=>self::optionalSource($sources)]+
                    ['cart_reference'=>$cart,'conversation_reference'=>['type'=>'null']]);
            }
            if ($choices===[]) return self::result(false,'INVALID_INPUT');
            $base['properties']['items']['items']=['anyOf'=>$choices];
            $eligibleCustomer=array_values(array_unique(array_merge($customerText,$carts)));
            foreach (['request_ids'=>$eligibleCustomer,'confirmation_ids'=>$businessText,'customer_acceptance_ids'=>$customerText] as $field=>$ids)
                $base['properties']['evidence']['properties'][$field]=self::idList($ids);
            $base['properties']['evidence']['properties']['location_id']=['type'=>['string','null'],'enum'=>array_merge($locations,[null])];
            $input=['transcript'=>$transcript,'name_tokens'=>$nameTokens,'summary_clauses'=>$clauseInput];
            if (self::enumCount($base)>self::MAX_ENUM_VALUES
                || strlen(json_encode(['input'=>$input,'schema'=>$base],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))>self::MAX_BYTES)
                return self::result(false,'INPUT_TOO_LARGE');
            return self::result(true,null,['input'=>$input,'schema'=>$base,'sources'=>$sources,'clauses'=>$clauses]);
        } catch (\Throwable $error) { return self::result(false,'INVALID_INPUT'); }
    }

    /** Decode supplied choices and their native name citations; no customer/order fact is approved. */
    public static function decode(array $wire,array $plan): array
    {
        try {
            if (!is_array($wire['items']??null) || !array_is_list($wire['items'])) return self::result(true,null,$wire);
            $normal=$wire; $anchorRequests=null; $nameAnchors=[];
            foreach ($wire['items'] as $index=>$item) {
                // Legacy fixture/provider replies retain their original contract. The new
                // request schema never permits a free-form item name.
                if (!is_array($item) || !array_key_exists('name_source',$item)) continue;
                if (!self::keys($item,['name_source','quantity','quantity_mode','option_source','cart_reference','conversation_reference']))
                    return self::sourceFailure(array_key_exists('option_hint',$item)?'ITEM_OPTION':'ITEM');
                $name=self::copiedSource($item['name_source'],$plan);
                if ($name===null) return self::sourceFailure('ITEM_NAME');
                $source=$item['name_source']; $row=$name['row'];
                $option=$item['option_source']===null?null:self::copiedSource($item['option_source'],$plan);
                if ($item['option_source']!==null && $option===null) return self::sourceFailure('ITEM_OPTION');
                if ($option!==null && !self::cited($option['message_id'],$wire,['request_ids','confirmation_ids']))
                    return self::sourceFailure('ITEM_OPTION');
                $reference=$item['conversation_reference'];
                if ($reference!==null) {
                    if ($item['cart_reference']!==null || !is_array($reference)
                        || !self::keys($reference,['business_offer_id','customer_quantity_id','summary_clause_id']))
                        return self::sourceFailure('ITEM');
                    foreach (['business_offer_id'=>'business','customer_quantity_id'=>'customer'] as $key=>$speaker) {
                        $id=$reference[$key];
                        $target=is_string($id)?($plan['sources'][$id.':text']??null):null;
                        if (!is_array($target) || $target['speaker']!==$speaker || $target['source']!=='text') return self::sourceFailure('ITEM');
                    }
                    if ($row['speaker']!=='customer' || $row['source']!=='text') return self::sourceFailure('ITEM_NAME');
                    if ($anchorRequests===null) {
                        $anchorRequests=self::originalRequests($wire,$plan);
                        if ($anchorRequests===null) return self::sourceFailure('ITEM_NAME');
                    }
                    // Only the selected native name row carries an implicit citation.
                    // Quantity and option citations must be present in the ORIGINAL
                    // response, even when they happen to use the same row as its name.
                    if (!self::cited($reference['customer_quantity_id'],$wire,['request_ids'])) return self::sourceFailure('ITEM');
                    if ($option!==null && ($option['row']['speaker']!=='customer' || $option['row']['source']!=='text'
                        || !self::cited($option['message_id'],$wire,['request_ids']))) return self::sourceFailure('ITEM_OPTION');
                    $choice=is_string($reference['summary_clause_id'])?($plan['clauses'][$reference['summary_clause_id']]??null):null;
                    if (!is_array($choice)) return self::sourceFailure('ITEM');
                    $nameAnchors[$source['message_id']]=true;
                    $reference=['name_anchor_id'=>$source['message_id'],'business_offer_id'=>$reference['business_offer_id'],
                        'customer_quantity_id'=>$reference['customer_quantity_id'],'business_summary_id'=>$choice['message_id'],
                        'summary_clause_index'=>$choice['clause_index'],'customer_option_id'=>$option===null?null:$option['message_id']];
                }
                unset($normal['items'][$index]['name_source'],$normal['items'][$index]['option_source']);
                $normal['items'][$index]['name']=$name['text'];
                $normal['items'][$index]['option_hint']=$option===null?null:$option['text'];
                $normal['items'][$index]['conversation_reference']=$reference;
            }
            if ($anchorRequests!==null) {
                foreach (array_keys($nameAnchors) as $id) {
                    if (in_array($id,$anchorRequests,true)) continue;
                    if (count($anchorRequests)>=WhatsAppOrderExtraction::MAX_MESSAGES) return self::sourceFailure('ITEM_NAME');
                    $anchorRequests[]=$id;
                }
                $normal['evidence']['request_ids']=$anchorRequests;
            }
            return self::result(true,null,$normal);
        } catch (\Throwable $error) { return self::sourceFailure('ITEM'); }
    }

    private static function tokens(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\p{M}]*|[^\s\p{L}\p{N}]+/u',$text,$matches,PREG_OFFSET_CAPTURE);
        $tokens=[];
        foreach ($matches[0] as [$word,$start]) {
            $tokens[]=['index'=>count($tokens),'text'=>$word,'start'=>$start,'end'=>$start+strlen($word)];
            // This is an explicit overlapping original substring, not a normalized alias.
            if (preg_match('/\Aال(?=\p{L})/u',$word)) {
                $offset=strlen('ال');
                $tokens[]=['index'=>count($tokens),'text'=>substr($word,$offset),'start'=>$start+$offset,'end'=>$start+strlen($word)];
            }
        }
        return $tokens;
    }
    private static function nameSource(array $sources): array
    {
        $choices=[];
        foreach ($sources as $source) {
            $maximum=count($source['tokens'])-1;
            $choices[]=self::object(['message_id'=>self::labels([$source['message_id']]),'source'=>self::labels([$source['source']]),
                'first_token'=>['type'=>'integer','minimum'=>0,'maximum'=>$maximum],
                'last_token'=>['type'=>'integer','minimum'=>0,'maximum'=>$maximum]]);
        }
        return ['anyOf'=>$choices];
    }
    private static function optionalSource(array $sources): array
    {
        return ['anyOf'=>array_merge([['type'=>'null']],self::nameSource($sources)['anyOf'])];
    }
    /** Copy one exact original field span; no normalization, aliases or field joins. */
    private static function copiedSource($source,array $plan): ?array
    {
        if (!is_array($source) || !self::keys($source,['message_id','source','first_token','last_token'])
            || !is_string($source['message_id']) || !in_array($source['source'],['text','cart_text'],true)
            || !is_int($source['first_token']) || !is_int($source['last_token'])
            || $source['first_token']<0 || $source['last_token']<$source['first_token']) return null;
        $row=$plan['sources'][$source['message_id'].':'.$source['source']]??null;
        $first=is_array($row)?($row['tokens'][$source['first_token']]??null):null;
        $last=is_array($row)?($row['tokens'][$source['last_token']]??null):null;
        if (!is_array($row) || !is_array($first) || !is_array($last) || !is_string($row['text']??null)
            || ($row['message_id']??null)!==$source['message_id'] || ($row['source']??null)!==$source['source']
            || !in_array($row['speaker']??null,['customer','business'],true)) return null;
        $text=substr($row['text'],$first['start'],$last['end']-$first['start']);
        if ($text==='' || preg_match('//u',$text)!==1 || self::length($text)>200) return null;
        return ['text'=>$text,'row'=>$row,'message_id'=>$source['message_id']];
    }
    private static function cited(string $id,array $wire,array $fields): bool
    {
        foreach ($fields as $field) {
            $ids=$wire['evidence'][$field]??null;
            if (is_array($ids) && in_array($id,$ids,true)) return true;
        }
        return false;
    }
    /** Validate original metadata before deriving a selected contextual name citation. */
    private static function originalRequests(array $wire,array $plan): ?array
    {
        $transcript=$plan['input']['transcript']??null;
        $evidence=$wire['evidence']??null;
        if (!is_array($transcript) || !WhatsAppOrderExtraction::validateTranscript($transcript)['ok']
            || !is_array($evidence)
            || !self::keys($evidence,['request_ids','confirmation_ids','customer_acceptance_ids','location_id'])) return null;
        $rows=array_column($transcript,null,'id');
        foreach (['request_ids'=>'customer','confirmation_ids'=>'business','customer_acceptance_ids'=>'customer'] as $field=>$speaker) {
            $ids=$evidence[$field];
            if (!is_array($ids) || !array_is_list($ids) || count($ids)>WhatsAppOrderExtraction::MAX_MESSAGES) return null;
            $seen=[];
            foreach ($ids as $id) {
                if (!is_string($id) || !isset($rows[$id]) || isset($seen[$id]) || $rows[$id]['speaker']!==$speaker) return null;
                $text=implode("\n",array_filter([$rows[$id]['text'],$rows[$id]['cart']['text']??null],'is_string'));
                if ((trim($text)==='' || preg_match('//u',$text)!==1 || self::length($text)>WhatsAppOrderExtraction::MAX_INPUT_CHARACTERS)
                    && !($field==='request_ids' && ($rows[$id]['cart']??null)!==null)) return null;
                $seen[$id]=true;
            }
        }
        $location=$evidence['location_id'];
        if ($location!==null && (!is_string($location) || !isset($rows[$location])
            || $rows[$location]['speaker']!=='customer' || $rows[$location]['location']===null)) return null;
        return $evidence['request_ids'];
    }
    private static function idList(array $ids): array
    {
        return $ids===[] ? ['type'=>'array','maxItems'=>0,'items'=>['type'=>'string']]
            : ['type'=>'array','maxItems'=>60,'items'=>self::labels($ids)];
    }
    private static function labels(array $ids): array { return ['type'=>'string','enum'=>$ids]; }
    private static function object(array $properties): array
    {
        return ['type'=>'object','properties'=>$properties,'required'=>array_keys($properties),'additionalProperties'=>false];
    }
    private static function enumCount(array $node): int
    {
        $count=isset($node['enum'])&&is_array($node['enum'])?count($node['enum']):0;
        foreach ($node as $key=>$child) if ($key!=='enum' && is_array($child)) $count+=self::enumCount($child);
        return $count;
    }
    private static function keys(array $value,array $keys): bool
    {
        $actual=array_keys($value); sort($actual); sort($keys); return $actual===$keys;
    }
    private static function length(string $value): int
    {
        return function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):preg_match_all('/./us',$value);
    }
    private static function sourceFailure(string $field): array
    {
        return self::result(false,'INVALID_EVIDENCE')+['validation_category'=>'SOURCE_REFERENCE_INVALID','validation_field'=>$field];
    }
    private static function result(bool $ok,?string $reason=null,?array $data=null): array
    {
        return ['ok'=>$ok,'reason'=>$reason,'data'=>$data];
    }
}
