<?php

// A menu question cannot resolve duplicate ERP identities with indistinguishable labels.
// Reuse actual workflow/receipt/native validation; catalog and external transports remain fixtures.
require __DIR__.'/whatsapp_order_dialogue_workflow_test.php';

$catalogChecks=0;
function catalogDialogueExpect($condition,string $label): void {
    $GLOBALS['catalogChecks']++;
    if (!$condition) throw new RuntimeException('DIALOGUE_CATALOG_FAILED:'.$label);
}
function catalogDialoguePrepare(array $products,bool $options=false): int {
    dialogueSetup();
    $conversation=dialogueDraft($options?'عايز 1 كيلو فسيخ كامل أو مخلي':'عايز 1 كيلو فسيخ');
    \App\Services\Dashboard\WhatsAppOrderAiProvider::$data['items'][0]['quantity']='1';
    \App\Services\Dashboard\WhatsAppOrderAiProvider::$data['items'][0]['quantity_mode']='weight';
    \App\Services\Dashboard\WhatsAppOrderAiProvider::$data['issues']=$options
        ?['UNCONFIRMED','MISSING_BRANCH','AMBIGUOUS_ITEM']:['UNCONFIRMED','MISSING_BRANCH'];
    DialogueFixtureCatalog::$products=$products;
    return $conversation;
}
function catalogDialogueProduct(int $id,string $name='فسيخ',bool $available=true,array $options=[]): array {
    return ['id'=>$id,'name'=>$name,'available'=>$available,'quantity_mode'=>'weight','options'=>$options];
}
function catalogDialogueBlocked(string $label): void {
    global $dialogueGraphs;
    dialogueRun();
    catalogDialogueExpect(count($dialogueGraphs)===0&&count(DialogueFixtureProvider::$contexts)===0,
        $label.' does not spend contextual AI or send an unresolvable question');
    catalogDialogueExpect(\Illuminate\Support\Facades\DB::table('whatsapp_reply_requests')->count()===0,
        $label.' never reserves a misleading customer choice');
    catalogDialogueExpect(\Illuminate\Support\Facades\DB::table('whatsapp_order_drafts')->value('status')==='REVIEW',
        $label.' remains an operator review');
    dialogueNoErp($label.' never guesses a product or option ERP identity');
}

foreach (['فسيخ',' فسيخ ','فسيخ  نبروه'] as $name) {
    $first=$name==='فسيخ  نبروه'?'فسيخ نبروه':'فسيخ';
    catalogDialoguePrepare([catalogDialogueProduct(11,$first),catalogDialogueProduct(12,$name)]);
    catalogDialogueBlocked('different available product IDs with same normalized name');
}
catalogDialoguePrepare([catalogDialogueProduct(11,'FISH'),catalogDialogueProduct(12,'fish')]);
catalogDialogueBlocked('case-only duplicate product labels');

foreach ([
    [['id'=>'clean','label'=>'مخلي'],['id'=>'clean','label'=>'كامل']],
    [['id'=>'clean','label'=>'مخلي'],['id'=>'other-clean','label'=>'مخلي']],
    [['id'=>'clean','label'=>'مخلي'],['id'=>'other-clean','label'=>' مخلي ']],
    [['id'=>'one','label'=>'تنظيف إضافي'],['id'=>'two','label'=>'تنظيف  إضافي']],
    [['id'=>'one','label'=>'CLEAN'],['id'=>'two','label'=>'clean']],
    [['id'=>'empty','label'=>'   ']],
] as $options) {
    catalogDialoguePrepare([catalogDialogueProduct(11,'فسيخ',true,$options)],true);
    catalogDialogueBlocked('ambiguous or malformed options within one product');
}

// A temporarily unavailable duplicate name cannot hide the one genuine available item.
catalogDialoguePrepare([catalogDialogueProduct(11),catalogDialogueProduct(12,' فسيخ ',false)]);
dialogueRun();
catalogDialogueExpect(count($dialogueGraphs)===1&&str_contains(dialogueLastText(),'1 كيلو فسيخ')
    &&str_contains(dialogueLastText(),'اكتب موافق'),
    'one available identity plus unavailable same-name product can request the actual basket approval');
catalogDialogueExpect(count(DialogueFixtureProvider::$contexts)===0,
    'an unavailable duplicate does not cause a false product-selection question');
dialogueNoErp('ordinary approval recap still waits for genuine customer approval');

// Distinct available labels remain useful menu choices; a candidate never becomes an ERP selection.
catalogDialoguePrepare([catalogDialogueProduct(11,'فسيخ نبروه'),catalogDialogueProduct(12,'فسيخ دسوق')]);
dialogueRun(); $context=dialogueLastContext();
catalogDialogueExpect($context['kind']==='PRODUCT_CHOICE'
    &&array_column($context['candidates'],'label')===['فسيخ نبروه','فسيخ دسوق'],
    'different real product names still render the authorized available menu choices');
catalogDialogueExpect(count($dialogueGraphs)===1&&!str_contains(dialogueLastText(),'اكتب موافق'),
    'distinct actual choices remain a question without an invented order recap');
dialogueNoErp('genuine menu alternatives do not silently select a product');

// Option identifiers and labels need to be unique within their product, not across the branch menu.
$options=[['id'=>'whole','label'=>'كامل'],['id'=>'clean','label'=>'مخلي']];
catalogDialoguePrepare([catalogDialogueProduct(11,'فسيخ',true,$options),catalogDialogueProduct(12,'رنجة',true,$options)],true);
dialogueRun(); $context=dialogueLastContext();
catalogDialogueExpect($context['kind']==='OPTION_CHOICE'&&$context['item_name']==='فسيخ'
    &&array_column($context['candidates'],'label')===['كامل','مخلي'],
    'shared ordinary option labels across different products do not create false catalog ambiguity');
catalogDialogueExpect(count($dialogueGraphs)===1&&count(DialogueFixtureProvider::$contexts)===1,
    'one valid named-product option question is accepted');
dialogueNoErp('valid option question still cannot dispatch ERP');

// The source selector preserves a joined conjunction as native text. Suggest the
// real menu names instead of pretending the customer supplied a different name.
dialogueSetup(); dialogueDraft('عايز ورنجة');
\App\Services\Dashboard\WhatsAppOrderAiProvider::$data['items'][0]['name']='ورنجة';
DialogueFixtureCatalog::$products=[catalogDialogueProduct(11,'رنجة بطارخ'),
    catalogDialogueProduct(12,'رنجة ناعمة'),catalogDialogueProduct(13,'رنجة مدخنة'),
    catalogDialogueProduct(14,'رنجة غير متاحة',false),catalogDialogueProduct(15,'فسيخ')];
dialogueRun(); $context=dialogueLastContext();
catalogDialogueExpect($context['kind']==='PRODUCT_CHOICE'&&$context['item_name']==='ورنجة'
    &&array_column($context['candidates'],'label')===['رنجة بطارخ','رنجة ناعمة','رنجة مدخنة'],
    'a joined native conjunction can suggest only the three actual available herring products');
catalogDialogueExpect(\App\Services\Dashboard\WhatsAppOrderAiProvider::$data['items'][0]['name']==='ورنجة',
    'candidate similarity does not silently rewrite the selected native product name');
dialogueNoErp('joined conjunction similarity remains a customer menu question');

echo 'WHATSAPP_ORDER_DIALOGUE_CATALOG_TESTS='.$catalogChecks." PASS\n";
