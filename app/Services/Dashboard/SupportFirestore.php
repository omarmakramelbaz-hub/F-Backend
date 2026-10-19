<?php

namespace App\Services\Dashboard;

use Google\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** The same Firestore project used by the customer app and dashboard SDK. */
class SupportFirestore
{
    protected function token(): string
    {
        $path = config('firebase.credentials', storage_path('app/firebase_credentials.json'));
        $key = 'dashboard-support-firestore-token-'.hash('sha256', $this->project().'|'.(is_string($path) ? $path : '').'|'.(is_string($path) && is_file($path) ? filemtime($path) : 0));
        return Cache::remember($key, now()->addMinutes(45), function () {
            $path = config('firebase.credentials', storage_path('app/firebase_credentials.json'));
            if (!is_string($path) || !is_file($path)) throw new RuntimeException('Support credentials unavailable.');
            $client = new Client();
            $client->setHttpClient(new \GuzzleHttp\Client(['connect_timeout' => 4, 'timeout' => 12]));
            $client->setAuthConfig($path);
            $client->addScope('https://www.googleapis.com/auth/datastore');
            $result = $client->fetchAccessTokenWithAssertion();
            if (empty($result['access_token'])) throw new RuntimeException('Support authentication unavailable.');
            return $result['access_token'];
        });
    }

    public function project(): string
    {
        $project = (string) config('services.fcm.project_id');
        if (!preg_match('/^[a-z][a-z0-9-]{3,62}$/D', $project)) throw new RuntimeException('Support project unavailable.');
        return $project;
    }

    public function base(): string
    {
        return 'https://firestore.googleapis.com/v1/projects/'.$this->project().'/databases/(default)/documents';
    }

    protected function request(string $method, string $path, array $data = []): array
    {
        $request = Http::withToken($this->token())->acceptJson()->withOptions(['connect_timeout' => 4])->timeout(12);
        $response = $method === 'GET' ? $request->get($this->base().$path, $data)
            : $request->send($method, $this->base().$path, ['json' => $data]);
        if (!$response->successful()) throw new RuntimeException('Support service unavailable.');
        $result = $response->json();
        if (!is_array($result)) throw new RuntimeException('Support response unavailable.');
        return $result;
    }

    public function rooms(int $inbox): array
    {
        $rooms = [];
        foreach ([['stringValue' => (string) $inbox], ['integerValue' => (string) $inbox]] as $value) {
            $data = $this->request('POST', ':runQuery', ['structuredQuery' => [
                'from' => [['collectionId' => 'DashboardChat']],
                'where' => ['fieldFilter' => ['field' => ['fieldPath' => 'users'], 'op' => 'ARRAY_CONTAINS', 'value' => $value]],
                'limit' => 10000,
            ]]);
            if (count($data) >= 10000) throw new RuntimeException('Support inbox too large to count completely.');
            foreach ($data as $row) if (isset($row['document']['name'])) $rooms[$row['document']['name']] = $row['document'];
        }
        return array_values($rooms);
    }

    public function messages(string $room, string $page = ''): array
    {
        return $this->request('GET', '/DashboardChat/'.$room.'/messages', [
            'pageSize' => 100, 'pageToken' => $page, 'orderBy' => 'timestamp desc',
        ]);
    }

    public function message(string $room, string $id): array
    {
        return $this->request('GET', '/DashboardChat/'.$room.'/messages/'.$id);
    }

    public function markRead(string $room, string $id, array $document, int $actor): void
    {
        $path = '/DashboardChat/'.$room.'/messages/'.$id;
        $mask = '?updateMask.fieldPaths=dashboard_support_read_at&updateMask.fieldPaths=dashboard_support_read_by';
        if (!empty($document['updateTime'])) $mask .= '&currentDocument.updateTime='.rawurlencode($document['updateTime']);
        $this->request('PATCH', $path.$mask, ['fields' => [
            'dashboard_support_read_at' => ['timestampValue' => now()->toIso8601ZuluString()],
            'dashboard_support_read_by' => ['integerValue' => (string) $actor],
        ]]);
    }

    public function batchMessages(string $room, array $ids): array
    {
        $prefix = 'projects/'.$this->project().'/databases/(default)/documents/DashboardChat/'.$room.'/messages/';
        $data = $this->request('POST', ':batchGet', ['documents' => array_map(fn ($id) => $prefix.$id, $ids)]);
        $documents = [];
        foreach ($data as $row) if (isset($row['found'])) $documents[] = $row['found'];
        return $documents;
    }

    public function markReads(string $room, array $documents, int $actor): void
    {
        $prefix = 'projects/'.$this->project().'/databases/(default)/documents/DashboardChat/'.$room.'/messages/';
        $writes = [];
        foreach ($documents as $document) {
            if (!str_starts_with($document['name'] ?? '', $prefix) || empty($document['updateTime'])) throw new RuntimeException('Support message changed.');
            $writes[] = [
                'update' => ['name' => $document['name'], 'fields' => [
                    'dashboard_support_read_at' => ['timestampValue' => now()->toIso8601ZuluString()],
                    'dashboard_support_read_by' => ['integerValue' => (string) $actor],
                ]],
                'updateMask' => ['fieldPaths' => ['dashboard_support_read_at', 'dashboard_support_read_by']],
                'currentDocument' => ['updateTime' => $document['updateTime']],
            ];
        }
        if ($writes) $this->request('POST', ':commit', ['writes' => $writes]);
    }

    public function send(string $room, array $fields, string $id): array
    {
        $prefix = 'projects/'.$this->project().'/databases/(default)/documents/DashboardChat/'.$room;
        $roomFields = [
                'roomId' => ['stringValue' => $room],
                'users' => ['arrayValue' => ['values' => [
                    ['stringValue' => $fields['sender_id']['stringValue']],
                    ['stringValue' => $fields['user_id']['stringValue']],
                ]]],
                'lastMessageTimestamp' => $fields['timestamp'],
        ];
        // A stable request UUID and one atomic commit prevent a retry from duplicating a message.
        try {
            $this->request('POST', ':commit', ['writes' => [
                ['update' => ['name' => $prefix.'/messages/'.$id, 'fields' => $fields], 'currentDocument' => ['exists' => false]],
                ['update' => ['name' => $prefix, 'fields' => $roomFields],
                    'updateMask' => ['fieldPaths' => ['roomId', 'users', 'lastMessageTimestamp']]],
            ]]);
        } catch (RuntimeException $error) {
            $existing = $this->message($room, $id);
            foreach (['message', 'sender_id', 'user_id', 'dashboard_sender_admin_id'] as $field) {
                if (($existing['fields'][$field] ?? null) !== ($fields[$field] ?? null)) throw $error;
            }
            return $existing;
        }
        return ['name' => $prefix.'/messages/'.$id, 'fields' => $fields];
    }

    public static function value(array $document, string $field)
    {
        $value = $document['fields'][$field] ?? [];
        return $value['stringValue'] ?? $value['integerValue'] ?? $value['timestampValue'] ?? null;
    }
}
