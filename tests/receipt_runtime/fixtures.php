<?php
// Synthetic orders only. These fixtures never read a customer or a database.
$base = [
    'number'=>'58', 'created_at'=>'2026-11-06T13:30:00+00:00', 'channel'=>'takeaway',
    'branch'=>['name'=>'فرع المنصورة', 'phone'=>'01000000000', 'address'=>'عنوان الفرع التجريبي'],
    'cashier'=>['name'=>'كاشير تجريبي'],
    'context'=>['customer_name'=>'عميل تجريبي', 'customer_phone'=>'+201000000000'],
    'payment_method'=>'cash', 'payment_reference'=>'', 'can_reprint'=>false,
    'items'=>[
        ['name'=>'فسيخ بوري', 'option_label'=>'مخلي · عادي', 'quantity_mode'=>'weight', 'quantity'=>'0.500', 'unit_price'=>'500.00', 'total'=>'250.00'],
        ['name'=>'رنجة مدخنة', 'option_label'=>'تنظيف · عادي', 'quantity_mode'=>'weight', 'quantity'=>'1.000', 'unit_price'=>'220.00', 'total'=>'220.00'],
        ['name'=>'طبق طحينة', 'option_label'=>'', 'quantity_mode'=>'piece', 'quantity'=>'1.000', 'unit_price'=>'30.00', 'total'=>'30.00'],
    ],
    'subtotal'=>'500.00', 'discount'=>'0.00', 'discount_reason'=>'', 'tax_rate'=>'0.00', 'tax'=>'0.00',
    'service'=>'0.00', 'delivery'=>'0.00', 'total'=>'500.00', 'cash_received'=>'600.00', 'change'=>'100.00',
    'notes'=>'الليمون والشطة في عبوات منفصلة.',
];
$phone=$base;
$phone['channel']='phone'; $phone['delivery']='50.00'; $phone['total']='550.00'; $phone['change']='50.00';
$phone['context']+=['area'=>'المنصورة', 'address'=>'شارع الجمهورية أمام البوابة الرئيسية، الدور الثاني، شقة ٥', 'delivery_notes'=>'الاتصال قبل الوصول',
    'delivery_company'=>['name'=>'شركة تجريبية', 'phone'=>'01000000001'],
    'delivery_location'=>['latitude'=>'31.1234567','longitude'=>'31.7654321','distance_km'=>'0.770','method'=>'app_direct']];
