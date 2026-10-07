<?php
namespace App\Http\Traits;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Google\Client as GoogleClient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use App\Models\GeneralSettings;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Factory;
trait FcmFirebase {

    public function sendFcmNotification($user_tokens = null, $data = [])
    {
        // Convert single token to array for consistent processing
        $tokens = is_array($user_tokens) ? $user_tokens : [$user_tokens];
        
        // Remove any null or empty tokens
        $tokens = array_filter($tokens);
        
        if (empty($tokens)) {
            return response()->json(['message' => 'No device tokens provided'], 400);
        }
        
        $setting = app(GeneralSettings::class); 
        $title = $data['title'];
        $description = $data['text'];
        $order_id = isset($data['data']['order_id']) ? (string) $data['data']['order_id'] : null;
        $notification_sound = isset($data['data']['notification_sound']) ? (string) $data['data']['notification_sound'] : 'default';
        $notification_type = (string) $data['data']['notification_type'];
        $projectId = config('services.fcm.project_id');
        
        $credentialsFilePath = storage_path('app/firebase_credentials.json');
        
        // Initialize Google Client and get access token
        $client = new GoogleClient();
        $client->setHttpClient(new \GuzzleHttp\Client(['connect_timeout' => 4, 'timeout' => 12]));
        $client->setAuthConfig($credentialsFilePath);
        $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
        $client->refreshTokenWithAssertion();
        $token = $client->getAccessToken();
        $access_token = $token['access_token'];
        
        $headers = [
            "Authorization: Bearer $access_token",
            'Content-Type: application/json'
        ];
        
        // Determine action URL
        if ($data['data']['notification_type'] == '1') {
            $action = env('APP_URL')."/admin/applies-orders/?modal=order".$data['data']['order_id'];
        } elseif (isset($data['data']['click_action']) && $data['data']['click_action']) {
            $action = $data['data']['click_action'];
        } else {
            $action = env('APP_URL')."/admin/dashboard";
        }
        
        // Prepare common data
        $notificationData = array_merge($data['data'], [
            "click_action" => $action,
            "icon" => url('/storage/').$setting->logo
        ]);
        
        $responses = [];
        $errors = [];
        
        // Send notification to each token
        foreach ($tokens as $token) {
            $token = is_string($token) ? $token : $token['token'] ?? null;
            if(!$token){
                continue;
            }
            $message = [
                "message" => [
                    "token" => $token,
                    "notification" => [
                        "title" => $title,
                        "body" => $description,
                    ],
                    'android' => [
                        'notification' => [
                            'sound' => 'default',
                            "icon" => url('/storage/').$setting->logo,
                        ],
                        "data" => [
                            "click_action" => $action
                        ]
                    ],
                    'apns' => [
                        'payload' => [
                            'aps' => [
                                'sound' => 'default',
                                "icon" => url('/storage/').$setting->logo,
                            ]
                        ]
                    ],
                    "data" => array_map('strval', $notificationData)
                ]
            ];
            
            $payload = json_encode($message);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            
            $response = curl_exec($ch);
            $err = curl_error($ch);
            
            if ($err) {
                $errors[$token] = $err;
            } else {
                $responses[$token] = json_decode($response, true);
            }
            
            curl_close($ch);
        }
        
        if (!empty($errors)) {
            return response()->json([
                'message' => 'Some notifications failed to send',
                'successful' => $responses,
                'errors' => $errors
            ], 207); // 207 Multi-Status
        }
        
        return response()->json([
            'message' => 'All notifications have been sent',
            'responses' => $responses
        ]);
     }

//     /** Resolve the Kreait Messaging service */
    protected function messaging(): Messaging
    {
        $path = public_path('firebase_credentials.json');
        // Ensure the file exists there
        $factory = (new Factory())->withServiceAccount($path);
        return $factory->createMessaging();
    }

