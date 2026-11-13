<?php
declare(strict_types=1);

use BibliotecaVendas\Infrastructure\Database;
use BibliotecaVendas\Repositories\GestaoRepository;
use BibliotecaVendas\Support\Autenticacao;
use BibliotecaVendas\Support\SessaoWeb;

require_once dirname(__DIR__) . '/src/bootstrap.php';
SessaoWeb::iniciar();
Autenticacao::exigirEquipe();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="vendas-biblioteca.csv"');
header('Cache-Control: no-store');
$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Pedido','Criado em','Pago em','Status','Valor centavos','Cliente','E-mail','CPF'], ';');
foreach ((new GestaoRepository(Database::connect()))->exportarVendas() as $row) {
    $cells = [$row['id'], $row['criado_em'], $row['pago_em'], $row['status'], $row['valor_total_centavos'], $row['nome'], $row['email'], $row['identificacao']];
    foreach ($cells as &$cell) {
        $cell = (string) $cell;
        if (preg_match('/^[\s]*[=+\-@]/u', $cell)) $cell = "'" . $cell;
    }
    unset($cell);
    fputcsv($out, $cells, ';', '"', '');
}
fclose($out);
