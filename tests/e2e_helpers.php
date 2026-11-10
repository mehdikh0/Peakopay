<?php
function http_req(string $method, string $url, ?string $body = null, array $headers = [], ?string &$cookies = null): array
{
    $ch = curl_init($url);
    $hdr = ['Accept: application/json'];
    foreach ($headers as $k => $v) {
        $hdr[] = (is_int($k) ? $v : $k . ': ' . $v);
    }
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $hdr,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($cookies !== null) {
        $opts[CURLOPT_COOKIE] = $cookies;
    }
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
        $hasCT = false;
        foreach ($hdr as $h) {
            if (stripos((string) $h, 'content-type:') === 0) {
                $hasCT = true;
            }
        }
        if (!$hasCT) {
            $hdr[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $hdr;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        return ['status' => 0, 'body' => 'curl error: ' . curl_error($ch), 'headers' => ''];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr((string) $raw, 0, $hsize);
    $respBody = substr((string) $raw, $hsize);
    if ($cookies !== null && preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $head, $m)) {
        foreach ($m[1] as $c) {
            $parts = explode('=', $c, 2);
            $jar[$parts[0]] = $parts[1] ?? '';
        }
        $cookies = implode('; ', array_map(static fn ($k, $v) => $k . '=' . $v, array_keys($jar ?? []), $jar ?? []));
    }
    return ['status' => $status, 'body' => $respBody, 'headers' => $head];
}

function extract_csrf(string $html): string
{
    if (preg_match('#name="_csrf" value="([^"]+)"#', $html, $m)) {
        return html_entity_decode($m[1]);
    }
    if (preg_match('#name="_csrf"\s+value="([^"]+)"#s', $html, $m)) {
        return $m[1];
    }
    return '';
}

function form_post(string $url, array $fields, string &$cookies, ?string $csrfUrl = null): array
{
    $html = http_req('GET', $csrfUrl ?? $url, cookies: $cookies)['body'];
    $fields['_csrf'] = extract_csrf($html);
    $body = http_build_query($fields);
    return http_req('POST', $url, $body, ['Content-Type' => 'application/x-www-form-urlencoded'], $cookies);
}

function device_headers(int $deviceId, string $secret, string $jsonBody): array
{
    $ts = (string) time();
    return [
        'X-Peakopay-Device'    => (string) $deviceId,
        'X-Peakopay-Timestamp' => $ts,
        'X-Peakopay-Signature' => hash_hmac('sha256', $ts . '.' . $jsonBody, $secret),
    ];
}

function mock_events(): array
{
    $f = sys_get_temp_dir() . '/hp_mock_woo.log';
    if (!is_file($f)) {
        return [];
    }
    return array_map(static fn ($l) => json_decode($l, true) ?: [], array_values(array_filter(explode("\n", trim((string) file_get_contents($f))))));
}

