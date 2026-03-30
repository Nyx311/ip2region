<?php
/**
 * 批量翻译 ip2region IPv6 源数据（OpenAI 兼容接口）
 */

function printHelp(string $script): void
{
    echo "php {$script} [options]\n";
    echo "options:\n";
    echo "  --input=PATH           输入文件，默认 data/ipv6_source.txt\n";
    echo "  --output=PATH          输出文件，默认 data/ipv6_source_zh.txt\n";
    echo "  --api-key=KEY          API Key（可用 OPENAI_API_KEY 环境变量）\n";
    echo "  --base-url=URL         OpenAI 兼容接口地址\n";
    echo "  --model=NAME           模型名，默认 qwen-mt-plus\n";
    echo "  --batch-size=N         每批唯一词条数，默认 200\n";
    echo "  --sleep-ms=N           每批请求后暂停毫秒，默认 1000\n";
    echo "  --flush-every=N        每 N 批落盘输出文件，默认 20\n";
    echo "  --cache-file=PATH      共享缓存文件，默认 data/region_translate_cache.json\n";
}

function parseArgs(array $argv): array
{
    $args = [
        'input' => 'data/ipv6_source.txt',
        'output' => 'data/ipv6_source_zh.txt',
        'api-key' => 'sk-v0kHd1fMd90QQ73QThbhoyVUqE2SBUUPSuatgb2XlFInWowX',
        'base-url' => 'https://api.gzcrtw.com/v1/chat/completions',
        'model' => 'mistral-medium-latest',
        'batch-size' => 100,
        'sleep-ms' => 1500,
        'flush-every' => 20,
        'cache-file' => 'data/region_translate_cache.json',
    ];

    array_shift($argv);
    foreach ($argv as $raw) {
        if ($raw === '--help' || $raw === '-h') {
            $args['help'] = true;
            continue;
        }

        if (strpos($raw, '--') !== 0) {
            continue;
        }

        $p = strpos($raw, '=');
        if ($p === false) {
            continue;
        }

        $k = substr($raw, 2, $p - 2);
        $v = substr($raw, $p + 1);
        if (array_key_exists($k, $args)) {
            $args[$k] = $v;
        }
    }

    $args['batch-size'] = max(1, (int)$args['batch-size']);
    $args['sleep-ms'] = max(0, (int)$args['sleep-ms']);
    $args['flush-every'] = max(1, (int)$args['flush-every']);

    return $args;
}

function isChinese(string $text): bool
{
    return preg_match('/[\x{4e00}-\x{9fff}]/u', $text) === 1;
}

function shouldTranslate(string $text): bool
{
    $text = trim($text);
    if ($text === '' || $text === '0') {
        return false;
    }

    if (isChinese($text)) {
        return false;
    }

    return true;
}

function chunkArray(array $arr, int $size): array
{
    $out = [];
    $tmp = [];
    foreach ($arr as $v) {
        $tmp[] = $v;
        if (count($tmp) >= $size) {
            $out[] = $tmp;
            $tmp = [];
        }
    }
    if (!empty($tmp)) {
        $out[] = $tmp;
    }
    return $out;
}

function requestTranslation(string $baseUrl, string $apiKey, string $model, array $terms): array
{
    $user = "你是地理位置与运营商词条翻译器。把英文词条翻译成简体中文。保持准确、简洁。\n\n";
    $user .= "请将以下 JSON 数组中的每个词条翻译为简体中文，并返回 JSON 对象（key 是原文，value 是译文）。\n";
    $user .= "规则：\n";
    $user .= "1) 只返回 JSON 对象，不要 markdown，不要解释。\n";
    $user .= "2) 专有名词可采用常见译名。\n";
    $user .= "3) ISP 名称可意译，保留品牌可读性。\n";
    $user .= "4) 原文为 Reserved 时翻译为 保留。\n\n";
    $user .= json_encode(array_values($terms), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $payload = [
        'model' => $model,
        'temperature' => 0,
        'messages' => [
            ['role' => 'user', 'content' => $user],
        ],
    ];

    $raw = null;
    $httpCode = 0;
    $lastErr = '';

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $ch = curl_init($baseUrl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 180);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw !== false && $httpCode >= 200 && $httpCode < 300) {
            break;
        }

        $retryableHttp = ($httpCode === 408 || $httpCode === 429 || $httpCode >= 500);
        $retryableCurl = ($raw === false);

        if (($retryableHttp || $retryableCurl) && $attempt < 3) {
            $waitMs = $attempt * 2000;
            echo "请求重试: 第 {$attempt} 次失败，{$waitMs}ms 后重试\n";
            usleep($waitMs * 1000);
            continue;
        }

        if ($raw === false) {
            $lastErr = 'HTTP 请求失败: ' . $curlErr;
        } else {
            $lastErr = "API 返回异常 HTTP {$httpCode}: {$raw}";
        }
        break;
    }

    if ($raw === false || $httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException($lastErr !== '' ? $lastErr : 'HTTP 请求失败');
    }


    $resp = json_decode($raw, true);
    if (!is_array($resp)) {
        throw new RuntimeException('API 响应不是合法 JSON');
    }

    $content = $resp['choices'][0]['message']['content'] ?? '';
    if (!is_string($content) || trim($content) === '') {
        throw new RuntimeException('API 响应中无可用内容');
    }

    $jsonText = trim($content);
    if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $jsonText, $m) === 1) {
        $jsonText = trim($m[1]);
    }

    $map = json_decode($jsonText, true);
    if (!is_array($map) && preg_match('/\{[\s\S]*\}/', $jsonText, $m) === 1) {
        $map = json_decode($m[0], true);
    }

    if (!is_array($map)) {
        throw new RuntimeException('模型输出不是 JSON 对象: ' . $content);
    }

    $out = [];
    foreach ($terms as $t) {
        if (isset($map[$t]) && is_string($map[$t]) && trim($map[$t]) !== '') {
            $out[$t] = trim($map[$t]);
        } else {
            $out[$t] = $t;
        }
    }

    return $out;
}

