<?php
// ============================================================
//  CONEXAO.PHP — Ligação ao PostgreSQL via PDO
//  Base de dados: chatbot
//  Schema: public
//  Servidor: localhost (XAMPP + PostgreSQL)
// ============================================================

define('DB_HOST',     'localhost');
define('DB_PORT',     '5432');
define('DB_NOME',     'chatbot');
define('DB_USUARIO',  'postgres');
define('DB_SENHA',    '12345678');
define('DB_SCHEMA',   'public');

function obterConexao(): PDO {
    static $pdo = null;

    // Reutiliza a conexão se já existir (padrão Singleton)
    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;options=--search_path=%s',
        DB_HOST,
        DB_PORT,
        DB_NOME,
        DB_SCHEMA
    );

    try {
        $pdo = new PDO($dsn, DB_USUARIO, DB_SENHA, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // lança excepções em erros
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // retorna arrays associativos
            PDO::ATTR_EMULATE_PREPARES   => false,                  // usa prepared statements reais
        ]);

        return $pdo;

    } catch (PDOException $e) {
        // Em produção, nunca mostrar detalhes do erro ao utilizador
        $mensagem = defined('AMBIENTE') && AMBIENTE === 'producao'
            ? 'Erro ao conectar à base de dados. Tente mais tarde.'
            : 'Erro de conexão: ' . $e->getMessage();

        http_response_code(500);
        die(json_encode([
            'sucesso' => false,
            'erro'    => $mensagem
        ]));
    }
}