    /**
     * Send FCM:
     * - Topic mode: set $data['is_topic'] = true and $data['topic'] = 'my_topic'
     *   - If $user_tokens is provided (array), they will be subscribed to that topic before sending.
     * - Token mode: pass a string token or an array of tokens in $user_tokens.
     *
     * Returns a JSON Response with per-target results.
     */
    public function sendFcmNotificationTobic($user_tokens = null, array $data = [])
    {
        $isTopic = (bool)($data['is_topic'] ?? false);
        $topic   = isset($data['topic']) ? trim((string)$data['topic']) : null;

        // Normalize tokens (used to subscribe for topic mode or to send for token mode)
        $tokens = is_array($user_tokens) ? array_values(array_filter($user_tokens)) : array_filter([$user_tokens]);

        if ($isTopic && empty($topic)) {
            return response()->json(['message' => 'Topic name is required when is_topic = true'], 400);
        }
        if (!$isTopic && empty($tokens)) {
            return response()->json(['message' => 'No device tokens provided'], 400);
        }

        $setting     = app(GeneralSettings::class);
        $title       = $data['title'] ?? '';
        $body        = $data['text'] ?? '';

        // Build click action (as you had before)
        if (($data['data']['notification_type'] ?? null) == '1' && !empty($data['data']['order_id'])) {
            $action = env('APP_URL')."/admin/applies-orders/?modal=order".$data['data']['order_id'];
        } elseif (!empty($data['data']['click_action'])) {
            $action = $data['data']['click_action'];
        } else {
            $action = env('APP_URL')."/admin/dashboard";
        }
        
        $requiredData = [
            'notificationType'   => '1',
            'notification_type'  => '1',
            'notification_sound' => 'long',
        ];

        // Merge custom data
        $extraData = array_merge($data['data'] ?? [], [
            'click_action' => $action,
            'icon' => url('/storage/').$setting->logo,
        ]);
        $extraData = array_merge(
            $data['data'] ?? [],
            $requiredData, // enforce/ensure these keys exist in the data payload
            [
                'click_action' => $action,
                'icon'         => url('/storage/').$setting->logo,
            ]
        );

        // Platform configs
        $android = AndroidConfig::fromArray([
            'priority' => 'high',
            'notification' => [
                'sound' => 'default',
                // Icon can also go into android.notification if you prefer
            ],
        ]);

        $apns = ApnsConfig::fromArray([
            'headers' => ['apns-priority' => '10'],
            'payload' => ['aps' => [
                'sound' => 'last_sound.aiff',
                // 'mutable-content' => 1, // enable if you use a Notification Service Extension
            ]],
        ]);

        // ------------- TOPIC MODE -------------
        if ($isTopic) {
            $subscribeReport = null;

            // (Optional) subscribe all supplied tokens first
            if (!empty($tokens)) {
                $subscribeReport = $this->subscribeTokensToTopic($tokens, $topic);
            }
            

            // Send one message to the topic
            $message = CloudMessage::withTarget('topic', $topic)
                ->withNotification(Notification::create($title, $body))
                ->withData(array_map('strval', $extraData))
                ->withAndroidConfig($android)
                ->withApnsConfig($apns);

            $messageName = $this->messaging()->send($message);
            // dd($messageName);

            return response()->json([
                'message'         => 'Notification sent to topic',
                'is_topic'        => true,
                'topic'           => $topic,
                'subscribe_report'=> $subscribeReport,
                'message_name'    => $messageName,
            ]);
        }

        // ------------- TOKEN MODE -------------
        // Send to many tokens: you can either sendAll(...) or loop. We'll use sendAll for batching.
        $messages = [];
        foreach ($tokens as $t) {
            $messages[] = CloudMessage::withTarget('token', $t)
                ->withNotification(Notification::create($title, $body))
                ->withData(array_map('strval', $extraData))
                ->withAndroidConfig($android)
                ->withApnsConfig($apns);
        }

        $report = $this->messaging()->sendAll($messages); // Multicast send

        $results = [];
        foreach ($report->responses() as $i => $response) {
            $token = $tokens[$i] ?? null;
            $results[] = $response->isSuccess()
                ? ['token' => $token, 'ok' => true,  'message_name' => $response->messageId()]
                : ['token' => $token, 'ok' => false, 'error' => $response->error()->getMessage()];
        }

        $success = array_values(array_filter($results, function($r){
            return $r['ok'];
        }));
        $failed  = array_values(array_filter($results, function($r){
            return !$r['ok'];
        }));

        return response()->json([
            'message'        => 'Notifications processed (per-token)',
            'is_topic'       => false,
            'total'          => count($tokens),
            'success_count'  => count($success),
            'failure_count'  => count($failed),
            'results'        => $results,
        ]);
    }

    /**
     * Subscribe tokens to a topic using the Admin SDK (service account).
     * Returns an array with counts and any errors.
     */
    protected function subscribeTokensToTopic(array $tokens, string $topic): array
    {
        $tokens = array_column($tokens, 'token');
        if (empty($tokens)) {
            return ['success' => 0, 'failure' => 0, 'errors' => []];
        }

        $report = $this->messaging()->subscribeToTopic($topic, $tokens);
        return [
            'success' => count($report),
            'failure' => 0,
            'errors'  => []
        ];
    }

    /** Optional: Unsubscribe helper */
    protected function unsubscribeTokensFromTopic(array $tokens, string $topic): array
    {
        $tokens = array_values(array_filter(array_map('strval', $tokens)));
        if (empty($tokens)) {
            return ['success' => 0, 'failure' => 0, 'errors' => []];
        }

        $report = $this->messaging()->unsubscribeFromTopic($topic, $tokens);

        return [
            'success' => count($report),
            'failure' => 0,
            'errors'  => []
        ];
    }
}