function translateChunkWithFallback(string $baseUrl, string $apiKey, string $model, array $terms): array
{
    if (count($terms) === 0) {
        return [];
    }

    try {
        return requestTranslation($baseUrl, $apiKey, $model, $terms);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        $jsonParseFailed = (strpos($msg, '模型输出不是 JSON 对象') !== false);
        if (!$jsonParseFailed || count($terms) <= 1) {
            throw $e;
        }

        $mid = intdiv(count($terms), 2);
        if ($mid < 1) {
            throw $e;
        }

        $left = array_slice($terms, 0, $mid);
        $right = array_slice($terms, $mid);
        echo "解析失败，自动拆分批次: " . count($terms) . " => " . count($left) . "+" . count($right) . "\n";

        $leftMap = translateChunkWithFallback($baseUrl, $apiKey, $model, $left);
        $rightMap = translateChunkWithFallback($baseUrl, $apiKey, $model, $right);
        return $leftMap + $rightMap;
    }
}

function loadLines(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException("读取文件失败: {$path}");
    }
    return $lines;
}

function saveCache(string $cachePath, array $cache): void
{
    file_put_contents($cachePath, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function flushOutputFile(array $lines, array $cache, string $output): bool
{
    $fp = fopen($output, 'w');
    if ($fp === false) {
        return false;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $parts = explode('|', $line);
        if (count($parts) >= 7) {
            foreach ([2, 3, 4, 5] as $idx) {
                $v = trim($parts[$idx]);
                if (shouldTranslate($v) && isset($cache[$v])) {
                    $parts[$idx] = $cache[$v];
                }
            }
            $line = implode('|', $parts);
        }

        if (fwrite($fp, $line . PHP_EOL) === false) {
            fclose($fp);
            return false;
        }
    }

    fclose($fp);
    return true;
}

function main(array $argv): int
{
    $args = parseArgs($argv);
    if (!empty($args['help'])) {
        printHelp($argv[0]);
        return 0;
    }

    $input = $args['input'];
    $output = $args['output'];
    $apiKey = (string)$args['api-key'];
    $baseUrl = (string)$args['base-url'];
    $model = (string)$args['model'];
    $batchSize = (int)$args['batch-size'];
    $sleepMs = (int)$args['sleep-ms'];
    $flushEvery = (int)$args['flush-every'];
    $cacheFile = (string)$args['cache-file'];

    if (!is_file($input)) {
        fwrite(STDERR, "输入文件不存在: {$input}\n");
        return 1;
    }

    if ($apiKey === '') {
        fwrite(STDERR, "缺少 API Key，请设置 --api-key 或 OPENAI_API_KEY\n");
        return 1;
    }

    $lines = loadLines($input);

    $cachePath = $cacheFile !== '' ? $cacheFile : ($output . '.cache.json');
    $cache = [];
    if (is_file($cachePath)) {
        $cached = json_decode((string)file_get_contents($cachePath), true);
        if (is_array($cached)) {
            $cache = $cached;
        }
    }

    $need = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $parts = explode('|', $line);
        if (count($parts) < 7) {
            continue;
        }

        foreach ([2, 3, 4, 5] as $idx) {
            $v = trim($parts[$idx]);
            if (shouldTranslate($v) && !isset($cache[$v])) {
                $need[$v] = true;
            }
        }
    }

    $needTerms = array_keys($need);
    $chunks = chunkArray($needTerms, $batchSize);

    $done = 0;
    $total = count($needTerms);
    $failed = false;
    $failReason = '';
    $batchNo = 0;

    foreach ($chunks as $chunk) {
        $batchNo++;
        try {
            $map = translateChunkWithFallback($baseUrl, $apiKey, $model, $chunk);
        } catch (RuntimeException $e) {
            $failed = true;
            $failReason = $e->getMessage();
            break;
        }

        foreach ($map as $k => $v) {
            $cache[$k] = $v;
            $done++;
        }

        saveCache($cachePath, $cache);

        echo "翻译进度: {$done}/{$total}\n";

        if ($batchNo % $flushEvery === 0) {
            $ok = flushOutputFile($lines, $cache, $output);
            if (!$ok) {
                fwrite(STDERR, "落盘失败: {$output}\n");
                return 1;
            }
            echo "已落盘: 批次 {$batchNo}，输出文件 {$output}\n";
        }

        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }
    }

    if ($failed) {
        $ok = flushOutputFile($lines, $cache, $output);
        if (!$ok) {
            fwrite(STDERR, "失败时落盘失败: {$output}\n");
            return 1;
        }
        fwrite(STDERR, "中断: {$failReason}\n");
        fwrite(STDERR, "失败时已落盘当前进度。\n");
        fwrite(STDERR, "输出文件: {$output}\n");
        fwrite(STDERR, "缓存文件: {$cachePath}\n");
        return 1;
    }

    $ok = flushOutputFile($lines, $cache, $output);
    if (!$ok) {
        fwrite(STDERR, "写入输出文件失败: {$output}\n");
        return 1;
    }

    echo "完成。\n";
    echo "输出文件: {$output}\n";
    echo "缓存文件: {$cachePath}\n";

    return 0;
}

exit(main($argv));
