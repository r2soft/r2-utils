<?php
declare(strict_types=1);

/**
 * Debug moderno (à prova de OOM):
 * - Header com label, arquivo:linha (EXATA do callsite), tempo e memória
 * - Abas: Variável (default), Percurso, Trecho, Server
 * - Botões de copiar (JSON, texto, stack, trecho) com limite
 * - Trecho de arquivo com highlight DA LINHA ONDE debug() FOI CHAMADO
 * - Saída CLI limitada (sem estourar memória)
 *
 * Ajustes por ENV:
 *   R2DBG_MAX_DEPTH, R2DBG_MAX_ITEMS, R2DBG_MAX_STRING, R2DBG_MAX_CLIP_BYTES
 */

if (!function_exists('debug')) {
    function debug(mixed $variavel, bool $stop = true, ?string $label = ''): void
    {
        $isCli = (PHP_SAPI === 'cli');
        $cfg   = r2dbg_cfg();

        // Backtrace bruto (sem args) e "origem": frame do próprio debug (self)
        $btAll = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        [$caller, $btTailFromOrigin] = r2dbg_pick_origin('self', $btAll);
        $bt = r2dbg_clean_trace($btTailFromOrigin);

        // Origem & tempo/memória
        $fileAbs = $caller['file'] ?? 'unknown';
        $line    = (int)($caller['line'] ?? 0);
        $fileRel = shorten_path($fileAbs);

        $inicio = $_SERVER['REQUEST_TIME'];
        $fim = microtime(true);
        $total = $fim - $inicio;
        $elapsedMs = number_format($total, 5);
        $start = $_SERVER['REQUEST_TIME_FLOAT'] ?? ($_SERVER['REQUEST_TIME'] ?? microtime(true));
//        $elapsedMs = number_format((microtime(true) - (float)$start) * 1000, 2, '.', '');
        $memNow  = format_bytes(memory_get_usage(true));
        $memPeak = format_bytes(memory_get_peak_usage(true));

        // Representações para copiar (LIMITADAS)
        $jsonLimited = json_safe_limited($variavel, $cfg);
        [$b64Json]   = r2dbg_make_clip($jsonLimited, $cfg['maxClipboardBytes']);
        $stackText   = format_stack_text($bt);
        [$b64Stack]  = r2dbg_make_clip($stackText,  $cfg['maxClipboardBytes']);

        if ($isCli) {
            // ===== CLI =====
            $hdr = sprintf(
                "[DEBUG]%s %s:%d  ⏱ %sms  📦 %s (peak %s)\n",
                $label ? " {$label}" : '',
                $fileRel,
                $line,
                $elapsedMs,
                $memNow,
                $memPeak
            );
            if (defined('STDERR')) { fwrite(STDERR, $hdr); } else { echo $hdr; }

            $cliDump = r2dbg_dump_text_limited($variavel, $cfg);
            $stackBlock = "-> Percurso:\n" . $stackText . "\n";

            if (defined('STDOUT')) { fwrite(STDOUT, $cliDump . PHP_EOL . $stackBlock); }
            else { echo $cliDump . PHP_EOL . $stackBlock; }

            if ($stop) { exit(1); }
        }

        // ===== HTML =====
        $id = 'r2dbg_' . bin2hex(random_bytes(6));
        [$snippetHtml, $snippetRaw] = read_source_snippet($fileAbs, $line, 6);
        [$b64Src] = r2dbg_make_clip($snippetRaw, $cfg['maxClipboardBytes']);
        $phpLimited = r2dbg_export_php_limited($variavel, $cfg);
        [$b64Php]   = r2dbg_make_clip($phpLimited, $cfg['maxClipboardBytes']);

        inject_r2_debug_assets_once();

        $labelHTML = htmlspecialchars($label ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fileHTML  = htmlspecialchars($fileRel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // desabilita botões quando > limite (string vazia = desativado pelo JS)
        $dJson  = $b64Json  ? '' : ' disabled title="Muito grande para copiar"';
        $dStack = $b64Stack ? '' : ' disabled title="Muito grande para copiar"';
        $dSrc   = $b64Src   ? '' : ' disabled title="Muito grande para copiar"';
        $dPhp  = $b64Php  ? '' : ' disabled title="Muito grande para copiar"';


        echo <<<HTML
<div id="{$id}" class="r2dbg" data-r2dbg>
  <div class="r2dbg__header">
    <div class="r2dbg__title">
      <strong>⚙️ R2Debug</strong>
      <span class="r2dbg__label">{$labelHTML}</span>
    </div>
    <div class="r2dbg__meta">
      <span class="r2dbg__crumbs">{$fileHTML}:<b>{$line}</b></span>
      <span>• ⏱ {$elapsedMs} ms</span>
      <span>• 📦 {$memNow} (peak {$memPeak})</span>
    </div>
    <div class="r2dbg__actions">
      <button class="r2dbg__btn" data-r2clip="{$b64Json}" {$dJson}>Copiar JSON</button>
      <button class="r2dbg__btn" data-r2clip="{$b64Php}"  {$dPhp}>Copiar PHP</button>
      <button class="r2dbg__btn" data-r2clip="{$b64Stack}" {$dStack}>Copiar stack</button>
      <button class="r2dbg__btn" data-r2clip="{$b64Src}" {$dSrc}>Copiar trecho</button>
    </div>
  </div>

  <div class="r2dbg__tabs" role="tablist" aria-label="Debug Tabs">
    <button class="r2dbg__tab is-active" aria-selected="true" data-tab="dump"   role="tab">Variável</button>
    <button class="r2dbg__tab"           aria-selected="false" data-tab="stack"  role="tab">Percurso</button>
    <button class="r2dbg__tab"           aria-selected="false" data-tab="source" role="tab">Trecho</button>
    <button class="r2dbg__tab"           aria-selected="false" data-tab="server" role="tab">Server</button>
  </div>

  <div class="r2dbg__panes">
    <section class="r2dbg__pane is-active" data-pane="dump">
      <div class="r2dbg__dump">
HTML;

        // Dump HTML LIMITADO
        echo r2dbg_dump_html_limited($variavel, $cfg);

        // Stack visual + breadcrumb
        $stackItemsHtml = format_stack_html($bt);
        $breadcrumb     = format_stack_breadcrumb($bt);

        // Server info (safe)
        $serverSafe = htmlspecialchars(var_export([
            'GET'     => $_GET ?? null,
            'POST'    => $_POST ?? null,
            'COOKIE'  => $_COOKIE ?? null,
            'SESSION' => isset($_SESSION) ? $_SESSION : null,
            'SERVER'  => $_SERVER ?? null,
        ], true), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        echo <<<HTML
      </div>
    </section>

    <section class="r2dbg__pane" data-pane="stack">
      <div class="r2dbg__breadcrumb" title="Percurso até o debug">{$breadcrumb}</div>
      <ol class="r2dbg__stack">
        {$stackItemsHtml}
      </ol>
    </section>

    <section class="r2dbg__pane" data-pane="source">
      <div class="r2dbg__source">
        {$snippetHtml}
      </div>
    </section>

    <section class="r2dbg__pane" data-pane="server">
      <pre class="r2dbg__pre">{$serverSafe}</pre>
    </section>
  </div>
</div>
HTML;
        if ($stop) {  die(); }
    }
}

if (!function_exists('r2dbg_export_php_limited')) {
    function r2dbg_export_php_limited(mixed $value, array $cfg): string
    {
        // Limita antes para evitar OOM e tornar objetos exportáveis
        $preview = r2dbg_limit_value($value, $cfg['maxDepth'], $cfg['maxItems'], $cfg['maxString']);

        try {
            $code = var_export($preview, true); // array(...) válido em PHP
        } catch (Throwable) {
            $code = '/* não exportável em PHP */';
        }

        // Mesma política de corte dos outros dumps
        if (strlen($code) > $cfg['maxClipboardBytes']) {
            $code = substr($code, 0, $cfg['maxClipboardBytes'] - 64) . "\n/* … [TRUNCADO] */";
        }

        return $code;
    }
}


/**
 * Versão "bruta" reaproveitando a UI (var_dump capturado)
 */
if (!function_exists('debug_pre')) {
    function debug_pre(mixed $variavel, bool $stop = true, ?string $label = 'var_dump'): void
    {
        ob_start();
        var_dump($variavel);
        $raw = ob_get_clean() ?: '';
        debug($raw, $stop, $label);
    }
}

/* --------------- Config/limites e dumps limitados --------------- */

if (!function_exists('r2dbg_cfg')) {
    function r2dbg_cfg(): array
    {
        return [
            'maxDepth'          => 300,
            'maxItems'          =>  2000000,
            'maxString'         => 800000,
            'maxClipboardBytes' => (int)($_ENV['R2DBG_MAX_CLIP_BYTES']     ?? getenv('R2DBG_MAX_CLIP_BYTES')     ?: 262144), // 256 KiB
        ];
    }
}

if (!function_exists('r2dbg_make_clip')) {
    /** @return array{0:string,1:bool} base64 e se foi truncado */
    function r2dbg_make_clip(string $text, int $maxBytes): array
    {
        if ($text === '') return ['', false];
        if (strlen($text) > $maxBytes) {
            $cut = substr($text, 0, $maxBytes - 64) . "\n… [TRUNCADO para clipboard]";
            return [base64_encode($cut), true];
        }
        return [base64_encode($text), false];
    }
}

if (!function_exists('r2dbg_dump_text_limited')) {
    function r2dbg_dump_text_limited(mixed $value, array $cfg): string
    {
        $preview = r2dbg_limit_value($value, $cfg['maxDepth'], $cfg['maxItems'], $cfg['maxString']);

        if (class_exists(\Symfony\Component\VarDumper\Cloner\VarCloner::class)
            && class_exists(\Symfony\Component\VarDumper\Dumper\CliDumper::class)) {

            $cloner = new \Symfony\Component\VarDumper\Cloner\VarCloner();
            if (method_exists($cloner, 'setMaxItems'))  { $cloner->setMaxItems($cfg['maxItems']); }
            if (method_exists($cloner, 'setMaxString')) { $cloner->setMaxString($cfg['maxString']); }

            $dumper = new \Symfony\Component\VarDumper\Dumper\CliDumper();
            $dumper->setColors(false);

            ob_start();
            $dumper->dump($cloner->cloneVar($preview));
            $out = (string)ob_get_clean();

            if (strlen($out) > $cfg['maxClipboardBytes']) {
                $out = substr($out, 0, $cfg['maxClipboardBytes'] - 64) . "\n… [TRUNCADO]";
            }
            return $out;
        }

        $text = print_r($preview, true);
        if (strlen($text) > $cfg['maxClipboardBytes']) {
            $text = substr($text, 0, $cfg['maxClipboardBytes'] - 64) . "\n… [TRUNCADO]";
        }
        return $text;
    }
}

if (!function_exists('r2dbg_dump_html_limited')) {
    function r2dbg_dump_html_limited(mixed $value, array $cfg): string
    {
        $preview = r2dbg_limit_value($value, $cfg['maxDepth'], $cfg['maxItems'], $cfg['maxString']);

        if (class_exists(\Symfony\Component\VarDumper\Cloner\VarCloner::class)
            && class_exists(\Symfony\Component\VarDumper\Dumper\HtmlDumper::class)) {

            $cloner = new \Symfony\Component\VarDumper\Cloner\VarCloner();
            if (method_exists($cloner, 'setMaxItems'))  { $cloner->setMaxItems($cfg['maxItems']); }
            if (method_exists($cloner, 'setMaxString')) { $cloner->setMaxString($cfg['maxString']); }

            $dumper = new \Symfony\Component\VarDumper\Dumper\HtmlDumper();
            if (method_exists($dumper, 'setStyles')) { $dumper->setStyles([]); }

            ob_start();
            $dumper->dump($cloner->cloneVar($preview));
            return (string)ob_get_clean();
        }

        $safe = htmlspecialchars(print_r($preview, true), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return "<pre class=\"r2dbg__pre\">{$safe}</pre>";
    }
}

if (!function_exists('json_safe_limited')) {
    function json_safe_limited(mixed $value, array $cfg): string
    {
        $preview = r2dbg_limit_value($value, $cfg['maxDepth'], $cfg['maxItems'], $cfg['maxString']);
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;
        try {
            $json = json_encode($preview, $flags);
            return is_string($json) ? $json : '"<não serializável em JSON>"';
        } catch (Throwable) {
            return '"<não serializável em JSON>"';
        }
    }
}

if (!function_exists('r2dbg_limit_value')) {
    /**
     * Reduz estruturas: limita profundidade, itens e string size. Evita recursão.
     */
    function r2dbg_limit_value(mixed $v, int $depth, int $maxItems, int $maxString, \SplObjectStorage $seen = null)
    {
        if ($depth <= 0) {
            if (is_object($v)) return sprintf('object(%s){…}', get_class($v));
            if (is_array($v))  return 'array{…}';
            if (is_string($v)) return (strlen($v) > $maxString) ? (substr($v, 0, $maxString) . '…') : $v;
            return $v;
        }

        if (is_string($v)) {
            return (strlen($v) > $maxString) ? (substr($v, 0, $maxString) . '…') : $v;
        }

        if (is_array($v)) {
            $out = [];
            $i = 0;
            foreach ($v as $k => $item) {
                if ($i++ >= $maxItems) {
                    $out['…'] = sprintf('(+%d itens)', max(0, count($v) - ($i - 1)));
                    break;
                }
                $out[$k] = r2dbg_limit_value($item, $depth - 1, $maxItems, $maxString, $seen);
            }
            return $out;
        }

        if (is_object($v)) {
            if ($seen === null) $seen = new \SplObjectStorage();
            if ($seen->contains($v)) {
                return sprintf('object(%s)#%s (recursivo)', get_class($v), spl_object_id($v));
            }
            $seen->attach($v);

            if ($v instanceof \JsonSerializable) {
                $data = $v->jsonSerialize();
            } elseif ($v instanceof \Traversable) {
                $data = [];
                $i = 0;
                foreach ($v as $k => $item) {
                    if ($i++ >= $maxItems) { $data['…'] = '(+mais itens)'; break; }
                    $data[$k] = $item;
                }
            } else {
                $data = get_object_vars($v);
            }

            $out = ['__class__' => get_class($v)];
            $i = 0;
            foreach ($data as $k => $item) {
                if ($i++ >= $maxItems) { $out['…'] = '(+mais props)'; break; }
                $out[$k] = r2dbg_limit_value($item, $depth - 1, $maxItems, $maxString, $seen);
            }
            return $out;
        }

        if (is_resource($v)) {
            return sprintf('resource(%s)', get_resource_type($v));
        }

        return $v;
    }
}

/* --------------- helpers gerais --------------- */

if (!function_exists('format_bytes')) {
    function format_bytes(int $bytes): string
    {
        $units = ['B','KB','MB','GB','TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
        return number_format($bytes, $i === 0 ? 0 : 2, ',', '.') . ' ' . $units[$i];
    }
}

if (!function_exists('r2dbg_starts_with')) {
    function r2dbg_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('shorten_path')) {
    function shorten_path(?string $path): string
    {
        if (!$path) return 'unknown';
        $bases = array_filter([
            $_SERVER['DOCUMENT_ROOT'] ?? null,
            getcwd() ?: null,
            dirname(__DIR__, 2),
            dirname(__FILE__, 3),
        ]);
        foreach ($bases as $base) {
            $base = rtrim((string)$base, DIRECTORY_SEPARATOR);
            if ($base && r2dbg_starts_with($path, $base)) {
                return ltrim(substr($path, strlen($base)), DIRECTORY_SEPARATOR);
            }
        }
        return $path;
    }
}

/* --------------- stack & origem --------------- */

if (!function_exists('r2dbg_clean_trace')) {
    function r2dbg_clean_trace(array $bt): array
    {
        $out = [];
        foreach ($bt as $f) {
            if (!empty($f['file'])) {
                $scheme = parse_url($f['file'], PHP_URL_SCHEME);
                if ($scheme === null || $scheme === 'file') {
                    $rp = @realpath($f['file']);
                    if ($rp) $f['file'] = $rp;
                }
            }
            $out[] = $f;
        }
        return $out;
    }
}

if (!function_exists('r2dbg_pick_origin')) {
    /**
     * Pega a “origem” conforme o modo:
     *  - 'self'     => frame 0 (o próprio debug)
     *  - 'deepest'  => o frame mais profundo com file/line (entrada)
     *  - default    => primeiro frame com file/line após o frame 0
     *
     * @return array{0:array,1:array} [$frameEscolhido, $btDesdeEle]
     */
    function r2dbg_pick_origin(string $mode, array $btAll): array
    {
        $n = count($btAll);
        if ($n === 0) return [['file'=>'unknown','line'=>0], []];

        $pickSelf = fn() => [$btAll[0], $btAll];

        $pickDeepest = function() use ($btAll, $n) {
            for ($i = $n - 1; $i >= 0; $i--) {
                if (!empty($btAll[$i]['file']) && !empty($btAll[$i]['line'])) {
                    return [$btAll[$i], array_slice($btAll, $i)];
                }
            }
            return [$btAll[$n-1], [$btAll[$n-1]]];
        };

        if ($mode === 'self') return $pickSelf();
        if ($mode === 'deepest') return $pickDeepest();

        for ($i = 1; $i < $n; $i++) {
            if (!empty($btAll[$i]['file']) && !empty($btAll[$i]['line'])) {
                return [$btAll[$i], array_slice($btAll, $i)];
            }
        }
        return $pickSelf();
    }
}

/* --------------- leitura de fonte (trecho) --------------- */

if (!function_exists('r2dbg_resolve_path')) {
    function r2dbg_resolve_path(?string $file): ?string
    {
        if (!$file) return null;

        if (@is_file($file) && @is_readable($file)) return $file;

        $scheme = parse_url($file, PHP_URL_SCHEME);
        if ($scheme === null || $scheme === 'file') {
            $rp = @realpath($file);
            if ($rp && @is_readable($rp)) return $rp;

            $inc = @stream_resolve_include_path($file);
            if ($inc && @is_readable($inc)) return $inc;
        }

        if ($scheme === 'phar' && @is_readable($file)) return $file;

        $alt = getcwd() ? (getcwd() . DIRECTORY_SEPARATOR . ltrim($file, DIRECTORY_SEPARATOR)) : null;
        if ($alt && @is_readable($alt)) return $alt;

        return null;
    }
}

if (!function_exists('read_source_snippet')) {
    /**
     * Retorna [html, raw] de um trecho do arquivo em torno de $line.
     * Agora com syntax highlight estilo PhpStorm Dark (Darcula).
     * @return array{0:string,1:string}
     */
    function read_source_snippet(string $file, int $line, int $radius = 6): array
    {
        $resolved = r2dbg_resolve_path($file);

        if (!$resolved || $line <= 0 || !is_readable($resolved)) {
            $fileSafe = htmlspecialchars((string) $file, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $msg = '<div class="r2dbg__muted">Fonte indisponível: <code>' . $fileSafe . '</code></div>';
            return [$msg, "Fonte indisponível: {$file}\n"];
        }

        $start = max(1, $line - $radius);
        $end = $line + $radius;

        $f = new SplFileObject($resolved, 'r');
        $f->setFlags(SplFileObject::DROP_NEW_LINE);
        $f->seek($start - 1); // Spl é 0-based

        // tabela com skin PhpStorm
        $html = '<table class="r2dbg__code phpstorm" data-lang="php">';
        $raw = '';

        $currentLineNumber = $start;
        while (!$f->eof() && $currentLineNumber <= $end) {
            $ln = (string) $f->current();
            $lnHighlighted = phpstorm_highlight_php_line($ln);

            $active = $currentLineNumber === $line ? ' class="is-hl"' : '';
            $html .= "<tr{$active}>"
                . "<td class=\"r2dbg__gutter\">{$currentLineNumber}</td>"
                . "<td class=\"r2dbg__line\"><code>{$lnHighlighted}</code></td>"
                . '</tr>';

            $raw .= sprintf("%-5d %s\n", $currentLineNumber, $ln);

            $currentLineNumber++;
            $f->next();
        }

        $html .= '</table>';
        return [$html, $raw];
    }
}

if (!function_exists('phpstorm_highlight_php_line')) {
    function phpstorm_highlight_php_line(string $line): string
    {
        // Garante contexto PHP pra tokenização correta
        $code = "<?php\n" . $line;
        $tokens = token_get_all($code);

        // Remove T_OPEN_TAG e a quebra logo após
        array_shift($tokens);
        if (!empty($tokens) && is_array($tokens[0]) && $tokens[0][0] === T_WHITESPACE) {
            $first = $tokens[0][1];
            $pos   = strpos($first, "\n");
            if ($pos !== false) {
                $tokens[0][1] = substr($first, $pos + 1);
            }
        }

        // Helpers de lookahead/lookbehind
        $isKwClassy = function ($lastSig) {
            static $set = [
                T_NEW, T_EXTENDS, T_IMPLEMENTS, T_INSTANCEOF,
                T_USE, T_NAMESPACE, T_CLASS, T_TRAIT, T_INTERFACE, T_CATCH
            ];
            return in_array($lastSig, $set, true) || $lastSig === '::';
        };
        $nextNonWs = function(array $tokens, int $i) {
            $n = count($tokens);
            for ($j = $i + 1; $j < $n; $j++) {
                $t = $tokens[$j];
                if (is_array($t) && $t[0] === T_WHITESPACE) continue;
                return $t;
            }
            return null;
        };
        $prevNonWs = function(array $tokens, int $i) {
            for ($j = $i - 1; $j >= 0; $j--) {
                $t = $tokens[$j];
                if (is_array($t) && ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) continue;
                return $t;
            }
            return null;
        };

        $out = '';
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];

            if (is_array($tok)) {
                [$id, $text] = $tok;
                $esc = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                switch ($id) {
                    case T_VARIABLE:
                        $out .= '<span class="tok var">'.$esc.'</span>';
                        break;

                    case T_CONSTANT_ENCAPSED_STRING:
                    case T_ENCAPSED_AND_WHITESPACE:
                        $out .= '<span class="tok str">'.$esc.'</span>';
                        break;

                    case T_LNUMBER:
                    case T_DNUMBER:
                        $out .= '<span class="tok num">'.$esc.'</span>';
                        break;

                    case T_COMMENT:
                    case T_DOC_COMMENT:
                        $out .= '<span class="tok com">'.$esc.'</span>';
                        break;

                    // Palavras-chave
                    case T_CLASS: case T_INTERFACE: case T_TRAIT:
                    case T_EXTENDS: case T_IMPLEMENTS: case T_INSTANCEOF:
                    case T_FUNCTION: case T_PUBLIC: case T_PROTECTED: case T_PRIVATE:
                    case T_STATIC: case T_ABSTRACT: case T_FINAL:
                    case T_IF: case T_ELSE: case T_ELSEIF:
                    case T_FOR: case T_FOREACH: case T_WHILE: case T_DO:
                    case T_SWITCH: case T_CASE: case T_DEFAULT:
                    case T_BREAK: case T_CONTINUE: case T_RETURN:
                    case T_NEW: case T_THROW: case T_TRY: case T_CATCH: case T_FINALLY:
                    case T_USE: case T_NAMESPACE: case T_GLOBAL:
                    case T_ECHO: case T_PRINT: case T_INCLUDE: case T_REQUIRE:
                    case T_INCLUDE_ONCE: case T_REQUIRE_ONCE:
                    case T_YIELD: case T_MATCH:
                    $out .= '<span class="tok kw">'.$esc.'</span>';
                    break;

                    case T_STRING:
                        // Heurísticas p/ class|func|método|prop
                        $prev = $prevNonWs($tokens, $i);
                        $next = $nextNonWs($tokens, $i);

                        $prevSign = null;
                        if (is_array($prev)) {
                            $prevSign = $prev[0];
                        } elseif (is_string($prev)) {
                            $prevSign = $prev; // pode ser '->' ou '::' etc.
                        }

                        // Método estático/instância?
                        $isAfterScope = ($prevSign === '::');
                        $isAfterArrow = ($prevSign === '->');

                        // Próximo abre parênteses?
                        $nextIsCall = is_string($next) ? ($next === '(')
                            : (is_array($next) && $next[0] === '(');

                        if ($isAfterArrow) {
                            // ->prop ou ->metodo()
                            if ($nextIsCall) {
                                $out .= '<span class="tok func">'.$esc.'</span>'; // método
                            } else {
                                $out .= '<span class="tok var">'.$esc.'</span>';  // propriedade (usa cor de var)
                            }
                        } elseif ($isAfterScope) {
                            // ::Constante / ::metodo()
                            if ($nextIsCall) {
                                $out .= '<span class="tok func">'.$esc.'</span>'; // método estático
                            } else {
                                $out .= '<span class="tok cls">'.$esc.'</span>';  // constante/identificador estático
                            }
                        } else {
                            // Classe se veio depois de keyword "classy"; senão, função se for chamada
                            if ($isKwClassy($prevSign)) {
                                $out .= '<span class="tok cls">'.$esc.'</span>';
                            } elseif ($nextIsCall) {
                                $out .= '<span class="tok func">'.$esc.'</span>';
                            } else {
                                // fallback: tratamos como classe/identificador
                                $out .= '<span class="tok cls">'.$esc.'</span>';
                            }
                        }
                        break;

                    case T_WHITESPACE:
                        $out .= $esc; // preserva indent
                        break;

                    default:
                        $out .= $esc;
                        break;
                }
            } else {
                // Símbolos brutos (=>, (), {}, ::, ->, ;, , ...)
                $sym = htmlspecialchars($tok, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $out .= '<span class="tok sym">'.$sym.'</span>';
            }
        }

        // Linha vazia? garante altura mínima:
        return $out === '' ? '&#8203;' : $out;
    }
}



/* --------------- renderizadores de stack --------------- */

if (!function_exists('format_stack_text')) {
    function format_stack_text(array $bt): string
    {
        $out = [];
        foreach ($bt as $i => $f) {
            $file = shorten_path($f['file'] ?? 'unknown');
            $line = $f['line'] ?? 0;
            $call = ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '');
            $call = $call ?: 'global';
            $out[] = sprintf('#%02d %s:%s -> %s()', $i, $file, (string)$line, $call);
        }
        return implode(PHP_EOL, $out);
    }
}

if (!function_exists('format_stack_breadcrumb')) {
    function format_stack_breadcrumb(array $bt): string
    {
        $items = [];
        foreach ($bt as $f) {
            $file = htmlspecialchars(shorten_path($f['file'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $line = (int)($f['line'] ?? 0);
            $items[] = "<span class=\"r2dbg__crumb\"><code>{$file}</code>:<b>{$line}</b></span>";
        }
        return implode('<span class="r2dbg__sep"> ➜ </span>', $items);
    }
}

if (!function_exists('format_stack_html')) {
    function format_stack_html(array $bt): string
    {
        $html = '';
        foreach ($bt as $i => $f) {
            $file = htmlspecialchars(shorten_path($f['file'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $line = (int)($f['line'] ?? 0);
            $func = htmlspecialchars(($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? 'global'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= "<li class=\"r2dbg__frame\">
                <div class=\"r2dbg__frame-head\">
                    <span class=\"r2dbg__badge\">#{$i}</span>
                    <span class=\"r2dbg__func\">{$func}()</span>
                    <span class=\"r2dbg__file\"><code>{$file}</code>:<b>{$line}</b></span>
                </div>
            </li>";
        }
        return $html;
    }
}

/* --------------- assets (CSS/JS) --------------- */

if (!function_exists('inject_r2_debug_assets_once')) {
    function inject_r2_debug_assets_once(): void
    {
        static $injected = false;
        if ($injected) return;
        $injected = true;

        echo <<<HTML
<style id="r2dbg-style">
/* =========================
   R2DBG • Dark Harmony Theme
   Paleta base:
   - Base: Slate (bg), Zinc (bordas)
   - Acentos: Indigo / Sky / Emerald / Amber
   ========================= */

.r2dbg {
  /* ---- Tokens de cor (escopo local) ---- */
  --r2-bg-0: #0b0f14;   /* fundo da área */
  --r2-bg-1: #0f141a;   /* header/tabs stickies */
  --r2-bg-2: #121923;   /* painéis e caixas */
  --r2-bg-3: #0d131b;   /* pre/code */
  --r2-brd:  #222933;   /* bordas neutras */
  --r2-brd-2:#2a3340;

  --r2-fg:   #e6e9ee;   /* texto principal */
  --r2-fg-2: #c9d0d9;   /* texto secundário */
  --r2-fg-3: #9aa7b5;   /* meta/crumbs */

  --r2-accent:    #5b7cfa; /* indigo-400/500 */
  --r2-accent-2:  #7aa2ff; /* hover/acento claro */
  --r2-muted:     #2a3344; /* botões e tabs off */
  --r2-hover:     #1a2230; /* hover neutro */
  --r2-focus:     #8ec5ff; /* foco/anel */

  /* sintaxe (code) */
  --tok-kw:   #aab4ff; /* indigo-300/400 */
  --tok-var:  #8ce3ff; /* sky-300 */
  --tok-str:  #9bf3b3; /* emerald-300 */
  --tok-num:  #ffd590; /* amber-300 */
  --tok-com:  #7f8a98; /* slate-400 */
  --tok-func: #7aa2ff; /* fuchsia-300 */
  --tok-cls:  #d5ddf6; /* indigo-200 */
  --tok-sym:  #e2e6ea; /* neutro claro */

  color-scheme: dark;
  margin:1rem 0;
  border:1px solid var(--r2-brd);
  border-radius:14px;
  background:var(--r2-bg-0);
  color:var(--r2-fg);
  font:13px ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,Ubuntu,"Noto Sans",Arial;
}
.r2dbg *{box-sizing:border-box}

/* ---------- Header ---------- */
.r2dbg__header {
  position:sticky; top:0; z-index:1;
  display:grid; grid-template-columns:1fr auto;
  gap:.5rem; align-items:center;
  padding:.6rem .8rem;
  border-bottom:1px solid var(--r2-brd);
  border-radius:14px;
  margin:0.5rem;
  background:linear-gradient(180deg,var(--r2-bg-1),var(--r2-bg-0));
}
.r2dbg__title{display:flex;gap:.5rem;align-items:baseline}
.r2dbg__label{opacity:.95;font-weight:700;color:var(--r2-accent)}
.r2dbg__meta{
  display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;
  opacity:.9;font-size:12px;color:var(--r2-fg-3)
}
.r2dbg__crumbs code,
.r2dbg__file code{
  background:rgba(124,143,255,.10);
  padding:.14rem .36rem;border-radius:6px;
  color:var(--r2-fg-2); border:1px solid var(--r2-brd);
}
.r2dbg__actions{display:flex;gap:.4rem}

/* ---------- Botões ---------- */
.r2dbg__btn {
  border:1px solid var(--r2-brd-2);
  background:var(--r2-muted);
  padding:.35rem .65rem; border-radius:8px;
  color:#e9edff; cursor:pointer; transition:.15s;
  font-size:12px;
}
.r2dbg__btn:hover{background:var(--r2-hover); border-color:var(--r2-accent)}
.r2dbg__btn:focus-visible{outline:2px solid var(--r2-focus); outline-offset:1px}
.r2dbg__btn[disabled]{opacity:.55; cursor:not-allowed}

/* ---------- Tabs ---------- */
.r2dbg__tabs{
  display:flex;gap:.25rem;
  padding:.4rem .4rem .2rem;
  border-bottom:1px solid var(--r2-brd);
  position:sticky; top:2.7rem; background:var(--r2-bg-0); z-index:1
}
.r2dbg__tab{
  appearance:none; border:1px solid transparent;
  background:transparent; color:var(--r2-fg-2);
  padding:.35rem .6rem; border-radius:8px; cursor:pointer;
  font-size:12px; transition:.15s;
}
.r2dbg__tab:hover{background:rgba(124,143,255,.10); color:#fff}
.r2dbg__tab.is-active{
  border-color:var(--r2-brd-2);
  background:linear-gradient(180deg,var(--r2-bg-2),var(--r2-bg-0));
  color:#fff
}

/* ---------- Panes / Conteúdo ---------- */
.r2dbg__panes{padding:.5rem .8rem;font-size:13px}
.r2dbg__pane{display:none}
.r2dbg__pane.is-active{display:block}
.r2dbg__dump{overflow:auto}
.r2dbg__pre{
  white-space:pre-wrap; line-height:1.25;
  background:var(--r2-bg-3); padding:.6rem .7rem;
  border:1px solid var(--r2-brd); border-radius:8px;
  color:#fff; font-size:12px;
}

/* ---------- Breadcrumb & Stack ---------- */
.r2dbg__breadcrumb{display:flex;flex-wrap:wrap;gap:.25rem .4rem;margin-bottom:.5rem;font-size:12px;color:var(--r2-fg-3)}
.r2dbg__sep{opacity:.6}
.r2dbg__stack{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.3rem;font-size:12px}
.r2dbg__frame{
  padding:.38rem .5rem;border:1px solid var(--r2-brd);border-radius:8px;
  background:var(--r2-bg-3)
}
.r2dbg__badge{
  display:inline-block; min-width:1.3rem; text-align:center;
  border:1px solid var(--r2-brd-2); border-radius:999px;
  padding:.05rem .35rem; margin-right:.35rem;
  background:linear-gradient(180deg,var(--r2-bg-2),var(--r2-bg-3));
  color:#a9b4ff; font-size:11px
}
.r2dbg__func{font-weight:700;margin-right:.4rem;color:#e9edff}

/* ---------- Tabela do “Trecho” (compacto, PhpStorm vibe) ---------- */
.r2dbg__code{
  width:100%; border-collapse:separate; border-spacing:0;
  border:1px solid var(--r2-brd-2); border-radius:8px; overflow:hidden;
  font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace;
  font-size:12px; line-height:1.25;
  background:#1b1f26;
}
.r2dbg__code .r2dbg__gutter{
  width:3.2rem; text-align:right;
  padding:.12rem .45rem;
  color:#a9b7c6b3; background:#222734;
  border-right:1px solid var(--r2-brd-2); user-select:none;
  font-size:11px;
}
.r2dbg__code .r2dbg__line{padding:.12rem .5rem}
.r2dbg__code .r2dbg__line code{
  display:block; white-space:pre; tab-size:4; color:#cfd6df;
}

/* highlight da linha do callsite */
.r2dbg__code tr.is-hl .r2dbg__line{background:#202836}
.r2dbg__code tr.is-hl .r2dbg__gutter{background:#263048;color:#ffffff}
.r2dbg__code tr.is-hl .r2dbg__line{box-shadow:inset 3px 0 0 var(--r2-accent)}

/* ---------- Tokens de sintaxe (harmonizados) ---------- */
.tok.kw   { color:var(--tok-kw);   font-weight:600; }
.tok.var  { color:var(--tok-var); }
.tok.str  { color:var(--tok-str); }
.tok.num  { color:var(--tok-num); }
.tok.com  { color:var(--tok-com); font-style:italic;}
.tok.func { color:var(--tok-func); }
.tok.cls  { color:var(--tok-cls); }
.tok.sym  { color:var(--tok-sym); }

.r2dbg .r2dbg__code .tok-comment{ color:var(--tok-com)!important; font-style:italic }
.r2dbg .r2dbg__code .tok-string { color:var(--tok-str)!important; }
.r2dbg .r2dbg__code .tok-var    { color:var(--tok-var)!important; }
.r2dbg .r2dbg__code .tok-number { color:var(--tok-num)!important; }
.r2dbg .r2dbg__code .tok-kw     { color:var(--tok-kw)!important; font-weight:700 }
.r2dbg .r2dbg__code .tok-func   { color:var(--tok-func)!important; }
.r2dbg .r2dbg__code .tok-class  { color:var(--tok-cls)!important; }
.r2dbg .r2dbg__code .tok-magic  { color:var(--tok-cls)!important; font-weight:600 }
.r2dbg .r2dbg__code .tok-php    { color:var(--tok-kw)!important; font-weight:700 }
.r2dbg .r2dbg__code .tok-op     { color:#cfd6df!important; }
.r2dbg .r2dbg__code .tok-type   { color:var(--tok-kw)!important; }
.r2dbg .r2dbg__code .tok-key    { color:#a5d27a!important; }   /* keys de arrays/objetos */
.r2dbg .r2dbg__code .tok-method { color:#ffd58f!important; }
.r2dbg .r2dbg__code .tok-prop   { color:#2083ef!important; }
.r2dbg .r2dbg__code .tok-const  { color:#dadbff!important; }
.r2dbg .r2dbg__code .tok-attrname{color:#e7c75f!important;}
</style>



<script id="r2dbg-script">
(function(){
  if (window.__r2dbgLoaded) return; window.__r2dbgLoaded = true;

  function copyBase64(b64, btn){
    try{
      var text = atob(b64 || '');
      if (!text) { feedback(btn,true); return; }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function(){ feedback(btn); }, function(){ fallback(text, btn); });
      } else { fallback(text, btn); }
    }catch(e){ console.error('r2dbg copy error', e); }
  }
  function fallback(text, btn){
    var ta = document.createElement('textarea');
    ta.value = text; document.body.appendChild(ta);
    ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
    feedback(btn);
  }
  function feedback(btn, disabled){
    if (!btn) return;
    if (disabled) { btn.disabled = true; return; }
    var old = btn.textContent;
    btn.textContent = 'Copiado ✓'; btn.disabled = true;
    setTimeout(function(){ btn.textContent = old; btn.disabled = false; }, 1200);
  }

  // Tabs (garante "Variável" como default)
  document.addEventListener('click', function(e){
    var btn = e.target.closest('button[data-r2clip]');
    if (btn){ e.preventDefault(); copyBase64(btn.getAttribute('data-r2clip'), btn); return; }

    var tab = e.target.closest('.r2dbg__tab');
    if (tab){
      var root = tab.closest('[data-r2dbg]');
      var name = tab.getAttribute('data-tab');
      root.querySelectorAll('.r2dbg__tab').forEach(function(t){
        var active = t === tab;
        t.classList.toggle('is-active', active);
        t.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      root.querySelectorAll('.r2dbg__pane').forEach(function(p){
        p.classList.toggle('is-active', p.getAttribute('data-pane') === name);
      });
    }
  }, true);
})();
</script>
HTML;
    }
}
