<?php

use Symfony\Component\VarDumper\VarDumper;

if (!function_exists('debug')) {
    function debug($variavel, $stop = true)
    {
        echo "\r\n\r\n";
        $debug = debug_backtrace();
        $trace = array_shift($debug);
        $file = $trace['file'];
        $line = $trace['line'];
        echo $lineInfo = sprintf('%s (line %s) -> %s', $file, $line, tempo_execucao());

        VarDumper::dump($variavel);
        if ($stop) {
            die();
        }
    }
}
if (!function_exists('debug_pre')) {
    function debug_pre($variavel, $stop = true)
    {
        echo "<pre>";
        var_dump($variavel);
        echo "<pre>";

        if ($stop) {
            die();
        }
    }
}
function tempo_execucao()
{
    $inicio = $_SERVER['REQUEST_TIME'];
    $fim = microtime(true);
    $total = $fim - $inicio;
    return 'Tempo de Execução: ' . number_format($total, 5) . ' ms';
}
