<?php
declare(strict_types=1);
class Http {
    function request(string $method, string $path, string $token, ?array $body=null): array {
        $c=curl_init('https://api.hetzner.cloud/v1'.$path);
        curl_setopt_array($c,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>45,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json'],CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
        if ($body!==null) { curl_setopt($c,CURLOPT_POSTFIELDS,json($body)); }
        $raw=curl_exec($c); $status=curl_getinfo($c,CURLINFO_RESPONSE_CODE); curl_close($c);
        if ($raw===false || !$status) { throw new RuntimeException('Provider transport ambiguous'); }
        if ($status===429) { throw new RuntimeException('Provider rate limited; reconcile before retry'); }
        if ($status<200 || $status>=300) { throw new RuntimeException('Provider HTTP '.$status); }
        return $raw==='' ? [] : json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    }
}
