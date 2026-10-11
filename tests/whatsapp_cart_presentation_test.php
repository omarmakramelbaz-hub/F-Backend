<?php

namespace App\Http\Controllers { class Controller {} }

namespace {
    function app() { return new class { public function getLocale() { return 'ar'; } }; }
    $presentationConfig = [];
    function config($key, $default = null) { global $presentationConfig; return $presentationConfig[$key] ?? $default; }
    require dirname(__DIR__) . '/app/Support/WhatsAppInboxProtocol.php';
    require dirname(__DIR__) . '/app/Http/Controllers/Dashboard/WhatsAppInboxController.php';

    $checks = 0;
    function cartViewExpect($actual, $expected, string $label): void {
        global $checks; $checks++;
        if ($actual !== $expected) throw new \RuntimeException('FAILED: ' . $label);
    }
    try {
        $controller = new \App\Http\Controllers\Dashboard\WhatsAppInboxController();
        $cartMethod = new \ReflectionMethod($controller, 'displayCart'); $cartMethod->setAccessible(true);
        $textMethod = new \ReflectionMethod($controller, 'displayText'); $textMethod->setAccessible(true);
        $raw = ['catalog_id' => '1234567', 'product_items' => [
            ['product_retailer_id' => 'SKU_ONE', 'quantity' => 2, 'item_price' => '100.50', 'currency' => 'EGP'],
            ['product_retailer_id' => 'SKU_TWO', 'quantity' => 1, 'item_price' => 244, 'currency' => 'EGP'],
        ], 'unused_private_field' => 'not-for-presentation'];
        // This is the original encrypted order DTO shape, without any new stored cart field.
        $dto = ['type' => 'order', 'direction' => 'inbound', 'text' => null,
            'message_id' => 'fixture-raw-message-id', 'peer_identity' => 'fixture-private-identity',
            'content' => ['sources' => ['messages'], 'message' => ['order' => $raw]]];
        $cart = $cartMethod->invoke($controller, $dto);
        cartViewExpect($cart['total_price'], '445.00', 'old encrypted DTO cart displayed without migration');
        cartViewExpect(array_keys($cart), ['catalog_id', 'text', 'product_items', 'total_price', 'currency'], 'only bounded cart fields presented');
        cartViewExpect(strpos(json_encode($cart), 'not-for-presentation'), false, 'unselected raw fields remain private');
        cartViewExpect($textMethod->invoke($controller, $dto, 20000), '2 × سلة العميل · 445.00 EGP', 'cart preview contains line count and quoted subtotal');
        $presentationConfig['whatsapp_cart.product_names'] = ['1234567' => ['SKU_ONE' => 'صنف كتالوج موثّق']];
        $named = $cartMethod->invoke($controller, $dto);
        cartViewExpect($named['product_items'][0]['name'], 'صنف كتالوج موثّق', 'exact approved catalog and retailer mapping provides public display name');
        cartViewExpect(isset($named['product_items'][1]['name']), false, 'unmapped retailer retains exact ID without guessed name');
        $presentationConfig['whatsapp_cart.product_names'] = ['OTHER_CATALOG' => ['SKU_ONE' => 'Unrelated']];
        cartViewExpect(isset($cartMethod->invoke($controller, $dto)['product_items'][0]['name']), false, 'same retailer in another catalog cannot supply a name');
        foreach (["bad\nname", ' padded ', str_repeat('ع', 201), ['not-a-title']] as $badName) {
            $presentationConfig['whatsapp_cart.product_names'] = ['1234567' => ['SKU_ONE' => $badName]];
            cartViewExpect(isset($cartMethod->invoke($controller, $dto)['product_items'][0]['name']), false, 'malformed configured title is not presented');
        }
        $presentationConfig = [];
        $raw['text'] = 'Cart note'; $dto['content']['message']['order'] = $raw;
        cartViewExpect($textMethod->invoke($controller, $dto, 4), 'Cart', 'cart note preview is bounded');
        $dto['direction'] = 'outbound';
        cartViewExpect($cartMethod->invoke($controller, $dto), null, 'business messages do not impersonate submitted carts');
        $dto['direction'] = 'inbound'; $dto['content']['message']['order']['product_items'][0]['quantity'] = -1;
        cartViewExpect($cartMethod->invoke($controller, $dto), null, 'malformed historical carts remain unavailable for guessing');
        cartViewExpect($cartMethod->invoke($controller, null), null, 'unreadable ciphertext has no cart');
        echo 'WHATSAPP_CART_PRESENTATION_TESTS=' . $checks . " PASS\n";
    } catch (\Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n"); exit(1);
    }
}
