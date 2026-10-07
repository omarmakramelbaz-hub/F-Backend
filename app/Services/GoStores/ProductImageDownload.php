<?php

namespace App\Services\GoStores;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\ResponseInterface;

/** Download bounded raster bytes, with DNS pinned to a validated public IPv4 address. */
class ProductImageDownload
{
    public function fetch(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || ($parts['port'] ?? 443) !== 443
            || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/D', $host)) {
            throw new \RuntimeException('Invalid image host');
        }
        $ips = gethostbynamel($host) ?: [];
        if (!$ips) throw new \RuntimeException('Image DNS unavailable');
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException('Non-public image host');
            }
        }
        $client = new Client(['handler' => HandlerStack::create(new CurlHandler())]);
        $response = $client->get($url, [
            'allow_redirects' => false, 'connect_timeout' => 4, 'timeout' => 8, 'stream' => true,
            'proxy' => '', 'verify' => true,
            'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$ips[0]], CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
            'on_headers' => function (ResponseInterface $response) {
                if ($response->getStatusCode() !== 200 || (int) $response->getHeaderLine('Content-Length') > 5 * 1024 * 1024) {
                    throw new \RuntimeException('Image rejected');
                }
            },
        ]);
        $body = $response->getBody();
        $bytes = '';
        try {
            while (!$body->eof()) {
                $bytes .= $body->read(65536);
                if (strlen($bytes) > 5 * 1024 * 1024) throw new \RuntimeException('Image too large');
            }
        } finally {
            $body->close();
        }
        $size = @getimagesizefromstring($bytes);
        if (!$size || !in_array($size['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
            || min($size[0], $size[1]) < 128 || max($size[0], $size[1]) > 4096) {
            throw new \RuntimeException('Invalid raster image');
        }
        // Decode/re-encode to remove metadata and ensure that exactly these pixels are inspected and saved.
        $image = @imagecreatefromstring($bytes);
        if (!$image) throw new \RuntimeException('Image decode failed');
        $scale = min(1, 1000 / max($size[0], $size[1]));
        $canvas = imagecreatetruecolor(max(1, (int) ($size[0] * $scale)), max(1, (int) ($size[1] * $scale)));
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas), $size[0], $size[1]);
        ob_start();
        imagejpeg($canvas, null, 85);
        $jpeg = ob_get_clean();
        imagedestroy($image);
        imagedestroy($canvas);
        if (!$jpeg) throw new \RuntimeException('Image encode failed');
        return $jpeg;
    }
}
