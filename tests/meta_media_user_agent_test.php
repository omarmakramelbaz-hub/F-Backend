<?php
// Exercise production cURL options with in-process transport doubles; no network or Laravel bootstrap.
namespace App\Services\Dashboard {
    function dns_get_record($host, $type) { return [['ip' => '1.1.1.1']]; }
    function curl_init($url) { return (object) ['url' => $url, 'options' => []]; }
    function curl_setopt_array($handle, $options) { $handle->options = $options; $GLOBALS['media_requests'][] = $handle; return true; }
    function curl_exec($handle) {
        $body = $GLOBALS['media_body'];
        return $handle->options[CURLOPT_WRITEFUNCTION]($handle, $body) === strlen($body);
    }
    function curl_getinfo($handle, $option) { return $option === CURLINFO_RESPONSE_CODE ? $GLOBALS['media_status'] : 'image/gif'; }
    function curl_close($handle) {}
}
namespace {
    final class MediaAgentAbort extends \RuntimeException {
        public function __construct(public int $status) { parent::__construct('SUPPRESSED'); }
    }
    function abort_unless($condition, $status, $message = '') { if (!$condition) throw new MediaAgentAbort($status); }
    require __DIR__ . '/../app/Services/Dashboard/MetaMediaService.php';
    require __DIR__ . '/../deployment/meta_media_diagnostic.php';
    $checks = 0;
    function agentCheck($condition, $label) { global $checks; $checks++; if (!$condition) throw new \RuntimeException($label); }
    $media_body = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
    $media_status = 200; $media_requests = [];
    $media = new \App\Services\Dashboard\MetaMediaService();
    foreach (['https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=fixture&signature=fixture',
        'https://lookaside.fbsbx.com/ig_messaging_cdn?asset_id=fixture'] as $url) {
        $file = $media->fetch(['type' => 'image', 'url' => $url]);
        $options = end($media_requests)->options;
        agentCheck($file['body'] === $media_body && $file['mime'] === 'image/gif', 'Verified image bytes retained');
        agentCheck($options[CURLOPT_USERAGENT] === \MetaMediaDiagnostic::BROWSER_USER_AGENT, 'Tested Instagram profile matches production');
        agentCheck($options[CURLOPT_FOLLOWLOCATION] === false && $options[CURLOPT_MAXREDIRS] === 0, 'Redirects stay prohibited');
        agentCheck($options[CURLOPT_SSL_VERIFYPEER] === true && $options[CURLOPT_SSL_VERIFYHOST] === 2, 'TLS verification retained');
        agentCheck($options[CURLOPT_PROXY] === '' && $options[CURLOPT_RESOLVE] === ['lookaside.fbsbx.com:443:1.1.1.1'], 'Public DNS pin and empty proxy retained');
        agentCheck($options[CURLOPT_TIMEOUT] === 20 && $options[CURLOPT_CONNECTTIMEOUT] === 5, 'Production time bounds retained');
    }
    foreach (['https://scontent.xx.fbcdn.net/photo', 'https://lookaside.fbsbx.com/messenger_attachment',
        'https://scontent.cdninstagram.com/photo', 'https://lookaside.fbsbx.com/ig_messaging_cdn/other'] as $url) {
        $media->fetch(['type' => 'image', 'url' => $url]);
        agentCheck(!isset(end($media_requests)->options[CURLOPT_USERAGENT]), 'Other attachment endpoints retain their existing headers');
    }
    $before = count($media_requests);
    try { $media->fetch(['type' => 'image', 'url' => 'https://www.facebook.com/unsupportedbrowser']); agentCheck(false, 'Unapproved source must fail'); }
    catch (MediaAgentAbort $error) { agentCheck($error->status === 404 && count($media_requests) === $before, 'Facebook pages remain outside the proxy'); }
    $media_status = 302;
    try { $media->fetch(['type' => 'image', 'url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/']); agentCheck(false, 'Redirect must still fail'); }
    catch (MediaAgentAbort $error) { agentCheck($error->status === 502 && count($media_requests) === $before + 1, 'Redirect is not followed'); }
    $media_status = 200; $media_body = '<html>Not an image</html>';
    try { $media->fetch(['type' => 'image', 'url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/']); agentCheck(false, 'HTML must fail'); }
    catch (MediaAgentAbort $error) { agentCheck($error->status === 415, 'MIME verification remains required'); }
    echo "meta-media-user-agent: $checks checks passed\n";
}