$dine=$base; $dine['channel']='dine'; $dine['context']+=['table'=>['name'=>'طاولة ٧'], 'waiter_name'=>'نادل تجريبي', 'guest_count'=>4];
$phoneTicket=$phone+$phone['context']; $dineTicket=$dine+$dine['context'];
$mixed=$dine; $mixed['payment_method']='mixed'; $mixed['payment_breakdown']=[['method'=>'cash','amount'=>'200.00'],['method'=>'card','amount'=>'300.00','reference'=>'CARD-DEMO-123']];
$mixed['cash_received']='250.00'; $mixed['change']='50.00';
$unpaid=$dine; $unpaid['payment_method']='unpaid'; $unpaid['unpaid']=true; $unpaid['print_marker']='pos-ticket';
$cancelled=$base; $cancelled['status']='cancelled';
$paper=$base;
$paper['number']='TA94-S2-0017'; $paper['branch']=['name'=>'فرع المحلة']; $paper['notes']='';
$paper['items']=[
    ['name'=>'طبق ليمون','quantity_mode'=>'piece','quantity'=>'1.000','unit_price'=>'15.00','total'=>'15.00'],
    ['name'=>'سردين','option_label'=>'مخلي','quantity_mode'=>'weight','quantity'=>'0.525','unit_price'=>'250.00','total'=>'131.25'],
    ['name'=>'طبق طماطم','quantity_mode'=>'piece','quantity'=>'1.000','unit_price'=>'15.00','total'=>'15.00'],
    ['name'=>'طبق طحينة','quantity_mode'=>'piece','quantity'=>'1.000','unit_price'=>'30.00','total'=>'30.00'],
    ['name'=>'عدد 1 خبز بلدي','quantity_mode'=>'piece','quantity'=>'20.000','unit_price'=>'3.00','total'=>'60.00'],
    ['name'=>'رنجة أفراح','option_label'=>'مخلي','quantity_mode'=>'weight','quantity'=>'0.860','unit_price'=>'250.00','total'=>'215.00'],
    ['name'=>'طبق فلفل أحمر','quantity_mode'=>'piece','quantity'=>'1.000','unit_price'=>'15.00','total'=>'15.00'],
    ['name'=>'فسيخ دسوقي 3 قطعة','option_label'=>'مخلي','quantity_mode'=>'weight','quantity'=>'1.030','unit_price'=>'450.00','total'=>'463.50'],
    ['name'=>'طبق بطاطس نينسي','quantity_mode'=>'piece','quantity'=>'2.000','unit_price'=>'15.00','total'=>'30.00'],
];
$paper['subtotal']='974.75'; $paper['total']='974.75'; $paper['cash_received']='1000.00'; $paper['change']='25.25';
$paperKitchen=$paper; $paperKitchen['number']='PH94-S2-0012'; $paperKitchen['channel']='phone';
$paperKitchen['items']=[$paper['items'][1],$paper['items'][7]];
$paperKitchen['items'][0]['quantity']='0.500'; $paperKitchen['items'][1]['quantity']='1.000';
$paperKitchen['context']+=['address'=>'شارع تجريبي أمام مبنى تجريبي'];
$paperKitchen+=$paperKitchen['context'];
$long=$phone; $long['number']='TA-F-1-20261106-000000000058';
$long['context']['customer_name']='عميل ذو اسم طويل للاختبار والتأكد من اكتمال النص';
$long['context']['address']=str_repeat('عنوان طويل في المنصورة بجوار البوابة الرئيسية والدور الثاني، ',5);
$long['notes']="تعليمات طويلة للتجهيز\n".str_repeat('تظهر الملاحظة كاملة دون حذف أي كلمة. ',5);
$long['items'][0]['name']=str_repeat('اسم صنف طويل جدًا للاختبار ',4);
$long['items'][0]['quantity']='1234.567'; $long['items'][0]['unit_price']='999999.99'; $long['items'][0]['total']='1234567.89';
$long['notes'].=' <script>UNSAFE_FIXTURE</script>';
$card=[
    'number'=>'APP-58','created_at'=>$base['created_at'],'created_label'=>'06/11/2026 15:30','app_label'=>'طلبات التطبيق','store'=>'فرع المنصورة',
    'customer'=>'عميل التطبيق التجريبي','phone'=>'+201000000000','address'=>$phone['context']['address'],
    'items'=>[['name'=>'فسيخ بوري','quantity'=>1,'display_quantity'=>'0.500','quantity_unit'=>'كجم','option_label'=>'مخلي','line_total'=>'250.00'],
        ['name'=>'رنجة مدخنة','quantity'=>1,'option_label'=>'تنظيف','line_total'=>'220.00']],
    'totals'=>[['label'=>'قيمة الأصناف','amount'=>'470.00'],['label'=>'التوصيل','amount'=>'50.00'],['label'=>'الخصم','amount'=>'-20.00']],
    'total'=>'500.00','payment_label'=>'فيزا','payment_paid'=>true,'payment_failed'=>false,'notes'=>$base['notes'],
    'status_label'=>'قيد التجهيز','status'=>'preparing','source'=>'legacy','actions'=>[],'urls'=>['print'=>'/admin/order-board/legacy/58/print'],
];
$failed=$card; $failed['payment_paid']=false; $failed['payment_failed']=true; $failed['status_label']='فشل الدفع';
$pending=$card; $pending['payment_paid']=false; $pending['total']=null; $pending['items']=[]; $pending['totals']=[];
$legacy=(object)[
    'order_no'=>'LEG-58','created_at'=>$base['created_at'],'resturant'=>(object)['name'=>'فرع المنصورة'],
    'user'=>(object)['name'=>'عميل قديم تجريبي','mobile'=>'1000000000'],
    'user_address'=>(object)['area_name'=>'المنصورة','street_name'=>'شارع تجريبي','address_name'=>'عمارة تجريبية','floor_no'=>'2','apartment_no'=>'5'],
    'payment_type'=>'visa','total'=>'500.00','delivery_price'=>'50.00','service_fees'=>'10.00','user_tax'=>'25.00','grand_total'=>'585.00',
    'carts'=>[(object)['resturant_product'=>(object)['product_name'=>'صنف قديم تجريبي'],'qty'=>2,'price'=>250,'product_feature'=>null,'product_clean'=>null]],
];
return [
    'takeaway'=>['view'=>'admin.takeaway.receipt','data'=>['receipt'=>$base]],
    'phone'=>['view'=>'admin.takeaway.receipt','data'=>['receipt'=>$phone]],
    'dine'=>['view'=>'admin.takeaway.receipt','data'=>['receipt'=>$dine]],
    'mixed'=>['view'=>'admin.takeaway.receipt','data'=>['receipt'=>$mixed]],
    'unpaid'=>['view'=>'admin.takeaway.receipt','data'=>['receipt'=>$unpaid]],
    'cancelled'=>['view'=>'admin.takeaway.receipt','data'=>['receipt'=>$cancelled]],
    'paper-sample'=>['view'=>'admin.takeaway.receipt','data'=>['receipt'=>$paper]],
    'paper-kitchen'=>['view'=>'admin.pos_service.kitchen','data'=>['kitchen'=>['ticket'=>$paperKitchen,'revision'=>1,'created_at'=>$base['created_at']]]],
    'long'=>['view'=>'admin.takeaway.receipt','data'=>['receipt'=>$long]],
    'preview'=>['view'=>'admin.takeaway.receipt','data'=>['receipt'=>$base,'preview'=>true]],
    'auto-print'=>['view'=>'admin.takeaway.receipt','data'=>['receipt'=>$base],'auto_print'=>true],
    'kitchen-phone'=>['view'=>'admin.pos_service.kitchen','data'=>['kitchen'=>['ticket'=>$phoneTicket,'revision'=>1,'created_at'=>$base['created_at']]]],
    'kitchen-dine'=>['view'=>'admin.pos_service.kitchen','data'=>['kitchen'=>['ticket'=>$dineTicket,'revision'=>2,'created_at'=>$base['created_at']]]],
    'ticket'=>['view'=>'admin.pos_service.ticket','data'=>['ticket'=>$dineTicket]],
    'app'=>['view'=>'admin.orders.board_detail','data'=>['printing'=>true,'card'=>$card]],
    'app-failed'=>['view'=>'admin.orders.board_detail','data'=>['printing'=>true,'card'=>$failed]],
    'app-pending'=>['view'=>'admin.orders.board_detail','data'=>['printing'=>true,'card'=>$pending]],
    'app-screen'=>['view'=>'admin.orders.board_detail','data'=>['printing'=>false,'card'=>$card]],
    'legacy'=>['view'=>'printReceipt','data'=>['invoice'=>$legacy]],
];
